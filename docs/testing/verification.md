# Verificação: matriz de compatibilidade PHP × banco (fase 8a)

Executado em 2026-11-08 no Windows 11 com Docker Engine 28.3.0, Node 22.17.0, Chrome 157 e `playwright-core` 1.64.1. WordPress fixado em 7.1.4 (pacote release). Esta etapa cobre o item 1 da fase 8 (unidade, integração WordPress, concorrência real com dois processos, subprocessos e navegação). Benchmarks, injeção de falhas, pacote prefixado e ZIP pertencem às fases 8b–8d e **não** foram medidos aqui.

## Como reproduzir

```text
npm run matrix -- --php 8.4 --db mysql:8.4.13 --e2e
npm run matrix -- --php 8.5 --db mariadb:10.11.19 --e2e
npm run matrix -- --php 8.4 --db mysql:8.4.13 --prefix wpx9_ --collate utf8mb4_0900_ai_ci
```

`tools/matrix.mjs` cria um ambiente **adicional e isolado** por combinação (`tools/wp-env.cjs --matrix=…`): config gerada e ignorada pelo git (`.wp-env.matrix-<slug>.json`), `WP_ENV_HOME` próprio (`~/.wp-env-stealthaudit-matrix`), projeto Compose, volumes e porta próprios (127.0.0.1:8881 por padrão; 8870 e 8880 são recusadas). Os ambientes de desenvolvimento (8870) e de testes (8880, `wp-env-stealthaudit-tests-*`) não são tocados. Passos: subir → registrar versões → `composer test:unit` → `composer test:integration` (sem o timeout de 300 s do Composer) → e2e opcional (`STEALTHAUDIT_E2E_ENV`/`STEALTHAUDIT_E2E_HTTP_PORT` apontam o roteiro para o ambiente da matriz) → `wp-env destroy --force`. Logs, versões e `results.json` ficam em `artifacts/matrix/<slug>/` (ignorado). O código de saída é diferente de zero se qualquer suíte falhar; o ambiente é destruído mesmo assim (`--keep` mantém para depuração).

Imagens somente com tag exata, nunca `latest`. O wrapper troca as bases flutuantes geradas pelo wp-env 12.1.0 (`wordpress:php8.x`, `wordpress:cli-php8.x`) por `wordpress:7.1.4-php8.x-apache` e `wordpress:cli-2.12.0-php8.x`, usa `mariadb:X.Y.Z` ou `mysql:X.Y.Z` (com healthcheck `mysqladmin ping` por TCP no MySQL, cujo servidor de inicialização escuta só no socket) e remove o serviço phpMyAdmin, que não tem tag fixa. `--prefix`/`--collate` passam `WORDPRESS_TABLE_PREFIX`/`WORDPRESS_DB_COLLATE` ao site e à biblioteca de testes.

Critério de aprovação: código de saída zero **e** saída sem diagnósticos PHP. O PHPUnit agora converte deprecations em falha (`convertDeprecationsToExceptions`), a matriz reprova qualquer `Deprecated/Warning/Notice/Fatal` impresso fora do PHPUnit e, a partir da combinação 8.3/MySQL, também lê o log do Apache e reprova qualquer diagnóstico vindo de `mu-plugins/stealthaudit`.

Os testes usam `vendor/` do workspace (instalado pelo lock, `platform.php` 8.3.0), montado em todos os ambientes; a matriz não executa `composer install`.

### Execução interrompida

Ctrl+C, terminal fechado ou máquina reiniciada pulam o `finally` da matriz: contêineres, volumes e a config `.wp-env.matrix-<slug>.json` ficam para trás. Para limpar, repita as **mesmas** opções `--matrix` da execução (elas formam o nome do ambiente) com `destroy`, e só então apague a config gerada:

```text
node tools/wp-env.cjs --matrix=8.4,mysql:8.4.13 destroy --force
node tools/wp-env.cjs --matrix=8.4,mysql:8.4.13,prefix=wpx9_,collate=utf8mb4_0900_ai_ci destroy --force
node tools/wp-env.cjs --matrix=8.3,mariadb:10.11.19,port=8882,tag=bench destroy --force              # benchmark
node tools/wp-env.cjs --matrix=8.3,mariadb:10.11.19,port=8883,tag=artifact,source=none destroy --force # test:package
```

