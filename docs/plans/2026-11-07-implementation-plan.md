# StealthAudit — plano de implementação

## Objetivo principal

Permitir que a equipe DevOps consulte, por instalação WordPress, o histórico de ações e os erros observáveis do site em um painel próprio protegido por autorização explícita e Authenticator obrigatório, com impacto e limites de coleta documentados.

Data: 2026-11-07. Estado: plano pronto para execução; nenhuma implementação iniciada. Base: [design aprovado](../specs/2026-11-07-stealthaudit-design.md) e [revisão APPROVED](../specs/2026-11-07-stealthaudit-review.md). APIs e exemplos: [catálogo de descoberta](2026-11-07-documentation-discovery.md), referências D1–D8 usadas abaixo.

Atualização de frontend solicitada pelo usuário: usar **Hallmark com tema ASCII Terminal**. Skill já instalada para Codex, origem nutlope/hallmark, versão local 2.0.0. Seguir a [especificação visual](../specs/2026-11-07-frontend-ascii-terminal.md), aplicando o ramo App screens; não reinstalar se estiver disponível. Em ambiente ausente, usar `npx skills add nutlope/hallmark -g --agent codex`.

## Resultado esperado e fronteiras

O entregável de implementação será um ZIP instalável em wp-content/mu-plugins, com loader na raiz, núcleo/dependências em subdiretório e instruções para configuração DevOps. Cada site armazena seus próprios registros e oferece endpoint HTTPS independente do tema. Não há serviço remoto obrigatório, alteração do login WordPress ou publicação/deploy implícitos neste plano.

Escopo fechado: eventos de acesso/usuários/administração/conteúdo em resumo; IP confiável quando disponível; localização aproximada local opcional; erros PHP/WordPress dentro da cobertura possível; retenções de 365/30 dias; autorização por usuários explicitamente selecionados; TOTP exclusivamente no painel, com expiração por 30 minutos inativos ou 8 horas totais.

Proteção contra desativação casual. Um administrador que instala PHP pode interferir deliberadamente; não prometer proteção contra isso. MU pode continuar visível na seção Obrigatórios. Subdomínio futuro deve ser possível pela separação entre URLs, roteamento, interface e acesso; não implementá-lo agora.

## Decisões técnicas para executar o plano

| Área | Escolha e critério |
| --- | --- |
| Plataforma | PHP 8.3+; matriz inicial 8.3/8.4/8.5, WordPress 7.1 com patch 7.1.4 observado. Revalidar patch no início e fixar a versão de teste, sem tags latest. |
| Banco | Tabelas próprias InnoDB; MariaDB 10.11 como base e MySQL 8.0+ como segunda família, a declarar suportada somente após integração real. Charset/collation da instalação. |
| Extensões | mysqli e requisitos normais do WordPress; sodium, iconv e XMLWriter para acesso/QR. Loader verifica requisitos antes de incluir arquivos incompatíveis. Sem chave/cifra válida, negar painel, preservando coleta quando seus requisitos independentes estiverem atendidos. |
| Linguagem e painel | PHP com namespace StealthAudit e PSR-4; templates próprios, CSS e JavaScript pequenos, servidos localmente. Hallmark ASCII Terminal em scope app, tokens semânticos, fonte mono local/fallback e tabelas operacionais. Sem necessidade de React, CDN, SPA ou build Node na hospedagem. Strings traduzíveis, interface inicial pt-BR. |
| TOTP/QR | OTPHP 11.6.x e BaconQrCode 3.x, com versões/transitivas travadas após resolução e checagem de manutenção. PSR Clock injetável para testes. Não usar QR externo. |
| Cifra | Sodium XChaCha20-Poly1305, chave exclusiva de instalação fora do banco, nonce aleatório por envelope e AAD de contexto. Nada de cifra/algoritmo TOTP próprios. |
| Persistência | Duas tabelas de registros, mais uma tabela auxiliar de estado para fator/sessão/limites/locks. A auxiliar concretiza o 2FA e não constitui nova categoria de log. Não usar transients como autoridade de segurança. |
| GeoIP | GeoIp2 Database Reader, MMDB fornecido pelo operador e validado localmente. Consultar na leitura, sem persistir país/cidade históricos e sem incluir base no ZIP. |
| Desenvolvimento | wp-env 12.1.0, Node >=22.13 e Docker. Configurações isoladas de desenvolvimento/testes. Composer/PHPCS/PHPUnit dentro de containers, sem depender de PHP local. |
| Distribuição | Dependências de produção prefixadas no build; ZIP com autoloader pronto. Licença candidata GPL-3.0-or-later, a confirmar com inventário das dependências antes de inserir LICENSE/publicar. Preservar avisos de terceiros e separar termos da base GeoIP. |

Essas escolhas são detalhes de implementação dentro do design aprovado. Compatibilidade não demonstrada é pendência de teste, não promessa. Versões exatas de bibliotecas/ferramentas serão registradas em lockfiles na fase 1.

## Estrutura prevista

Os caminhos abaixo são novos arquivos a criar durante a implementação; só os documentos existem hoje.

