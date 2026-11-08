# Interface do painel, Hallmark ASCII Terminal (fase 6b)

Esta etapa substitui o placeholder da fase 6a pela interface do operador, seguindo a [especificação ASCII Terminal](../specs/2026-11-07-frontend-ascii-terminal.md) com a skill Hallmark 2.0.0 (nutlope/hallmark), tema Terminal por pedido do usuário, sem sorteio, escopo app, gênero atmospheric. O contrato HTTP continua o de [phase-6-http.md](phase-6-http.md).

## O que foi construído

- `assets/tokens.css` e `assets/base.css`: gerados pelo scaffold real `theme.mjs scaffold terminal --scope app --dir assets`. Ajustes registrados no próprio arquivo: pilha de fonte `ui-monospace, monospace` (nenhum arquivo Martian Mono é distribuído, logo nada entra em THIRD-PARTY-NOTICES), container 1360px, tokens de controle (40px, alvo 44px) e de QR, e `--color-error`/`--color-success` apontando para a tinta e o verde do tema (o Terminal proíbe segunda matiz; erro é dito por palavra, peso e borda tracejada).
- `assets/panel.css`: stamp Hallmark na primeira linha não vazia, crítica pré-emissão, `answered F4` e `dismissed F25`. Todos os valores passam por tokens; `overflow-x: clip` em `html`/`body` vem do base; sem `100vw`, sem `transition: all`, transições só de cor em `steps(1)`, redução de movimento herdada do base.
- `assets/panel.js`: script local sem build (~600 linhas legíveis), sem dependências. Segredos só em memória/DOM; nada em URL, `localStorage`, `sessionStorage` ou console. Texto de log sempre por `textContent`. Em `pagehide` limpa QR, códigos de recuperação, linhas e campos secretos; em `pageshow` vindo do bfcache recarrega. O QR chega como SVG do servidor e é exibido como `<img src="data:image/svg+xml,…">` (SVG em imagem não executa script); CSP já permitia `img-src data:`.
- `templates/panel.php`: barra superior (marca, site/host, abas Auditoria/Erros e "Sair do StealthAudit" quando autorizado) e uma tela por estado:
  - `password_required`: confirmação de senha, explicando que o WordPress já está conectado.
  - `enrollment_required` → "Gerar código QR"; `enrollment_pending`/após recuperação ou troca → QR + campo único de TOTP; após confirmação, os dez códigos de recuperação aparecem uma vez com caixa de confirmação.
  - `challenge_required`: "falta só verificar o StealthAudit"; recuperação (senha + código) em `<details>`. Variante de expiração ("A verificação expirou", registros guardados, filtros mantidos) é ativada pelo script quando uma consulta autorizada recebe 401 `proof_required`; a tabela é esvaziada antes.
  - QR perdido (recarga, navegação ou aba fechada antes de escanear): a tela pendente mostra senha + "Exibir o código QR"; o script chama `reconfirm` e depois `enrollment/begin` e esconde esse formulário enquanto o QR está visível. Um aviso pede para não recarregar antes de confirmar. Sem isso, a pendência expiraria em `recovery_locked` com o código de recuperação já gasto.
  - Estado desconhecido: tela neutra "Estado desconhecido", sem formulários nem dados.
  - `recovery_locked`: instrução para pedir o reset offline ao operador; sem formulários.
  - Sessão WordPress encerrada (401 `unauthenticated` no meio do uso): tela própria com link para o wp-login com retorno local. Sem sessão na carga da página, o servidor já redireciona (fase 6a).
  - `authorized`: filtros do design (período UTC, usuário, categoria, ação, resultado, IP; gravidade em Erros; 25/50/100 linhas), estado de captura ao lado da lista, tabela com quatro estados (carregando com skeleton, vazia, erro, preenchida), paginação por cursor ("Primeira página", "Próxima página") e troca de autenticador em `<details>`.
