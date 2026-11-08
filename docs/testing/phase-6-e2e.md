# Verificação ponta a ponta no navegador (fase 6c)

Esta etapa fecha a verificação da fase 6: o painel foi percorrido num Chrome real, atrás de TLS real, contra o WordPress do ambiente de testes, do login WordPress até a troca de fator. As camadas estão descritas em [phase-6-http.md](phase-6-http.md) (transporte/REST) e [phase-6-ui.md](phase-6-ui.md) (interface). Os números abaixo vêm da execução final de `npm run test:e2e` em 2026-11-08 (WordPress 7.1.4, PHP 8.3.36, MariaDB 10.11, Chrome 157, Windows 11).

## Como executar

```text
npm run env:test:start      # só se o ambiente de testes não estiver rodando; nunca um segundo wp-env
npm run test:e2e
```

Pré-requisitos de desenvolvimento: Docker com o ambiente de testes em 8880, Node 22, OpenSSL no PATH (o do Git for Windows serve) e Google Chrome instalado (ou `CHROME_PATH`). As dependências novas são só `devDependencies` com versão exata: `playwright-core` 1.64.1 (usa o Chrome instalado, não baixa navegadores), `jsqr` 1.4.0, `pngjs` 7.0.0 e `http-cache-semantics` 4.3.0. Nenhuma entra no pacote do plugin; `npm audit` continua com os mesmos sete avisos moderados da árvore de desenvolvimento já registrados na fase 1.

Arquivos em `tests/e2e/`:

- `fixture.php` (`wp eval-file` no contêiner `cli`): tira um snapshot de `wp-config.php`, `.htaccess`, todas as options, usuários, posts e das três tabelas do StealthAudit; cria três usuários sintéticos (`sa-e2e-operator-*` administrador e `sa-e2e-second-*` assinante, ambos na allowlist; `sa-e2e-outsider-*` fora dela) com senhas aleatórias, uma chave aleatória de 32 bytes, `key_id`/`installation_id` aleatórios e um bloco `STEALTHAUDIT_CONFIG` temporário no `wp-config.php`, que também troca `WP_HOME`/`WP_SITEURL` para `https://localhost:8443`. Ações auxiliares mudam a configuração (endpoint, chave ausente, subdiretório), permalinks, tema, envelhecem a prova e injetam payloads. `teardown` apaga usuários/posts; `restore`, em outro processo (o coletor grava as exclusões no shutdown), restaura arquivos, options e tabelas e confere tudo contra o snapshot, falhando se algo sobrar.
- `proxy.mjs`: terminador TLS só em 127.0.0.1:8443 com certificado autoassinado gerado na hora (OpenSSL, RSA 2048, SAN `localhost`/`127.0.0.1`, validade de um dia, arquivos temporários apagados logo após a leitura), e um cache HTTP compartilhado RFC 9111 (`http-cache-semantics`, `shared: true`, chave método+URL) que registra cada resposta, se era armazenável e se foi servida do cache. Inclui uma página "atacante" em `http://127.0.0.1:8444` (outro site).
- `otp.mjs`: decodificação do QR por `jsQR` sobre a captura PNG do elemento exibido e TOTP RFC 6238 com `node:crypto` (HMAC-SHA1, base32 próprio), sem código em comum com OTPHP/BaconQrCode. Valida os seis vetores SHA-1 do apêndice B da RFC antes de usar.
- `run.mjs`: o roteiro, com checagens numeradas; o `finally` sempre executa `teardown` + `restore`.

Saída em `artifacts/e2e/` (gitignored): `results.json`, `run.log`, capturas 1280px com QR e códigos de recuperação mascarados, e HTML saneado em `render/` (sem scripts, nonces trocados por `redacted`, QR substituído por um quadrado sem dados, códigos por `0000…0000`). O roteiro recusa gravar uma captura que contenha `otpauth` ou nonce. A senha sintética e a chave existem só em `artifacts/e2e/state.json` e no bloco temporário, ambos removidos no `restore`.

## Como o WordPress passou a enxergar HTTPS