```text
loader/stealthaudit.php          # arquivo instalado na raiz mu-plugins
bootstrap.php                   # núcleo instalado em mu-plugins/stealthaudit
src/
  Config/ Bootstrap/ Storage/ Audit/ Diagnostics/
  Security/ Http/ Geo/ Maintenance/
templates/ assets/ languages/
config/stealthaudit.example.php  # exemplo sem credenciais reais
tests/unit/ tests/integration/ tests/concurrency/ tests/e2e/
tests/fixtures/ benchmarks/
tools/package.php tools/verify-package.php tools/scoper.inc.php
composer.json composer.lock package.json package-lock.json
.wp-env.json .wp-env.tests.json phpunit.xml.dist phpcs.xml.dist
docs/operations/ docs/testing/ docs/releases/
```

No desenvolvimento, mapear a raiz do repositório para wp-content/mu-plugins/stealthaudit e loader/stealthaudit.php para wp-content/mu-plugins/stealthaudit.php. Não cadastrar a raiz em plugins como se o produto fosse convencional. O ZIP final omite testes, ferramentas e arquivos de desenvolvimento.

## Fase 0 — Descoberta documental e contratos verificados

**Como atende ao objetivo principal:** evita construir um histórico enganoso ou um acesso contornável por assumir semânticas que WordPress e as bibliotecas não garantem.

**Pré-requisitos:** design/revisão aprovados. A pesquisa desta fase está concluída para planejar; revalidar fontes móveis ao executar.

**O que fazer:**

1. Ler o catálogo D1–D8 e os trechos oficiais indicados, não apenas este resumo. Fixar fontes do core na tag usada pela matriz e registrar versões das ferramentas.
2. Manter uma lista de APIs permitidas: hooks pós-evento e resultados por item; dbDelta/prepare; CAS por revisão; sessão WordPress validada; OTPHP/Bacon/sodium; Reader local; WP-Cron e REST com autorização.
3. Criar docs/testing/compatibility.md com matriz, dependências, extensões e restrições do ambiente; docs/testing/event-coverage.md com hook, argumentos permitidos, sucesso/falha e lacunas por evento.
4. Fechar a licença do código com inventário das bibliotecas; confirmar requisitos/avisos da base escolhida sem aceitá-los em nome do operador. Se alguma versão verificada estiver sem manutenção, resolver uma versão mantida e revalidar a API antes de usá-la.

**Referências:** catálogo completo D1–D8; exemplos Source das funções e métodos; RFC 6238, §5.2/apêndice B.

**Verificação/saída:** cada dependência e evento aponta para fonte primária/versionada; lacunas identificadas; sem API inventada ou promessa de cobertura SQL/filesystem. Registrar a conclusão na lista canônica implementation, quando essa execução começar.

**Guardas:** não confundir hook de conclusão com sucesso; não substituir a pesquisa por conhecimento de versão antiga; não usar tag latest nos testes reproduzíveis; não instalar ou implementar nada como parte da entrega deste plano.

## Fase 1 — Base do projeto, ambiente e loader MU

**Como atende ao objetivo principal:** produz uma instalação reproduzível que inicia sem afetar o site e permite testar o produto no modo MU verdadeiro.

**Depende de:** fase 0.

**Implementar:**

1. Criar a estrutura, manifestos/lockfiles, autoload e scripts de qualidade. Inicializar Git local se ainda ausente; não criar remoto ou publicar automaticamente.
2. Copiar o padrão loader do guia MU. O loader deve ter sintaxe compatível com o diagnóstico de versão mínima antes de carregar classes PHP 8.3; inclusão direta fora de WordPress encerra sem expor informações.
3. Separar requisitos de coleta dos de segurança/QR: indisponibilidade do segundo fator nunca libera logs. Tratar vendor ausente e configuração inválida de modo previsível, sem exception pública ou falha fatal causada pelo próprio loader.
4. Carregar configuração somente de fonte controlada pelo operador: caminho/constantes definidos em wp-config.php, arquivo fora do webroot ou ambiente. Campos: IDs WordPress autorizados, endpoint relativo, chave/key-id, proxies confiáveis, limites e caminho MMDB opcional. Lista ausente/vazia nega painel; defaults não autorizam automaticamente administradores.
5. Preparar wp-env de desenvolvimento e testes com portas/dados separados, mapping MU, PHP 8.3 e WP fixado. Confirmar extensões e versão real do runner PHPUnit antes de travar seus pacotes/polyfills. Desenvolvimento HTTP só em loopback; os fluxos de acesso devem ter testes HTTPS e não relaxar requisito de produção.
   Preparar também a execução Hallmark local: Node disponível, leitura de SKILL.md e flow.md/App screens, renderer/Chrome quando exigidos pelo lint visual. Ferramentas Hallmark são de desenvolvimento; não são dependência da hospedagem. Fontes escolhidas precisam de licença e arquivos locais antes do empacotamento.
