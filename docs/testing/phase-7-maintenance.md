# Manutenção, retenção física e estado observável (fase 7a)

Verificado em 2026-11-08 no ambiente de testes da [fase 1](local-setup.md): WordPress 7.1.4, PHP 8.3.36, MariaDB 10.11/InnoDB, PHPUnit 9.6.38, Chrome 157. MySQL e PHP 8.4/8.5 continuam sem teste. Todos os dados são sintéticos. GeoIP e runbooks pertencem à fase 7b.

## O que foi implementado

`Runtime::initialize_maintenance()` (`plugins_loaded`, prioridade 30, só com os pré-requisitos de coleta) registra `Bootstrap\Scheduler`:

- o handler `stealthaudit_maintenance` é registrado em toda requisição, para o executor do WP-Cron sempre encontrá-lo;
- em `init`, com armazenamento pronto, `wp_next_scheduled( 'stealthaudit_maintenance', array() )` antes de `wp_schedule_event( time(), 'hourly', …, array(), true )`. Argumentos estáveis (sempre `array()`), então um evento existente, mesmo atrasado, é mantido e nunca duplicado. `WP_Error` (veto de `pre_schedule_event`, falha ao gravar a option) só significa "tentar na próxima requisição": nada é lançado na página;
- o handler executa uma passada `Maintenance::run()` e engole falhas; o resultado fica no registro de status, não na resposta.

`Storage\Maintenance::run( $now, $max_batches = 20, $seconds = 2.0 )`:

1. **Lease** `lease:<sha256>` no estado InnoDB, TTL 120 s. A linha é fixa: criada pelo instalador do esquema (expirada, dono vazio) e excluída do expurgo de estado, então a manutenção nunca precisa de capacidade livre para rodar. Expirada → `replace_expired`; liberada (dono vazio) → CAS por revisão; viva com outro dono → `busy`, sem trabalho; sem a linha (instalação anterior a esta correção) → `create`. Antes de cada lote e antes de gravar o status o worker confere que ainda é o dono; ao fim libera por CAS.
2. **Retenção física** igual à das consultas: auditoria `occurred_at <= agora − 365 d`, erros `first_seen <= agora − 30 d` (a consulta mostra `> agora − prazo`, portanto o expurgo apaga exatamente o que já é invisível). `DELETE … ORDER BY (tempo,id) LIMIT 500`, lote a lote. O índice `(tempo,id)` é o ponto de retomada: cada lote começa no início da faixa vencida, sem cursor e sem varrer a tabela (`EXPLAIN DELETE`: `type=range`, `key=occurred` / `key=first_time`).
3. **Estado auxiliar**: `purge_expired_state` (fase 2) em lotes de 500, só `proof:`/`limit:`/`lease:` com `expires_at <= agora`, exceto a lease da manutenção. Fatores, orçamentos e o status não têm TTL e nunca são selecionados; prova, limite e lease ainda válidos também não.
4. **Recontagem do estado auxiliar** num único `SELECT COUNT(*)` limitado a `state_rows + 1` linhas, publicada por CAS no `budget:state`. `create()` reserva o slot por CAS e insere no comando seguinte, sem marcador pendente; por isso o orçamento é lido, lido de novo 100 ms depois e só então contado. Revisão diferente entre as leituras → adia para a próxima execução; um `create()` anterior à primeira leitura teve 100 ms para inserir; um posterior à segunda faz a CAS falhar.
5. **Recontagem de eventos/erros** por cursor de id em lotes de 500, com a soma parcial (`id`, linhas, bytes e margem) persistida no status entre execuções. Uma leitura consistente pode passar por um id cujo INSERT só confirma depois; todo escritor reserva antes do INSERT e conclui depois dele. Por isso, em cada lote que não é o último, o orçamento é lido antes e depois da varredura, e a margem soma as reservas recentes pendentes na primeira leitura mais as linhas/bytes reservados entre as duas (bytes das pendentes contados pelo teto por registro: 1.024 para auditoria, 1.536 para erros). O lote que alcança o fim lê o orçamento **antes** de varrer: reserva posterior muda a revisão e a publicação falha (vira um novo lote); reserva recente pendente nessa leitura adia a publicação. A publicação grava linhas e bytes contados **mais a margem** (nunca abaixo do que está em uso), move `dropped` para `dropped_before`, zera `dropped` e marca `maintained_at`.
   **Pendências envelhecem sozinhas.** `Budget` guarda, além do total `pending`, `pending_by_minute` (minuto UTC → reservas abertas, no máximo 16 minutos; `complete()` usa o mesmo `$now` da reserva e baixa o próprio balde). Só baldes com menos de 900 s bloqueiam a publicação; os mais antigos são de requisições mortas (crash entre reserva e conclusão, herdado da fase 2) e são zerados na publicação (`pending_reset`), por mais tráfego que o site tenha. Um pendente recente é tentado de novo até 3 vezes na mesma execução, com 25 ms de espera, antes de virar `blocked`. Orçamentos gravados antes dos baldes usam a regra antiga (nenhuma reserva por 900 s).