O navegador fala TLS com o proxy em `https://localhost:8443`. O proxy descarta qualquer `X-Forwarded-*`/`Forwarded` do cliente, envia `X-Forwarded-Proto: https` e repassa o `Host` original para o Apache do wp-env (`127.0.0.1:8880`). O `wp-config.php` gerado pelo wp-env já contém o trecho padrão da imagem Docker: `X-Forwarded-Proto` contendo `https` ⇒ `$_SERVER['HTTPS'] = 'on'`. Assim `is_ssl()` fica verdadeiro e `home_url()` gera `https://localhost:8443`. O gate do StealthAudit não foi alterado nem relaxado: o mesmo Apache acessado direto em `http://localhost:8880` continua respondendo 403 "HTTPS obrigatório" (checagem 2). Limite: esse trecho do wp-env confia no cabeçalho vindo de qualquer cliente que alcance a porta 8880 (só loopback aqui); em produção ele só é seguro atrás de um proxy que sobrescreva o cabeçalho, como este faz.

## Resultado: 33 checagens, 33 aprovadas

Execução final: `33 passed, 0 failed`, cerca de 7 minutos (a maior parte esperando passos TOTP novos de 30 s, porque o servidor recusa replay e passos menores). O proxy registrou 546 requisições.

| # | Item do brief | Resultado observado |
| --- | --- | --- |
| 1 | TOTP independente | 6/6 vetores RFC 6238 SHA-1 corretos. |
| 2 | HTTPS real | HTTP direto 403 com `no-store`; HTTPS anônimo 302. |
| 3 | Redirect externo | Anônimo → `wp-login.php?redirect_to=https://localhost:8443/?stealthaudit=panel&view=errors&severity=warning&reauth=1`; com `redirect_to=https://evil.example/` o retorno é `?stealthaudit=panel&view=errors`, sem `evil`. |
| 4 | 401 sem dados | `/events`, `/errors`, `/status` 401 `stealthaudit_unauthenticated` sem `items`; POST `/reconfirm` 401; ativo 404 sem corpo; `_jsonp` 400; tudo `no-store`. |
| 5 | Login WP real | Formulário real do wp-login → volta para `/?stealthaudit=panel&view=audit`, tela "Confirme sua senha". |
| 6 | Nenhum menu wp-admin | Operador administrador: 45 links em `#adminmenu`, zero com "stealth" no menu e na admin bar; o plugin só aparece em Plugins › Must-Use (esperado para um MU). |
| 7 | REST sem 2º fator | `GET /events` com os dois nonces válidos: 401 `stealthaudit_proof_required`, `panel: password_required`, sem `items`. |
| 8 | Cadastro QR | Reconfirmação → "Gerar código QR" → resposta `no-store` → captura do elemento → `jsQR` lê `otpauth://totp/StealthAudit:operator-<ID>?issuer=StealthAudit&secret=…` (só `issuer` e `secret`; SHA1/6/30 são os padrões de qualquer Authenticator) → código calculado em Node, colado com espaço ("123 456") → confirmado. QR versão 5 (37×37 módulos, 5,7 px por módulo), zona de silêncio de 6,8 módulos (mínimo 4) e contraste 21:1 medido nos pixels. 10 códigos de recuperação de 32 hex. |
| 9 | Códigos recovery | Sem marcar a caixa, o botão mostra "Confirme que guardou…"; marcada, a auditoria carrega 50 linhas (padrão), com `next_cursor` e captura `available`. |
| 10 | Paginação | 25 linhas por página; páginas 1 e 2 disjuntas e em ordem (tempo, id) decrescente; cursor `<unix>-<id>` na URL; "Primeira página" aparece na página 2. |
| 11 | Filtros | Usuário (4 linhas, todas do operador), ação+resultado+categoria (`mfa_enrolled`/`success`/`access`, 1 linha), IP (4 linhas de `172.22.0.1`, o gateway Docker visto pelo Apache), período UTC da última hora (44 linhas, todas no intervalo, valor mantido no formulário) e período sem registros (estado vazio "Nenhum registro com estes filtros"). |
| 12 | Filtros malformados | Na página: `from=yesterday<b>&ip=999.1.1.1` → aviso `role="alert"`, lista sem filtros, nada ecoado no HTML. No REST (autorizado): data, `limit=1000`, IP, cursor, parâmetro desconhecido, usuário negativo e `from > to` → 400 sem `items`. |
| 13 | XSS em log | Diagnóstico gravado pelo `ErrorRepository` real e uma linha de evento adulterada direto no banco com `<img src=x onerror=…><script>…</script>`: a célula mostra o texto literal, zero `img`/`script` no `tbody`, título intacto, nenhum diálogo; o JSON bruto traz `<img`. |
| 14 | CSRF | Com prova válida: sem `X-StealthAudit-Nonce` 403, nonce forjado 403, sem `X-WP-Nonce` 401 (o WordPress descarta o cookie), revoke sem/forjado 403, `Origin: https://evil.example` 403 (GET e POST), `Sec-Fetch-Site: cross-site` 403, POST `text/plain` 400; estado continuou `authorized`. |
| 15 | Página de outro site | `http://127.0.0.1:8444` com `fetch` (credentials), `fetch` no-cors POST, `<img>` e formulário POST para `revoke`: quatro requisições, todas 401 (o Chrome não mandou cookie nos subrecursos; no POST de navegação mandou, mas sem nonce o WordPress tratou como anônimo). Estado continuou `authorized`. |
| 16 | Revogar → desafio | "Sair do StealthAudit" → tela de desafio, `/events` 401; TOTP novo → registros de volta; sessão WordPress mantida. |
| 17 | Expiração (relógio controlado) | A consulta da página foi segurada pelo Playwright enquanto `fixture.php age-proof` recuava `last_activity` da prova em 1.801 s (via CAS do `StateRepository`); ao liberar, o servidor respondeu `proof_required` e a tela virou "A verificação expirou", sem linhas, tabela e abas ocultas, foco no título. |
| 18 | Expiração com recarga | `?view=errors&severity=warning` → desafio com "Os filtros do endereço serão aplicados depois da verificação". |
| 19 | Recovery → novo cadastro | Senha + primeiro código de recuperação (digitado em maiúsculas com hífens) → QR novo com segredo diferente → TOTP → 10 códigos novos, nenhum igual aos anteriores → painel volta em Erros com `severity=warning` aplicado. |
| 20 | Troca de fator | Senha + TOTP atual → terceiro segredo, diferente dos dois anteriores → confirmação → autorizado. |
| 21 | QR perdido | Segundo operador (papel assinante): senha errada → "Não foi possível confirmar…" e campo limpo; QR gerado; recarga → tela pendente sem QR e com o formulário "Exibir o código QR"; após a senha, o QR decodificado tem o mesmo segredo pendente; confirmado. |
| 22 | Janela de tentativas | Após 7 mutações válidas, três TOTP errados → 403, 403, 403; o quarto → 429 `stealthaudit_try_later`, sem `Retry-After` e com `data` só `status`; a página diz "Muitas tentativas…" sem números. |
| 23 | 403 fora da allowlist | Usuário logado fora da allowlist: página 403 "Acesso negado" sem `data-sa-root`; REST 401 sem `X-WP-Nonce` e 403 `stealthaudit_forbidden` com um nonce `wp_rest` válido obtido de `admin-ajax.php?action=rest-nonce`; ativo 404. |
| 24 | 503 | Chave removida da configuração: página 503 "Painel indisponível" com CSS (operador identificado) e `no-store`; `/status` e `/events` 503 `stealthaudit_unavailable` sem dados. |
| 25 | Tema trocado | Tema ativo do site de testes (`default`, inexistente), Twenty Twenty-Five e Twenty Twenty-Three: HTML do painel idêntico (20.602 bytes, nonces normalizados), sem nada de `/wp-content/themes/`. |
| 26 | Permalinks bonitos reais | `/%postname%/` com o `.htaccess` do Apache e uma página publicada com slug `stealthaudit`: `https://localhost:8443/stealthaudit/` serve o painel (regra `^stealthaudit/?$` no topo), abas em `/stealthaudit/?view=…`, REST em `/wp-json/stealthaudit/v1/`. |
| 27 | Endpoint alterado | Configuração com `/painel-ops`: a primeira requisição adquiriu uma lease e regravou as regras (`^painel\-ops/?$`, 1 linha `lease:`); `/painel-ops/` serve o painel e nenhum `href`/`action`/`data-rest` contém `/stealthaudit/`; `/stealthaudit/` deixou de ser o painel. |
| 28 | Permalinks simples | `/?stealthaudit=panel` 200; `/stealthaudit/` não é o painel. |
| 29 | Subdiretório | Ver abaixo: `/blog/?stealthaudit=panel` e `/blog/stealthaudit/` → `challenge_required`, REST em `/blog/wp-json/stealthaudit/v1/`, quatro ativos sob `/blog/`. |
| 30 | Sessão WP revogada | Com o painel aberto, `wp user session destroy --all` antes da consulta → tela "Sessão do WordPress encerrada" com link para o wp-login com retorno local (bug corrigido nesta etapa, ver abaixo). |
| 31 | Cache na frente | 258 respostas do painel/REST/ativos/QR/códigos (200, 302, 400, 401, 403, 404, 429, 500, 503): nenhuma armazenável, nenhum HIT. Controle `/readme.html`: MISS e depois HIT, provando que o cache funciona. |
| 32 | Movimento reduzido | `transition-duration` de `.btn`/abas: 0,12 s normal; 0,01 ms com `prefers-reduced-motion: reduce`. |
| 33 | Console | Nenhuma violação de CSP, erro de script ou diálogo nas páginas do painel. |