6. Criar smoke test de bootstrap, testes unitários com clock fake e integração carregando MU uma única vez no bootstrap WordPress. Qualidade: WPCS, compatibilidade PHP e análise estática configuradas sem silenciar globalmente erros.

**Referências:** D1, D2 e D8; copiar “MU Caveats”, “Add mu-plugins”, exemplos Composer/PHPUnit e bootstrap da tag do core. Comandos de partida:

```text
npm install --save-dev --save-exact @wordpress/env@12.1.0
npx wp-env start --config=.wp-env.tests.json
npx wp-env run cli --config=.wp-env.tests.json --env-cwd=wp-content/mu-plugins/stealthaudit composer install
npx wp-env run cli --config=.wp-env.tests.json phpunit --version
```

Na primeira instalação Composer, resolver e salvar lock; depois usar install/npm ci, sem atualização implícita. Os scripts de teste precisam usar o mesmo runner que foi comprovado compatível com a biblioteca WP_TESTS_DIR. Não copiar cegamente um phpunit.xml de major incompatível.

**Verificação/saída:** homepage/login continuam funcionando; MU carrega sem ativação; conta não autorizada não vê dados; ausência de chave/vendor/requisito tem resultado documentado; testes e lint executáveis pelo container. Capturar versões e comandos em docs/testing/local-setup.md.

**Guardas:** sem activation hooks obrigatórios, criação automática de credenciais, chave no exemplo, print de configuração, hooks pluggable chamados cedo ou PHP/Composer obrigatório no host final.

## Fase 2 — Persistência, concorrência e limites de recursos

**Como atende ao objetivo principal:** mantém registros consultáveis e fornece um estado confiável para 2FA, sem permitir crescimento ilimitado em hospedagem comum.

**Depende de:** fase 1. Deve anteceder os coletores e o acesso aos registros.

**Implementar:**

1. Copiar o padrão dbDelta de tabela prefixada, com versão de esquema, migração idempotente e lease de exclusão mútua recuperável. Criar tabelas com engine InnoDB e verificar estrutura/índices depois; trabalho de esquema somente quando necessário, nunca em cada evento. Não marcar migração concluída se parte dela falhar.
2. Tabelas de registros: stealthaudit_events (id, versão, occurred_at UTC, ator opcional, categoria/ação/resultado/origem, objeto, IP canônico opcional, resumo permitido e tamanho lógico); stealthaudit_errors (id, first_seen/last_seen UTC, gravidade, mensagem limitada, arquivo relativo/linha, fingerprint e contagem). Sem conteúdo integral, payload de requisição ou geodados históricos.
3. Índices iniciais: tempo/id; categoria/tempo/id; ator/tempo/id; IP/tempo/id, conforme filtros de auditoria; gravidade/tempo/id e fingerprint nos erros. Medir antes de acrescentar índices redundantes. Preservar contexto de usuário excluído sem FK que apague o histórico.
4. Tabela stealthaudit_state: chave única, revisão, payload limitado e validade UTC. Registros tipados de fator por usuário, prova por sessão, limites, leases e contadores. Nenhum token de sessão bruto. Implemente update condicional com revisão, uma linha afetada como sucesso e no máximo tentativas curtas/bounded de disputa. Não conceder autenticação após conflito ou falha não resolvida.
5. Para mudança de fator/recovery, armazenar fator, códigos e generation na mesma linha de usuário. Incrementar generation invalida provas antigas em outras linhas; toda leitura do painel compara a generation atual. Não exigir transação na conexão global do WordPress que possa englobar trabalho de terceiros.
6. Escrever repositórios separados para gravação não bloqueante da coleta e estado de acesso que nega em falha. Prepare em todas as consultas dinâmicas, lista permitida para colunas/ordenação, limites de string e página no servidor.
7. Limites configuráveis: quantidade e bytes lógicos por categoria, ritmo de gravação e trabalho por requisição. Reservar orçamento atomicamente antes da inserção; inserção que falha pode sobrecontar conservadoramente, nunca liberar capacidade inexistente. Reconciliação limitada e idempotente na manutenção; não fazer COUNT de toda a tabela por evento.
8. Ao atingir capacidade, suspender gravações da categoria, sinalizar limitação e contabilizar perdas por melhor esforço. Estado auxiliar também tem TTL e teto: não criar linhas indefinidamente para nomes/endereços arbitrários ou prolongar todos os estados em cada acesso. Só manutenção e expiração lógica liberam espaço, sem apagar registros válidos silenciosamente.

**Defaults candidatos, a validar na fase 8:** auditoria até 100.000 linhas ou 128 MiB de payload lógico; erros até 20.000 linhas ou 32 MiB; máximo 25 eventos de auditoria e 5 erros por requisição; ritmo inicial 60/20 gravações por segundo respectivamente, com comportamento de rajada limitado. Limitar resumo/evento a 2 KiB e mensagem de erro a 4 KiB. São pontos de partida configuráveis, não garantias de armazenamento físico; índices/overhead ocupam espaço adicional. Sites de maior volume precisarão dimensionar a política e acompanhar saturação.