6. **Status** `maintenance:status` (linha fixa criada pelo instalador com `{"result":"never"}`, sem TTL, contada na capacidade auxiliar): `ran_at`, `result` (`complete`, `partial`, `blocked`, `failed`), `backlog`, `blocked`, lotes, expurgos por tipo e cursores. Sem a linha e com orçamentos já mantidos, o relatório diz `unavailable`, não `never`.

Cada execução para em 20 lotes ou 2 s (checados entre lotes; um `DELETE` em andamento não é interrompido). Não há loop sem limite: um lote que falha num CAS conta como lote. Não existe endpoint de "apagar tudo"; a manutenção não tem rota HTTP.

### Esquema versão 2

Erros paginavam por `first_seen` sem índice. A versão 2 acrescenta `first_time (first_seen,id)` e `severity_first (severity,first_seen,id)`, mantendo `seen`/`severity_time`. A paginação da 6a continua por `(first_seen,id)`; trocar para `last_seen` mudaria o significado do cursor. A migração reaproveita o instalador da fase 2: versão `1` ≠ `2` → lease do esquema → `dbDelta` (que só adiciona os índices que faltam) → `verify()` com os índices novos → publicação condicionada à posse da lease. Repetir é idempotente.

## Estado de captura e manutenção na API e na UI

`GET /status` (com prova válida) e as listas `/events` e `/errors` devolvem, além de `capture`, um objeto fechado:

```json
"maintenance": { "state": "current", "last_run": "2026-11-08T13:05:12Z", "dropped_before": { "events": 0, "errors": 4 } }
```

`state` ∈ `never` (nunca executou), `current`, `backlog` (parou no limite e continua na próxima), `blocked` (recontagem adiada por gravação em andamento), `overdue` (última execução há mais de 3 h; o WP-Cron depende de visitas), `failed`, `unavailable` (status malformado). `dropped_before` vem só do contador gravado; ausente é `null`, nunca zero inventado. Sem prova, `maintenance` é `null`, como `capture`.

Depois de uma manutenção completa a captura volta a `available` (o `limited` da fase 6 era cumulativo até aqui); as perdas do período encerrado continuam visíveis em `dropped_before` e na UI, sem contagem adulterada. A UI acrescenta uma linha discreta abaixo da captura: "Manutenção: última execução 2026-11-08T13:05:12Z; em dia" e, se houver, "N descartados antes da última manutenção". Os valores `dropped_before` só cobrem o período anterior à última execução; períodos mais antigos não são acumulados.

## Hardening herdado da revisão 6c

