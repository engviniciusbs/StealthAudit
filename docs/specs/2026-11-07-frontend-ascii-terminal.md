# StealthAudit — frontend ASCII Terminal

Data: 2026-11-07. Direção solicitada explicitamente pelo usuário após o plano técnico. Estado: especificação visual para implementação; não é interface pronta nem resultado de lint/render.

## Skill e procedência

Usar Hallmark, origem nutlope/hallmark, instalada no ambiente Codex em ~/.agents/skills/hallmark. Conferência local: SKILL.md versão 2.0.0 e registro ~/.agents/.skill-lock.json com source nutlope/hallmark. Não reinstalar ou atualizar automaticamente uma instalação existente apenas para cumprir o pedido.

Em outro ambiente onde estiver ausente, o comando solicitado pelo usuário é `npx skills add nutlope/hallmark -g --agent codex`. Ler a skill realmente instalada antes de implementar, pois versões futuras podem mudar os procedimentos. Não presumir instalação global em todos os ambientes de colaboradores.

Fontes locais lidas: SKILL.md; references/flow.md, especialmente “App screens” e “Harnesses”; references/themes/terminal.md e imagem terminal.jpg; gates.md para tokens, foco e escopo. Na implementação, ler também as seções aplicáveis de craft.md, patterns.md e interaction-and-states.md.

## Brief e decisões visuais

- Material: eventos e erros reais que a instalação coletar. Produto ainda não existe; protótipos só usam fixtures claramente identificadas. Sem números de segurança, uptime, tendências ou provas inventadas.
- Público: operadores DevOps autorizados.
- Uso: localizar e investigar ações/erros, com filtros, contexto confiável e estado de captura compreensível.
- Tom: técnico, sóbrio, utilitário; ASCII Terminal.
- Tema Hallmark: Terminal, slug terminal, por solicitação do usuário. Genre atmospheric da referência; scope app.
- Assinatura visual: o registro de auditoria como tabela operacional, com bordas retas, alinhamento monoespaçado e detalhes ASCII discretos.

## Composição do aplicativo

Aplicar o ramo App screens da Hallmark. A referência Terminal fornece paleta, família tipográfica, superfícies e controles; não copiar seu hero, foto em glifos, animação de digitação ou layout de landing page.

Barra superior com identificação StealthAudit, contexto do site e navegação curta entre Auditoria e Erros. Filtros inline, tabela e paginação. Estado de coleta próximo da listagem, com texto direto. Sem sidebar extensa para apenas duas áreas e sem sequência de cards estatísticos antes dos registros.

Na autenticação, usar um formulário contido com título, instrução, campo e ação principal. Primeiro cadastro, desafio, expiração e recuperação têm estados visuais distintos, mas compartilham tokens. A tela precisa dizer quando falta sessão WordPress e quando falta apenas verificar o StealthAudit.

Não acrescentar funções de terminal, execução de comandos, prompt remoto ou exclusão de logs: a aparência não altera o escopo funcional.

## Paleta, tipografia e tokens

Toda cor, família de fonte, espaço e duração devem usar tokens. O scaffold Hallmark na fase de implementação gera assets/tokens.css e base; nenhum arquivo CSS de runtime foi criado nesta etapa.

| Papel | Direção da referência Terminal |
| --- | --- |
| Fundo principal | Quase preto com matiz verde; --color-paper, referência oklch(12.5% 0.012 150). |
| Superfícies | Dois níveis ligeiramente mais claros, --color-paper-2 e --color-paper-3. |
| Texto | Verde muito claro, --color-ink; secundário e muted com contraste medido. |
| Ação e seleção | Verde fósforo, --color-accent, e wash correspondente. Não usar o destaque em toda a tela. |
| Divisórias | --color-rule e --color-rule-2; linhas finas, sem sombra/glow. |
| Foco | --color-focus; feedback imediato e perceptível pelo teclado. |
| Fonte | Martian Mono se houver pacote local licenciado adequado; fallback ui-monospace, monospace. Não buscar Google Fonts/CDN no navegador do operador. |
| Forma | Cantos retos, sem pills ou sombras; bordas de 1px quando necessárias. |

Tipografia de aplicação com escala fixa, aproximadamente 1.125–1.2, corpo legível e números tabulares. Cabeçalhos de tabela, abas, labels e badges em sentence case, sem tracking de capitais. Os identificadores técnicos mantêm sua grafia original, sem transformar a interface inteira em caixa alta.

## ASCII e semântica

Usar caracteres ASCII simples como +, -, | e colchetes em divisórias ou identificações pontuais. O tema informa que Martian Mono não contém box-drawing; não depender desses caracteres de outra fonte para montar uma moldura.

ASCII decorativo deve ser ignorado por leitores de tela e não aparecer como único nome acessível de um controle. Tabelas continuam table/thead/th/tbody e formulários usam label/input/button reais, em vez de converter toda a tela em pre ou canvas. Texto de logs continua dado escapado, nunca HTML executável.

Severidade e resultado têm palavras explícitas, como Erro, Aviso, Falha ou Sucesso, e diferenças de peso/borda quando úteis. Permanecer na paleta verde da referência; nunca depender apenas da cor ou de um ponto decorativo para transmitir estado. Prompts como $ só aparecem quando o conteúdo representa um comando real documentado, não antes de todo campo ou botão.

## Interações e estados