As linhas `mfa_*` gravadas na auditoria não contêm `otpauth` nem as senhas sintéticas (`secret_in_event = 0` antes do teardown).

### Subdiretório

O wp-env não instala WordPress em subdiretório. A fixture criou o link simbólico `/var/www/html/blog → .` e definiu `WP_HOME`/`WP_SITEURL` como `https://localhost:8443/blog`. O Apache passa a servir os arquivos reais sob `/blog/`, o WordPress calcula `home_url()`, cookies (`COOKIEPATH=/blog/`) e REST com `/blog`, e o login acontece em `/blog/wp-login.php`. Com permalinks simples e bonitos o painel, os ativos e o REST funcionaram sob `/blog/`. Diferença de uma instalação real: o `.htaccess` é o mesmo da raiz (`RewriteBase /`), que reescreve para o `index.php` raiz, o mesmo WordPress; numa instalação real o `RewriteBase` seria `/blog/`. O link é removido no `restore`.

### Acessibilidade, larguras e contraste

- Larguras 320, 375, 768, 1280 e 1920 em seis estados reais (senha, QR, códigos, Auditoria preenchida, Erros preenchida, desafio): `scrollWidth` igual à largura da janela e nenhum elemento visível fora da janela fora da região rolável da tabela (o `overflow-x: clip` do base esconderia um transbordo; por isso a checagem mede os elementos).
- Ordem de Tab na Auditoria: Ir para o conteúdo → Auditoria → Erros → Sair do StealthAudit → De (UTC) → Até (UTC) → ID do usuário → Categoria → Ação → Resultado → IP → Linhas por página → Filtrar → Limpar filtros → região da tabela → Próxima página → Trocar o autenticador. Todos com anel de 2 px não transparente e visíveis ao receber foco. No desafio: pular link, campo, Verificar, "Perdi o acesso".
- Contraste WCAG calculado com as cores pintadas pelo Chrome: tinta/fundo 17,12; tinta-2/fundo 11,75; muted/fundo 6,14; texto do botão primário 14,29; anel de foco/fundo 14,47; muted/fundo-3 5,43; QR escuro/claro 19,85.
- Leitor de tela real não foi usado.

