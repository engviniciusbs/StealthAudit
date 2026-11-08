# Rota, API REST e segurança HTTP (fase 6a)

Esta etapa adiciona o transporte HTTP do painel: rota própria, namespace REST `stealthaudit/v1`, política de permissão, cabeçalhos privados e a camada de consulta com filtros validados no servidor. A interface Hallmark/ASCII Terminal que substituiu o placeholder desta etapa está descrita em [phase-6-ui.md](phase-6-ui.md).

## Composição

`Runtime::initialize_http()` (em `plugins_loaded`, prioridade 20) compõe `src/Http/`:

- `Urls`: única geração de URLs. Caminho amigável `home_url(<root><endpoint>/)` com permalinks bonitos (inclui `index.php/` em PATHINFO e respeita WordPress em subdiretório); fallback `/?stealthaudit=panel` com permalinks simples. REST via `rest_url()`; login via `wp_login_url(<retorno local validado>, reauth=1)`. Uma futura opção de subdomínio substituiria somente esta classe; não há CORS nem cookies de subdomínio.
- `Router`: `add_rewrite_rule(<regex do endpoint>, 'index.php?stealthaudit=panel', 'top')` em `init`, query var pública, atendimento em `parse_request` (antes da consulta principal e do tema, com `exit`). A regra `top` impede que uma página com o mesmo slug sombreie o painel.
- `Panel`: página. GET/HEAD apenas (405 com `Allow` para o resto); sem HTTPS → 403; sem sessão WordPress (ou sessão revogada/expirada) → 302 para o wp-login existente com `redirect_to` local e somente `view` e filtros validados preservados — parâmetros desconhecidos, como `redirect_to` externo, descartam todos os filtros; usuário fora da allowlist → 403; backend/chave indisponível → 503; caso contrário 200 com o template do painel (fase 6b).
- `Guard`: permission callbacks. Ordem: HTTPS (403) → usuário WordPress (401) → allowlist (403) → sessão real `WordPressSession` (401) → `Origin`/`Sec-Fetch-Site` de mesma origem e nonce `stealthaudit` no cabeçalho `X-StealthAudit-Nonce` (403) → em POST, corpo ≤ 8.192 bytes (413) e `application/json` (400) → composição do `PanelAccess` (503). Para dados, ainda `PanelAccess::authorized()` com no máximo duas tentativas; falha → 401 `stealthaudit_proof_required` com o estado MFA fechado, ou 503 quando o próprio estado é `denied` (armazenamento indisponível).
- `Api`: rotas pequenas, todas com `show_in_index => false` e nenhum `permission_callback` permissivo.
  - `GET /events` (período, `user`, `category`, `action`, `result`, `ip`), `GET /errors` (período, `severity`), ambos exigem prova; `GET /status` exige identidade e devolve `status` e, só com prova válida (sem prolongar idle), o estado de captura.
  - `POST /reconfirm`, `/enrollment/begin`, `/enrollment/confirm`, `/authenticate`, `/recover`, `/replace-factor`, `/revoke`: corpo JSON com campos fechados e limitados, lidos apenas do corpo (nunca da URL). Repassam o nonce do cabeçalho ao `PanelAccess` (que repete POST + nonce + limite de tentativas). Falha → 403 genérico; janela de tentativas cheia → 429 `stealthaudit_try_later` (fase 6b), também sem dados nem contadores. Nenhuma devolve logs; `begin/recover/replace` devolvem o SVG do QR e `confirm` os códigos recovery, sempre com `no-store`.

O cliente precisa enviar dois nonces: `X-WP-Nonce` (`wp_rest`, sem ele o próprio WordPress descarta a autenticação por cookie e a resposta é 401) e `X-StealthAudit-Nonce` (`stealthaudit`). Nenhum substitui a prova MFA.

## Consultas

