# StealthAudit — especificação de design

Data: 2026-11-07. Estado: escopo e critérios validados pelo usuário; revisão estruturada concluída com disposição APPROVED. Não há implementação.

## Entendimento validado

Projeto open-source de audit log e diagnóstico PHP/WordPress para operação gerenciada por DevOps. Cada instalação terá coleta, armazenamento e painel próprios. O formato principal será mu-plugin. O público auditado tem acesso ao wp-admin e mantém instalação/upload de plugins e edição de código.

A proteção desejada é contra descoberta e desativação casual pelas telas administrativas. Não existe promessa de impedir um administrador de executar código deliberadamente para interferir na coleta. O login WordPress continua existente; o StealthAudit acrescenta autorização explícita e 2FA obrigatório exclusivamente ao seu painel.

Escopo inicial:

- Acessos: login, falha de autenticação e encerramento de sessão quando observável.
- Usuários: criação, exclusão e mudanças de permissões.
- Administração: instalação, atualização, ativação/desativação e remoção de plugins/temas; configurações e atualizações do core, conforme eventos observáveis.
- Conteúdo: criação, edição e exclusão de posts, páginas e mídias. Sem diffs ou cópias do conteúdo.
- Contexto: ator quando conhecido, horário, ação, objeto, origem e IP quando disponível; localização aproximada por consulta local opcional.
- Erros: mensagens PHP/WordPress semelhantes às do diagnóstico WP_DEBUG, dentro da cobertura possível e do nível configurado.
- Auditoria: retenção de 365 dias. Erros: retenção de 30 dias.
- Single-site; hospedagem compartilhada como base, com operação DevOps mais robusta opcional.

Fora da primeira versão: painel centralizado, suporte Multisite, eventos específicos WooCommerce, instalação convencional alternativa, diffs, armazenamento remoto obrigatório e acesso via subdomínio. A estrutura deve permitir escolher subdomínio em uma evolução futura.

## Premissas e limites aceitos

- A coleta não bloqueia ações do site em caso de falha própria; perdas podem ocorrer e devem ser sinalizadas quando observáveis.
- Não coletar senhas, tokens, cookies, corpos completos de requisições ou conteúdo integral dos objetos.
- Mensagens de erro são dados não confiáveis e podem conter informações sensíveis; reduzir, limitar e escapar antes de exibir. Não prometer mascaramento perfeito de textos arbitrários.
- Sem ativar WP_DEBUG automaticamente ou habilitar exibição de erros aos visitantes. A configuração do diagnóstico continua responsabilidade do operador.
- DevOps instala, configura, atualiza e recupera a instalação. O site deve ter meios documentados de remover/desabilitar o loader em incidentes operacionais.
- Não se promete escala ilimitada nem garantia de entrega. Matriz suportada e limites serão estabelecidos por testes antes do release.
- Ausência da base GeoIP não impede coleta; localização pode ficar indisponível. Não enviar os IPs auditados a APIs externas.
- O painel depende de WordPress, PHP e banco funcionais; não é um console de recuperação para um site que não inicializa.

## Alternativas e decisão de arquitetura

1. **Mu-plugin modular — escolhido.** Loader pequeno em mu-plugins, núcleo em subdiretório. Carregamento anterior aos plugins convencionais e ausência de desativação pelo fluxo comum do painel. Instalação, migrações e atualização precisam de processo próprio.
2. **Plugin convencional com all_plugins/views_plugins — preterido.** Distribuição por ZIP e atualização convencionais mais familiares; carregamento posterior e desativação ainda possível por outros caminhos. Pode ser considerado futuramente sem duplicar o núcleo.

Mu-plugins aparecem por padrão na seção Must-Use. all_plugins filtra a lista convencional e views_plugins os links de visualização; eles não são garantia de ocultação total de mu-plugins. A implementação deve documentar o comportamento real da versão suportada. Qualquer ajuste de apresentação deve afetar apenas o StealthAudit, preservando os demais plugins e contagens. O usuário aceitou mu-plugin como alternativa ao ocultamento completo; não é requisito tornar o código indetectável.