1. **CORS do core removido no namespace.** O WordPress ecoa qualquer `Origin` com `Access-Control-Allow-Credentials` em todas as rotas REST (`rest_send_cors_headers`, prioridade 10 em `rest_pre_serve_request`, inclusive no preflight `OPTIONS`). `Api::strip_cors` roda em `rest_pre_serve_request` prioridade 99 e remove `Access-Control-Allow-Origin` e `Access-Control-Allow-Credentials` só em `stealthaudit/v1`. A checagem e2e do atacante agora exige `fetch` com credenciais = `blocked` e confere GET e OPTIONS com `Origin` cruzado sem esses cabeçalhos; o índice `/` do core continua com CORS (controle).
2. **Oráculo 401/403 de login.** Além de o item 1 impedir a leitura, `Api::session_ended` só converte o 403 `rest_cookie_invalid_nonce` em 401 quando a requisição é de mesma origem (ou sem `Origin`/`Sec-Fetch-Site`), reutilizando `Guard::same_origin`. Com `Origin` cruzado fica o 403 do core.
3. **`fixture.php restore`** apaga o `.htaccess` criado durante a execução quando o snapshot não tinha um, e `$left` compara o `.htaccess`.
4. **`run.mjs`**: `setUp` é marcado antes do `setup` (teardown/restore são no-ops sem snapshot), e um `snapshot.json` deixado por execução interrompida não é mais apagado no início: o roteiro para e imprime o comando de recuperação.

## Testes

`tests/integration/MaintenanceTest.php` (InnoDB real, prefixo isolado):

- **Prazo exato e lotes**: 1.100 eventos vencidos, um exatamente em `agora − 365 d` e um em `agora − 365 d + 1`; erros nas mesmas três posições para 30 d. A consulta só mostra o `+1` antes de qualquer expurgo. Um lote por execução apaga exatamente 500, os mais antigos (o menor restante é `corte − 600`); orçamento de tempo já esgotado executa 0 lotes; a execução seguinte termina (`complete`) com 101 eventos e 2 erros, sobrando só as linhas `+1`. `EXPLAIN DELETE` confirma `range` pelos índices. Limite de lotes > 100 é recusado.
- **Disputa de dois workers**: dois processos PHP com conexões próprias, liberados juntos, sobre 3.000 eventos vencidos: resultados `busy` e `complete`, tabela vazia. Lease viva de um "worker morto" → `busy`; ao vencer (120 s) outra execução assume. Cinco repetições seguidas do arquivo passaram.
- **Cron atrasado**: `wp_schedule_event` agendado 2 h no passado é mantido por `schedule()` (mesmo horário, um evento); uma execução atrasada apaga 700 eventos de 400 dias e 3 erros de 40 dias. `report()` diz `current` até 3 h e `overdue` depois; sem execução, `never` com `dropped_before` nulo.
- **WP-Cron**: agenda uma vez, `hourly`, sem duplicar; veto `WP_Error` → `false` sem exceção e nada agendado; armazenamento indisponível → `schedule()` falso e `run()` silencioso; `has_action` confirma o handler do Runtime real.
- **Estado auxiliar**: limite/prova/lease vencidos são apagados; limite e prova vivos (com contagem intacta) e o fator permanecem; o contador sobrecontado em +50 volta a 8, igual às linhas físicas.
- **Erro recorrente**: a mesma mensagem em `bucket`, `bucket+299` (mesma linha, 2 ocorrências), `+300`, `+1 dia` e agora, começando 31 dias atrás: quatro linhas, nenhuma com `last_seen − first_seen ≥ 300 s`; só a de agora aparece na consulta e o expurgo remove as três antigas.
- **Reconciliação limitada**: 1.200 linhas fora do orçamento e contador inflado (5.000 linhas, 7 perdas). Uma execução de 6 lotes guarda o cursor em 1.000 linhas/100.000 bytes sem publicar (`backlog`); um evento gravado depois do cursor entra na soma; a execução seguinte publica 1.201 linhas, bytes exatos, `dropped` 0, `dropped_before` 7. Reserva em andamento → `blocked` e contador intocado; 900 s depois, `pending` zerado e a sobrecontagem da reserva morta removida.
- **Pendente órfão em site movimentado** (correção 1): uma reserva morta e, depois, reserva+conclusão a cada minuto por 16 minutos (`second` sempre atualizado, no máximo 16 baldes). Aos 5 minutos a publicação é `blocked`; aos 16, `complete`, com `pending` 0, baldes vazios e `pending_reset`. A regra antiga nunca teria liberado.
- **Pendente recente**: uma reserva que nunca conclui custa exatamente 3 novas tentativas (9 lotes) antes de `blocked`. Com dois processos, uma reserva concluída 150 ms depois do início da execução resulta em `complete` na mesma execução.
- **Margem da recontagem**: ids 1..1200 sem o 100 e uma reserva aberta. Uma execução de 5 lotes guarda o cursor no id 501 com margem de 1 linha e 1.024 bytes. O id 100 é então inserido atrás do cursor e a reserva concluída. A publicação fica com 1.200 linhas, igual às físicas (a varredura viu 1.199), e bytes ≥ os físicos. No teste de reconciliação, a execução bloqueada deixou uma margem de 2 (sobrecontagem temporária), que a recontagem seguinte desfaz (1.201 exatos).
- **Capacidade auxiliar cheia**: com `state_rows = 5` (só as linhas fixas) nenhuma prova pode ser criada, mas a manutenção roda, o relatório passa de `never` a `current`, e sem a linha de status com orçamentos mantidos o relatório diz `unavailable`.
- **Migração 1 → 2**: índices removidos e versão `1`: `verify()` recusa; dois processos executam `ensure()` em disputa, pelo menos um conclui, `verify()` passa; repetir `ensure()` (também forçando a versão `1`) não muda os índices; a paginação por cursor de 3 em 3 sobre 7 erros com tempos repetidos devolve os mesmos ids na mesma ordem antes e depois.

