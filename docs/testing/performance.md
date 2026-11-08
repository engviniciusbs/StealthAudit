# Desempenho, capacidade e injeção de falhas (fase 8b)

Medido em 2026-11-08 numa única máquina de desenvolvimento. São critérios de benchmark local, **não SLA**: outra máquina, outro banco ou outra carga dão outros números. Cada número abaixo vem de uma execução registrada em `artifacts/benchmark/results.json` (benchmark) ou `artifacts/fault-injection/results.json` (injeção de falhas), ambos ignorados pelo git. Nenhuma meta é declarada cumprida sem o número medido ao lado.

## Como reproduzir

```text
npm run benchmark                       # 10 mil, 100 mil e 1 milhão de registros (~20 min + build)
npm run benchmark -- --sizes 10000 --http 50 --iterations 50 --keep   # rodada curta, mantém o ambiente
npm run test:concurrency                # dois/quatro processos reais e injeção de falhas (8880)
```

`benchmarks/run.mjs` cria um ambiente wp-env **adicional e descartável** pelo mesmo mecanismo da matriz da fase 8a: `tools/wp-env.cjs --matrix=8.3,mariadb:10.11.19,port=8882,tag=bench` (config gerada `.wp-env.matrix-php83-mariadb101119-bench.json`, `WP_ENV_HOME` da matriz, projeto Compose, volumes e porta próprios; 8870, 8880 e 8881 são recusadas). Ao final roda `wp-env destroy --force` e apaga a config gerada (`--keep` mantém o ambiente; `--reuse` reaproveita um ambiente mantido). Antes de subir e antes de cada semeadura confere o espaço livre no disco do host e **para** abaixo de 8 GiB. Os ambientes de desenvolvimento (8870) e de testes (8880) não são tocados. Sem rede só funciona se `artifacts/geoip` já tiver a base de teste da MaxMind.

Arquivos: `benchmarks/run.mjs` (orquestração, HTTP, rajadas, resumo), `benchmarks/lib/panel.mjs` (Chrome), `benchmarks/php/bench.php` (ações WP-CLI: semear, coletar, consultar, limpar), `benchmarks/php/toggle.php` (MU plugin só do ambiente de benchmark que liga/desliga o StealthAudit por requisição). Nada disso entra no pacote.

## Ambiente medido

| Item | Valor |
| --- | --- |
| Host | Windows 11 Pro 10.0.26100, AMD Ryzen 5 3600 (6 núcleos, 12 threads), 32 GiB |
| Docker | Docker Desktop, Engine 28.3.0, kernel 6.6.87.2-microsoft-standard-WSL2, 12 CPUs e 16 GiB para a VM |
| PHP | 8.3.36 (Apache e CLI), imagens `wordpress:7.1.4-php8.3-apache` / `wordpress:cli-2.12.0-php8.3`; opcache padrão da imagem (`validate_timestamps` ligado, `revalidate_freq` 2) |
| Banco | MariaDB 10.11.19 (`mariadb:10.11.19`), InnoDB, `innodb_buffer_pool_size` padrão de 128 MiB |
| WordPress | 7.1.4, `WP_DEBUG` falso e `DISABLE_WP_CRON` verdadeiro neste site (a manutenção é medida diretamente) |
| Navegador | Chrome via `playwright-core` 1.64.1, sem cabeça, pelo proxy TLS local do e2e |
| Execução final | 2026-11-08 20:56–21:12 UTC; disco livre 23,9 → 23,7 GiB; ambiente destruído ao final |

Tamanho das tabelas após semear (dados + índices, `information_schema`): 10 mil → auditoria 3,0 MiB, erros 5,2 MiB; 100 mil → 28,6 / 50,2 MiB; 1 milhão → 227,0 / 453,1 MiB. Semear 1 milhão + 1 milhão levou 82 s.

## Metodologia