## Componentes e fluxo

Fluxo de auditoria: hook observado → normalização por esquema permitido → redução de dados → persistência → consulta autorizada.

Componentes:

1. Loader MU mínimo, sem dependência de hooks de ativação de plugin convencional.
2. Inicialização/migração versionada e idempotente, com exclusão mútua, evitando trabalho de esquema em cada evento.
3. Coletores de auditoria com mapa explícito entre hooks e eventos; distinguir tentativa, sucesso e falha quando essa informação existir.
4. Coletor de diagnóstico separado, preservando tratadores existentes e comportamento da aplicação.
5. Persistência em duas tabelas próprias no banco WordPress: eventos de auditoria e erros.
6. Autorização, cadastro TOTP, verificação de sessão e recuperação.
7. Interface do painel, consultas filtradas e paginadas.
8. Manutenção: retenção, saúde observável da coleta e limpeza em lotes.

Horários persistidos em UTC e apresentados com fuso explícito. Esquema de evento versionado; dados específicos são limitados por categoria, sem serialização indiscriminada de argumentos dos hooks. Não atribuir IP ou ator humano a cron/CLI sem evidência. Evitar duplicação por autosave/revisão e recursão da própria auditoria; casos observados devem ser documentados e testados.

## Painel e endereço

Direção de frontend adicionada por solicitação do usuário: **Hallmark com tema ASCII Terminal**, aplicada como app screen. Seguir a [especificação visual](2026-11-07-frontend-ascii-terminal.md): fonte monoespaçada, superfícies escuras com matiz verde, verde fósforo, bordas retas e detalhes ASCII discretos. Interface operacional com tabelas/filtros e estados acessíveis; não copiar layout de marketing nem alterar segurança/autenticação. Recursos, fontes e QR permanecem locais.

Endpoint HTTPS no domínio do site, por exemplo /stealth-audit/, independente do tema e sem menu no wp-admin. Caminho final e configuração serão detalhes da implementação; o endereço não é um segredo de autenticação.

Interface, geração de URLs, autorização e roteamento devem ser separados. URLs geradas a partir da configuração, respeitando instalações WordPress em subdiretórios. Essa separação permite futura escolha de subdomínio, sem construir agora uma aplicação distribuída. A evolução exigirá DNS, TLS, roteamento e decisão específica de autenticação entre origens; não ampliar automaticamente o escopo dos cookies WordPress.

Áreas propostas: Auditoria e Erros. Filtros de auditoria por período, usuário, categoria, ação e IP; erros por período e gravidade. Paginação com limite de página imposto no servidor. Consultas respeitam retenção e autorização independentemente da interface. Sem cache público das páginas e respostas sensíveis.

Estados da interface: sem sessão WordPress, encaminhar ao login existente e retornar ao endpoint por destino local validado; sem prova StealthAudit ou com prova expirada, solicitar o código com explicação clara. Distinguir resultados vazios de captura indisponível, limitada ou suspensa por capacidade. Ausência de registros nunca comprova ausência de erros, e expiração de sessão não significa perda de registros.

## Autorização e 2FA exclusivo do painel

O usuário utiliza sua sessão WordPress como primeiro fator. Ser administrador não concede acesso ao StealthAudit. A lista de usuários autorizados é definida pelo operador na configuração da instalação; não confiar apenas em capabilities que administradores possam conceder pela interface.

Todas as páginas, endpoints de dados e operações do painel exigem a política de acesso correspondente. Para consultas aos logs: usuário autorizado, sessão WordPress válida e sessão StealthAudit com segundo fator concluído. Cookies/nonce REST não substituem autorização ou a prova de segundo fator. Rotas de cadastro/desafio só permitem seus fluxos limitados e nunca retornam logs.