`tests/integration/HttpTest.php`:

- `maintenance` nulo sem prova (status), `never` com prova; captura `limited` (4 perdas) vira `available` após uma execução, com `dropped_before.errors = 4` e `last_run` ISO; o mesmo objeto em `/status` e na lista; status malformado → `unavailable`; após revogar, nulo.
- `strip_cors` registrado em 99 (core em 10), cabeçalhos exatos, só no nosso namespace, GET e OPTIONS.
- Sessão encerrada com `Origin` cruzado mantém 403 `rest_cookie_invalid_nonce`; com `Origin` do site, 401.

`tests/e2e/run.mjs` ganhou uma checagem e reforçou outra (34 no total): a checagem do atacante agora exige `blocked` e respostas GET/OPTIONS com `Origin` cruzado sem CORS (com um OPTIONS do índice do core como controle, que mantém o cabeçalho); a nova checagem roda a manutenção pelo executor real `wp cron event run stealthaudit_maintenance`, com `/status` `current`, `last_run` ISO, captura `available` e a linha "Manutenção: … em dia" na página.

## Medidas

Medição avulsa (script de desenvolvimento fora do repositório, tabelas com prefixo isolado e apagadas ao fim) no mesmo contêiner, com os limites padrão: 20.000 eventos vencidos e 5.000 dentro do prazo. Primeira execução: 20 lotes, 10.000 linhas apagadas em 112 ms; segunda: 20 lotes, 10.000 linhas em 123 ms (cerca de 6 ms por lote de 500); terceira: 16 lotes (3 de expurgo vazios, 1 contagem auxiliar, 11 de recontagem de 5.000 eventos e 1 de erros) em 23 ms, `complete`, orçamento publicado com 5.000 linhas e 500.000 bytes. O pico de memória do processo `wp eval-file` inteiro foi 54 MB; não é um número da manutenção isolada. Os tempos são de um MariaDB local em Docker sem carga concorrente; hospedagem compartilhada pode ser bem mais lenta, e por isso o limite é por lotes e por tempo, não por linhas.

## Suítes

Execução final depois da correção 1 (unidade, integração, qualidade, e2e):