- **Mesma máquina, mesmo site, aquecido.** O MU plugin de benchmark (`0-stealthaudit-bench.php`, carregado antes do `stealthaudit.php`) lê um JSON por requisição: modo `off` define `STEALTHAUDIT_BOOT_STATUS` e o loader real retorna no primeiro teste (linha de base "sem loader"); modo `on` define `STEALTHAUDIT_CONFIG` e carrega uma cópia do plugin no disco do próprio contêiner (`/opt/stealthaudit-bench`, fora do webroot). Os dois modos leem os mesmos arquivos do WordPress e passam pelo mesmo opcache aquecido.
- **WordPress fora do bind mount.** O wp-env monta `/var/www/html` (o core inteiro) a partir do Windows por 9p. Medido aqui: um `POST /wp-login.php` com o plugin **desligado** custava ~0,4 s por esse caminho e 0,04 s a partir do disco do contêiner, e o ambiente de testes de 8880 chegou a 1–14 s. Para não medir o compartilhamento do host como custo do plugin, o Apache do ambiente de benchmark serve uma cópia do site em `/opt/wp-local` (mesmos arquivos, `wp-config` e banco; do repositório só os arquivos de runtime). O WP-CLI continua no caminho montado; isso não afeta o laço medido, que carrega o código uma vez.
- **Uma ação, um evento.** A ação medida é um `wp_signon()` com usuário inexistente (WP-CLI) ou um `POST /wp-login.php` com credencial errada (HTTP): o WordPress faz a busca do usuário e dispara `wp_login_failed`, que o StealthAudit grava como `access/login_failed`. O roteiro confere que o modo `on` gravou exatamente um evento por ação (330 de 330 por processo; 200 de 200 requisições) e o `off`, nenhum.
- **WP-CLI:** 30 ações de aquecimento + 300 medidas por processo, `off, on, off, on`; o acréscimo usa o menor p50/p95 de cada modo. Neste processo os orçamentos por requisição/segundo/trabalho são elevados (perfil `loop`), porque 330 ações num único processo esgotariam por desenho o limite de 25 eventos e 100 ms por requisição.
- **HTTP:** 20 aquecimentos por modo e 8 blocos de 25 requisições alternando `off`/`on` (200 por modo), uma conexão TCP por requisição, orçamentos padrão. O tempo é o do cliente (envio → fim da resposta).
- **Caps elevados só aqui, explicitamente:** `event_rows`/`error_rows` 3.000.000 e `event_bytes`/`error_bytes` 2.000.000.000. Com os padrões (100 mil auditoria / 128 MiB; 20 mil erros / 32 MiB) uma tabela de 1 milhão suspende a coleta por desenho. Os demais limites HTTP (25 eventos e 5 erros por requisição, 60/20 por segundo, 100 ms de trabalho) ficam nos padrões.
- **Massa sintética:** auditoria uniforme em 300 dias, 8 formas de ação, atores 1–50 ou nulo, IPs do bloco 198.18.0.0/15 misturados a endereços que a base City **de teste** da MaxMind conhece; diagnósticos uniformes em 29 dias, impressões digitais distintas, 5 severidades. As tabelas são truncadas e semeadas de novo em cada tamanho, e os contadores de capacidade recebem a soma real.
- **Consultas:** 3 aquecimentos + 20 repetições de cada janela de 100 linhas pelo repositório real (`window()`); o handler REST (`Api::events()`, sem as camadas HTTP/autenticação) filtrado em `action=logout` para ter 100 linhas com 40 IPs distintos (66 estimativas GeoIP), com e sem a base de teste.
- **Painel:** um operador sintético faz o fluxo real (formulário do wp-login, reconfirmação da senha, QR decodificado por jsQR, TOTP RFC 6238 independente, códigos confirmados). Depois, 3 cargas de aquecimento + 15 medidas por visão: da navegação até a tabela renderizada (`data-state`), e a chamada REST vista pelo navegador (`requestStart → responseEnd`).
- **Limpeza:** 20 mil linhas de auditoria vencidas (400 dias) somadas à massa; dez execuções de `Maintenance::run($now, 1)` (um lote de 500 com lease e status), cinco `DELETE … ORDER BY occurred_at,id LIMIT 500` isolados e três execuções padrão (20 lotes / 2 s).