**Referências:** D3, exemplos dbDelta/prepare e UPDATE/InnoDB; D6 para por que cache não é autoridade de replay.

**Verificação/saída:** instalação repetida e migração disputada; prefixo/collation alternativos; interrupção entre etapas; CAS em dois processos reais; erro de consulta não vira sucesso; limites exatos sob concorrência; sem SQL durante migração em cada evento; coleta falha sem bloquear site e acesso nega em falha. SQL e testes passam em MariaDB e MySQL da matriz.

**Guardas:** sem usermeta/options read-modify-write como lock de segurança, transients como prova, SELECT FOR UPDATE sem transação, valores de segredos em logs SQL, SELECT/COUNT ilimitado ou migração destrutiva automática.

## Fase 3 — Auditoria de ações e contexto de origem

**Como atende ao objetivo principal:** dá ao DevOps um histórico fiel das ações observáveis, com identidade e resultado sem capturar conteúdo ou credenciais.

**Depende de:** fase 2.

**Implementar:**

1. Copiar as assinaturas do mapa D4 para adaptadores pequenos por categoria. Registrar hooks e accepted_args exatos, transformando argumentos em um objeto de evento permitido. Não serializar objetos WP_User, WP_Post, WP_Error ou userdata.
2. Acessos/usuários: login, falha, logout explícito, criação/edição/exclusão, roles e capacidades individuais da chave correta. Dados de tentativa desconhecida não viram usuário autenticado. Registrar motivo de falha por código permitido, sem mensagem que possa carregar entrada sensível.
3. Administração: plugin ativado/desativado/excluído, tema trocado/excluído, instalações e updates de plugin/tema/core, inclusive lote. Examinar resultados por item e registrar falha/tentativa não confirmada onde o fluxo não comprova sucesso. Definir lista inicial explícita de options relevantes (ex.: URL do site, registro, papel padrão, leitura, comentários e permalinks), sem registrar valores e sem afirmar cobrir toda opção de todo plugin.
4. Conteúdo: posts/páginas/anexos criados, editados, enviados à lixeira/restaurados e excluídos; filtrar revisões/autosave e deduplicar hooks sobrepostos. Resumo com ID/tipo/ação, sem texto integral ou antes/depois.
5. Contexto HTTP/REST/admin/cron/CLI e UTC. Resolver IP por conexão direta; só consumir headers quando REMOTE_ADDR estiver na configuração de proxies confiáveis, com validação de cadeia IPv4/IPv6 e limites. Não aplicar reverse DNS ou GeoIP na escrita.
6. Ignorar estado interno, manutenção, transients e eventos gerados pelo próprio logger. Preservar o retorno original dos filtros de atualização. Construir fixtures dos caminhos observáveis e registrar lacunas em event-coverage.md.

**Referências:** D4, blocos Source dos hooks e pontos de emissão na tag fixada; D3 para escrita parametrizada; design D07/D08/D11.

**Verificação/saída:** eventos das quatro categorias gerados em WordPress real; autoria ausente em cron/CLI quando apropriado; logout usa argumento correto; lixeira não é exclusão física; update bulk parcial não vira sucesso total; silent/SQL direto documentados como lacuna; autosave/revisão não duplicam; cabeçalho forjado em acesso direto não troca IP; fixtures de senha/token/conteúdo não aparecem nos registros.

**Guardas:** não inventar added_user_cap/deactivated_theme, logar todo option/usermeta, classificar pré-evento como sucesso, supor wp_login cobre Application Passwords/SSO de terceiros ou prometer monitoramento de filesystem/banco fora das APIs.

## Fase 4 — Diagnóstico PHP e comportamento em falhas

**Como atende ao objetivo principal:** mostra erros observáveis com clareza, sem transformar o coletor em causa adicional de falhas ou alterar o comportamento do site.

**Depende de:** fase 2; usa contexto/normalização da fase 3.

**Implementar:**

1. Copiar as assinaturas do manual PHP D5. Registrar handler cedo o suficiente para plugins convencionais, mantendo coleta mínima até armazenamento estar disponível. Se necessário buffer em memória pequeno e limitado, sem arquivo público de fallback.
2. Conferir error_reporting antes de registrar; respeitar supressão, preservar chamada e retorno do handler anterior e permitir tratamento padrão quando apropriado. Não lançar exceção no lugar de warning nem capturar Throwable da aplicação para escondê-lo.
3. No shutdown, olhar error_get_last e gravidades fatais elegíveis, com proteção contra duplicação/reentrada. Coletar somente quando inicialização e recursos permitirem. Não reconstruir backtrace que não existe.
4. Reduzir caminhos, campos e comprimento; mascarar padrões conhecidos e informar que texto arbitrário pode conter dados sensíveis. Escapar na leitura. Fingerprint de erro por gravidade/local/mensagem normalizada, agregação com contagem e primeiro/último horário; definir janela curta para erros repetidos não manterem mensagens antigas indefinidamente além dos 30 dias.
5. Conter falhas próprias e registrar saúde por melhor esforço, com distinção entre ausência de erro, diagnóstico desativado/limitado, banco indisponível e capacidade atingida. Não prometer contador preciso durante falha do armazenamento.

