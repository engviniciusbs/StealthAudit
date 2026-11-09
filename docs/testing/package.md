# Pacote de distribuição: build prefixado, ZIP e instalação pelo artefato (fase 8c)

Executado em 2026-11-08 no Windows 11 (Docker Desktop/WSL2, Node 22.17.0, Chrome local via `playwright-core` 1.64.1). Cobre os itens 5–8 da fase 8: build limpo a partir dos lockfiles com dependências prefixadas, ZIP com checksum e lista de conteúdo, verificações Composer/licenças/Plugin Check e instalação do ZIP num WordPress limpo, com o fluxo do operador executado **pelo artefato**. Nenhum release, tag ou deploy foi feito.

## Comandos

```text
npm run env:test:start                 # ambiente de testes 8880: o CLI dele é o host de build (PHP 8.3 + Composer)
npm run package                        # dist/stealthaudit-<versão>.zip + .zip.sha256 + .contents.txt + .build.json
npm run verify-package                 # o ZIP mais novo de dist/
npm run verify-package -- dist/stealthaudit-0.1.0-dev.zip --against-source
npm run test:package                   # build + verificação + instalação limpa + fluxo completo (≈ 30 min)
```

Funcionam iguais em PowerShell e Git Bash (Node chama `git` e `docker`; nenhum comando de shell Unix no host). `npm run package` exige árvore rastreada sem alterações; `-- --allow-dirty` inclui alterações rastreadas ainda não commitadas e marca o build como `"dirty": true` (não é artefato de release). `-- --package-version X --out DIR` só serve para builds de teste (marcador de atualização). `dist/` e `build/` estão no `.gitignore`.

Conferência do checksum pelo operador: `sha256sum -c stealthaudit-0.1.0-dev.zip.sha256` (Linux) ou `Get-FileHash -Algorithm SHA256 .\stealthaudit-0.1.0-dev.zip` (PowerShell) comparado com o arquivo `.sha256`.

## Versões

| Item | Valor |
| --- | --- |
| Host de build | CLI do ambiente 8880: PHP 8.3.36, Composer 2.11.1 (`wordpress:cli-php8.3` do wp-env) |
| PHP-Scoper | 0.18.20 (publicado em 2026-10-08), PHAR oficial do release do GitHub, SHA-256 `5e35e4a89ea53b2c70a19bfa8a856e4f9080aa12eb13a5bce650bb69d08eb122` fixado em `tools/package.php`; assinatura `.asc` conferida uma vez com a chave `74A7 54C9 778A A03A A451 D1C1 A000 F927 D671 84EE` (Théo Fidry, keys.openpgp.org): "Good signature" |
| Site limpo | WordPress 7.1.4, `wordpress:7.1.4-php8.3-apache` (PHP 8.3.36, Apache 2.4.69), MariaDB 10.11.19, WP-CLI 2.12.0; 127.0.0.1:8883 |
| Plugin Check | 2.1.0 (wordpress.org), instalado só no site isolado e removido em seguida |
| Dependências de produção (lock) | bacon/bacon-qr-code v3.1.1, composer/ca-bundle 1.6.1, dasprid/enum 1.0.7, geoip2/geoip2 v3.4.0, maxmind-db/reader v1.14.0, maxmind/web-service-common v0.11.1, paragonie/constant_time_encoding v3.1.4, psr/clock 1.0.0, spomky-labs/otphp 11.5.0, symfony/deprecation-contracts v3.8.1 |

O PHP-Scoper não entra no `composer.json` nem no `vendor/` do workspace: o PHAR fica em `build/tools/` (ignorado), é baixado na primeira execução e recusado se o SHA-256 diferir.

## Como o build funciona

`tools/package.mjs` exporta o commit com `git archive` (respeitando `export-ignore`) e executa `tools/package.php` no CLI do 8880. Tudo acontece num diretório temporário do container, apagado ao final; o `vendor/` de desenvolvimento do workspace nunca é tocado.