## Resultados contra as metas de referência

| Medida | Meta | 10 mil | 100 mil | 1 milhão |
| --- | --- | --- | --- | --- |
| Acréscimo p95 por ação, WP-CLI (ms) | ≤ 20 | 7,9 | 9,2 | 7,1 |
| Acréscimo p95 por requisição HTTP (ms) | ≤ 20 | 6,9 | 16,6 | 12,0 |
| Acréscimo p50 por requisição HTTP (ms) | — | 6,1 | 6,3 | 5,7 |
| Memória adicional do request inicializado (MiB) | ≤ 5 | 1,36 | 1,36 | 1,36 |
| Crescimento de memória em 330 coletas (KiB) | ≤ 5 MiB | 15,3 | 15,3 | 15,3 |
| p95 janela de 100 eventos, sem filtro (ms) | ≤ 500 | 0,59 | 0,88 | 0,59 |
| p95 janela de 100 eventos, filtros com resultado (pior) (ms) | ≤ 500 | 0,95 | 1,43 | 0,84 |
| p95 janela, filtro **sem** resultado (`category=access&action=created`) (ms) | ≤ 500 | 7,0 | 73,9 | **2.134** |
| p95 janela, filtro **sem** resultado (`result=unknown`) (ms) | ≤ 500 | 16,2 | 186,7 | **8.188** |
| p95 janela de 100 erros (pior: sem filtro, severidade, 24 h) (ms) | ≤ 500 | 0,80 | 0,84 | 1,49 |
| p95 handler REST, 100 eventos, sem GeoIP (ms) | — | 4,4 | 4,8 | 6,4 |
| p95 handler REST, 100 eventos, com GeoIP (ms) | — | 9,3 | 9,1 | 9,7 |
| p95 painel aquecido, auditoria (ms) | ≤ 2.000 | 149 | 208 | 338 |
| p95 painel aquecido, erros (ms) | ≤ 2.000 | 207 | 253 | 271 |
| p95 painel aquecido, `action=logout` sem GeoIP (ms) | ≤ 2.000 | 251 | 177 | 289 |
| p95 painel aquecido, `action=logout` com GeoIP (ms) | ≤ 2.000 | 211 | 228 | 369 |
| p95 painel aquecido, filtro sem resultado (`result=unknown`) (ms) | ≤ 2.000 | 188 | 321 | **6.448** |
| p95 `DELETE` de 500 linhas (ms) | ≤ 250 | 12,5 | 6,8 | 15,7 |
| p95 manutenção com um lote de 500 (lease + status) (ms) | ≤ 250 | 21,5 | 13,8 | 30,1 |

Leitura:

- **Coleta: atende** nos três tamanhos. O custo da coleta não cresce com a tabela (um `INSERT` e duas CAS no estado). No WP-CLI a mediana por ação passa de 0,25–0,30 ms para 4,1–6,0 ms. O p95 de uma das duas rodadas `on` de 1 milhão foi 26,2 ms (a outra 7,6 ms); a tabela usa o menor de cada modo, como descrito, e a rodada alta fica registrada no JSON. Por requisição HTTP inteira (boot do plugin + coleta) o acréscimo mediano é ~6 ms sobre 21–24 ms de base.
- **Memória: atende.** 1.425.696 bytes a mais no request inicializado (autoload, configuração, hooks e objetos de runtime) e ~15 KiB de crescimento ao longo de 330 coletas (contra ~14 KiB sem o plugin). O pico do processo cresce os mesmos 1,36 MiB.
- **Consulta paginada: atende para janelas que encontram linhas** (todas abaixo de 1,5 ms, inclusive cursor no meio da tabela de 1 milhão). **Não atende** quando a combinação de filtros não casa com nenhuma linha (ou casa com pouquíssimas) numa tabela grande: a busca mais-recente-primeiro percorre todo o intervalo indexado da retenção para provar que não há 100 linhas. O custo cresce com a tabela (≈ 0,2 s em 100 mil, 2–8 s em 1 milhão; a variação entre rodadas é grande porque 227 MiB de dados não cabem no buffer pool padrão de 128 MiB). O mesmo vale para o painel nesse caso (6,4 s em 1 milhão).
- **Painel aquecido: atende** em todas as visões com resultado, inclusive com GeoIP; **não atende** com filtro sem resultado em 1 milhão (acima).
- **Limpeza: atende.** O lote de 500 fica entre 7 e 16 ms (p95) e a execução de um lote com lease/status entre 14 e 30 ms. A execução padrão (20 lotes) levou 0,21–0,39 s em 1 milhão, dentro do teto de 2 s.
- **GeoIP** (base City de **teste**, nunca uma GeoLite/GeoIP real): abrir a base e estimar 40 IPs distintos acrescenta ~4–5 ms ao handler REST; no painel a diferença fica dentro da variação entre cargas.

