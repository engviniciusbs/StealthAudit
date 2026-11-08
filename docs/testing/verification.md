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

## Versões observadas

| Item | Valor |
| --- | --- |
| PHP 8.3 (ambiente de testes original) | 8.3.36, imagem `wordpress:php8.3` do wp-env (ambiente existente, não recriado) |
| PHP 8.3 (matriz) | 8.3.x de `wordpress:7.1.4-php8.3-apache` / `wordpress:cli-2.12.0-php8.3` (ver tabela de resultados) |
| PHP 8.4 | 8.4.27 (CLI e Apache); `wordpress:7.1.4-php8.4-apache` sha256:042ae671b7a5…, `wordpress:cli-2.12.0-php8.4` sha256:37b7e3ed9b53… |
| PHP 8.5 | 8.5.12 (CLI e Apache); `wordpress:7.1.4-php8.5-apache` sha256:63f89c89eeba…, `wordpress:cli-2.12.0-php8.5` sha256:d16fa67b450f… |
| MariaDB | `mariadbd Ver 10.11.19-MariaDB-ubu2204`; `mariadb:10.11.19` sha256:d769e3796082…; banco utf8mb4/utf8mb4_general_ci; conexão WordPress utf8mb4_unicode_520_ci |
| MySQL | `mysqld Ver 8.4.13 (MySQL Community Server - GPL)`; `mysql:8.4.13` sha256:a81d043930e5…; banco utf8mb4/utf8mb4_0900_ai_ci; conexão WordPress utf8mb4_unicode_520_ci; autenticação padrão caching_sha2_password via mysqlnd |
| WordPress / WP-CLI / Composer | 7.1.4 / 2.12.0 / 2.11.1 |
| PHPUnit / polyfills | 9.6.38 / yoast 4.0.0 (do lock); compatíveis com 8.3, 8.4 e 8.5 nesta execução |
| Extensões (8.4, igual em 8.3/8.5) | bcmath ctype curl date dom exif fileinfo filter gd hash iconv imagick intl json libxml mbstring mysqli mysqlnd openssl pcre PDO pdo_sqlite Phar posix random readline Reflection session SimpleXML sodium SPL sqlite3 standard tokenizer xml xmlreader xmlwriter OPcache zip zlib |

Os digests completos de cada execução ficam em `artifacts/matrix/<slug>/results.json`.

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

- **Limitador NAT / dois processos** (pendência da fase 5): `PanelAccessTest::test_shared_nat_origin_limits_every_account_behind_it_but_not_other_origins` (100 contas distintas atrás de uma origem esgotam a janela de origem; a 101ª e outra conta da mesma origem recebem `AttemptsExhausted`; as mesmas contas por outra origem ou origem desconhecida passam; a reserva de conta das tentativas negadas continua contada, sem reembolso). `test_two_real_processes_never_exceed_a_shared_origin_window`: dois processos PHP reais, 160 contas, mesma origem → exatamente 100 aceitas (50/50 observado), contador persistido igual ao total aceito (sem atualização perdida) e a próxima tentativa esgotada. `test_two_real_processes_never_exceed_one_account_window`: mesma conta, 2 × 8 tentativas por origens diferentes → no máximo 10, contadores iguais aos aceitos. Worker: `tests/fixtures/limiter-worker.php`.
- 7b: `GeolocationTest` inicializa `$dir = ''` e o `tearDown` retorna cedo se o `setUp` falhou; `tools/geoip-test-data.mjs` usa `AbortSignal.timeout(30 s)` por arquivo; [local-setup.md](local-setup.md) registra que a primeira execução precisa de rede.
- Prefixo/collation alternativos (pendência da fase 2): ver linhas "alt" da tabela.

## Suítes finais no ambiente original (8880)

Depois de todas as correções, no ambiente de testes original (PHP 8.3.36, MariaDB 10.11.19), sem carga concorrente: `npm run test:unit` 214 testes, 649 asserções, 1 skip; `npm run test:integration` 83 testes, 2.931 asserções (2 min); `npm run quality` sem erros (lint, WPCS/PHPCompatibilityWP, PHPStan nível 6); `npm run test:e2e` 35 aprovados, 0 falhas, restauração conferida. Saídas sem avisos PHP.

## Limpeza

Cada combinação terminou com `wp-env destroy --force` (contêineres, volumes, rede e imagens construídas). Ao final não restou contêiner, volume ou config `.wp-env.matrix-*.json`; as imagens base com tag exata baixadas (wordpress 7.1.4-php8.x, cli-2.12.0-php8.x, mysql:8.4.13, mariadb:10.11.19) continuam no cache local do Docker.