**Referências:** D5, Parameters/Description/Return Values do manual; D3 para agregação atômica/limites e D4 para exclusão da recursão.

**Verificação/saída:** subprocessos de warnings/notices/deprecated/fatais capturáveis; handler anterior true/false/exception conserva comportamento; supressão é respeitada; WP_DEBUG não é alterado; duplicatas limitadas; cap não preenche toda a capacidade de auditoria; site continua com o comportamento original; erro anterior ao loader ou error_log arbitrário não é falsamente anunciado como capturado.

**Guardas:** sem leitura de arquivo apontado pelo navegador, publicação de debug.log, mudança de display_errors/status HTTP, promessa de todos os fatais ou teste de fatal derrubando o processo principal da suíte.

## Fase 5 — Autorização e 2FA obrigatório no StealthAudit

**Como atende ao objetivo principal:** restringe os registros à equipe selecionada e exige Authenticator sem alterar a forma de login do restante do WordPress.

**Depende de:** fases 1–2; integra sua própria auditoria pela fase 3. É pré-requisito para expor qualquer consulta de logs.

**Implementar:**

1. Política central de acesso com allowlist de IDs da configuração, usuário/cookie WordPress realmente validados e token de sessão presente/válido. Extrair token não basta. Não aceitar Application Password, papel administrator ou nonce isoladamente como substitutos da sessão exigida.
2. Máquina de estados explícita: sem sessão; não autorizado; reconfirmação necessária; cadastro pendente; fator ativo sem prova; prova válida; expirada/revogada; recuperação limitada. Sem estado comprovado, negar dados.
3. Cadastro: reconfirmar senha com wp_check_password, dar validade curta a essa reconfirmação (5 minutos), criar segredo pela biblioteca, envelope cifrado pendente por até 10 minutos, gerar URI e QR em memória no servidor, confirmar TOTP e só então ativar. Descartar pendência expirada; nenhuma rotina de analytics/cache/QR externo nas telas sensíveis.
4. Perfil TOTP: SHA-1, 6 dígitos, 30 segundos; clock PSR explícito. Verificar candidatos corrente e ±1 passo com timestamp explícito e sem confundir leeway com passos. Persistir o passo aceito na linha do fator e consumi-lo por CAS antes de criar prova. Dois requests com o mesmo passo só podem ter um vencedor; teste com os vetores RFC e clock congelado.
5. Regras de tentativa atômicas, por conta autorizada e IP confiável, também nas operações de senha/recovery: candidato inicial 10 tentativas por conta/5 minutos e 100 por origem/5 minutos, cooldown temporário e sem bloqueio permanente. Validar interação com NAT e concorrência. Falha de backend de limite impede nova autenticação.
6. Cifra sodium com key-id, nonce e AAD de instalação/usuário/versão. Chave de 32 bytes fornecida fora do banco, exemplo sem valor real, detecção de perda/tamper. Códigos recovery aleatórios de alta entropia, hashes e consumo CAS. Nada sensível em URLs, logs, exceções ou arquivos gerados.
7. Prova no servidor vinculada ao ID, derivação do token específico e factor_generation. last_activity e authenticated_at impõem 30 minutos/8 horas; verificar também a sessão WP e allowlist em cada consulta. Polling de status não renova atividade. Logout, revogação, reset e geração alterada invalidam prova.
8. Código recovery mais senha permite somente novo cadastro; invalidar generation/provas/códigos antigos, confirmar novo TOTP antes dos dados e emitir novo conjunto. Persistência falha não permite acesso parcial. Troca de fator pede fator atual ou recovery controlado, não desabilitação.
9. Procedimento DevOps fora do painel, por CLI/script operacional local documentado e restrito: backup da chave, migração com key-id antigo/novo, recifra por lote validada antes de remover chave antiga, reset de fator de um operador e revogação de sessões. Não oferecer bypass HTTP. Chave perdida sem backup exige reset controlado e recadastro; não há recuperação mágica do segredo.
10. Proteger operações por CSRF, métodos corretos e limites de entrada. Emitir eventos de cadastro/acesso/falha/recuperação sem credenciais. Registrar apenas resultado/identidade/contexto permitido.

**Referências:** D6, APIs WordPress, OTPHP TOTP.php, Bacon Writer/SVG, exemplos sodium, RFC 6238 e OWASP MFA; D3 para CAS. Não copiar o exemplo de QR remoto do OTPHP.

**Verificação/saída:** replay e recovery com dois processos concorrentes; sessão e factor_generation cruzadas; expiração de idle/absoluta com clock; credencial WordPress válida sem 2FA negada; app password sem sessão negada; estado/limite/chave indisponível negam painel sem parar o site; troca/recuperação não acessam logs antes do novo fator; QR compatível testado em Authenticator real; senha/URI/código nunca nos registros.