### Limite registrado (decisão desta fase)

A meta de 500 ms por página é cumprida, em todos os casos medidos, até 100 mil registros de auditoria — o cap padrão `event_rows` (pior caso medido: 187 ms). Acima disso só está validada para consultas que encontram linhas; um filtro sem correspondência numa tabela de 1 milhão levou 2,1–8,2 s no banco e 6,4 s no painel. **Não** foi otimizado nesta fase: um índice por coluna filtrável (`action`, `result`) exigiria a versão 3 do esquema, mais custo de escrita e espaço, e ainda deixaria combinações esparsas lentas; limitar o tempo da consulta (`max_statement_time`/`MAX_EXECUTION_TIME`) mudaria o contrato da API e da UI. Quem elevar `event_rows` acima do padrão deve esperar esse comportamento, ou restringir o período (`from`/`to`) nas buscas sem resultado. O WordPress não interrompe a consulta: o painel espera o banco.

## Rajadas concorrentes (HTTP, orçamentos padrão)

16 clientes × 25 requisições de login falho (400 no total), tabela de 1 milhão, depois do aquecimento:

| Modo | Duração | Vazão | Latência p50 / p95 / máx | Eventos gravados | Perdas contadas |
| --- | --- | --- | --- | --- | --- |
| off | 1,58 s | 253 req/s | 61 / 82 / 110 ms | 0 | 0 |
| on | 1,97 s | 203 req/s | 75 / 105 / 130 ms | 120 | 275 |

O orçamento de 60 eventos por segundo funcionou como projetado: 120 gravados em ~2 s, 275 recusados e contados em `dropped` (o painel mostra "limitado"). 3 reservas ficaram pendentes (conclusão que perdeu três rodadas de CAS; envelhecem e são zeradas pela manutenção depois de 900–960 s, fase 7a) e 5 tentativas (400 − 120 − 275) não aparecem em nenhum contador: reservas que perderam três rodadas de CAS são recusadas sem gravar `dropped` (falha fechada, sem nova tentativa). As 3 pendentes estão entre as 120 gravadas. Nenhuma requisição falhou, nenhum aviso PHP no log do Apache.

## Injeção de falhas

`tests/integration/FaultInjectionTest.php` (grupos `fault-injection` e `concurrency`, roda em `npm run test:integration` e `npm run test:concurrency` no ambiente de 8880) usa tabelas isoladas `sa_fault_<hex>_*` e o worker `tests/fixtures/fault-worker.php`, que mantém o StealthAudit do próprio site fora do processo (nada chega às tabelas reais do site de testes). As asserções verificam comportamento — falha fechada, nenhuma nova tentativa no mesmo request, trabalho limitado — e **não** prazo: a chamada SQL síncrona do `wpdb` não pode ser interrompida e o orçamento de 100 ms só é conferido entre instruções. Tempos observados (8880: PHP 8.3.36, MariaDB 10.11.19), execução de `npm run test:concurrency` em 2026-11-08 21:20 UTC (as execuções anteriores ficaram na mesma ordem de grandeza):