- `npm run test:e2e`: `34 passed, 0 failed`, cerca de 6 minutos (346 s somando as checagens). Checagem 15: `browserRead: "blocked"`, GET 401 e OPTIONS 200 sem `Access-Control-Allow-Origin`/`-Credentials`. Checagem 16: evento agendado encontrado, `never` → `current`, linha "Manutenção: última execução 2026-11-08T13:42:30Z; em dia". Checagem 32: 268 respostas privadas, nenhuma armazenável. Checagem 34: o console da página do atacante (que registra o bloqueio CORS esperado) deixou de contar como página do painel; zero problemas nas páginas do painel. O `restore` conferiu `wp-config.php`, `.htaccess`, options, usuários, estado e registros.
- `npm run test:unit`: 200 testes, 580 asserções, 1 skip (XMLWriter, fase 5).
- `npm run test:integration`: 77 testes, 2.103 asserções (11 em `MaintenanceTest`, 4 deles da correção 1; 2 novos e 2 ampliados em `HttpTest`). `MaintenanceTest` passou 5 vezes seguidas isoladamente na primeira entrega; depois da correção 1, `MaintenanceTest`+`StorageTest`+`DiagnosticsTest` passaram 3 vezes seguidas.
- `npm run quality`: lint, WPCS/PHPCompatibilityWP e PHPStan nível 6 sem erros (o aviso de versão raiz do Composer é do ambiente).

Durante o desenvolvimento duas execuções e2e falharam e foram corrigidas no roteiro, não no plugin: o controle de CORS era um GET armazenável pelo cache de teste (checagem 32) e o console da página atacante registrava o bloqueio CORS agora esperado (checagem 34).

Efeitos no site de testes: o esquema migrou para a versão 2 e o evento `stealthaudit_maintenance` ficou agendado (comportamento normal do plugin). A suíte de integração continua gravando eventos/erros nas tabelas do próprio site ao criar usuários; as linhas de auditoria e de erro geradas nesta etapa (72 + 3 na entrega, mais 2 na correção 1) foram apagadas depois; os contadores de orçamento desse site ficam sobrecontados até a próxima manutenção.

## Operação e limitações

- **WP-Cron depende de tráfego.** Sem visitas o evento atrasa; a consulta continua escondendo o vencido e o painel mostra `overdue` após 3 h. Cron de servidor é opcional: `define( 'DISABLE_WP_CRON', true )` e chamar `wp cron event run --due-now` (ou `wp-cron.php`) pelo agendador do host. Nada exige SSH.
- **Vazão**: 20 lotes de 500 por hora, até 10 mil linhas apagadas por execução; o expurgo vem antes da recontagem, então um acúmulo grande (ex.: 100 mil eventos vencidos de uma vez) leva cerca de 10 execuções antes de a recontagem rodar. Até lá a capacidade continua conservadora (sobrecontada), nunca subcontada pelo expurgo.
- **Limites do argumento de não subcontagem**: a margem cobre escritores que reservam antes do INSERT e concluem depois dele, que é o caso de todo `append()`. Ela supõe que nenhuma requisição viva fica mais de 900 s entre reserva e conclusão (essa reserva é tratada como morta). A margem é sobrecontagem temporária: some na recontagem seguinte sem gravações em curso. No estado auxiliar, a garantia supõe que o INSERT de um `create()` não demora mais de 100 ms depois da sua CAS. Nada disso afeta autorização, só a margem de capacidade.
- **Linhas apagadas atrás do cursor** durante uma recontagem em várias execuções ficam sobrecontadas até a próxima recontagem completa (conservador).
- **`pending` recente** (menos de 900 s) adia a publicação daquele tipo após 3 novas tentativas na execução; um pendente órfão deixa de bloquear depois de 15 minutos, mesmo com gravações contínuas. A UI mostra "recontagem adiada" enquanto isso.
- **Backups e cópias externas** seguem a política do operador: o expurgo físico não alcança dumps, réplicas, binlogs ou backups do host, e o plugin não promete apagá-los.
- **Remoção do loader** deixa o evento WP-Cron agendado sem handler (o WordPress apenas o reagenda); os dados não são apagados por isso. A remoção explícita fica para os runbooks da 7b.
- O `ensure()` desta versão migra automaticamente o site existente para o esquema 2 na primeira requisição; durante a migração (lease de 120 s) uma requisição concorrente fica sem armazenamento e não coleta.
- Não testado: MySQL, PHP 8.4/8.5, tabelas reais de 100 mil linhas sob carga de escrita simultânea, hospedagem com `DISABLE_WP_CRON` real.
