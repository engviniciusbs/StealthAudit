# GeoIP local na leitura e runbooks (fase 7b)

Verificado em 2026-11-08 no ambiente de testes da [fase 1](local-setup.md): WordPress 7.1.4, PHP 8.3.36 (leitor MaxMind em PHP puro; a extensão C `maxminddb` não está instalada), MariaDB 10.11, PHPUnit 9.6.38, Chrome 157. Só bases de **teste** oficiais da MaxMind; nenhuma base GeoLite/GeoIP real e nenhuma chave de licença foram usadas. Com isto a fase 7 está completa (7a: [manutenção](phase-7-maintenance.md)). Runbooks do operador: [docs/operations](../operations/installation.md).

## O que foi implementado

- `Config\Configuration`: novo `mmdb_max_age_days` (inteiro 1–3650, padrão 30). `mmdb_path` continua opcional, absoluto e sem `\0`.
- `Geo\Geolocation::resolve( $ips )` (classe final, sem dependência de armazenamento, rede ou WordPress):
  1. filtra os IPs da página: só strings válidas por `FILTER_VALIDATE_IP`, sem repetição, no máximo 100 (`MAX_ADDRESSES`); nulos, malformados e repetidos não consomem a cota;
  2. `realpath` do caminho; ausente/ilegível → `missing`; dentro do webroot (`ABSPATH`) → `misplaced`, sem abrir;
  3. abre `GeoIp2\Database\Reader` **uma vez** (locales `pt-BR`, `en`), lê a metadata como dados não confiáveis (`databaseType` string, `buildEpoch` inteiro, build no máximo 1 dia no futuro; senão `invalid`);
  4. tipo com `City` → `city()`, com `Country` → `country()`, outro → `unsupported`;
  5. idade `agora − build > mmdb_max_age_days` → `outdated`, sem consultar;
  6. por IP: `AddressNotFoundException` ou `InvalidArgumentException` (ex.: IPv6 numa base só IPv4) = sem resultado; qualquer outra falha (ex.: `InvalidDatabaseException` no meio da consulta) → `invalid` sem nenhum resultado parcial; fecha o leitor no `finally`. Nunca lança para quem chama.
  Campos: `country_code`, `country`, `region` (subdivisão mais específica, só City), `city`; podem ser nulos. Registro todo nulo conta como sem resultado.
- `Http\Api` recebe o resolvedor (opcional; `Runtime` só o cria com `mmdb_path`). Só em `/events`: cada item ganha `location` (`status` `estimate` + campos, ou `unavailable` com campos nulos) e a resposta ganha `geolocation` (`state`, `basis: "current_database"`, `database_built` ISO UTC ou nulo). Exceção inesperada do resolvedor → `invalid`, consulta 200. Sem `mmdb_path` a resposta é idêntica à da fase 7a; `/errors` e `/status` não mudam.
- UI: com `mmdb_path` e na visão de auditoria, a tabela ganha a coluna "Local estimado (base atual)" logo após o IP (`data-geoip` no root, `th[data-sa-geoip]`). Célula "estimativa: Londres, Inglaterra, Reino Unido (GB)" ou "indisponível" (atenuada). Abaixo da manutenção, "Localização: estimativa pela base GeoIP local atual, não o local do IP no momento do evento; base compilada em …" ou o motivo do "indisponível". Textos traduzíveis em pt-BR; tudo por `textContent`.
- Nada de localização é gravado: nem tabelas, nem options, nem cache, nem cópias históricas. O GeoIP não está no caminho de gravação (Recorder/Collectors não mudaram).

Decisões registradas (escolha mais simples consistente):

- Abrir a base em toda consulta de eventos configurada, mesmo com página vazia, para o estado (ex.: `outdated`) aparecer ao operador; nenhum cache entre consultas, então uma substituição atômica vale na consulta seguinte.
- Base dentro do webroot é recusada (`misplaced`), como a configuração externa.
- `outdated` usa só o `build_epoch` da metadata; não se olha `mtime` do arquivo.
- O painel não exibe atribuição de dados automaticamente; o runbook explica a obrigação do operador.
- Bases de teste **baixadas** no teste, não versionadas (a brief prefere isso e `*.mmdb` segue no `.gitignore`); por isso `THIRD-PARTY-NOTICES.md` (gerado do `composer.lock`, só do que é distribuído) não muda.