- `src/Http/Assets.php` + `Urls::asset()` + `Router`: CSS/JS servidos pela própria rota (`/?stealthaudit=asset&file=…`), lista fechada de quatro arquivos, só GET/HEAD, só HTTPS e só para usuário da allowlist com sessão WordPress válida; qualquer outro caso é 404 sem corpo. Cabeçalhos privados (`no-store`, `nosniff`), `Content-Type` correto e `Cross-Origin-Resource-Policy: same-origin`. Funciona mesmo com `content_url` em CDN (CSP `'self'`) e não revela os arquivos a anônimos.
- Itens herdados da revisão 6a:
  1. `AttemptLimiter` lança `AttemptsExhausted` (subclasse de `RuntimeException`) quando a janela está cheia; `PanelAccess` repassa o tipo com a mesma mensagem opaca e `Api::operate` responde 429 `stealthaudit_try_later`, sem `Retry-After`, contadores ou tempo restante. Continua sendo negação: nenhum estado muda e falhas de armazenamento/contensão seguem 403.
  2. Estado `limited` é redigido "perdas registradas desde a última manutenção (N descartados)", não como estado ao vivo.
- `Panel`: valida view/filtros uma vez; campos vazios de um formulário GET sem script contam como ausentes; filtros inválidos são ignorados com aviso `role="alert"` e nunca ecoados. A página 503 (operador identificado) recebe o CSS; 403/405 continuam sem estilos.

## Decisões de projeto não fixadas pela especificação

- Dados continuam carregados pelo navegador via REST (contrato 6a): o HTML nunca contém registros, QR ou códigos, e a expiração consegue limpar a tela sem cache. Sem JavaScript: o estado atual, as instruções e o formulário de filtros (GET local) funcionam; formulários de autenticação aparecem com `fieldset disabled` e um aviso explícito, porque a API exige JSON e cabeçalhos de nonce, e assim senha/TOTP nunca podem cair num GET.
- Ativos servidos pela rota (não pelo URL do mu-plugin) para controlar cabeçalhos e manter a mesma origem.
- Três `<link>` em vez de `@import`: URLs por query string impedem import relativo.
- Ações aparecem pelo código técnico (`login_failed`), como pede a especificação para identificadores; categorias, resultados, origens e gravidades têm rótulos pt-BR.
- Não há chave manual além do QR: a API da fase 5 devolve só o SVG.

## Hallmark: comandos e resultados reais

Pré-requisitos: Node local; Chrome em `C:\Program Files\Google\Chrome`; `puppeteer-core@23.11.1` instalado num diretório temporário fora do projeto e apontado por `HALLMARK_PUPPETEER` (não é dependência do projeto).

Os previews vêm de `tools/ui-preview.php` (`wp eval-file` no contêiner `cli`), que renderiza o template real para onze cenários com dados fictícios identificados, um stub local de `fetch` e um padrão placeholder no lugar do QR. Nada é criado no banco; os arquivos ficam em `artifacts/ui-preview/` (gitignored), capturas incluídas.

| Comando | Resultado |
| --- | --- |
| `sloplint.mjs assets --scope app --genre atmospheric` | `clean. 0 FAIL, 0 REFLEX, 0 WARN across 3 file(s).` |
| `sloplint.mjs artifacts/ui-preview --scope app --genre atmospheric` | `clean. 0 FAIL, 0 REFLEX, 0 WARN across 14 file(s).` |
| `sloplint.mjs artifacts/ui-preview --scope app --genre atmospheric --render` | `0 FAIL, 0 WARN, 2 DISMISSED across 14 file(s)`, 55 capturas (1280x800, 1440x900, 1920x1080, 1440 e 375 de página inteira) |

Rodadas anteriores encontraram e corrigiram: CSS não associado às páginas (assets em subpasta e `esc_url` prefixando nomes relativos nos previews), contraste 2,06:1 do marcador ASCII `+--` (passou para `--color-muted`) e uma menção a `@import` em comentário. F25 (tabela abre com colunas fora da tela em 375px) foi dispensada no stamp: a primeira coluna, o horário, fica fixa e o restante rola dentro da região rotulada; não há ação por linha.