## Hallmark

Comandos (Hallmark 2.0.0 local, `puppeteer-core` 23.11.1 instalado fora do projeto e indicado por `HALLMARK_PUPPETEER`):

| Comando | Resultado |
| --- | --- |
| `sloplint.mjs artifacts/e2e/render --scope app --genre atmospheric` | `0 FAIL, 1 WARN across 17 file(s)` |
| `sloplint.mjs artifacts/e2e/render --scope app --genre atmospheric --render` | `0 FAIL, 1 WARN, 3 DISMISSED across 17 file(s)`, 70 capturas (1280x800, 1440x900, 1920x1080, 1440 e 375 de página inteira) |
| `sloplint.mjs --url https://localhost:8443/?stealthaudit=panel --scope app --genre atmospheric` (fixture e proxy ativos) | `1 FAIL`: `net::ERR_CERT_AUTHORITY_INVALID`; o render não rodou |

`--url` contra a rota autenticada não é possível com este renderer: ele abre um Chrome próprio sem opção de cookies nem de aceitar o certificado de teste, e mesmo com certificado confiável só veria o 302 para o wp-login (ou, em HTTP, o 403 "HTTPS obrigatório", que também testamos: `1 FAIL ... HTTP 403`). Por isso o render foi feito sobre os 14 estados reais capturados autenticados pelo Playwright (HTML do DOM após o script, sem segredos), mais os três CSS.