`Filters` valida tudo depois da permissão, de modo que um anônimo recebe 401/403 e não aprende o formato. Rejeita (400): chave desconhecida, `_wpnonce` na URL, valor não string, vazio ou > 64 bytes, mais de 16 parâmetros, datas fora de RFC 3339 com segundos (`Z` ou `±HH:MM`, sem datas impossíveis nem anteriores a 1970), `from > to`, IP inválido, usuário não positivo, códigos fora das listas fechadas dos repositórios, `limit` fora de 1–100 (padrão 50) e cursor fora de `<unix>-<id>`. `_envelope`, `_jsonp`, `_embed` e `_fields` também são recusados no namespace.

`Records::read_window` lê uma janela keyset ordenada por (tempo, id) decrescente: `occurred_at` para auditoria (índice `occurred`) e `first_seen` para erros. Busca `limit + 1` linhas para emitir `next_cursor` e nunca faz contagem total. A retenção é aplicada na consulta (auditoria `> agora − 365 dias`, erros `> agora − 30 dias`), inclusive quando o período pedido é mais antigo. Horários saem em ISO 8601 UTC com `timezone: "UTC"`.

Cada listagem devolve `capture`: `diagnostics` (saúde do coletor nesta requisição) e, por tipo, `state` = `available`, `limited` (há descartes acumulados), `suspended` (linhas ou bytes no limite configurado; novas gravações param) ou `unavailable` (linha de orçamento ausente/malformada), com `dropped`. Assim, lista vazia não é apresentada como ausência de eventos.

## Segurança HTTP

Toda resposta do painel e do namespace REST, incluindo 302/4xx/5xx, `rest_no_route` e QR, recebe `Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private`, `CDN-Cache-Control`/`Surrogate-Control: no-store`, `Pragma: no-cache`, `Expires` no passado, `Vary: Cookie`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: no-referrer` e `X-Robots-Tag`. A página acrescenta CSP só com recursos locais e `frame-ancestors 'none'`, `X-Frame-Options: DENY` e `DONOTCACHEPAGE`. No REST os cabeçalhos são aplicados em `rest_post_dispatch` e reenviados em `rest_pre_serve_request` (cobre `_envelope`, que descartaria os cabeçalhos do objeto).

O JSON do namespace usa `JSON_HEX_TAG|AMP|APOS|QUOT`: mensagens de log com `<script>` saem como `<script>`, inertes mesmo se interpretadas fora de `application/json`; o valor decodificado continua íntegro e a 6b deve renderizá-lo como texto. O template escapa todo valor com `esc_html`/`esc_attr`/`esc_url`.

## Regras de rewrite

`Router::maybe_flush()` roda em `wp_loaded` e custa só a leitura das options já carregadas: com permalinks simples ou com a regra do endpoint já presente em `rewrite_rules`, não faz nada. Caso falte (instalação ou mudança real de endpoint), adquire `lease:<sha256>` por 300 s no estado InnoDB (verificando antes se existe lease viva, para não reservar capacidade a cada requisição) e chama `flush_rewrite_rules(false)` uma vez. A lease não é liberada cedo: se o flush não persistir, ele se repete no máximo uma vez por janela. Sem armazenamento não há flush; o fallback por query var continua funcionando. `StateRepository::replace_expired` passou a aceitar `lease:` para reaproveitar a linha expirada sem esperar o Cron.

Se o servidor não encaminhar o caminho amigável ao `index.php` (por exemplo `.htaccess`/`try_files` ausente), use `/?stealthaudit=panel`; com permalinks simples `/stealthaudit/` cai na página inicial do site, não no painel.

## Ajuste herdado da fase 5

`PanelAccess::proof_valid`: quando o CAS de atividade perde para outra consulta da mesma sessão, relê fator e prova uma única vez e só concede se a prova continua com a mesma geração ativa, autenticada e dentro dos limites idle/absoluto. Revogação ou troca de fator concorrente nega; qualquer outra falha nega. O teste simula a corrida com um filtro `query` que aplica o CAS concorrente imediatamente antes do UPDATE; com o código anterior ele falha.

## Evidência

Comandos no ambiente de testes já em execução (WordPress 7.1.4, PHP 8.3, MariaDB 10.11): `npm run test:unit`, `npm run test:integration`, `npm run quality`.

- Unidade: 200 testes, 580 asserções, 1 skip (XMLWriter, já explicado na fase 5). Novo `HttpFiltersTest` cobre normalização e 30 entradas malformadas.
- Integração: 57 testes, 1.218 asserções. Novo `HttpTest` (10 testes) com despacho REST real, cookies assinados, sessões reais e tabelas isoladas: matriz de permissão (sem sessão 401, fora da allowlist 403, sem HTTPS 403, sem prova 401 sem dados, chave indisponível 503, backend removido 503, fluxo completo de cadastro pela API → dados, sessão WordPress revogada → 401); nonce ausente/forjado/`wp_rest`, `Origin` e `Sec-Fetch-Site` cruzados, métodos errados (404 `rest_no_route`), corpo não JSON, campo extra, senha só na URL, corpo > 8 KiB (413) e parâmetros de transporte; filtros malformados só após autorização; paginação estável com inserção entre páginas; retenção 365/30 dias; estados de captura; XSS inerte; página com redirecionamento local, `reauth=1` e no-store em todos os estados; fallback por query var, rota amigável e WordPress em subdiretório simulado (`/blog`) por `WP::parse_request`; flush único sob lease, sem flush durante a lease viva, reaquisição após expiração e sem flush quando o armazenamento falha. `PanelAccessTest` ganhou o teste com `Recorder` (oito linhas `mfa_*` de sucesso/falha sem senha, OTP, recovery, token, hash de sessão ou nonce) e o teste do CAS concorrente.
- Qualidade: lint (agora incluindo `templates/`), WPCS/PHPCompatibilityWP e PHPStan nível 6 sem erros. A mensagem do Composer sobre versão do pacote raiz é aviso do ambiente, não do código.

Smoke HTTP real em `http://localhost:8880` (Apache do wp-env). Sem cabeçalho de proxy: página 403 "HTTPS obrigatório", REST 403, `_jsonp` 400 (o WordPress ainda embrulha o erro em JSONP, sem dados), POST na página 405 — todos com `no-store`. O `wp-config.php` gerado pelo wp-env converte `X-Forwarded-Proto: https` em HTTPS; com ele, um fluxo descartável com usuário sintético, constante `STEALTHAUDIT_CONFIG` temporária e chave aleatória passou por wp-login real → página 200 (estado `password_required`) → `/status` 200 → `/events` 401 `proof_required` → sem nonce StealthAudit 403 → sem `X-WP-Nonce` 401 → reconfirmação → QR → confirmação TOTP → `/events` 200 com as linhas `mfa_*` gravadas pelo Runtime → filtro malformado 400 → revogação → 401. O redirecionamento com `redirect_to=https://evil.example/` voltou para `/?stealthaudit=panel&view=audit` local. Constante, usuário, estado e eventos sintéticos foram removidos ao final.