**`--render --url` contra a rota real não foi executado**: a página exige login WordPress, HTTPS e TOTP, e o render tier não autentica. O render foi feito sobre o HTML gerado pelo próprio template. `.hallmark/log.json` registra o build real. A revisão "finish" em contexto separado da Hallmark não foi feita (esta tarefa não permite subagentes).

## Acessibilidade verificada

- Rótulo visível em todo campo; `aria-describedby` para instrução e mensagem; `aria-invalid` + borda tracejada + texto em negrito no erro; primeiro campo inválido recebe foco.
- `:focus-visible` instantâneo em links, botões, campos, `summary` e região da tabela (anel de 2px no verde do foco); link "Ir para o conteúdo"; foco vai para o título ao trocar de tela.
- Região `role="status" aria-live="polite"` única para carregamento/resultado; mensagens de formulário também ao vivo.
- Severidade e resultado por palavra (Fatal, Erro, Aviso, Falha, Desconhecido), com borda/peso como reforço; ASCII decorativo com `aria-hidden`.
- Tabela semântica (`caption`, `th scope="col"`), rolagem horizontal só na própria região focável; página sem overflow nos tamanhos medidos pelo render tier; contraste medido pelo render tier (gate 40) sem falhas.
- Campo TOTP único, `inputmode="numeric"`, `autocomplete="one-time-code"`, aceita colar com espaços e zeros à esquerda.
- Controles de 40px com alvo expandido; 44px abaixo de 40rem. `prefers-reduced-motion` do base.

## Testes

- `npm run test:unit`: 200 testes, 580 asserções, 1 skip (XMLWriter, já explicado na fase 5).
- `npm run test:integration`: 63 testes, 1.780 asserções. Novos em `HttpTest`: página por estado percorrendo o fluxo real (senha, cadastro, pendente, autorizado, desafio, bloqueio por troca abandonada) com exatamente a tela esperada visível; nome do site hostil escapado; nenhum SVG, `otpauth`, `data:image` ou código de recuperação no HTML; todos os `src`/`href`/`action` na mesma origem e scripts só externos ou JSON; formulários com credenciais em POST, sem `action` e desativados até o script; filtro GET local com o parâmetro da rota; filtro inválido ignorado com aviso e sem eco; ativos com tipo, `nosniff`, `no-store`, CORP, conteúdo idêntico ao arquivo, sem URL remota, e 404 para nome fora da lista, HTTP, anônimo, usuário fora da allowlist ou sessão revogada; 503 estilizado só para o operador; janela de tentativas cheia → 429 sem contadores, ainda negando dados, e recuperação após a janela; QR de troca perdido (recarga após 5 min) reexibido com senha reconfirmada e cadastro confirmado; estado desconhecido em tela neutra sem formulários.
- `npm run quality`: lint, WPCS/PHPCompatibilityWP e PHPStan nível 6 sem erros (o aviso de versão do Composer é do ambiente).
- Smoke HTTP em `http://localhost:8880` com `X-Forwarded-Proto: https`: ativo sem sessão → 404 `text/plain` com `no-store` e `nosniff`; página sem sessão → 302.

## Limitações

- O fluxo real por navegador, a decodificação independente do QR e o render dos estados reais autenticados foram feitos na fase 6c ([phase-6-e2e.md](phase-6-e2e.md)), que também corrigiu a tela "Sessão do WordPress encerrada" (antes uma sessão encerrada aparecia como página desatualizada). Continuam pendentes o QR escaneado num celular e o render `--url` direto na rota autenticada.
- Teclado, foco e leitores de tela foram verificados por inspeção do código e pelos gates automáticos, não com leitor de tela real. 320px não foi capturado isoladamente (o render mede 375px e a ausência de overflow).
- Sem JavaScript a consulta de registros e a autenticação não funcionam (informado na página).
- Martian Mono não é distribuída; a aparência depende da monoespaçada do sistema.
- Mensagens de erro REST continuam em inglês no JSON; a interface mostra textos pt-BR próprios.
