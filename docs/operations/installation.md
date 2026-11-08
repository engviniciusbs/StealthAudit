# Instalação, configuração segura e atualização

Runbook do operador para um site WordPress single-site. Chave, backup da chave, rotação e reset de fator estão em [panel-security.md](panel-security.md); manutenção, recuperação, desativação e remoção de dados em [maintenance-and-data.md](maintenance-and-data.md); GeoIP em [geoip.md](geoip.md). Não há pacote de produção publicado ainda (fase 8): o procedimento abaixo usa uma cópia do repositório com dependências de produção instaladas.

## Requisitos

- PHP 8.3 ou mais novo. Coleta: `mysqli` e `json`. Painel: também `sodium`, `iconv` e `xmlwriter`. Sem eles a coleta continua e o painel fica bloqueado (503), nunca aberto.
- MariaDB/MySQL com InnoDB (testado só MariaDB 10.11).
- HTTPS no site: o painel recusa HTTP simples. Atrás de proxy, o WordPress precisa enxergar `is_ssl()` verdadeiro por um cabeçalho que o proxy **sobrescreve** (não basta repassar o do cliente).
- Acesso de escrita a `wp-content/mu-plugins/` e um lugar **fora do webroot** para a configuração (e, se usar, para a base GeoIP). SSH e cron de servidor não são obrigatórios.

## Instalação como must-use

1. Prepare a árvore fora do servidor: `composer install --no-dev --prefer-dist` na cópia do código (gera `vendor/` de produção). Não envie `tests/`, `tools/`, `docs/`, `node_modules/`, `artifacts/` nem arquivos `.mmdb`.
2. Envie o núcleo para `wp-content/mu-plugins/stealthaudit/` (com `bootstrap.php`, `src/`, `templates/`, `assets/`, `vendor/`, `LICENSE`, `THIRD-PARTY-NOTICES.md`).
3. Copie **por último** o loader `loader/stealthaudit.php` para `wp-content/mu-plugins/stealthaudit.php`. O WordPress só carrega arquivos `.php` na raiz de `mu-plugins`; o loader é o interruptor. Enviando-o por último, nenhuma requisição carrega um núcleo incompleto (se carregar, o loader detecta `vendor_missing`/`bootstrap_missing`/`bootstrap_error` e não faz nada).
4. Na primeira requisição o esquema é criado (`{prefixo}stealthaudit_events`, `_errors`, `_state`; options `stealthaudit_schema_version` e, durante a criação, `stealthaudit_schema_lease`) e o evento WP-Cron `stealthaudit_maintenance` é agendado. A coleta começa sem nenhuma configuração; o painel continua fechado para todos até a configuração abaixo.
5. Confira: em **Plugins → Must-Use** aparece "StealthAudit"; o painel em `https://site/stealthaudit/` (permalinks bonitos) ou `https://site/?stealthaudit=panel` (permalinks simples) redireciona ao login e, sem configuração, responde 503 "Painel indisponível".

## Configuração segura

A configuração vem só de uma fonte escolhida pelo operador; o plugin nunca lê configuração de requisições, options ou usermeta.

- **Preferido:** um arquivo PHP fora do webroot que retorna um array, apontado por `define( 'STEALTHAUDIT_CONFIG_FILE', '/caminho/absoluto/stealthaudit.php' );` em `wp-config.php` (ou pela variável de ambiente `STEALTHAUDIT_CONFIG_FILE`). Um arquivo dentro do webroot é recusado e o painel fica bloqueado. Permissão de leitura só para o operador e o usuário do PHP (ex.: `0640`).
- **Alternativa:** `define( 'STEALTHAUDIT_CONFIG', array( … ) );` no próprio `wp-config.php`. A chave então fica no `wp-config.php`, que entra em backups de arquivos: trate-o como segredo.

Partindo de `config/stealthaudit.example.php`:

| Chave | Uso |
| --- | --- |
| `authorized_user_ids` | IDs numéricos explícitos. Vazio nega todos, inclusive administradores; ser administrador não autoriza. |
| `endpoint` | Caminho do painel no mesmo domínio (`/stealthaudit`). |
| `key_base64`, `key_id`, `installation_id` | Chave de 32 bytes e identidades; geração, guarda e rotação em [panel-security.md](panel-security.md). |
| `trusted_proxies` | IPs/CIDRs exatos do proxy reverso. Vazio ignora `X-Forwarded-For`. |
| `limits` | Orçamentos de capacidade (`Configuration::DEFAULT_LIMITS`). |
| `mmdb_path`, `mmdb_max_age_days` | GeoIP opcional, ver [geoip.md](geoip.md). |

Configuração malformada não abre nada: o painel volta aos padrões que negam e a coleta continua. Não versionar o arquivo efetivo nem colocá-lo em tickets ou CI.

## Visibilidade real

- O StealthAudit aparece em **Plugins → Must-Use** para quem pode ver a tela de plugins, com o nome e a descrição do cabeçalho do loader. O WordPress não oferece "Desativar" para must-use; a lista de plugins convencionais, contagens e links dos outros plugins não são alterados (verificado no e2e da fase 6).
- Não há item de menu, barra de administração nem tela no wp-admin. O endpoint do painel e as rotas `stealthaudit/v1` existem e respondem sem dados a quem não cumpre a política (login WordPress, allowlist, HTTPS, senha reconfirmada e TOTP).
- Isto evita desativação acidental pelo painel do WordPress; **não** protege contra quem consegue executar PHP, instalar plugins, editar arquivos ou o banco. Um código detectável é aceito pelo projeto.

## Atualização de arquivos e migração

1. Faça backup do banco (tabelas do plugin incluídas) e do arquivo de configuração, conforme [maintenance-and-data.md](maintenance-and-data.md#backups-e-cópias-externas).
2. Envie a nova versão para um diretório irmão (ex.: `mu-plugins/stealthaudit.new/`) e troque por renomeação: `stealthaudit/` → `stealthaudit.old/`, `stealthaudit.new/` → `stealthaudit/`. Copiar por cima, arquivo a arquivo, deixa requisições com `vendor/` misturado; o loader contém a falha, mas a coleta para durante a cópia.
3. Atualize o loader só se ele mudou, também por renomeação de um arquivo completo.
4. A migração de esquema roda sozinha na primeira requisição, sob lease de 120 s; enquanto isso, requisições concorrentes não coletam. Verifique o painel e a linha "Manutenção" depois.
5. Mantenha `stealthaudit.old/` fora de `mu-plugins` (ou apague) depois de validar: um diretório antigo não é carregado, mas guarda código desatualizado no webroot.

**Rollback.** Dentro da mesma versão de esquema basta trocar os diretórios de volta. Se a nova versão migrou o esquema (ex.: 1 → 2), restaure **juntos** os arquivos antigos e o backup do banco; não há migração para trás. A tabela de estado (fatores, provas, limites de tentativa, orçamentos) não é reescrita por atualização de arquivos, então fatores e limites sobrevivem a uma troca de diretório. Mudanças de chave seguem só a rotação offline de [panel-security.md](panel-security.md).