1. **Coerência do arquivo-fonte.** O `git archive` precisa conter exatamente o que o ZIP leva (`loader/`, `bootstrap.php`, `src/`, `assets/`, `templates/`, `bin/security-operator.php`, `config/stealthaudit.example.php`, `LICENSE`, `THIRD-PARTY-NOTICES.md`), os insumos `composer.json`/`composer.lock` e o que é só do código-fonte (`README.md`, `docs/`). Qualquer outro arquivo faz o build falhar: o `.gitattributes` (`export-ignore` de `tests/`, `tools/`, `benchmarks/`, `artifacts/`, `.hallmark/`, `.superpowers/`, `package*.json`, `node_modules/`, configs de dev, `vendor/`, `dist/`, `build/`) e as exclusões do pacote não podem divergir.
2. **Composer na cópia temporária.** `validate --strict`; `install --no-dev --prefer-dist` a partir do lock; `audit --locked --no-dev` (falha com advisory ou pacote abandonado); `check-platform-reqs --lock --no-dev`; `licenses --no-dev` contra a allowlist MIT, BSD-2/3-Clause, Apache-2.0, ISC. `THIRD-PARTY-NOTICES.md` é regenerado do lock e dos arquivos de licença baixados e precisa ser idêntico ao commitado (`php tools/notices.php` regrava). Só a cópia temporária recebe `autoloader-suffix`, `platform-check: false` e `classmap-authoritative`.
3. **Poda do vendor.** De cada pacote ficam as raízes de autoload (`psr-4`, `files`), os arquivos de licença e o `res/` do composer/ca-bundle (lido pelo próprio código). Saem `ext/` (fonte C do maxminddb), `docs/`, exemplos, `composer.json`, READMEs, CHANGELOGs, configs de ferramentas.
4. **PHP-Scoper** (`tools/scoper.inc.php`) prefixa todo o vendor com `StealthAuditVendor\`, sem aliases globais (`expose-* = false`), inclusive o `installed.json` que o `dump-autoload` lê.
5. **Código do produto.** `src/` e `templates/` **não** passam pelo PHP-Scoper. No protótipo, o Scoper aplicado a `src/` prefixou `REST_REQUEST`, `DOING_AUTOSAVE`, `WP_CLI`, `LOGGED_IN_COOKIE`, `DONOTCACHEPAGE`, `STEALTHAUDIT_CONFIG(_FILE)`, `\WP`, `Core_Upgrader`, `Plugin_Upgrader` e `Theme_Upgrader` e reformatou todos os arquivos. Em vez disso, uma passada por tokens (`tools/package-lib.php`) reescreve apenas imports e nomes totalmente qualificados de namespaces de vendor (10 imports em `TotpProvider` e `Geolocation`) e **interrompe o build** diante de nome relativo, import em grupo, chamada não qualificada de função de vendor ou string com nome de classe de vendor. Fora dessas linhas, os arquivos do pacote são byte a byte os do repositório.
6. **Autoloader.** `composer dump-autoload --no-dev --classmap-authoritative` no pacote montado; depois `Composer\Autoload\ClassLoader` passa a `StealthAuditVendor\Composer\Autoload\ClassLoader` (outro plugin com outra versão do Composer não divide a classe), a entrada `InstalledVersions` sem arquivo é removida do classmap e os identificadores dos autoloads `files` viram `md5('StealthAuditVendor:' . md5(pacote:caminho))`, únicos do pacote. Sem `platform_check.php`: o do Composer escreveria texto na resposta pública e derrubaria o boot em hosts sem `curl`/`iconv`, contrariando o fechamento seletivo do painel.
7. `php -l` em cada PHP do pacote; ZIP com entradas ordenadas, data fixa (1980-02-01T00:00:00Z), modos 0644/0755, deflate; `.zip.sha256`, `.contents.txt` (SHA-256, tamanho e caminho de cada arquivo) e `.build.json` (commit, dirty, versões das ferramentas, hash do lock, resultados Composer).

### Defeitos encontrados durante a fase (corrigidos no build)

- **`MaxMind\Db\Reader` não prefixado.** O PHP-Scoper trata como interno todo símbolo que está nos stubs do PHP, incluindo extensões; a extensão opcional `maxminddb` declara `MaxMind\Db\Reader`, então `use MaxMind\Db\Reader as DbReader;` do geoip2 ficou sem prefixo. No primeiro pacote, o smoke no site limpo recebeu estado `invalid` da `Geolocation`, e o leitor prefixado lançou `Class "MaxMind\Db\Reader" not found` (no painel, a localização apareceria como indisponível); num host com a extensão ou com outro plugin, usaria silenciosamente outra implementação. Correção: um patcher reaplica a passada por tokens a todo o vendor já prefixado; o build e o `verify-package` passaram a varrer também o vendor, e o caso entrou no teste negativo.
- **`trigger_deprecation()` não qualificado.** O Scoper deixa chamadas não qualificadas em código com namespace; o PHP as resolveria para a função **global** (de outro plugin) ou falharia. Um patcher as qualifica como `\StealthAuditVendor\trigger_deprecation(`; o smoke força o caminho (OTPHP sem relógio) sem nenhum outro plugin.
- Identificador `files` compartilhado, `platform_check.php` e entrada `InstalledVersions` pendente: tratados como no passo 6.

## Conteúdo do pacote

`dist/stealthaudit-0.1.0-dev.zip` — **SHA-256 `46ec2ffba4a573eea1f31ba7c1425010235bf2098765aafc3ac4fcad546eab1e`**, 457.933 bytes, 222 arquivos (204 PHP), construído do commit `4c295ba` sem alterações locais (`"dirty": false`). Um segundo build do mesmo commit deu o mesmo SHA-256 (passo 3 do `test:package`). A lista completa com SHA-256 por arquivo está em `dist/stealthaudit-0.1.0-dev.contents.txt`; o manifesto (`.build.json`) registra commit, versões das ferramentas e o resultado de cada verificação Composer.

| Caminho no ZIP | Arquivos | Bytes |
| --- | --- | --- |
| `mu-plugins/stealthaudit.php` (loader, `Version: 0.1.0-dev`) | 1 | 1.847 |
| `mu-plugins/stealthaudit/bootstrap.php` | 1 | 268 |
| `…/src/` (byte a byte o repositório, exceto 10 linhas `use` prefixadas) | 39 | 227.261 |
| `…/templates/`, `…/assets/` | 1 + 4 | 33.839 + 39.682 |
| `…/bin/security-operator.php` | 1 | 4.068 |
| `…/config/stealthaudit.example.php` (chave e allowlist vazias) | 1 | 1.028 |
| `…/LICENSE` (GPL-3.0), `…/THIRD-PARTY-NOTICES.md` | 2 | 35.149 + 45.203 |
| `…/vendor/autoload.php` + `vendor/composer/` (autoloader prefixado + LICENSE do Composer) | 1 + 8 | 728 + 71.957 |
| `…/vendor/bacon/bacon-qr-code` | 63 | 216.273 |
| `…/vendor/composer/ca-bundle` (inclui `res/cacert.pem`, só certificados públicos) | 3 | 218.544 |
| `…/vendor/geoip2/geoip2`, `maxmind-db/reader`, `maxmind/web-service-common` | 34 + 6 + 13 | 96.708 + 46.703 + 36.431 |
| `…/vendor/spomky-labs/otphp`, `paragonie/constant_time_encoding`, `psr/clock` | 17 + 12 + 2 | 44.073 + 65.440 + 1.304 |
| `…/vendor/dasprid/enum`, `symfony/deprecation-contracts` | 11 + 2 | 22.590 + 2.140 |

Composer na cópia de build (registrado no manifesto): `validate --strict` válido; `audit --locked --no-dev` sem advisories nem pacotes abandonados; `check-platform-reqs --lock --no-dev` (host de build) `ext-curl`, `ext-iconv`, `ext-json`, `ext-openssl`, `ext-pcre`, `php` = success; `licenses --no-dev`: BSD-2-Clause (bacon, dasprid), MIT (composer/ca-bundle, paragonie, psr/clock, otphp, symfony), Apache-2.0 (geoip2, maxmind-db/reader, maxmind/web-service-common) — todas compatíveis com a distribuição sob GPL-3.0-or-later. `THIRD-PARTY-NOTICES.md` regenerado do lock é idêntico ao commitado. O mesmo `composer validate --strict` e `audit --locked` rodados no workspace (com dev) também passaram.

Fora do ZIP (verificado): `.env`, configuração real (`config/` só tem o exemplo, com chave e allowlist vazias), chaves e PEM (o único `.pem` é o bundle público de CAs do composer/ca-bundle, conferido como só certificados), códigos de recovery, MMDB, `tests/`, `tests/e2e`, fixtures, `node_modules/`, `package*.json`, `composer.json`/`composer.lock`, `installed.json`, `tools/`, `benchmarks/`, `artifacts/`, `docs/`, `.hallmark/`, `.superpowers/`, `vendor/bin`.

**Desvio:** não há `languages/`. O produto não tem traduções nem carrega textdomain (as strings já são pt-BR na origem); o verificador aceita o diretório se ele existir no futuro.

## Verificação do ZIP

`tools/verify-package.php`: SHA-256 e lista de conteúdo batem com o ZIP; nomes de entrada (só `mu-plugins/`, sem `..`, barra invertida, absolutos), modos (sem symlink nem executável); layout permitido; exclusões por caminho; conteúdo com cara de segredo (chave privada, URI `otpauth://` com segredo, chave de nuvem, caminho de home, senha sintética do e2e; no código do produto também valor base64 de 32 bytes, `key_base64` preenchido, allowlist preenchida e marcadores de fixture); todo namespace do vendor sob `StealthAuditVendor\` e nenhum símbolo global além de `ComposerAutoloaderInitStealthAudit`; nenhuma referência sem prefixo a namespace de vendor, em produto **ou** vendor; no produto nenhum símbolo prefixado que não seja de vendor (WordPress intacto); classmap só com `StealthAudit\`/`StealthAuditVendor\` e todos os alvos presentes; identificadores `files` isolados; runtime do Composer prefixado; `php -l`; versão do loader igual à do nome; GPL-3.0 em `LICENSE`; seção e arquivo de licença para cada pacote embarcado. `--against-source` compara os arquivos fora do vendor com a árvore de trabalho (após a mesma reescrita).

Resultado sobre o ZIP de release: `[verify] OK` com `--against-source` (222 arquivos, 204 PHP, 32 namespaces de vendor) e também para o build B. Teste negativo (`tests/package/verify-negative.php`, cada caso altera uma coisa e reescreve checksum e lista para que só a regra-alvo possa disparar): **13/13 cópias adulteradas rejeitadas** — diretório de testes, configuração real com chave, MMDB, chave privada, `node_modules`, `package.json`, import sem prefixo em `src/`, classe WordPress prefixada, namespace de vendor sem prefixo, import sem prefixo no vendor (o caso do `MaxMind\Db\Reader`), `ClassLoader` do Composer sem prefixo, arquivo de licença removido, erro de sintaxe PHP.

Isto não é auditoria de segurança: são regras objetivas sobre o artefato. Ocorrências encontradas foram revisadas à mão (por exemplo, a string `otpauth://%s/%s?%s` do OTPHP e `"%%Creator: BaconQrCode\n"` do backend EPS deixaram de ser falsos positivos com regras mais exatas, sem lista de exceções).

## Instalação limpa e fluxo pelo artefato

Ambiente extra criado por `tools/wp-env.cjs --matrix=8.3,mariadb:10.11.19,port=8883,tag=artifact,source=none`: o repositório **não** é mapeado no WordPress, Composer e o PHPUnit global são removidos das duas imagens, e o repositório é montado só no CLI em `/opt/stealthaudit-dev` (fora do webroot) para o harness (`tests/e2e/fixture.php`, `tests/package/*.php`, `artifacts/`). O smoke confirma que nenhum arquivo PHP do repositório além do próprio script é incluído no processo e que todas as classes vêm de `wp-content/mu-plugins/stealthaudit`. HTTPS pelo proxy TLS do e2e (`tests/e2e/proxy.mjs`, 127.0.0.1:8443 → 8883, `X-Forwarded-Proto` sobrescrito); permalinks bonitos servidos pelo `.htaccess` do Apache.

Execução de registro: `npm run test:package` a partir do commit `4c295ba`, árvore limpa, ambiente novo (log em `artifacts/package-e2e/run.log`, resultados em `results.json` e `e2e-*.log`). Os passos 1–24 terminaram antes de uma reinicialização do Docker Desktop (externa a esta fase); o passo de Plugin Check, a varredura do log do Apache e a destruição do ambiente foram executados depois, com os mesmos comandos, após `docker start` dos contêineres (ver Plugin Check).

| # | Passo | Resultado observado |
| --- | --- | --- |
| 1 | Guarda de disco; host de build | 24,1 GiB livres; CLI do 8880 |
| 2 | `npm run package` (A) | `dist/stealthaudit-0.1.0-dev.zip`, SHA-256 `46ec2ffb…eab1e`, 222 arquivos, `dirty: false` |
| 3 | Segundo build do mesmo commit | SHA-256 idêntico (build determinístico) |
| 4 | Build B com marcador de teste `0.1.0-dev+update` | SHA-256 `0d2dfc15…4686e` |
| 5 | `verify-package` A (`--against-source`) e B | OK / OK |
| 6 | Teste negativo | 13/13 cópias adulteradas rejeitadas |
| 7 | Fixture de convivência a partir do lock | montada no host de build, `composer audit` limpo |
| 8 | Site limpo 127.0.0.1:8883 | WordPress 7.1.4, PHP 8.3.36 (`mysqli json sodium iconv xmlwriter zip curl openssl mbstring`), MariaDB 10.11.19, WP-CLI 2.12.0; **Composer e Node ausentes** nos contêineres web e CLI; `mu-plugins` vazio antes da instalação |
| 9 | Instalação de A (núcleo, depois loader por rename) | 222 arquivos instalados idênticos à lista do ZIP (SHA-256 a SHA-256); home 200; `ready`, versão 0.1.0-dev, esquema 2 criado, cron agendado. O `wp core install` deixou permalinks `/%year%/%monthnum%/%day%/%postname%/` |
| 10 | Smoke sem outro plugin | classes do pacote; 9 classes/interfaces/função prefixadas do vendor do pacote; vetor RFC 6238 (`287082` em T=59) e código HOTP/SHA-1 independente aceitos, código fora da janela recusado; `StealthAuditVendor\trigger_deprecation()` alcançada pelo OTPHP; QR SVG sem referência externa; cifra XChaCha20-Poly1305 ida e volta, usuário/chave errados recusados; GeoIP `81.2.69.160` → GB/Londres, `2001:218::` → JP; nenhuma classe de biblioteca sem prefixo carregada; nenhum arquivo do repositório incluído |
| 11 | **E2E completo pelo artefato** (`tests/e2e/run.mjs`) | **35 aprovados, 0 falhas**: proxy TLS, redirecionamentos, REST anônimo, login real, sem menu no wp-admin, reconfirmação, QR decodificado de forma independente + TOTP, códigos de recuperação, paginação, filtros, filtros malformados, XSS, GeoIP, CSRF, página atacante, WP-Cron (retenção), revogar, expiração por inatividade, recuperação, troca de fator, segundo operador, janela de tentativas (429), outsider 403, backend indisponível 503, troca de tema, **permalinks bonitos servidos pelo Apache**, troca de endpoint, permalinks simples, subdiretório `/blog`, sessão WordPress revogada, cache compartilhado, movimento reduzido, sem violação de CSP; site restaurado |
| 12 | Fixture carregada **antes** | smoke completo OK com as duas cópias (ver Convivência) |
| 13 | Fixture carregada **depois** | smoke OK; relógio PSR sem prefixo recusado |
| 14 | **E2E completo de novo com a fixture carregada antes** | **35 aprovados, 0 falhas** |
| 15 | Configuração do operador | arquivo externo a partir do exemplo empacotado, modo 0640, fora do webroot, `STEALTHAUDIT_CONFIG_FILE` no `wp-config.php`, `WP_HOME`/`WP_SITEURL` = `https://localhost:8443`, permalinks `/%postname%/`, `.htaccess` com 3 `RewriteRule` |
| 16 | URL bonita por HTTPS | HTTP simples em `/stealthaudit/` → 403; login → senha → QR → TOTP → 10 códigos → auditoria; REST em `/wp-json/stealthaudit/v1/` |
| 17 | Ações e erros | login falho, post publicado, `E_USER_WARNING` |
| 18 | Consulta com filtros | `login_failed`+`failure`+`access`: 1; `content`: 1; erros `warning` com a mensagem: 1 |
| 19 | Expirar e revogar | prova envelhecida (>1.800 s) → 401 `challenge_required`; TOTP reautoriza; "Sair do StealthAudit" → 401; TOTP reautoriza |
| 20 | Retenção | `wp cron event run stealthaudit_maintenance` → estado `current`, `last_run` atualizado |
| 21 | **Atualizar A → B** por troca de diretório | 222 arquivos de B idênticos à lista; versão `0.1.0-dev+update`, `ready`; esquema igual; os 13 eventos anteriores mantidos; linha do fator idêntica (revisão e SHA-256); arquivo de configuração idêntico; a prova da sessão aberta continuou válida (REST 200 sem nova verificação); novo login falho registrado por B; manutenção roda em B |
| 22 | CLI empacotado com allowlist ativa | contêiner web (só PHP, sem Composer): saída 1, "Offline maintenance failed; panel must remain paused.", sem stdout; por HTTPS `…/bin/security-operator.php` → 200 com corpo vazio |
| 23 | Rotação de chave pelo CLI | allowlist vazia; `rotate 0` → `Processed 1; migrated=1; last_user=8; complete=yes.`; `validate 0` → `Processed 1; last_user=8; complete=yes.`; chave nova ativada: prova antiga 401, o **mesmo** segredo TOTP autoriza (recifrado) |
| 24 | Reset de fator pelo CLI | `Factor reset; WordPress sessions revoked.`; navegador volta ao wp-login; após login e senha, tela de **cadastro** (novo fator exigido) |

Execuções anteriores do mesmo roteiro: em `a96f985` o e2e falhou porque o título do site limpo ("StealthAudit") casava com a checagem "nenhum item de menu" (corrigido no harness em `4c295ba`); na primeira execução em `4c295ba`, o passo "Outsider" excedeu 30 s porque o POST do formulário de login nunca chegou ao Apache (o log mostra a página de login carregada e nenhum POST); na execução de registro, o mesmo commit passou nesse passo nas duas passagens (35/35 e 35/35). Tratado como intermitência do harness no navegador, não do produto, e registrado aqui.

## Convivência com outro plugin

Fixture `tests/package/conflict/` (lock commitado, `composer audit` limpo; montado no host de build e copiado ao site): um must-use que carrega o próprio autoloader Composer e, já no carregamento, todas as classes das mesmas bibliotecas **sem prefixo**, em outras versões. Instalado como `0-sa-conflict.php` (carrega antes de `stealthaudit.php`) e como `zz-sa-conflict.php` (depois).

| Biblioteca | StealthAudit (prefixada) | Fixture (sem prefixo) |
| --- | --- | --- |
| bacon/bacon-qr-code | v3.1.1 | 2.0.8 |
| geoip2/geoip2 | v3.4.0 | v2.13.0 |
| maxmind-db/reader | v1.14.0 | v1.11.1 |
| paragonie/constant_time_encoding | v3.1.4 | v2.7.0 |
| spomky-labs/otphp | 11.5.0 | 11.4.4 (11.2/11.3 recusados pelo bloqueio de advisories do Composer) |
| symfony/deprecation-contracts | v3.8.1 | v3.5.1 |
| psr/clock, dasprid/enum, composer/ca-bundle, maxmind/web-service-common | mesma versão | cópia separada |

Observado nas duas ordens: as 18 classes/interfaces sem prefixo vêm de `mu-plugins/sa-conflict/`, todas as do StealthAudit de `mu-plugins/stealthaudit/vendor`; no mesmo processo, QR (SVG), TOTP (vetor RFC 6238 `287082`) e GeoIP (`GB` para 81.2.69.160) funcionam com as bibliotecas da fixture e, depois, com as do pacote; `StealthAuditVendor\trigger_deprecation()` é carregada mesmo com a fixture tendo carregado antes o `function.php` do symfony/deprecation-contracts (o identificador `files` isolado impede que o Composer pule a cópia prefixada); um relógio que implementa o `Psr\Clock\ClockInterface` sem prefixo é recusado (`TypeError`). Com a fixture carregada **antes**, o e2e completo rodou de novo pelo painel (ver tabela do fluxo).

## Plugin Check (apoio)

Plugin Check 2.1.0 (`wp plugin check stealthaudit --format=json`, só checagens estáticas) sobre o pacote **A** extraído como plugin convencional **inativo** (`plugins/stealthaudit/stealthaudit.php` + `plugins/stealthaudit/stealthaudit/`), no site isolado, depois removido. Executado após a reinicialização do Docker, com os contêineres religados por `docker start`. Saída em `artifacts/package-e2e/plugin-check.txt`. 27 ocorrências, nenhuma alterada no produto:

| Ocorrência | Qtde | Justificativa |
| --- | --- | --- |
| `no_plugin_readme` (ERROR) | 1 | `readme.txt` é exigência do diretório wordpress.org; o produto é must-use distribuído por ZIP com docs próprias. |
| `missing_direct_file_access_protection` em `bin/security-operator.php` (ERROR) | 1 | É ferramenta de linha de comando que roda **antes** do WordPress (carrega `wp-load.php`), então não pode exigir `ABSPATH`; a proteção é `PHP_SAPI !== 'cli'` → `exit(1)` na primeira instrução. Observado por HTTPS: 200 com corpo vazio. O Plugin Check não apontou essa regra em nenhum outro arquivo do pacote. |
| `PrefixAllGlobals.NonPrefixedVariableFound` em `bin/` (WARNING) | 20 | Variáveis do escopo do script CLI, que não é carregado pelo WordPress em requisições. |
| `EscapeOutput.OutputNotEscaped` em `bin/` (ERROR) | 2 | Saída de terminal com inteiros (`$cursor`, `$migrated`), não HTML. |
| `AlternativeFunctions.file_system_operations_fwrite` em `bin/` (ERROR) | 1 | `fwrite( STDERR, … )` de CLI; `WP_Filesystem` não se aplica. |
| `PHPErrorReporting.DirectErrorReportingCall` em `src/Diagnostics/Collector.php` (WARNING) | 2 | Falso positivo: `error_reporting()` é só **lido** para respeitar `@` e a máscara do site; o nível nunca é alterado. |

Num teste anterior em que a cópia vinha do pacote B, o Plugin Check também apontou `plugin_header_invalid_plugin_version` (o `+` do marcador de teste); A não tem esse problema. Plugin Check é apoio, não certificação, e a distribuição convencional não é o alvo.

## Varredura do log e limpeza

Log do Apache do site limpo (1.195 linhas, todas as execuções do roteiro de registro): 0 diagnósticos PHP. Ao final, `wp-env destroy --force` do ambiente `artifact`: nenhum contêiner, volume ou config `.wp-env.matrix-*.json` restante; o ambiente 8880 não foi reconfigurado (só religado com `docker start` após a reinicialização externa do Docker).

## Limitações

- O host de build é o CLI do ambiente 8880 (PHP 8.3.36/Composer 2.11.1); outro host com outra versão do Composer/libzip pode gerar bytes diferentes. Reprodutibilidade comprovada só no mesmo host.
- `composer audit` consulta o Packagist: sem rede o build falha (infra), em vez de pular a auditoria.
- `check-platform-reqs` avalia o host de build; no site limpo as extensões foram listadas à parte (tabela acima). O pacote não tem verificação de plataforma do Composer de propósito.
- O CLI do operador fica no webroot (`mu-plugins/stealthaudit/bin/`); por HTTP ele termina antes de qualquer saída (status 200, corpo vazio, observado). Quem quiser pode bloquear `bin/` no servidor web.
- A PSR-20 aceita pelo pacote é a prefixada (`StealthAuditVendor\Psr\Clock\ClockInterface`); um relógio de outro plugin é recusado com `TypeError` (observado). Não há API pública que receba relógio.
- Pacotes com a mesma versão nos dois lados (psr/clock, dasprid/enum, composer/ca-bundle, maxmind/web-service-common) também ficam em cópias separadas; nada é deduplicado.
- Plugin Check rodou num site isolado com o pacote como plugin convencional **inativo**; checagens de runtime do Plugin Check não foram executadas.
- Escaneamento por câmera do QR, subdiretório real do WordPress e CDN de produção continuam pendentes para o usuário (ver phase-6-e2e.md); aqui o QR foi decodificado de forma independente pelo e2e.
- O "atualizar pacote" testado é a troca de diretório entre dois builds do mesmo esquema v2 (B difere de A só no marcador de versão do loader); não há afirmação de migração entre versões de esquema.
