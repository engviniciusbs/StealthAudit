# Instalação, configuração segura e atualização

Runbook do operador para um site WordPress single-site. Chave, backup da chave, rotação e reset de fator estão em [panel-security.md](panel-security.md); manutenção, recuperação, desativação e remoção de dados em [maintenance-and-data.md](maintenance-and-data.md); GeoIP em [geoip.md](geoip.md). O procedimento usa o ZIP de distribuição gerado por `npm run package` e conferido por `npm run verify-package` ([package.md](../testing/package.md)). A versão 0.1.0 está preparada para revisão: notas, SHA-256 e limitações conhecidas em [docs/releases/0.1.0.md](../releases/0.1.0.md). Nenhum release público, tag ou deploy foi feito. Índice da documentação: [docs/README.md](../README.md).

## Requisitos

- PHP 8.3 ou mais novo (testados 8.3.36, 8.4.27 e 8.5.12). Coleta: `mysqli` e `json`. Painel: também `sodium`, `iconv` e `xmlwriter`. Sem eles a coleta continua e o painel fica bloqueado (503 para um ID autorizado), nunca aberto.
- MariaDB/MySQL com InnoDB. Testados: MariaDB 10.11.19 e MySQL 8.4.13, com WordPress 7.1.4 single-site. Outras versões, multisite e outros bancos não têm evidência ([compatibility.md](../testing/compatibility.md#matriz-verificada-fase-8a)).
- HTTPS no site: o painel recusa HTTP simples. Atrás de proxy, o WordPress precisa enxergar `is_ssl()` verdadeiro por um cabeçalho que o proxy **sobrescreve** (não basta repassar o do cliente).
- Acesso de escrita a `wp-content/mu-plugins/` e um lugar **fora do webroot** para a configuração (e, se usar, para a base GeoIP). SSH e cron de servidor não são obrigatórios.

## Instalação como must-use

1. Confira o SHA-256 do ZIP (ex.: `sha256sum stealthaudit-0.1.0.zip`; no PowerShell, `Get-FileHash -Algorithm SHA256`). O `.zip.sha256` ao lado do ZIP só detecta download corrompido: quem troca o ZIP troca também esse arquivo. Para autenticidade, compare com o SHA-256 obtido por **outro canal** (notas do release no repositório, tag assinada ou o mantenedor), nunca só com o arquivo baixado junto. O ZIP já traz `vendor/` de produção prefixado (`StealthAuditVendor\`) e o autoloader pronto: **não** rode Composer no servidor. A lista de arquivos com SHA-256 de cada um está em `.contents.txt`.
2. Descompacte fora do webroot. O ZIP contém `mu-plugins/stealthaudit.php` (loader) e `mu-plugins/stealthaudit/` (`bootstrap.php`, `src/`, `templates/`, `assets/`, `bin/`, `config/stealthaudit.example.php`, `vendor/`, `LICENSE`, `THIRD-PARTY-NOTICES.md`). Copie o diretório `mu-plugins/stealthaudit/` para `wp-content/mu-plugins/stealthaudit/`.
3. Copie **por último** o loader `mu-plugins/stealthaudit.php` para `wp-content/mu-plugins/stealthaudit.php` (copie com outro nome e renomeie, para que nenhuma requisição leia um arquivo pela metade). O WordPress só carrega arquivos `.php` na raiz de `mu-plugins`; o loader é o interruptor. Enviando-o por último, nenhuma requisição carrega um núcleo incompleto (se carregar, o loader detecta `vendor_missing`/`bootstrap_missing`/`bootstrap_error` e não faz nada).
4. Na primeira requisição o esquema é criado (`{prefixo}stealthaudit_events`, `_errors`, `_state`; options `stealthaudit_schema_version` e, durante a criação, `stealthaudit_schema_lease`) e o evento WP-Cron `stealthaudit_maintenance` é agendado. A coleta começa sem nenhuma configuração; o painel continua fechado para todos até a configuração abaixo.
5. Confira: em **Plugins → Must-Use** aparece "StealthAudit"; o painel em `https://site/stealthaudit/` (permalinks bonitos) ou `https://site/?stealthaudit=panel` (permalinks simples) redireciona ao login. Sem configuração (ou com a allowlist vazia), um usuário logado recebe **403 "Acesso negado"**, inclusive administradores, porque a allowlist é conferida antes do resto. O 503 "Painel indisponível" só aparece para um ID **autorizado** quando a chave, o `installation_id`, as extensões do painel ou o armazenamento não estão prontos. Por HTTP simples a resposta é 403 "HTTPS obrigatório".

## Configuração segura

A configuração vem só de uma fonte escolhida pelo operador; o plugin nunca lê configuração de requisições, options ou usermeta.

- **Preferido:** um arquivo PHP fora do webroot que retorna um array, apontado por `define( 'STEALTHAUDIT_CONFIG_FILE', '/caminho/absoluto/stealthaudit.php' );` em `wp-config.php` (ou pela variável de ambiente `STEALTHAUDIT_CONFIG_FILE`). Um arquivo dentro do webroot é recusado e o painel fica bloqueado. Permissão de leitura só para o operador e o usuário do PHP (ex.: `0640`).
- **"Webroot" para o plugin é só o `ABSPATH`** (o diretório do WordPress, depois de `realpath`). A mesma regra vale para a base GeoIP. O plugin não conhece a raiz de documentos do servidor web: se ela fica **acima** do `ABSPATH` (WordPress instalado num subdiretório, como `public_html/wp/`), ou se há aliases e outros diretórios públicos, um arquivo nesses lugares passa pela checagem e pode ser baixável. Escolha um caminho fora de **todo** diretório servido pelo servidor web, não apenas fora do `ABSPATH`.
- **Alternativa:** `define( 'STEALTHAUDIT_CONFIG', array( … ) );` no próprio `wp-config.php`. A chave então fica no `wp-config.php`, que entra em backups de arquivos: trate-o como segredo.

Partindo de `config/stealthaudit.example.php`:

| Chave | Uso |
| --- | --- |
| `authorized_user_ids` | IDs numéricos explícitos. Vazio nega todos, inclusive administradores; ser administrador não autoriza. |
| `endpoint` | Caminho do painel no mesmo domínio (`/stealthaudit`). |
| `key_base64`, `key_id`, `installation_id` | Chave de 32 bytes e identidades; geração, guarda e rotação em [panel-security.md](panel-security.md). |
| `trusted_proxies` | IPs/CIDRs exatos do proxy reverso. Vazio ignora `X-Forwarded-For`. |
| `limits` | Orçamentos de capacidade (`Configuration::DEFAULT_LIMITS`); ver [Capacidade e limites](#capacidade-e-limites). |
| `mmdb_path`, `mmdb_max_age_days` | GeoIP opcional, ver [geoip.md](geoip.md). |

Configuração malformada não abre nada. **Qualquer** valor inválido descarta a configuração inteira, não só a chave errada: por exemplo `mmdb_max_age_days` fora de 1–3650 ou escrito como texto (`'30'`), `mmdb_path` relativo, uma chave desconhecida em `limits`, um ID não inteiro na allowlist ou uma chave que não tem 32 bytes. Nesse caso o painel fica fechado para todos (allowlist vazia: 403) e a coleta continua com os **padrões**: `limits` padrão, `endpoint` `/stealthaudit`, nenhum `trusted_proxies` (o IP registrado passa a ser o do proxy) e sem GeoIP. O plugin não mostra o motivo no painel; para confirmar, `wp eval 'var_dump( \StealthAudit\Bootstrap\Runtime::boot()->configuration_valid );'` imprime `bool(false)`. Não versionar o arquivo efetivo nem colocá-lo em tickets ou CI.

## Capacidade e limites

Padrões de `limits` (todos inteiros de 1 a 2.147.483.647; só se sobrescreve o que for informado):

| Limite | Padrão | Efeito |
| --- | --- | --- |
| `event_rows` / `event_bytes` | 100 000 / 134 217 728 (128 MiB lógicos) | Teto da auditoria retida. Atingido, a captura fica "suspensa por capacidade" até a manutenção liberar espaço. |
| `error_rows` / `error_bytes` | 20 000 / 33 554 432 (32 MiB lógicos) | Teto dos erros retidos, com o mesmo efeito. |
| `events_per_request` / `errors_per_request` | 25 / 5 | Gravações por requisição. |
| `events_per_second` / `errors_per_second` | 60 / 20 | Ritmo do site inteiro. |
| `state_rows` | 10 000 | Linhas de estado (fatores, provas, limites de tentativa, leases). |
| `storage_work_ms` | 100 | Trabalho de gravação por requisição; uma chamada SQL já iniciada não é interrompida. |

Bytes "lógicos" são uma estimativa do que foi gravado, não o tamanho no disco. Medido no benchmark local: 1 milhão de linhas de auditoria ocupam ~227 MiB e 1 milhão de erros ~453 MiB (dados + índices) ([performance.md](../testing/performance.md#ambiente-medido)).

- **Envelope validado: `event_rows` até 100 000, o padrão.** Nesse tamanho todas as metas de consulta foram atingidas (pior caso 187 ms no banco e 321 ms no painel). Acima dele, filtros esparsos em `action` ou `result` (colunas sem índice próprio; por exemplo uma ação rara ou `result=unknown`) percorrem toda a retenção. Numa tabela de 1 milhão levaram 2,1–8,2 s no banco e 6,4 s no painel, e o WordPress não interrompe a consulta. **Limite aceito para 0.1.0**; índices ficam para uma versão futura do esquema. Quem elevar `event_rows` deve esperar isso, ou restringir o período (`from`/`to`) nessas buscas ([performance.md](../testing/performance.md#limite-registrado-aceito-para-010)).
- **Perdas contadas só quando observáveis.** Recusas por capacidade ou ritmo entram no contador de perdas, e o painel mostra "limitado", quando a atualização do contador consegue gravar. Uma gravação perdida **só** por disputa na linha de estado (três rodadas de CAS perdidas), por falha do banco ou pelo limite por requisição pode não ser contada, e então não aparece no painel. É o custo de falhar fechado sem fila nem retry. Na rajada de 400 requisições do benchmark, 5 tentativas não apareceram em nenhum contador. Ausência de registro não prova ausência de ação.
- Rajadas acima do ritmo são recusadas por desenho (no benchmark, 275 de 400 contadas como perdas com o padrão de 60/s).

## Visibilidade real

- O StealthAudit aparece em **Plugins → Must-Use** para quem pode ver a tela de plugins, com o nome e a descrição do cabeçalho do loader. O WordPress não oferece "Desativar" para must-use; a lista de plugins convencionais, contagens e links dos outros plugins não são alterados (verificado no e2e da fase 6).
- Não há item de menu, barra de administração nem tela no wp-admin. O endpoint do painel e as rotas `stealthaudit/v1` existem e respondem sem dados a quem não cumpre a política (login WordPress, allowlist, HTTPS, senha reconfirmada e TOTP).
- Isto evita desativação acidental pelo painel do WordPress; **não** protege contra quem consegue executar PHP, instalar plugins, editar arquivos ou o banco. Um código detectável é aceito pelo projeto.

## Atualização de arquivos e migração

1. Faça backup do banco (tabelas do plugin incluídas) e do arquivo de configuração, conforme [maintenance-and-data.md](maintenance-and-data.md#backups-e-cópias-externas).
2. Confira o SHA-256 do novo ZIP, descompacte fora do webroot e envie `mu-plugins/stealthaudit/` para um diretório irmão (ex.: `mu-plugins/stealthaudit.new/`) e troque por renomeação: `stealthaudit/` → `stealthaudit.old/`, `stealthaudit.new/` → `stealthaudit/`. Copiar por cima, arquivo a arquivo, deixa requisições com `vendor/` misturado; o loader contém a falha, mas a coleta para durante a cópia.
3. Atualize o loader só se ele mudou, também por renomeação de um arquivo completo.
4. **Recarregue o PHP** logo depois da troca: `systemctl reload php8.3-fpm` (PHP-FPM), `apachectl -k graceful` (Apache com mod_php) ou o equivalente do painel de hospedagem; sem acesso a isso, um `opcache_reset()` executado pelo mesmo pool PHP do site. O opcache compara só o caminho e a data do arquivo: cópias que preservam a data (`unzip`, `rsync -a`, `cp -a`, SFTP com "preservar data") podem deixar a versão anterior em execução para os arquivos já compilados — uma mistura de versões. As entradas do ZIP têm a data do último commit que mudou o conteúdo empacotado, então uma versão posterior quase sempre tem data posterior. Mas dois builds do mesmo commit têm a mesma data. Além disso, a data DOS do ZIP tem passos de 2 segundos e não guarda fuso horário: o mtime instalado muda conforme o fuso do servidor que descompacta. Não dependa disso.
5. A migração de esquema roda sozinha na primeira requisição, sob lease de 120 s; enquanto isso, requisições concorrentes não coletam. Verifique o painel e a linha "Manutenção" depois.
6. Mantenha `stealthaudit.old/` fora de `mu-plugins` (ou apague) depois de validar: um diretório antigo não é carregado, mas guarda código desatualizado no webroot.

**Rollback.** Dentro da mesma versão de esquema basta trocar os diretórios de volta e **recarregar o PHP** como no passo 4 (o opcache pode manter a versão que estava rodando). Se a nova versão migrou o esquema (ex.: 1 → 2), restaure **juntos** os arquivos antigos e o backup do banco; não há migração para trás. A tabela de estado (fatores, provas, limites de tentativa, orçamentos) não é reescrita por atualização de arquivos, então fatores e limites sobrevivem a uma troca de diretório. Mudanças de chave seguem só a rotação offline de [panel-security.md](panel-security.md).