- Cada região de registros: carregando, vazia, erro e preenchida. Separar lista vazia de captura desativada/limitada, indisponível ou suspensa por capacidade.
- Controles: default, hover, focus-visible, active, disabled, loading, error e success quando aplicáveis, com demonstração dos estados no preview de componentes.
- Filtros, aba e paginação podem estar na URL; senhas, TOTP, código de recuperação, segredo e provisioning URI nunca na URL.
- Campo TOTP único, texto com inputmode numérico, suporte a colar e zeros à esquerda, label explícito e instrução curta. Não criar seis campos que compliquem teclado/colar sem necessidade.
- Erro de autenticação não revela registros, segredo ou credenciais. Sessão expirada pede nova verificação sem sugerir perda do histórico.
- Preservar leitura do conteúdo e formulários essenciais sem animação ou script; funcionalidades que exigirem JS devem informar isso claramente, sem tela em branco.

## Segurança visual e acessibilidade

Mantêm-se todas as regras do design funcional: HTTPS, autorização explícita, 2FA obrigatório, proteção de API e ausência de cache público.

QR de cadastro é um elemento funcional, não arte ASCII. Renderizar localmente, em memória, com quiet zone e contraste próprios para leitura pelo Authenticator. Criar tokens semânticos específicos para QR quando necessários; não aplicar duotone, filtros, decoração ASCII, animação ou canvas artístico ao código. Testar a leitura nos aplicativos alvo.

Não usar scripts, fontes, imagens ou QR de terceiros nas telas. A UI do cadastro não inclui analytics que capturem o segredo. Screenshots persistidos de revisão não contêm QR/segredos reais de operadores; usar cenários de teste descartáveis e versões redigidas quando preciso.

Foco visível em todos os controles, ordem de teclado previsível, mensagens ligadas aos campos e status acessível. Medir contraste de texto/controles/foco, especialmente muted no fundo escuro. Controles visualmente densos de 32–40px podem ter área de interação de pelo menos 44px.

## Responsividade e movimento

Layout testado entre 320px e 1920px, com container limitado e centralizado. Nada de overflow horizontal na página; tabelas largas usam região própria acessível ou detalhes em linha adaptados ao espaço, sem descartar ações/dados necessários.

Aplicar overflow-x: clip a html/body conforme Hallmark. Não usar largura 100vw. Strings técnicas longas têm tratamento para não estourar o container, sem mutilar o valor consultável.

Movimento somente para feedback de estado, curto e discreto. Sem cursor piscando continuamente, typewriter, scanlines, bezel, janela de terminal desenhada ou animação de entrada dos registros. prefers-reduced-motion reduz movimento; todo conteúdo é visível por padrão. Não usar transition: all ou animar propriedades de layout.

## Procedimento Hallmark na implementação

1. Ler o preflight e os arquivos reais do frontend, preservando contratos funcionais. O pedido já informa material/público/uso/tom; não repetir perguntas resolvidas.
2. Ler o ramo App screens e o tema Terminal, usando o tema por solicitação, sem sorteio. Iniciar com o scaffold terminal em scope app e conferir o help da versão instalada para diretório de saída.
3. Criar tokens e CSS com stamp Hallmark na primeira linha não vazia, critique e eventuais waivers fundamentadas. Registrar o build real em .hallmark/log.json somente quando houver um build, não fingir que documentação é UI construída.
4. Construir formulário de autenticação e tabela operacional com os estados previstos, sem material de marketing. Garantir consistência nos diferentes estados/telas do painel.
5. Executar sloplint sobre CSS/HTML renderizável com --scope app --genre atmospheric --render, usando --url para a instância de teste. PHP pode não ter cobertura estática completa: testar também o HTML efetivamente renderizado.
6. Corrigir todo FAIL; corrigir ou justificar REFLEX. Revisar teclado, contraste, mobile, estados e separação do 2FA. Sem navegador/dependência do render, declarar a limitação; nunca afirmar render pass que não ocorreu.

Exemplo a adaptar à rota e arquivos reais:

```text
node <hallmark-dir>/scripts/sloplint.mjs assets --scope app --genre atmospheric --render --url <endpoint-local-de-teste>
```

O comando é critério futuro: ainda não há assets nem endpoint para executar o lint. O ramo Codex da skill permite revisão em contexto separado no próprio fluxo, sem alegar revisão independente fictícia.

## Critérios de aceite visual

- Login/cadastro/desafio/recovery e Auditoria/Erros compartilham ASCII Terminal e recursos locais.
- Tabela e filtros recebem prioridade; nenhuma regra de marketing impõe hero ou limite artificial de palavras aos logs.
- Estados e severidades são compreensíveis sem depender de cor, sem métricas inventadas.
- QR legível; credenciais/segredos fora de URLs, logs e screenshots persistidos.
- Teclado, contraste, responsividade e reduced motion verificados no frontend real.
- Resultados Hallmark estáticos/render registrados com evidência e limitações reais; nenhuma aprovação presumida neste documento.

## Rastreabilidade

Complementa [design funcional](2026-11-07-stealthaudit-design.md) e [fase 6 do plano](../plans/2026-11-07-implementation-plan.md). A direção visual foi pedida pelo usuário; não reabre escopo, autenticação ou modelo de ameaça aprovados. Futuro subdomínio deve usar os mesmos tokens e componentes.