## Limitações

- HTTPS é simulado (`$_SERVER['HTTPS']` nos testes, `X-Forwarded-Proto` aceito pelo wp-config do wp-env no smoke); não houve navegação TLS real nem cache/CDN real na frente. Em produção, aceitar `X-Forwarded-Proto` só é seguro atrás de proxy confiável que sobrescreva o cabeçalho.
- Permalinks bonitos e subdiretório foram verificados pelo parser do WordPress (`WP::parse_request`), não por um Apache/Nginx configurado em subdiretório; o site de testes usa permalinks simples. Troca de tema não foi exercitada: a rota sai em `parse_request`, antes de qualquer template.
- Método errado devolve o 404 `rest_no_route` do WordPress, não 405. Erros REST têm mensagens genéricas em inglês; a página usa português.
- Erros são paginados por `first_seen` sem índice dedicado (varredura limitada pelo teto de linhas de erros); um índice exigiria migração de esquema. Uma ocorrência agregada aparece no período pelo seu primeiro registro da janela de cinco minutos.
- `dropped` e o contador de linhas do orçamento são cumulativos até a manutenção da fase 7; `suspended` reflete esse contador, não uma contagem física.
- UI, acessibilidade e Hallmark: ver [phase-6-ui.md](phase-6-ui.md). Browser real ponta a ponta e QR escaneado em aplicativo continuam pendentes.