## Bases de teste

`tools/geoip-test-data.mjs` baixa de `maxmind/MaxMind-DB` no commit fixo `d12e37856df12a62bb4647da66d20feddb8783a6` para `artifacts/geoip/` (gitignored), confere SHA-256 e só grava se bater; arquivos já corretos não geram requisição. Os scripts `npm run test:unit`, `test:integration` e `test:e2e` o executam antes (no host, não no contêiner). Licença: o repositório declara Copyright MaxMind, Inc., Apache-2.0 **ou** MIT, à escolha; o `LICENSE-MIT` é baixado junto.

| Arquivo | Uso |
| --- | --- |
| `GeoIP2-City-Test.mmdb` (18.035 B, build 2026-10-22T18:52:57Z) | City válida, IPv4/IPv6 |
| `GeoIP2-Country-Test.mmdb` (17.300 B) | só país |
| `GeoIP2-Anonymous-IP-Test.mmdb` (4.488 B) | tipo errado |
| `GeoIP2-City-Test-Broken-Double-Format.mmdb` (22.478 B) | metadata válida, dados corrompidos |
| `LICENSE-MIT` | licença |

Os SHA-256 completos estão em `tools/geoip-test-data.mjs`. Base aleatória, truncada pela metade e vazia são geradas no próprio teste. Os testes copiam as bases para um diretório temporário fora do repositório; nos testes de unidade o repositório faz o papel de webroot.

## Testes

`tests/unit/GeolocationTest.php` (10 testes):

- City: `81.2.69.160` → GB / Reino Unido / Inglaterra / Londres; IPv6 `2001:218::` → JP / Japão com região e cidade nulas; `::81.2.69.160` → Londres; `216.160.83.56` → Milton, Washington, EUA; `built` igual à metadata; uma abertura.
- Country: `89.160.20.112` → Suécia sem região/cidade; IPv6 `2a02:d300::` → UA.
- Sem resultado: `127.0.0.1`, `2001:db8::1`, `10.1.2.3` ausentes, `null`/`not-an-ip` ignorados, estado `available`.
- Ausente → `missing` sem abrir. Aleatória, truncada e vazia → `invalid`. Dados corrompidos → `invalid` e nenhum resultado parcial; a mesma base com só um IP sem resultado continua `available` (sem resultado ≠ corrupção).
- Idade: exatamente 30 dias → `available`; 30 dias + 1 s → `outdated` com `built`; build 2 dias no futuro → `invalid`.
- Anonymous-IP → `unsupported`. Base sob o webroot → `misplaced` sem abrir.
- Abertura e cota: 99 IPs privados únicos (cada um repetido, com nulos entre eles), Londres 50 vezes, um malformado, depois Linköping (101.º único) e Milton: só Londres resolvido, uma abertura; nova consulta abre de novo (2).
- Sem rede nem armazenamento: o código da classe não contém `wp_remote`, `curl_`, `fsockopen`, `stream_socket`, `file_get_contents`, `WebService`, `http`, `$wpdb`, `update_option`, `set_transient`, `wp_cache_set` ou `Storage`.

`tests/unit/ConfigurationTest.php`: padrão 30, valor explícito, recusa de 0, 3651 e string.

`tests/integration/HttpTest.php::test_location_is_estimated_at_read_time_and_never_stored` (REST real, InnoDB isolado):

