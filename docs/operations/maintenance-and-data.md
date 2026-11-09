# Manutenção, backups, recuperação, desativação e remoção de dados

Complementa [installation.md](installation.md) e [panel-security.md](panel-security.md) (chave, reset de fator e rotação, que não se repetem aqui). Detalhes técnicos da manutenção: [phase-7-maintenance.md](../testing/phase-7-maintenance.md).

## WP-Cron e cron de servidor (opcional)

A manutenção (`stealthaudit_maintenance`, de hora em hora) apaga auditoria com mais de 365 dias e erros com mais de 30, limpa estado auxiliar vencido e reconcilia contadores, em lotes limitados (20 lotes ou 2 s por execução). O WP-Cron só roda quando o site recebe visitas: sem tráfego ela atrasa. As consultas escondem o vencido mesmo antes do expurgo, e o painel mostra "atrasada" depois de 3 h sem execução.

Cron de servidor é opcional. Se o host oferecer:

```text
# wp-config.php
define( 'DISABLE_WP_CRON', true );

# agendador do host, a cada 5–15 minutos
wp cron event run --due-now --path=/caminho/wordpress
```

Sem WP-CLI, o agendador pode chamar `https://site/wp-cron.php` com o mesmo efeito. Para conferir: `wp cron event list --hook=stealthaudit_maintenance` e a linha "Manutenção:" do painel.

## Backups e cópias externas

- O backup precisa incluir as três tabelas `{prefixo}stealthaudit_*`, as options `stealthaudit_*` e a configuração externa. A chave fica em backup **separado** e cifrado ([panel-security.md](panel-security.md#configuração-e-backup)): banco sem chave não abre fatores TOTP.
- Backups, dumps, réplicas, binlogs e cópias do host têm política própria. O expurgo de 365/30 dias só alcança o banco ativo; o plugin não apaga nem promete apagar cópias externas. Ajuste a retenção dos backups à política de dados do site.
- A base GeoIP não precisa de backup do plugin: é do operador e é baixada de novo pelo processo dele ([geoip.md](geoip.md)). Nada de localização é gravado no banco.

## Recuperação

| Situação | Ação |
| --- | --- |
| Painel 403 "Acesso negado" para um operador que deveria entrar | O ID não está em `authorized_user_ids`, **ou a configuração inteira foi rejeitada**: arquivo ilegível ou dentro do webroot, chave que não tem 32 bytes, `mmdb_max_age_days` fora de 1–3650 ou qualquer outro valor inválido. Então a allowlist volta a vazia. Confira com `wp eval 'var_dump( \StealthAudit\Bootstrap\Runtime::boot()->configuration_valid );'` ([installation.md](installation.md#configuração-segura)). |
| Painel 503 "indisponível" (ID autorizado) | A configuração é válida, mas falta algo: `key_base64` vazio, `installation_id` vazio, extensões `sodium`/`iconv`/`xmlwriter` ou o armazenamento (banco, esquema) não está pronto. O código `STEALTHAUDIT_BOOT_STATUS` (`ready`, `vendor_missing`, `bootstrap_missing`, `bootstrap_error`, `php_unsupported`) pode ser lido por `wp eval 'echo STEALTHAUDIT_BOOT_STATUS;'`. |
| Fator perdido, recuperação abandonada (`recovery_locked`) | Reset offline em [panel-security.md](panel-security.md#reset-de-fator-perdido). |
| Chave perdida | [panel-security.md](panel-security.md#rotação-de-chave-e-migração), último parágrafo. |
| "Atrasada" persistente | Site sem tráfego ou WP-Cron desligado: veja a seção acima. |
| Captura "suspensa por capacidade" | Os limites `event_rows`/`event_bytes` (ou de erros) foram atingidos. Aguarde a manutenção, ou aumente `limits` conscientemente: acima de 100 000 eventos, buscas com filtros esparsos ficam lentas ([installation.md](installation.md#capacidade-e-limites)). |
| Atualização com problema | Rollback de arquivos (e do banco, se o esquema migrou) em [installation.md](installation.md#atualização-de-arquivos-e-migração). |
| Restauração de backup | Restaure banco e configuração do **mesmo** momento; um banco restaurado com outra chave não abre os fatores. Provas de sessão antigas restauradas expiram sozinhas (inatividade de 30 min). |

## Pausar o painel sem parar a coleta

Esvazie `authorized_user_ids` na configuração ativa. A coleta continua, ninguém consulta (o painel responde 403 a todos). É o passo inicial de reset e rotação ([panel-security.md](panel-security.md)).

## Desativação emergencial pelo loader

Quando o plugin precisa parar inteiro (coleta, painel e REST):

```text
mv wp-content/mu-plugins/stealthaudit.php wp-content/mu-plugins/stealthaudit.php.off
```

Pelo gerenciador de arquivos do host basta renomear do mesmo jeito; o WordPress só carrega `.php` na raiz de `mu-plugins`. Efeitos: na requisição seguinte nada do StealthAudit é carregado; o endpoint vira uma página comum do WordPress (normalmente 404) e `stealthaudit/v1` some. O evento `stealthaudit_maintenance` fica agendado sem handler (o WordPress só o reagenda, sem erro). **Nenhum dado é apagado**: tabelas, options, fatores e a configuração ficam como estavam, e o período desativado não terá registros. Para reativar, renomeie de volta.

Remover o loader nunca limpa logs automaticamente, por decisão de projeto: a desativação pode ser um incidente, e apagar provas nesse momento seria o pior efeito.

## Remoção explícita dos dados

Não existe endpoint, botão nem comando HTTP para "apagar tudo". A remoção é manual, deliberada e feita pelo operador com acesso ao banco:

1. Exporte o que precisar guardar e confirme a política de retenção (inclusive dos backups, que não são afetados).
2. Desative pelo loader (seção acima) **antes** de apagar: com o loader ativo a próxima requisição recriaria as tabelas.
3. Descubra o prefixo (`wp db prefix`) e apague as tabelas, trocando `wp_` pelo prefixo real; as options e o evento de cron saem pelas APIs do WordPress, que também limpam o cache de objetos:

   ```text
   wp db query "DROP TABLE IF EXISTS wp_stealthaudit_events, wp_stealthaudit_errors, wp_stealthaudit_state"
   wp option delete stealthaudit_schema_version stealthaudit_schema_lease
   wp cron event delete stealthaudit_maintenance
   ```

   A lease só existe durante uma instalação ou migração; sem ela, o `wp option delete` imprime `Warning: Could not delete 'stealthaudit_schema_lease' option. Does it exist?` e termina com código 0, o que é esperado. Sem WP-CLI, rode pelo phpMyAdmin do host o `DROP TABLE` acima e `DELETE FROM wp_options WHERE option_name IN ('stealthaudit_schema_version','stealthaudit_schema_lease')`. Nesse caso, com cache de objetos persistente (Redis, Memcached), esvazie o cache depois: o SQL direto não o atualiza.
4. Apague `wp-content/mu-plugins/stealthaudit/` e o `stealthaudit.php.off`.
5. Remova a configuração (`STEALTHAUDIT_CONFIG`/`STEALTHAUDIT_CONFIG_FILE` do `wp-config.php` e o arquivo externo) e descarte a chave conforme a política do cofre. Se usava GeoIP, apague a base e a configuração/chave de download do seu processo de atualização.
6. As regras de rewrite do endpoint saem na próxima regeneração (Configurações → Links permanentes → Salvar).

A própria remoção não fica registrada no StealthAudit; registre-a no processo de mudanças da equipe.