**Guardas:** sem integração global ao authenticate/wp-login, flag MFA confiada ao cliente, token bruto em banco/logs, cache como autoridade, chave nas opções, JWT/SSO desnecessários ou sessão de recovery equivalente a sessão de consulta.

## Fase 6 — Endpoint, API e interface de consulta

**Como atende ao objetivo principal:** permite ao DevOps encontrar ações e erros sem depender das telas administrativas ou do tema do site.

**Depende de:** fases 3–5. A rota pode existir antes como tela bloqueada; consultas só após a política completa.

**Direção visual obrigatória:** ler a [especificação ASCII Terminal](../specs/2026-11-07-frontend-ascii-terminal.md), a skill Hallmark instalada e seu tema terminal. Usar scope app, sem sorteio de tema, hero ou imagens decorativas. Preservar 2FA, recursos locais e semântica HTML.

**Implementar:**

1. Copiar o padrão add_rewrite_rule/query_vars com template próprio para /stealth-audit/ e fallback de query var para permalinks simples. Centralizar geração de URLs e separar transporte para futura opção de subdomínio. Resolver rota sem colisão com conteúdo existente e suportar WordPress em subdiretório.
2. Atualizar regras uma vez após instalação ou mudança real de configuração, via fluxo MU controlado/lease; nunca flush em toda requisição. Documentar fallback e falha do servidor em resolver o caminho amigável.
3. Expor namespace REST stealthaudit/v1 com controladores pequenos. GET de eventos, erros e estado autorizado; POST para operações de autenticação. Cada permission_callback usa o estado exigido pela operação, nonce/CSRF quando aplicável e validação do cookie/sessão. Nenhuma rota de schema ou callback auxiliar revela dados privados.
4. Telas: entrada/reconfirmação, cadastro/desafio/recovery, Auditoria, Erros e estados de coleta. Sem sessão WP, redirecionar ao login existente com retorno local validado; sessão StealthAudit expirada pede código, preservando filtros sem exibir dados em cache.
5. Consultas com filtros do design, 50 linhas por padrão e máximo 100, cursor estável por tempo/id e limites de período/parâmetro no servidor. Não executar totalizadores completos a cada carregamento. GeoIP quando disponível será encaixado na fase 7.
6. HTML sem dependência do tema WordPress, CSS/JS locais, labels e foco de teclado, mensagens compreensíveis, fuso explícito. Aplicar Hallmark Terminal: tokens centralizados, Martian Mono local ou fallback, paleta verde escura, ASCII simples, bordas retas, abas/tabelas em vez de cards de marketing. Severidade por texto, não só cor. Não mostrar “nenhum erro” quando captura estiver indisponível. Proteger páginas/API/QR contra cache público e fornecer no-store; escapar todas as mensagens e metadados.
7. Demonstrar estados carregando/vazio/erro/preenchido e estados de controles, além de cadastro/desafio/recovery/expiração. QR funcional com contraste/quiet zone, sem estilização ASCII. Layout entre 320–1920px, sem overflow da página, com tabela adaptável/região própria e reduced motion.
8. Gerar stamp/tokens/log Hallmark durante o build real e executar sloplint com --scope app --genre atmospheric --render, apontando --url para a rota de teste e verificando também HTML renderizado onde PHP limitar análise estática. Corrigir FAIL e corrigir/justificar REFLEX, sem afirmar renderização se faltarem pré-requisitos. Não persistir segredos reais em capturas de revisão.

**Referências:** D2, exemplos de roteamento e REST Permissions Callback/Controller Pattern; D3 para paginação/SQL; design/revisão U1–U2; especificação visual e Hallmark SKILL.md, flow.md/App screens e references/themes/terminal.md.

**Verificação/saída:** fluxo real pelo navegador de login WP até desafio e filtros; 401/403/503 coerentes sem dados; acesso direto REST sem segundo fator bloqueado; CSRF, XSS em log, redirecionamento externo e filtros malformados rejeitados; subdiretório/permalinks simples/tema WordPress trocado; nenhum menu wp-admin; endpoint pode mudar sem URLs fixas; respostas protegidas mesmo atrás de cache configurado em teste. Tema ASCII Terminal consistente, QR escaneável, teclado/contraste/mobile verificados e resultados Hallmark estáticos/render documentados sem contagens inventadas.

**Guardas:** sem __return_true em dados privados, confundir nonce com 2FA, incluir segredo em HTML antes de autorização, assets externos, login separado com outra senha ou implementar CORS/cookies de subdomínio antecipadamente.

## Fase 7 — Retenção, GeoIP e operação do mu-plugin

**Como atende ao objetivo principal:** mantém os registros úteis dentro dos prazos e permite ao operador manter a instalação saudável sem infraestrutura adicional obrigatória.

**Depende de:** fases 2–6.

**Implementar:**