A tolerância a falhas vale para a coleta, não para o acesso: se não for possível verificar autorização, sessão, limite de tentativas ou consumo único do fator/código, negar o acesso ao painel e aos dados. Nunca transformar indisponibilidade do armazenamento de autenticação em bypass.

Fluxo aceito:

1. Primeiro acesso: autorização explícita, reconfirmação recente da senha WordPress no fluxo do StealthAudit, cadastro QR local e confirmação TOTP antes de liberar registros.
2. Segredo TOTP cifrado usando chave de instalação mantida fora do banco; usar biblioteca/protocolo consolidados, sem criptografia própria. Ausência da chave impede acesso ao painel, sem bloquear o site.
3. Códigos de recuperação de uso único, mostrados no cadastro, armazenados como hashes e consumidos atomicamente.
4. Limites de tentativas por conta e origem, sem bloqueio permanente explorável; impedir reutilização concorrente de TOTP já aceito. Parâmetros e tolerância de relógio serão fixados com testes.
5. Prova de 2FA vinculada ao usuário e à sessão WordPress específica, mantida no servidor. Não aceitar um indicador enviado pelo navegador como prova.
6. Expiração após 30 minutos de inatividade ou 8 horas totais, o que ocorrer primeiro. Polling automático não deve renovar inatividade. Logout, revogação de acesso e recuperação invalidam a prova; ela nunca sobrevive à sessão WordPress.
7. Troca do fator exige reconfirmação e fator atual ou recuperação controlada; não permitir desabilitar o 2FA obrigatório pela interface.
8. Perda do dispositivo: após confirmar a senha, consumir um código de recuperação atomicamente permite apenas cadastrar novo fator. Revogar provas de 2FA anteriores e o conjunto antigo de recuperação; exigir confirmação do novo TOTP antes de consultar logs e emitir novo conjunto de códigos. Sem códigos, DevOps usa procedimento fora do painel, revoga sessões e exige o mesmo novo cadastro. Não criar bypass por email ou por ser administrador.
9. Auditar cadastro, falhas, acesso e recuperação sem armazenar códigos, QR, segredo ou credenciais nos logs.

O 2FA protege o acesso ao painel dentro do modelo casual aceito; não protege contra execução arbitrária de PHP, controle da configuração/servidor ou comprometimento completo do WordPress.

## IP, localização e privacidade

IP da conexão por padrão. Cabeçalhos de encaminhamento somente se o operador declarar proxies confiáveis; validar cadeia, formato IPv4/IPv6 e limites. Quando não houver origem confiável, informar ausência/limitação em vez de inventar localização.

Consulta GeoIP local, sem transmitir IPs auditados. País/região/cidade somente quando presentes na base, sempre rotulados como estimativa; não identificar residência ou pessoa. Provedor, licença, instalação e atualização da base continuam pendências técnicas, sem dependência externa no caminho crítico de coleta.

Persistir apenas contexto permitido. Limitar mensagens de erro e apresentar caminhos de forma reduzida quando possível. Filtros e buscas usam consultas parametrizadas e validação; mensagens e metadados têm escape contextual, pois usuários e plugins podem introduzir texto malicioso nos logs.

## Captura de erros e falhas

Registrar gravidade, mensagem reduzida, arquivo/linha e horário quando disponíveis. Respeitar nível configurado e tratadores existentes, não suprimir erros da aplicação nem mudar resposta HTTP para preservar aparência de sucesso. Não pressupor que todo texto de debug.log é capturável por error handlers, incluindo chamadas error_log() arbitrárias.

Falhas anteriores ao loader, falhas de inicialização PHP/servidor, falta extrema de memória e indisponibilidade do banco podem impedir captura. Erros fatais serão coletados apenas quando os mecanismos de encerramento e os recursos disponíveis permitirem. Sem leitura pública do debug.log ou leitura de caminhos arbitrários enviados pelo cliente.