No PowerShell, `Remove-Item .wp-env.matrix-<slug>.json`; no Git Bash, `rm .wp-env.matrix-<slug>.json`. Confira com `docker ps -a --filter name=wp-env-stealthaudit-matrix` que nada sobrou. Esses comandos não tocam o ambiente 8880 (`wp-env-stealthaudit-tests-*`), que usa outro `WP_ENV_HOME`. A seção [Limpeza](#limpeza) diz o que o `destroy` apaga.

## Versões observadas

| Item | Valor |
| --- | --- |
| PHP 8.3 (ambiente de testes original) | 8.3.36. O ambiente 8880 (existente, não recriado) usa as tags **flutuantes** geradas pelo wp-env 12.1.0: `wordpress:php8.3` / `wordpress:cli-php8.3` e `mariadb:10.11`, que nesta execução resolveram para PHP 8.3.36 e MariaDB 10.11.19 (a imagem `mariadb:10.11` local tem o mesmo ID `d769e3796082` que `mariadb:10.11.19`). Recriar esse ambiente pode trazer outras versões. |
| PHP 8.3 (matriz) | 8.3.36 (CLI e Apache); `wordpress:7.1.4-php8.3-apache` sha256:9001ea7dafd2…, `wordpress:cli-2.12.0-php8.3` sha256:a4fff017eb08… |
| PHP 8.4 | 8.4.27 (CLI e Apache); `wordpress:7.1.4-php8.4-apache` sha256:042ae671b7a5…, `wordpress:cli-2.12.0-php8.4` sha256:37b7e3ed9b53… |
| PHP 8.5 | 8.5.12 (CLI e Apache); `wordpress:7.1.4-php8.5-apache` sha256:63f89c89eeba…, `wordpress:cli-2.12.0-php8.5` sha256:d16fa67b450f… |
| MariaDB | `mariadbd Ver 10.11.19-MariaDB-ubu2204`; `mariadb:10.11.19` sha256:d769e3796082…; banco utf8mb4/utf8mb4_general_ci; conexão WordPress utf8mb4_unicode_520_ci |
| MySQL | `mysqld Ver 8.4.13 (MySQL Community Server - GPL)`; `mysql:8.4.13` sha256:a81d043930e5…; banco utf8mb4/utf8mb4_0900_ai_ci; conexão WordPress utf8mb4_unicode_520_ci; autenticação padrão caching_sha2_password via mysqlnd |
| WordPress / WP-CLI / Composer | 7.1.4 / 2.12.0 / 2.11.1 |
| PHPUnit / polyfills | 9.6.38 / yoast 4.0.0 (do lock); compatíveis com 8.3, 8.4 e 8.5 nesta execução |
| Extensões (8.4, igual em 8.3/8.5) | bcmath ctype curl date dom exif fileinfo filter gd hash iconv imagick intl json libxml mbstring mysqli mysqlnd openssl pcre PDO pdo_sqlite Phar posix random readline Reflection session SimpleXML sodium SPL sqlite3 standard tokenizer xml xmlreader xmlwriter OPcache zip zlib |

Os digests completos de cada execução ficam em `artifacts/matrix/<slug>/results.json` (por exemplo `wordpress:7.1.4-php8.3-apache` = `sha256:9001ea7dafd2bc3f05d068e6413b0edd2a116eeed4dfe9e08b6bd718dee4960a` e `wordpress:cli-2.12.0-php8.3` = `sha256:a4fff017eb08c03187e90fe52d4bff8d3a4d4a5103de05829a6e747c4ca3056e` em `php83-mysql8413`).

## Resultados

| Combinação | PHP observado | Unidade | Integração (inclui dois processos) | E2E (Chrome, TLS local) | Log do Apache | Resultado |
| --- | --- | --- | --- | --- | --- | --- |
| 8.3 × MariaDB 10.11.19 (ambiente original 8880) | 8.3.36 | 214 testes, 649 asserções, 1 skip | 83 testes, 2.931 asserções | 35/35 | não lido | **aprovado** |
| 8.3 × MySQL 8.4.13 | 8.3.36 (`wordpress:7.1.4-php8.3-apache`) | 214 / 649 / 1 skip | 83 / 2.931 | 35/35 | 0 diagnósticos PHP | **aprovado** |
| 8.4 × MariaDB 10.11.19 | 8.4.27 | 214 / 649 / 1 skip | 83 / 2.931 | 35/35 (segunda execução; a primeira parou na semeadura da fixture, ver harness) | 0 diagnósticos PHP | **aprovado** |
| 8.4 × MySQL 8.4.13 | 8.4.27 | 214 / 649 / 1 skip | 83 / 2.931 | 35/35 | não lido (antes da leitura do log) | **aprovado** |
| 8.5 × MariaDB 10.11.19 | 8.5.12 | 214 / 649 / 1 skip | 83 / 2.931 | 35/35 | não lido (antes da leitura do log) | **aprovado** |
| 8.5 × MySQL 8.4.13 | 8.5.12 | 214 / 649 / 1 skip | 83 / 2.931 | 35/35 | 0 diagnósticos PHP (capturado com `docker logs -f`, 495 linhas) | **aprovado** |
| alt: 8.4 × MySQL 8.4.13, prefixo `wpx9_`, `utf8mb4_0900_ai_ci` | 8.4.27 | 214 / 649 / 1 skip | 83 / 2.931 | não executado | 0 diagnósticos PHP | **aprovado** (sem e2e) |
| alt: 8.3 × MariaDB 10.11.19, prefixo `sa_alt_`, `utf8mb4_general_ci` | 8.3.36 | 214 / 649 / 1 skip | 83 / 2.931 | não executado | 0 diagnósticos PHP | **aprovado** (sem e2e) |

O skip é o teste de XMLWriter ausente, impossível quando a extensão é compilada estaticamente no `php -n` da imagem (explicado na fase 5). Os 35 passos e2e são os 33 da fase 6c mais GeoIP e manutenção das fases 7a/7b. Os dois processos reais (TOTP, recovery, CAS, lease, migração, limitador) rodaram em todas as linhas.

Uma tentativa com `utf8mb4_unicode_ci` no MariaDB (prefixo `sa_alt_`) também passou (214/649/1, 83/2.931), mas o `wpdb` do WordPress promove essa collation para `utf8mb4_unicode_520_ci` quando o servidor suporta, então ela não testou uma collation diferente; por isso a linha acima usa `utf8mb4_general_ci`.

Suporte declarado somente para as combinações aprovadas acima. MySQL 8.0 não foi testado: a série chegou ao fim de vida em abril de 2026 e a segunda família verificada é a LTS 8.4. MySQL 9.x, MariaDB 11.x, PHP < 8.3 e multisite continuam sem teste.

## Defeitos encontrados e corrigidos

1. **PHP 8.4+: o próprio normalizador de diagnósticos emitia `E_DEPRECATED`.** `Diagnostics\Normalizer` lia a constante `E_STRICT`, depreciada no PHP 8.4. Cada diagnóstico normalizado gerava um novo aviso dentro do caminho de coleta (o coletor registraria avisos sobre si mesmo). Agora o valor legado `2048` é uma constante privada; o mapeamento para `notice` continua. Regressão: `DiagnosticsNormalizerTest::test_normalizing_every_level_raises_no_diagnostic_of_its_own` (falha com o código anterior no PHP 8.4: quatro `Constant E_STRICT is deprecated`). Como o PHPUnit 9 engolia deprecations por padrão, as configs passaram a usar `convertDeprecationsToExceptions="true"`.
2. **MySQL: a instalação do schema rodava DDL por outra sessão.** `Schema::install()` chama `dbDelta()`, que sempre usa o `$wpdb` global, enquanto o lease, a verificação e a publicação da versão usam a conexão do `Storage`. Quando as duas diferem e a global está numa transação, o MySQL mantém metadata locks de `DESCRIBE`/`SHOW INDEX` até o fim dela, e um `DROP` posterior pela conexão do `Storage` esperou indefinidamente (`lock_wait_timeout` padrão de um ano; observado na integração em 8.4/MySQL, processo parado em "Waiting for table metadata lock"). Em produção as duas são o mesmo `$wpdb`, mas a instalação agora aponta o global para a conexão do schema durante o `dbDelta()` e o restaura em `finally`. Regressão: `StorageTest::test_schema_installs_through_its_own_connection_not_the_global_one` (nenhuma consulta pelo global durante `ensure()`; falha com o código anterior).

## Correções no harness de testes (não são defeitos do produto)

- **Tabela de options temporária em `AuditTest`.** O filtro do `WP_UnitTestCase` transforma `CREATE TABLE` em `CREATE TEMPORARY TABLE`; a cópia isolada de options era criada antes de removê-lo. O MySQL não reabre uma tabela temporária na mesma consulta (`Can't reopen table`), então o `INSERT … SELECT` de publicação do schema falhava (7 falhas em `AuditTest`); o MariaDB aceita. O filtro agora é removido antes da cópia.
- **Transação deixada pelo `WP_UnitTestCase`.** O `tear_down` do core faz `ROLLBACK` mas deixa `autocommit = 0` na conexão global. Os casos PHPUnit puros seguintes criavam usuários e tokens de sessão nessa transação, invisíveis aos processos workers reais; o teste de dois processos TOTP/recovery falhou de forma intermitente (ambos negados por `WordPressSession`). Antes da correção 2, o DDL do `dbDelta` pela conexão global fazia commit implícito e mascarava o problema. `stealthaudit_tests_commit_global_connection()` (bootstrap de integração) faz `COMMIT` e `SET autocommit = 1` no `setUp` dos casos puros.
- **E2E num site novo.** O roteiro pressupunha um site com ≥ 50 eventos e tabelas já instaladas (o ambiente de 8880 acumulou histórico). A fixture agora instala o schema antes do snapshot e completa até 120 eventos sintéticos (`login_failed`, IPs 198.18.0.x, duas horas atrás, `Storage` próprio com orçamento elevado) pelo repositório real; ficam acima do snapshot e o `restore` os apaga. `listing()` anexa um `catch` vazio à promessa para que uma etapa que falha antes de aguardá-la não derrube o processo antes do `finally` de restauração.
- `composer test:*` passou a rodar com `--timeout=0` (`npm run test:unit`/`test:integration` e a matriz): no MySQL a integração leva mais de 2,5 min e, com a máquina carregada, o ambiente de 8880 chegou a 289 s, perto do limite de 300 s do Composer.

## Infraestrutura (não é resultado do produto)

- WP-CLI 2.12.0 emite no PHP 8.5 `Deprecated: Using null as an array offset` em `php-cli-tools/lib/cli/Colors.php` (dentro do phar) antes de carregar o WordPress. Não vem do StealthAudit nem afeta o resultado; na imagem CLI da matriz `display_errors = stderr` mantém o aviso visível sem corromper o JSON que a fixture e2e imprime no stdout.
- Durante a primeira rodada o disco C: do host chegou a ~0,9 GB livres (cache de build das imagens da matriz) e o daemon Docker travou; foi preciso reiniciar o Docker Desktop. O ambiente de testes de 8880 foi religado com `docker start`, sem recriação. Na rodada final rodei `docker builder prune -f` (só cache sem referência) entre as combinações; isso fica fora da ferramenta, que não apaga cache do Docker por conta própria. Resultados daquela rodada foram descartados; todos os números acima são da rodada final.
- Uma rodada intermediária mostrou requisições de 3–5 s e timeouts de 30 s no e2e por carga concorrente na máquina (duas suítes ao mesmo tempo). Não reproduziu sem carga.

## Itens herdados

- **Limitador NAT / dois processos** (pendência da fase 5): `PanelAccessTest::test_shared_nat_origin_limits_every_account_behind_it_but_not_other_origins` (100 contas distintas atrás de uma origem esgotam a janela de origem; a 101ª e outra conta da mesma origem recebem `AttemptsExhausted`; as mesmas contas por outra origem ou origem desconhecida passam; a reserva de conta das tentativas negadas continua contada, sem reembolso). `test_two_real_processes_never_exceed_a_shared_origin_window`: dois processos PHP reais, 160 contas, mesma origem → exatamente 100 aceitas (50/50 observado), contador persistido igual ao total aceito (sem atualização perdida) e a próxima tentativa esgotada. `test_two_real_processes_never_exceed_one_account_window`: mesma conta, 2 × 8 tentativas por origens diferentes → no máximo 10, contadores iguais aos aceitos. (Fase 8b: o plano passou a 2 × 32 e os dois testes provam a sobreposição dos workers, ver abaixo.) Worker: `tests/fixtures/limiter-worker.php`.
- 7b: `GeolocationTest` inicializa `$dir = ''` e o `tearDown` retorna cedo se o `setUp` falhou; `tools/geoip-test-data.mjs` usa `AbortSignal.timeout(30 s)` por arquivo; [local-setup.md](local-setup.md) registra que a primeira execução precisa de rede.
- Prefixo/collation alternativos (pendência da fase 2): ver linhas "alt" da tabela.

## Suítes finais no ambiente original (8880)

Depois de todas as correções, no ambiente de testes original (PHP 8.3.36, MariaDB 10.11.19), sem carga concorrente: `npm run test:unit` 214 testes, 649 asserções, 1 skip; `npm run test:integration` 83 testes, 2.931 asserções (2 min); `npm run quality` sem erros (lint, WPCS/PHPCompatibilityWP, PHPStan nível 6); `npm run test:e2e` 35 aprovados, 0 falhas, restauração conferida. Saídas sem avisos PHP.

## Limpeza

Cada combinação terminou com `wp-env destroy --force`. No wp-env 12.1.0 isso roda `docker compose down --rmi all`, que apaga contêineres, volumes, rede, as imagens construídas **e também a imagem baixada do banco de cada combinação** (`mysql:8.4.13`, `mariadb:10.11.19`) quando nenhum outro contêiner a usa. Atenção: se outro projeto local usa a mesma tag exata e não está com um contêiner criado no momento, o teardown da matriz também remove essa imagem dele (ela é baixada de novo no próximo uso). A imagem `mariadb:10.11` do 8880 continuou presente porque o contêiner dele a usa.

As bases com tag exata (`wordpress:7.1.4-php8.x-apache`, `wordpress:cli-2.12.0-php8.x`) **não** ficam no cache local: o BuildKit as guarda só no cache de build, e o `docker builder prune -f` rodado entre as combinações (ver Infraestrutura) as removeu. Ao final não restou contêiner, volume, imagem ou config `.wp-env.matrix-*.json` da matriz; ficaram só as imagens do ambiente 8880. (Uma versão anterior desta seção dizia que as bases continuavam no cache; a revisão da fase 8a mostrou que não.)

# Fase 8b: desempenho, concorrência e injeção de falhas

Executado em 2026-11-08. Números, metodologia e limites em [performance.md](performance.md); aqui ficam os comandos, o que mudou nas suítes e a execução final.

## Comandos

```text
npm run benchmark          # ambiente adicional tag=bench na porta 8882, destruído ao final
npm run test:concurrency   # composer test:concurrency = phpunit --group concurrency (8880)
```

- `test:concurrency` (pendência M10 da revisão 8a) roda os testes com processos PHP reais — CAS e capacidade (`StorageTest`), lease e migração disputadas, pendente recente e `create()` travado (`MaintenanceTest`), limitador NAT/conta e vencedor único de TOTP/recovery (`PanelAccessTest`) — e todos os cenários de `FaultInjectionTest`. Eles continuam também em `test:integration`.
- `benchmark` reaproveita `tools/wp-env.cjs --matrix=…` com a nova opção `tag=<palavra>`, que só dá nome próprio ao ambiente (`php83-mariadb101119-bench`), sem compartilhar contêineres ou volumes com uma execução da matriz.

## Itens herdados da revisão 8a

- **M4** — os workers do limitador registram `hrtime` (CLOCK_MONOTONIC, comparável entre processos do mesmo host) da primeira e da última chamada, e os dois testes de dois processos afirmam que os intervalos se cruzam; o plano da conta passou de 2 × 8 para 2 × 32 tentativas para que a sobreposição seja demonstrável. Três execuções seguidas do grupo passaram. `unavailable` (três CAS perdidas, falha fechada) é permitido e somado no total de tentativas.
- **M3** — `tools/matrix.mjs` e `tests/e2e/run.mjs` escolhem o contêiner pelo nome exato do projeto Compose (`^<projeto>-[0-9a-f]{8}-<serviço>-1$`), não por prefixo.
- **M7** — a etapa e2e da matriz reprova diagnósticos PHP vindos de `/mu-plugins/stealthaudit` na saída do roteiro (a fixture roda no WP-CLI com `display_errors=stderr`), como já fazia com o log do Apache; o ruído do phar do WP-CLI só é relatado.
- **M9** — `AuditTest` não troca mais o `$wpdb` global em volta de `ensure()`; o próprio `Schema::install()` faz isso.
- M1, M2, M5, M6 e M8 (precisão da documentação da matriz) ficam para a fase 8d, como registrado no ledger.

## Observações sobre o ambiente de testes de 8880

- Durante o desenvolvimento do worker de falhas, duas execuções dele ainda carregavam o StealthAudit do próprio site, que gravou 8 linhas (18 ocorrências: avisos `mysqli_real_connect` das reconexões recusadas e avisos sintéticos da tempestade) na tabela de erros real do site. Foram apagadas e os contadores `budget:errors` restaurados (linhas 56 → 38, bytes 15.396 → 11.519); só o `AUTO_INCREMENT` e a revisão do orçamento avançaram. O worker agora define `STEALTHAUDIT_BOOT_STATUS` antes do `wp-load.php` e carrega o autoload por conta própria.
- Toda execução de `test:integration`/`test:concurrency` reinstala a biblioteca de testes do WordPress, e o StealthAudit do site registra esses eventos (`users/created`, `content/deleted`, …) na tabela de auditoria real, assim como o teste de fatal em processo filho (`diagnostics-worker.php`) grava um `fatal` na tabela de erros real. Isso já acontecia antes desta fase e é efeito das suítes padrão, não do benchmark.

## Suítes finais no ambiente original (8880), fase 8b

Depois do benchmark (ambiente adicional já destruído), sem carga concorrente, PHP 8.3.36 / MariaDB 10.11.19: `npm run test:unit` 214 testes, 649 asserções, 1 skip; `npm run test:integration` 90 testes, 3.017 asserções (83 anteriores + 7 de injeção de falhas); `npm run test:concurrency` 15 testes, 222 asserções; `npm run quality` sem erros (lint agora inclui `benchmarks/`, WPCS/PHPCompatibilityWP, PHPStan nível 6); `npm run test:e2e` 35 aprovados, 0 falhas, site restaurado. Saídas sem avisos PHP.

# Fase 8c: build prefixado, ZIP e instalação pelo artefato

Executado em 2026-11-08. Detalhes, conteúdo do pacote, checksums e resultados passo a passo em [package.md](package.md); aqui ficam os comandos e as mudanças nas suítes.

## Comandos

```text
npm run package                 # tools/package.mjs → tools/package.php no CLI do 8880; saída em dist/
npm run verify-package          # tools/verify-package.php (o ZIP mais novo de dist/)
npm run test:package            # build, verificação, teste negativo, instalação limpa e fluxo pelo artefato
```

- `test:package` cria **um** ambiente adicional (`tools/wp-env.cjs --matrix=8.3,mariadb:10.11.19,port=8883,tag=artifact,source=none`) e o destrói ao final; guarda de disco de 8 GiB. A nova opção `source=none` não mapeia o repositório no WordPress, remove Composer e o PHPUnit global das duas imagens e monta o repositório só no CLI, em `/opt/stealthaudit-dev`, fora do webroot.
- `tests/e2e/run.mjs` aceita `STEALTHAUDIT_E2E_WORKDIR` (onde o repositório é visível no CLI) e passa `--path=/var/www/html` ao WP-CLI; o padrão (8880) não muda. A checagem "nenhum item de menu do StealthAudit" ignora o nó do título do site (o site limpo do wp-env se chama "StealthAudit").
- O CLI do operador passou de `tools/security-operator.php` para `bin/security-operator.php` (vai no pacote); `lint` cobre `bin/`.

## Resultados (fase 8c)

Primeira rodada (2026-11-08): ZIP `46ec2ffb…eab1e` (datas fixas de 1980, sem aviso de modificação no vendor), 24 passos do `test:package` aprovados antes de uma reinicialização externa do Docker; Plugin Check, varredura do log e destruição feitos depois, à mão. A revisão pediu mudanças (opcache após atualização e avisos Apache-2.0 §4(b)); o registro válido é o da rodada de correção abaixo.

## Rodada de correção 1 (revisão phase8c-review.md)

- **Datas das entradas do ZIP** = data do último commit das entradas do pacote (fontes embarcadas, manifestos Composer, ferramentas de build), passada por `tools/package.mjs` como `--source-date-epoch`; determinístico por commit e estável em commits só de testes/docs (observado: `dfba1e6`, só de teste, gerou o mesmo SHA que `fcaeda4`).
- **Opcache**: `installation.md` exige recarregar PHP-FPM/Apache (ou `opcache_reset()`) após atualização e rollback. O teste de atualização instala com cópia que preserva datas, mostrou que **sem o reload o Apache continuou executando A** (mesmo mtime em A e B; `Runtime::VERSION` = `0.1.0-dev` com os arquivos de B no disco) e exige o marcador de B em todas as sondagens após `apachectl -k graceful`. `Runtime::VERSION` é novo e checado contra o loader e o `package.json` (teste unitário, build e `verify-package`).
- **Apache-2.0 §4(b)**: aviso de modificação na linha 2 dos 160 PHP do vendor; cabeçalho das notices descreve as mudanças; nova seção do runtime do Composer (MIT); `verify-package` exige os três.
- `bin/security-operator.php` coberto por phpcs e PHPStan nível 6; o teste do artefato pegou uma regressão introduzida nessa reformatação (variável global com o mesmo nome que o loader MU apaga) e ela foi corrigida (`$stealthaudit_cli_*`).
- Varredura de padrões proibidos sobre o código empacotado registrada e revisada à mão em [package.md](package.md#padrões-proibidos-no-código-empacotado-item-7-da-fase-8) (grep não é auditoria).
- Pacote `dist/stealthaudit-0.1.0-dev.zip`, **SHA-256 `dc6dc89824b509fc62dbf66f060848cfbf1fd7f0d0793faa412cdd4656e862c0`** (222 arquivos, 471.618 bytes, commit `de6fb79`, árvore limpa); segundo build idêntico; `verify-package --against-source` OK; **16/16** cópias adulteradas rejeitadas.
- `npm run test:package` (ambiente novo, sem interrupção): **25/25** passos, e2e pelo artefato 35/35 e com outro plugin carregado antes 35/35, atualização A → B com o código de B executando após o reload, CLI empacotado (recusa, rotação, validação, reset), Plugin Check 2.1.0 com 4 ocorrências justificadas, log do Apache sem diagnósticos PHP, ambiente destruído.

## Suítes finais no ambiente original (8880), fase 8c

Depois do `test:package` (ambiente adicional destruído) e da reinicialização externa do Docker (contêineres do 8880 religados com `docker start`, sem reconfiguração), PHP 8.3.36 / MariaDB 10.11.19: `npm run test:unit` 214 testes, 649 asserções, 1 skip; `npm run test:integration` 90 testes, 3.024 asserções; `npm run test:concurrency` 15 testes, 229 asserções; `npm run quality` sem erros (lint agora inclui `bin/`); `npm run test:e2e` 35 aprovados, 0 falhas, site restaurado. Saídas sem avisos PHP.

Rodada de correção 1 (2026-11-09), depois do `test:package` de registro: `npm run test:unit` 215 testes, 651 asserções, 1 skip (novo teste de `Runtime::VERSION`); `npm run test:integration` 90 testes, 3.024 asserções; `npm run test:concurrency` 15 testes, 229 asserções; `npm run quality` sem erros (phpcs e PHPStan agora incluem `bin/`); `npm run test:e2e` 35 aprovados, 0 falhas, site restaurado. Saídas sem avisos PHP.