| Cenário | O que foi injetado | Tempo real observado | Depois da falha |
| --- | --- | --- | --- |
| Banco lento | +60 ms em cada instrução da conexão do StealthAudit | gravação saudável 5,0 ms; a gravação lenta levou 308 ms (5 instruções), **3× o orçamento** | as 20 gravações seguintes do request: 0 instruções, 0,08 ms no total |
| Disputa de estado | outra transação segura `budget:events` com `SELECT … FOR UPDATE` por 1,5 s | a gravação esperou 1.507 ms (o `innodb_lock_wait_timeout` do servidor é 50 s) e então gravou | próxima gravação do request recusada sem SQL |
| Disputa + timeout curto | mesma trava por 3 s, sessão com `innodb_lock_wait_timeout = 1` | falhou em 1.009 ms, sem gravar | próxima: 0 instruções (0,007 ms); nenhuma reserva vazou; um request novo grava normalmente |
| Banco perdido após `template_redirect` | `KILL` da sessão + host inalcançável (reconexão recusada) | a gravação levou **5.002 ms**: as 5 tentativas de reconexão do `wpdb` com `sleep(1)` | próxima: 0 instruções (0,006 ms) |
| Banco perdido antes de `template_redirect` | idem | o próprio `wpdb` chamou `dead_db()` após 5.025 ms e encerrou o request ("Error establishing a database connection") | — (o WordPress terminou o processo) |
| Cron atrasado | 12 mil auditorias vencidas, 3 mil erros vencidos, 2 mil linhas vivas | 2 execuções (`partial` → `complete`), 123 e 90 ms, 20 lotes cada | linhas vivas intactas; contador recontado = 2.000 |
| Rajada de 4 processos | 4 × 50 gravações simultâneas (intervalos sobrepostos provados) | p50 5,7 ms, p95 8,7 ms, máx. 16,2 ms (outra execução: p95 30,5 ms) | 152 aceitas, 48 recusadas por CAS perdida (falha fechada); contador = linhas físicas; 1 reserva pendente |
| Tempestade de avisos (repetidos) | 10.000 `E_USER_WARNING` iguais | +4,0 µs por aviso (0,8 → 40,3 ms) | 5 ocorrências gravadas (30 instruções), 9.995 contadas como perdidas, 74 KiB de memória |
| Tempestade de avisos (distintos) | 10.000 mensagens diferentes | +2,7 µs por aviso (1,2 → 28,0 ms) | 5 gravadas (30 instruções), 9.995 contadas, 75 KiB |

Conclusões:

- O StealthAudit nunca tenta de novo no mesmo request depois de uma falha observada (circuito aberto em `Budget`) e nunca cria fila ou retry no front-end; o custo extra máximo de um request é **uma** operação que trava ou falha.
- Essa operação **não** é limitada pelo plugin: ela dura o que o banco ou o `wpdb` decidirem — até o `innodb_lock_wait_timeout` do servidor (50 s por padrão) numa disputa de linha, ~5 s de reconexões do `wpdb` num banco perdido. Antes de `template_redirect`, o próprio WordPress encerra o request nesse caso; não é algo que o plugin introduza nem possa evitar.
- Sob escritas concorrentes contínuas na mesma linha de orçamento, parte das gravações é recusada (fail closed) e uma conclusão perdida deixa a reserva pendente até a manutenção; os contadores nunca ficam abaixo das linhas físicas.

## O que não foi medido

- Outros hardwares, Linux nativo, MySQL 8.4 e PHP 8.4/8.5 no benchmark (a matriz da fase 8a cobre a funcionalidade, não o desempenho).
- Banco remoto (latência de rede), buffer pool dimensionado para a tabela, cache de objetos persistente, multisite.
- Carga real de produção; o tráfego medido é sintético e de uma única forma de ação.
- O pacote prefixado (fase 8c): os números são da árvore de código com o `vendor/` de desenvolvimento.