Proteção contra reentrada, limites por registro e trabalho limitado por requisição. Erros repetidos podem ser agregados com contagem e primeiro/último horário; sem ocultar silenciosamente truncamento ou descarte. Políticas numéricas de volume precisam ser medidas antes do release. Auditoria e erros têm políticas independentes, para uma tempestade de warnings não consumir toda a capacidade de registro de ações.

Antes do release, definir limites de capacidade por categoria e ritmo de gravação, com padrões conservadores, configuráveis e documentados. Ao atingir um limite, interromper novas gravações da categoria afetada em vez de crescer indefinidamente, mantendo contadores/sinalização por melhor esforço. Não apagar registros ainda dentro da retenção silenciosamente para abrir espaço. O operador deve poder acompanhar a limitação no painel. Isso não garante preservação do site se o banco/disco já estiver indisponível ou outros componentes esgotarem os recursos.

Falha de persistência não bloqueia o site e não gera uma cascata de logs da própria falha. Estado de saúde e contadores são melhores esforços; não podem comprovar ausência de perdas quando o armazenamento também falha. Não criar fallback em arquivo publicamente acessível.

## Retenção, desempenho e manutenção

Índices alinhados às consultas de período, tipo, ator e IP. Páginas pequenas, filtros limitados, exclusão em lotes e controle de concorrência da manutenção. Consultas não retornam registros vencidos, mesmo antes da exclusão física.

WP-Cron na hospedagem básica, cron de servidor opcional. Expurgo físico acontece no próximo processamento disponível; baixa visitação ou cron quebrado pode atrasá-lo. O operador precisa observar a última execução e volume pendente. Backups podem conter dados além do prazo e seguem política própria do operador; não prometer apagamento de backups pelo plugin.

Instalação e atualização devem ter instruções reproduzíveis, versão de esquema e recuperação operacional. Para mu-plugin, não depender de activation hooks. Nenhuma migração destrutiva automática sem estratégia de recuperação definida no plano de implementação.

## Validação e critérios de aceitação

- Testes de integração dos eventos e identificação correta de sucesso/falha, HTTP/cron/CLI e ausência de contexto.
- Página e API bloqueadas sem autorização/2FA; testes de sessão expirada, revogada, logout, nonce/CSRF e tentativa de acesso direto.
- Cadastro TOTP, confirmação, repetição concorrente, limite de tentativas, recuperação, hashes de códigos e perda de chave.
- Indisponibilidade da persistência de autorização, provas ou limites deve negar acesso; recuperação concorrente deve permitir apenas um consumo e nenhum acesso aos logs antes de confirmar novo TOTP.
- Nenhum segredo nos eventos/erros de teste; escape de mensagens maliciosas e validação de filtros, IPs e proxies.
- Coexistência com handlers, comportamento de warnings/fatais capturáveis, recursão e falha do banco sem interromper ação normal do site.
- Expiração lógica e limpeza em lotes nos prazos de 365/30 dias; retomada e concorrência de tarefas e migrações.
- Medir latência adicional, consultas, memória e crescimento de armazenamento em volumes representativos; números publicados, sem inventar benchmark antecipadamente.
- Exercitar capacidade e ritmo máximos, interrupção de gravações e contadores de descarte, sem expurgo silencioso de registros válidos.
- Matriz de PHP, WordPress e MySQL/MariaDB, bibliotecas TOTP/GeoIP e limites de suporte definidos no plano antes da implementação correspondente.
- Verificar o caminho de instalação/atualização MU, endpoint em subdiretório e ausência de efeitos sobre outros plugins.

## Registro de decisões