- sem MMDB, `/events` não tem `geolocation` nem `location`;
- com a City de teste: `geolocation` `available`/`current_database`/build ISO; Londres e Japão como `estimate`; `127.0.0.1` e IP nulo `unavailable` com campos nulos; IP preservado; `/errors` sem campos de localização;
- ausente, corrompida e desatualizada (política de 1 dia, relógio +400 dias): resposta 200, estado `missing`/`invalid`/`outdated`, todos os itens `unavailable`, IP exibido;
- nenhuma linha das tabelas de eventos, erros e estado, das options isoladas nem das options do site contém "Londres", "Reino Unido", "Japão" (também escapado em JSON) ou `current_database`;
- zero chamadas a `pre_http_request` durante tudo isso;
- página: sem MMDB, nada de `data-geoip`/"Local estimado"; com MMDB, `data-geoip`, o cabeçalho "Local estimado (base atual)", o texto "não o local do IP no momento do evento" e `"geo_none":"indisponível"` no JSON de tradução; a visão de erros sem a coluna.

`tests/e2e/run.mjs`, nova checagem "GeoIP" (35 no total): sem MMDB não há coluna, linha "Localização" nem campos na API; `fixture.php geoip on` copia a City de teste para `$HOME/stealthaudit-e2e-geoip/` (volume compartilhado pelos contêineres web e CLI, fora do webroot) e grava dois eventos `login_failed` com `81.2.69.160` e `2001:218::`; `config { mmdb }` liga o GeoIP (`mmdb_max_age_days` 3650 no bloco temporário, para a base de teste não envelhecer). No Chrome: cabeçalho "Local estimado (base atual)", linha `available` com "não o local do IP no momento do evento" e "base compilada em …", células "estimativa: Londres, Inglaterra, Reino Unido (GB)", "estimativa: Japão (JP)" e "indisponível" (atenuada) para um IP sem resultado; a API com `basis: current_database`. No `finally` a configuração volta sem MMDB e a cópia é apagada; a página seguinte não tem a coluna. O `restore` apaga a cópia se sobrar e confere que o diretório não existe.

## Suítes

Execução final em 2026-11-08, todas do zero, saída sem avisos:

- `npm run test:e2e`: `35 passed, 0 failed`, cerca de 7 minutos (403 s somando as checagens). Checagem 14 (GeoIP): cabeçalho "Local estimado (base atual)", "estimativa: Londres, Inglaterra, Reino Unido (GB)", "estimativa: Japão (JP)", `192.0.2.1 → indisponível`, sem coluna depois de remover a base. Checagem 33: 286 respostas privadas, nenhuma armazenável. Checagem 35: zero problemas de CSP/script. O `restore` conferiu `wp-config.php`, `.htaccess`, options, usuários, estado, registros, o link `/blog` e a ausência da cópia GeoIP.
- `npm run test:unit`: 213 testes, 648 asserções, 1 skip (XMLWriter, fase 5).
- `npm run test:integration`: 79 testes, 2.893 asserções.
- `npm run quality`: lint, WPCS/PHPCompatibilityWP e PHPStan nível 6 sem erros (o aviso de versão raiz do Composer é do ambiente).

Configuração temporária do site: só o bloco `STEALTHAUDIT_CONFIG` do e2e (restaurado byte a byte) e a cópia em `$HOME/stealthaudit-e2e-geoip/` (apagada). A suíte de integração continua gravando eventos de criação de usuários nas tabelas do próprio site de testes, como nas fases anteriores.

## Medidas

Não medidas. Não havia base GeoLite/GeoIP real neste ambiente e a brief proíbe baixar uma com chave; as bases de teste têm 4–22 KB e não representam o custo de uma base real (dezenas de MB). Antes de publicar números, medir tamanho do arquivo, tempo de abertura + até 100 consultas e pico de memória com a base real do operador, com e sem a extensão `maxminddb`.

## Limitações

- Estimativa pela base atual, não localização histórica; IPs de proxy, VPN, operadora móvel ou privados dão resultado errado ou nenhum. O IP só é confiável conforme `trusted_proxies` (fase 3).
- Testado só com bases sintéticas da MaxMind; outros fornecedores MMDB com tipos diferentes de `City`/`Country` aparecem como não suportados.
- Uma cópia sobre o arquivo em uso (em vez de `rename`) mostra "corrompida" enquanto durar; o runbook pede substituição atômica.
- Termos de licença são do operador; o plugin não verifica conta, licença ou atribuição.