1. Copiar WP-Cron com wp_next_scheduled antes de agendar, tratar WP_Error e usar args estáveis. Lease por tarefa, lotes iniciais de 500 registros, exclusão ordenada pelos índices, máximo de lotes/tempo por execução e retomada sem varrer toda a tabela. Limpar estado expirado sem apagar fator ativo ou limite ainda relevante.
2. Auditoria vencida em 365 dias e erros em 30 nunca aparecem nas consultas, mesmo antes do expurgo. Agregação de erro não pode manter indefinidamente um bucket antigo; rollover/janela e limite máximo de idade precisam de testes. Contadores de capacidade reconciliados com trabalho limitado e sem bloquear escrita por longos períodos.
3. Estado de manutenção: última execução, pendência e limitação observável. Documentar WP-Cron dependente de tráfego, cron servidor opcional, backups com política própria e ausência de garantia de apagar cópias externas.
4. GeoIP: copiar Reader local D7, abrir no máximo uma vez por consulta e resolver apenas IPs válidos, únicos e exibidos naquela página, com teto de 100. Base fora do webroot, caminho apenas de configuração; checar metadata, integridade/erros e idade conforme política do operador. País/região/cidade aproximados e possivelmente nulos; marcar como estimativa baseada na base atual, não localização histórica no instante do evento.
5. Sem base, registro ou atualização válida, manter IP e indicar localização indisponível. Não efetuar chamadas à API web e não persistir cópias históricas da geolocalização. Operador fornece a base licenciada e usa seu processo de download/substituição atômica; documentar atribuição/termos e não armazenar chave de download em registro do plugin.
6. Runbooks para instalação MU, configuração segura, atualização de arquivos/migração, backup/rotação da chave, recuperação, desativação emergencial via loader e remoção explícita dos dados. Não limpar logs automaticamente só porque o loader foi removido. Explicar visibilidade real na seção Must-Use e preservar listagem de outros plugins.