| ID | Decisão validada | Alternativa considerada e motivo |
| --- | --- | --- |
| D01 | Operação DevOps e proteção casual | Resistência a administrador executando código descartada pelo usuário. |
| D02 | Mu-plugin principal | Convencional oculto preterido por desativação casual e carregamento posterior. |
| D03 | Painel por instalação | Centralização fora da primeira versão, conforme escolha do usuário. |
| D04 | Endpoint agora; subdomínio possível depois | Não exigir DNS/TLS/integração entre origens no funcionamento básico. |
| D05 | Sessão WordPress e autorização explícita | Login separado/SSO não escolhidos. |
| D06 | TOTP obrigatório exclusivamente no painel | Não alterar login WordPress; usuário confirmou fluxo completo de cadastro, sessão e recuperação. |
| D07 | Resumos sem diffs | Reduzir dados coletados; usuário escolheu resumo por enquanto. |
| D08 | IP e GeoIP local quando disponível | Usuário pediu opção mais segura; não enviar IPs a serviços externos. |
| D09 | Auditoria 365 dias; erros 30 dias | Usuário escolheu retenções diferentes por finalidade. |
| D10 | Base em hospedagem compartilhada | Infraestrutura adicional opcional, sem dependência obrigatória. |
| D11 | Falhas não bloqueiam site; limites explícitos | Usuário aceitou perdas possíveis, cobertura limitada e validação por testes. |
| D12 | Tabelas locais, módulos separados, consultas limitadas | Proposta apresentada e consolidada no aceite dos critérios finais; nenhum serviço remoto obrigatório. |
| D13 | Hallmark, tema ASCII Terminal, scope app | Pedido explícito do usuário após o plano; skill nutlope/hallmark já instalada. Tema visual não altera escopo ou 2FA. |

## Pendências para o plano técnico

Não são requisitos de produto novos: versões mínimas, biblioteca TOTP e criptografia disponível, formato da configuração de operadores, regras detalhadas dos hooks, parâmetros de carga, limite de repetição, escolha/licença da base GeoIP, implementação exata da apresentação MU e política de distribuição/licença do código. Recomendações devem respeitar o escopo aceito; qualquer mudança material retorna ao usuário.

Condições da revisão de restrições: o plano deve definir backup/rotação/recuperação da chave TOTP, incluindo migrações; orçamentos de gravação/consulta/limpeza e comportamento com banco lento; licença, tamanho e atualização da base GeoIP. Ausência de chave nega acesso; ausência/falha da base de localização não impede auditoria.

## Revisão estruturada

Processo: brainstorming e multi-agent-brainstorming locais. Orçamento máximo: uma rodada sequencial de Skeptic, Constraint Guardian e User Advocate, uma consolidação de correções e uma avaliação final por Arbiter (quatro revisores reais). Caso haja bloqueadores restantes, encerrar como REVISE, com pendências explícitas; não implementar.

Revisões e resoluções registradas em [revisão estruturada](2026-11-07-stealthaudit-review.md). Quatro revisores reais foram invocados sequencialmente. Resultado final do Arbiter: APPROVED; S1–S3 resolvidas, G1–G3 registradas como condições do plano, U1–U2 incorporadas; nenhum bloqueador de design remanescente. Esta revisão não é certificação de segurança e não autoriza implementação.

## Referências técnicas consultadas

- https://developer.wordpress.org/advanced-administration/plugins/mu-plugins/
- https://developer.wordpress.org/reference/hooks/all_plugins/
- https://developer.wordpress.org/reference/hooks/views_this-screen-id/
- https://developer.wordpress.org/rest-api/using-the-rest-api/authentication/
- https://developer.wordpress.org/plugins/creating-tables-with-plugins/
- https://developer.wordpress.org/advanced-administration/debug/debug-wordpress/
- https://developer.wordpress.org/plugins/cron/
- https://www.php.net/manual/en/function.set-error-handler.php
- https://cheatsheetseries.owasp.org/cheatsheets/Multifactor_Authentication_Cheat_Sheet.html
- https://maxmind.github.io/GeoIP2-php/