O WARN restante (F1, aspas retas) está no texto do payload XSS injetado, dado de log que precisa aparecer literalmente; fica justificado. Uma rodada anterior também deu WARN F23 (página 503 sem meta description), corrigido nesta etapa: `Panel::page` agora tem description e `referrer`. F25 (tabela abre com colunas fora da tela em 375px) segue dispensada pelo stamp, como na fase 6b: horário fixo e rolagem só dentro da região rotulada.

## Bugs encontrados e corrigidos

1. **Sessão WordPress encerrada aparecia como "página desatualizada".** Com o painel aberto, após logout/revogação/expiração da sessão WordPress, o cookie deixa de identificar o usuário e o próprio WordPress (`rest_cookie_check_errors`) recusa o `X-WP-Nonce` com 403 `rest_cookie_invalid_nonce` antes de qualquer `permission_callback`. O script classificava isso como "stale" e pedia recarga; a tela "Sessão do WordPress encerrada", descrita na fase 6b, nunca aparecia numa consulta real. `Api::post_dispatch` agora converte, só no namespace `stealthaudit/v1`, esse 403 em 401 `stealthaudit_unauthenticated` quando o cookie não autenticou (`$wp_rest_auth_cookie !== true` e nenhum usuário). Cookie válido com nonce velho continua 403 do core; continua sendo negação sem dados. Regressão: `HttpTest::test_ended_wordpress_session_is_401_not_a_stale_nonce` reproduz a ordem de `serve_request()` (checagem de cookie do core e depois `rest_post_dispatch`) para sessão revogada, expirada e ausente; falha com o código anterior (`403` em vez de `401`).
2. **Página de status sem meta description** (F23 do Hallmark): acrescentada, junto com `referrer=no-referrer`.

## Suítes

Depois das correções, executadas de novo: `npm run test:unit` 200 testes, 580 asserções, 1 skip (XMLWriter, explicado na fase 5); `npm run test:integration` 64 testes, 1.833 asserções (inclui a nova regressão); `npm run quality` sem erros (lint, WPCS/PHPCompatibilityWP, PHPStan nível 6). A integração grava eventos e erros nas tabelas do próprio site de testes ao criar/remover usuários; depois da execução esses registros e os contadores de orçamento foram devolvidos ao estado encontrado.

## Observações sem correção

- O WordPress envia `Access-Control-Allow-Origin` igual ao `Origin` com `Allow-Credentials` para qualquer rota REST (comportamento do core). Uma página de outro site consegue ler o status das respostas (a checagem 15 leu 401), mas o `Guard` recusa `Origin` cruzado e exige o nonce do StealthAudit, então só vê erros genéricos. Não implementamos CORS próprio (guarda da fase 6).
- O tema ativo do site de testes é `default`, que não existe; para usuários logados o core responde 500 "The theme directory "default" does not exist" fora do painel (por exemplo `/favicon.ico/` e `/stealthaudit/` quando não é o painel). É estado do ambiente, não do plugin, e foi preservado.
- A variante "A verificação expirou" aparece quando a consulta da própria página encontra a prova vencida; ao recarregar uma página vencida o servidor já mostra o desafio comum com o aviso de filtros (checagem 18). Ambos preservam filtros sem exibir dados.

## Limpeza

O `finally` executa `teardown` e `restore`; o `restore` compara `wp-config.php` (bytes), options (todas, ordenadas), usuários, tabela de estado e maior id de eventos/erros com o snapshot e falha se algo divergir. Também remove o link `/blog`, os posts criados e `artifacts/e2e/{snapshot,state}.json`. Efeitos que não voltam: contadores AUTO_INCREMENT de usuários, posts e eventos avançaram.

## O que não foi possível

- **Escanear o QR num celular com um aplicativo Authenticator** não pode ser feito por um agente. A prova aqui é um decodificador independente sobre os pixels exibidos e um TOTP independente aceito pelo servidor, com módulos, zona de silêncio e contraste medidos. O escaneamento físico continua pendente para o usuário.
- **WordPress instalado de fato num subdiretório** (arquivos e `.htaccess` próprios em `/blog/`) não existe no wp-env; foi aproximado com o link simbólico descrito acima.
- **`sloplint --render --url` na rota autenticada**: impossível com o renderer atual (sem cookies, rejeita o certificado de teste).
- **CDN/proxy de cache de produção** (Cloudflare, Varnish, Nginx): o cache usado é uma implementação RFC 9111 de biblioteca, não um produto de mercado.
- TLS confiável por uma CA, HTTP/2, Nginx, PHP 8.4/8.5, MySQL e leitor de tela real continuam fora desta verificação.