**Referências:** D7, Reader/City Example e termos do fornecedor; [wp_schedule_event](https://developer.wordpress.org/reference/functions/wp_schedule_event/), [WP-Cron](https://developer.wordpress.org/plugins/cron/); D2 para lifecycle MU.

**Verificação/saída:** prazo exato na consulta, atraso e retomada do cron, disputa de dois workers, estados auxiliares preservados/expirados corretamente; erro recorrente não retém bucket de 31 dias; GeoIP válido/ausente/corrupto/desatualizado/IPv6/sem resultado, sem saída HTTP; medir tamanho/tempo/memória da base real antes de publicar números; atualização e rollback operacional sem perder provas de chave/limites.

**Guardas:** sem base MMDB no ZIP, aceite de licença em nome de terceiros, GeoIP no caminho de escrita, loop de limpeza infinito, endpoint de apagar tudo, esconder problemas via contagens adulteradas ou requerer SSH/cron servidor para funcionar.

## Fase 8 — Verificação completa, desempenho e pacote de distribuição

**Como atende ao objetivo principal:** prova o fluxo que o DevOps usará e entrega um pacote reproduzível com limites reais e instruções suficientes para terceiros.

**Depende de:** fases 0–7. Nenhum release público antes da saída desta fase.

**Implementar/validar:**

1. Rodar suítes unitária, integração WP, concorrência real, subprocessos de erro e navegação. Matriz WP fixada × PHP 8.3/8.4/8.5; MariaDB base e MySQL da segunda família. Declarar suporte somente onde passar; runner PHPUnit/infra incompatível não equivale a produto testado.
2. Medir baseline sem loader e com ele, aquecido, mesma máquina/carga; 10 mil/100 mil/1 milhão de registros sintéticos e bursts concorrentes. Massa de benchmark em ambiente isolado pode elevar caps explicitamente; a configuração padrão não deve ser burlada em teste de capacidade.
3. Metas iniciais de referência: p95 de acréscimo <=20 ms para ação com um evento e banco saudável; memória adicional <=5 MiB no caminho de coleta; consulta paginada de 100 registros <=500 ms no banco e painel aquecido <=2 s; lote de limpeza de 500 linhas <=250 ms na referência. Medir com/sem GeoIP separadamente. São critérios de benchmark local, não SLA universal. Se falhar, otimizar ou registrar novo limite/suporte antes do release, sem afirmar meta cumprida.
4. Injetar banco lento/indisponível, disputa de estado, cron atrasado e tempestade de warnings. Relatar tempo real de falha: a chamada SQL síncrona do WordPress pode bloquear além do orçamento; não prometer deadline que a API não oferece. Após falha observada, limitar novas tentativas daquele request; nunca adicionar fila infinita/retry no front-end.
5. Criar build limpo a partir dos lockfiles, com dependências de produção prefixadas por StealthAuditVendor usando PHP-Scoper isolado. Regenerar autoloader e testar classes/strings/QR/TOTP/GeoIP no pacote transformado e com outro plugin que carregue versões diferentes das bibliotecas. Não escopar classes/funções WordPress indiscriminadamente.
6. Construir ZIP com mu-plugins/stealthaudit.php e mu-plugins/stealthaudit/{bootstrap.php,src,assets,templates,languages,vendor,...}. Incluir versão, licenças e notices. Não incluir .env, configuração real, chaves, código recovery, MMDB, fixtures sensíveis, testes, node_modules ou ferramentas de desenvolvimento. Gerar checksum e lista de conteúdo.
7. Composer validate/audit/check-platform-reqs/licenses, lint/análise estática e Plugin Check como apoio; justificar diferenças MU em vez de alterar o produto para satisfazer listagem convencional. Verificar padrões proibidos no código e revisar ocorrências manualmente, sem tratar grep como auditoria de segurança completa.
8. Instalar o ZIP em WordPress limpo sem Composer/Node no host e executar o fluxo: configurar operador/chave → cadastro TOTP → gerar ações/erros → consultar com filtros → expirar/revogar → recuperar → executar retenção → atualizar pacote. Repetir pelo artefato, não apenas pelo source tree.
9. Publicar docs de instalação, cobertura, privacidade, capacidade, desempenho, compatibilidade, manutenção e recuperação; README, LICENSE final, CONTRIBUTING e guia para relatar vulnerabilidades. Preparar 0.1.0 revisável e seus checksums; criar remoto, publicar release ou fazer deploy somente quando fizer parte do pedido do usuário.

**Referências:** D8, comandos Composer/PHP-Scoper e testes do core; D1 para matriz; D3/D6 para replay e falha fechada; condições S1–S3/G1–G3/U1–U2 da revisão.

**Comandos previstos após configurar scripts/runner nas fases anteriores:**

```text
npm ci
npx wp-env start --config=.wp-env.tests.json
npx wp-env run cli --config=.wp-env.tests.json --env-cwd=wp-content/mu-plugins/stealthaudit composer install
npx wp-env run cli --config=.wp-env.tests.json --env-cwd=wp-content/mu-plugins/stealthaudit composer validate -- --strict
npx wp-env run cli --config=.wp-env.tests.json --env-cwd=wp-content/mu-plugins/stealthaudit composer audit -- --locked
npx wp-env run cli --config=.wp-env.tests.json --env-cwd=wp-content/mu-plugins/stealthaudit composer check-platform-reqs
```

Os scripts de teste/build são contratos novos a criar e documentar, não APIs de ferramenta já existentes: `test:unit`, `test:integration`, `test:concurrency`, `test:e2e`, `quality`, `benchmark`, `package` e `verify-package`. Os comandos definitivos precisam funcionar em PowerShell e CI; registrar saída e versões em docs/testing/verification.md.

**Verificação/saída:** artefato instalável, matriz e cenários comprovados, nenhum bypass conhecido nos fluxos testados, limitações explícitas, dependências/licenças inventariadas e resultados de desempenho anexados. Sem testes executados nesta etapa de planejamento; não converter esta lista em afirmação de qualidade já demonstrada.

**Guardas:** não remover dependências dev do workspace para empacotar; não copiar build antigo, usar credenciais reais nos testes ou declarar suporte/benchmark sem evidência; sem GitHub Actions/remoto presumidos para concluir validação local.

## Ordem de execução e passagem entre fases

Ordem recomendada: 0 → 1 → 2 → 3 → 4 → 5 → 6 → 7 → 8. A fase 5 pode ser desenvolvida após 2 com stubs restritos, mas nenhum acesso a logs é liberado antes da política completa. GeoIP nunca bloqueia a funcionalidade básica.

Ao iniciar uma fase em contexto novo: ler este plano, design, fonte D correspondente, estado canônico e arquivos existentes; não recriar o que já foi concluído. Registrar no work_state_write a fase e os resultados reais de validação. Cada fase deve encerrar com código/testes/documentação coerentes e handoff curto: arquivos, comandos, evidência, falhas e próximo passo. Não usar a lista deste documento como substituto do estado de execução.

## Rastreabilidade das condições da revisão

| Condição | Fases que a encerram |
| --- | --- |
| S1 — Coleta não bloqueante; acesso nega em falha | 2, 5 e 8: persistência/limites indisponíveis não concedem dados. |
| S2 — Recovery restrito a novo cadastro | 5 e 8: consumo único, generation e novo TOTP antes dos logs. |
| S3 — Limites de capacidade/ritmo | 2, 4, 7 e 8: caps, contadores, reconciliação e carga. |
| G1 — Backup, rotação e perda de chave | 5, 7 e 8: runbook, key-id e testes de recuperação/migração. |
| G2 — Orçamentos e banco lento | 2 e 8: metas medidas e limites reais documentados. |
| G3 — Base GeoIP/licença/tamanho | 0, 7 e 8: termos separados, medição e ausência sem falha de auditoria. |
| U1 — Sessão WordPress versus prova do painel | 5–6 e 8: estados e redirecionamentos testados. |
| U2 — Lista vazia versus captura indisponível | 4, 6–8: estado real de captura e mensagens sem falsa garantia. |

## Adiamentos explícitos

Painel centralizado; subdomínio funcional; SSO/login independente; Multisite; integração específica WooCommerce; diffs; arquivos remotos/SIEM obrigatórios; instalador convencional; promessa de ocultação total; proteção contra administrador executando PHP; importador genérico de debug.log; coleta completa de logs do servidor; publicação/deploy automáticos.
