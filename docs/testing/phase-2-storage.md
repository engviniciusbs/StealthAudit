# Persistência e limites — fase 2

Verificado em 2026-11-07 no ambiente local da [fase 1](local-setup.md): WordPress 7.1.4, PHP 8.3.36, MariaDB 10.11/InnoDB, PHPUnit 9.6.38. MySQL e PHP 8.4/8.5 ainda não foram testados. Nenhum dado real foi usado; todos os registros e envelopes dos testes são sintéticos.

## Implementação

`Runtime` inicializa a persistência no hook `plugins_loaded`, prioridade 5. Não há activation hook, coletor, rota pública ou autorização nova. O loader continua negando o painel por configuração padrão. `Runtime::storage()` lança uma exceção opaca se a inicialização não estiver pronta; coletores futuros devem conter essa falha e preservar a ação original. O estado `ready` não autentica ninguém.

As tabelas usam o prefixo WordPress e charset/collation da conexão: `stealthaudit_events`, `stealthaudit_errors` e `stealthaudit_state`. Não há foreign key para usuários: excluir um usuário não apaga o histórico. Datas são formatadas explicitamente em UTC. Consultas aplicam retenção lógica de 365/30 dias, paginação por cursor de ID descendente e máximo de 100 linhas; filtros e identificadores são permitidos explicitamente. A ordenação por ID representa ordem de inserção, inclusive quando o relógio de dois workers difere. O controle de acesso pertence à fase 5; os repositórios são APIs internas, sem endpoints.

O schema tem versão própria e lease de 120 segundos em uma linha de options acessada diretamente por SQL, sem usar o cache de options como autoridade. Lease expirado pode ser recuperado. A publicação da versão exige posse e validade do lease no mesmo `INSERT ... SELECT`. Após `dbDelta`, a verificação confere engine, collation, tipos, nulabilidade, autoincrement, colunas/ordem dos índices, ausência de índices prefixados e unicidade esperada. Uma instalação parcial ou engine incompatível não publica a versão. DDL só ocorre se a versão precisar de instalação; `append()` não executa migração. A flag de versão não substitui verificação operacional após alterações manuais do banco.

`StateRepository` oferece criação limitada e CAS por chave/revisão, com uma linha afetada como sucesso. Falha de banco lança `StorageException` sem SQL, caminhos ou payloads. Revisões iniciais aleatórias positivas com margem para incrementos reduzem o risco de ABA após remoção/recriação de uma chave; os testes demonstram a rejeição de revisão antiga. A prevenção é probabilística, com espaço de aproximadamente 62 bits. Provas/limites/leases aceitam somente digest hexadecimal na identidade e exigem TTL; fatores usam ID de usuário. Payload JSON tem teto de 8 KiB e profundidade limitada. Fator cifrado, hashes de recovery e generation devem ocupar a mesma linha; comparação da generation e autorização continuam a cargo da fase 5. Não armazenar token de sessão bruto ou segredo sem cifra no payload.

Eventos aceitam apenas campos estruturados, códigos fechados, identidades numéricas, IP canônico e resumo com `count`/`reason` permitido. Não aceitam conteúdo, senha ou objetos WordPress completos. Erros aceitam mensagem já reduzida pelo coletor, até 1024 bytes, arquivo relativo até 255 bytes e gravidade permitida. Há uma proteção adicional para padrões comuns de credenciais; ela não substitui o sanitizador da fase 4. Nesta fase cada ocorrência ocupa uma linha com fingerprint e contagem 1; agregação/rollover serão implementados com o coletor de erros.

## Capacidade e manutenção

Os defaults preservam os limites da fase 1: 100 mil eventos/128 MiB lógicos, 20 mil erros/32 MiB lógicos, 25/5 tentativas por requisição e 60/20 gravações por segundo. `state_rows=10000` inclui três linhas fixas de orçamento; `storage_work_ms=100` limita o trabalho cumulativo dos coletores nesta requisição. O relógio de trabalho usa tempo monotônico e não cobra tempo ocioso ou de outros plugins. Bytes lógicos são uma estimativa determinística dos dados gravados; não medem páginas, índices, binlogs ou backups do banco.

Cada inserção reserva linhas/bytes/ritmo atomicamente com CAS antes do SQL de inserção. O bucket de ritmo nunca retrocede por um worker atrasado. Disputa tem no máximo três tentativas. Inserção que falha pode sobrecontar; o caminho de coleta nunca restitui capacidade. Recusas por capacidade/ritmo contabilizam perdas quando o CAS consegue gravar; disputa, falha de banco ou limite por requisição podem impedir essa contagem. Não interpretar o contador como total exato de perdas.

O marcador `pending` impede reconciliação enquanto houver operação reservada em andamento. A conclusão tenta baixar apenas esse marcador, sem devolver linhas/bytes; cada repositório conclui uma reserva confirmada uma única vez, em `finally`. Crash ou falha de conclusão pode deixar `pending` conservador e bloquear reconciliação. Não há expiração automática que libere esse orçamento: recuperação operacional com exclusão de escritores ativos será tratada na fase 7. SQL síncrono em andamento não pode ser interrompido por esse limite de tempo; o limite impede novos trabalhos após a chamada retornar.

`Maintenance::reconcile_records()` lê no máximo 501 linhas para um lote de até 500; recusa soma parcial, estado `pending` ou revisão alterada. É idempotente nos valores de capacidade. Tabelas maiores exigem o cursor por lotes da fase 7; não há varredura completa nem `COUNT(*)` em gravações. `purge_expired_state()` remove até 500 provas/limites/leases expirados, preserva fatores e só desconta slots por CAS. Falha de CAS conserva sobrecontagem. Cron, retenção física e reconciliação de grandes tabelas ainda não estão agendados.

## Verificação executada

```text
npm run test:unit
npm run test:integration
npm run quality
```

- Unidade: 21 testes, 33 assertions, PASS.
- Integração: 16 testes, 127 assertions, PASS; inclui os dois testes anteriores de bootstrap.
- Concorrência real: dois processos PHP com conexões independentes; uma atualização CAS e uma reserva de capacidade vencem, sem exceder o teto de uma linha.
- Schema: prefixo isolado, InnoDB/collation, repetição idempotente, lease ocupado/expirado, falha de verificação MyISAM sem marcar versão, tipos e índices inválidos rejeitados.
- Limites: linhas, bytes, ritmo, requisição, teto auxiliar, sobrecontagem após falha de inserção, retenção lógica e filtros/página inválidos.
- Estado: conflito, generation/recovery na mesma linha, TTL exato e revisão antiga rejeitada após recriação.
- Manutenção: reconciliação limitada/idempotente, recusa de reserva pendente e limpeza limitada sem apagar fator.
- Qualidade: lint PHP, WPCS/PHPCompatibilityWP e PHPStan nível 6 PASS (`npm run quality`, exit 0). PHPStan usa `--memory-limit=512M`, pois 128 MiB esgotaram durante a primeira análise dos novos módulos. Esse teto é de desenvolvimento, não requisito de memória do plugin em hospedagem.

Inventário novo: `src/Storage/{Database,Schema,StateRepository,Budget,Records,EventRepository,ErrorRepository,Maintenance,Storage,StorageException}.php`, `tests/integration/StorageTest.php` e `tests/fixtures/storage-worker.php`. O fixture só executa em CLI; transmite a configuração efêmera do banco de testes por stdin aos filhos, sem salvar credenciais em arquivo ou output. O pacote futuro exclui testes/ferramentas. Nenhuma dependência de produção foi adicionada.
