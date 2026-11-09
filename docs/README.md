# Documentação do StealthAudit

Índice da documentação. Comece pela pergunta que você tem; cada link leva ao documento que responde, sem repetir o conteúdo aqui.

- `operations/`: **runbooks** para quem instala e opera (passo a passo, comandos).
- `testing/`: **registros** do que foi testado e medido, com comandos, resultados e limites.
- `specs/` e `plans/`: **projeto**, ou seja, as decisões e o plano de implementação.

Versão atual: **0.1.0**, pronta para revisão e não publicada. Notas, SHA-256 do pacote e limitações conhecidas: [releases/0.1.0.md](releases/0.1.0.md).

## Comece por aqui

| Eu quero… | Leia |
| --- | --- |
| Instalar e configurar pela primeira vez | [README do projeto](../README.md#instalação-em-5-passos) (resumo) e [operations/installation.md](operations/installation.md) (completo) |
| Saber se meu ambiente é suportado | [testing/compatibility.md](testing/compatibility.md#matriz-verificada-fase-8a) |
| Entender exatamente o que é registrado e o que fica de fora | [testing/event-coverage.md](testing/event-coverage.md) |
| Dar ou tirar acesso ao painel, resetar 2FA, trocar a chave | [operations/panel-security.md](operations/panel-security.md) |
| Atualizar para uma versão nova | [operations/installation.md](operations/installation.md#atualização-de-arquivos-e-migração) |
| Configurar backup, cron de servidor ou apagar os dados | [operations/maintenance-and-data.md](operations/maintenance-and-data.md) |
| Mostrar a localização estimada dos IPs | [operations/geoip.md](operations/geoip.md) |
| Saber quanto aguenta e o que acontece quando enche | [operations/installation.md](operations/installation.md#capacidade-e-limites) e [testing/performance.md](testing/performance.md) |
| Rodar os testes ou contribuir | [../CONTRIBUTING.md](../CONTRIBUTING.md) e [testing/local-setup.md](testing/local-setup.md) |
| Relatar uma vulnerabilidade | [../SECURITY.md](../SECURITY.md) |

## Operação (runbooks)

| Documento | Cobre | Exemplo de uso |
| --- | --- | --- |
| [installation.md](operations/installation.md) | requisitos, instalação pelo ZIP, configuração segura, capacidade, atualização e rollback | "O painel responde 403 para todos": a allowlist está vazia ou a configuração é inválida. |
| [panel-security.md](operations/panel-security.md) | chave, rotação offline, reset de fator, encerrar acesso na hora, CLI `bin/security-operator.php` | "Um operador perdeu o celular e os códigos": pausar o painel e rodar `security-operator.php reset <ID>`. |
| [maintenance-and-data.md](operations/maintenance-and-data.md) | WP-Cron ou cron de servidor, backups, recuperação, desativação emergencial, remoção dos dados | "Preciso desligar tudo agora": renomear `stealthaudit.php` para `stealthaudit.php.off`. |
| [geoip.md](operations/geoip.md) | base MMDB do operador, idade máxima, termos e atribuição, substituição atômica | "A coluna de local mostra indisponível": a base sumiu, está corrompida ou é mais velha que `mmdb_max_age_days`. |

## O que é registrado

| Documento | Cobre |
| --- | --- |
| [event-coverage.md](testing/event-coverage.md) | Contrato dos eventos: hooks do WordPress observados, campos permitidos, o que nunca é gravado |
| [phase-3-audit.md](testing/phase-3-audit.md) | Comportamento observado da auditoria, lacunas e limites |
| [phase-4-diagnostics.md](testing/phase-4-diagnostics.md) | Erros PHP: o que é capturado, como é agregado e reduzido, o que escapa |

A captura não é exaustiva: ausência de registro não prova ausência de ação ou de erro.

## Privacidade

| Pergunta | Onde |
| --- | --- |
| Por que IP e localização são tratados assim? | [specs/2026-11-07-stealthaudit-design.md](specs/2026-11-07-stealthaudit-design.md) (seção "IP, localização e privacidade") |
| Quais campos a gravação aceita? | [testing/phase-2-storage.md](testing/phase-2-storage.md) e [testing/event-coverage.md](testing/event-coverage.md#envelope-e-dados-permitidos) |
| Como mensagens de erro e credenciais são reduzidas? | [testing/phase-4-diagnostics.md](testing/phase-4-diagnostics.md#privacidade-limites-e-agregação) |
| A localização é gravada ou enviada a algum serviço? | Não; é estimada na leitura, a partir de uma base local: [operations/geoip.md](operations/geoip.md) |
| Por quanto tempo os dados ficam? E os backups? | 365 dias (auditoria) e 30 (erros); backups não são alcançados pelo expurgo: [operations/maintenance-and-data.md](operations/maintenance-and-data.md#backups-e-cópias-externas) |

## Capacidade e desempenho

| Documento | Cobre |
| --- | --- |
| [phase-2-storage.md](testing/phase-2-storage.md#capacidade-e-manutenção) | Tetos de linhas, bytes, ritmo e trabalho por requisição; como as perdas são contadas |
| [performance.md](testing/performance.md) | Benchmark com 10 mil, 100 mil e 1 milhão de eventos, metas, o limite aceito para a 0.1.0 e o que não foi medido |
| [performance.md#injeção-de-falhas](testing/performance.md#injeção-de-falhas) | Banco lento ou fora do ar, disputa, cron atrasado, tempestade de avisos |
| [phase-7-maintenance.md](testing/phase-7-maintenance.md) | Retenção, lotes de expurgo e estado da manutenção mostrado no painel |

Resumo: com o teto padrão (`event_rows` = 100 000) todas as metas foram atingidas. Acima disso, filtros raros por `action` ou `result` ficam lentos.

## Compatibilidade

| Documento | Cobre |
| --- | --- |
| [compatibility.md](testing/compatibility.md#matriz-verificada-fase-8a) | Combinações aprovadas: PHP 8.3, 8.4 e 8.5 × MariaDB 10.11.19 e MySQL 8.4.13, com WordPress 7.1.4 |
| [verification.md](testing/verification.md) | Como a matriz é rodada, versões e digests das imagens, limpeza de execução interrompida |

Suporte só é declarado onde o teste passou. Multisite, outras versões, Nginx e PHP-FPM não têm evidência.

## Segurança do painel

| Documento | Cobre |
| --- | --- |
| [phase-5-security.md](testing/phase-5-security.md) | Autorização, TOTP, recuperação, limites de tentativa |
| [phase-6-http.md](testing/phase-6-http.md) | Rota do painel, API REST, cabeçalhos, CSRF e CORS |
| [phase-6-ui.md](testing/phase-6-ui.md) | Interface e acessibilidade |
| [phase-6-e2e.md](testing/phase-6-e2e.md) | Testes no navegador real e o que não pôde ser testado |

## Pacote e desenvolvimento

| Documento | Cobre |
| --- | --- |
| [package.md](testing/package.md) | Build com bibliotecas prefixadas, ZIP, `verify-package`, instalação pelo artefato, convivência com outro plugin |
| [local-setup.md](testing/local-setup.md) e [../CONTRIBUTING.md](../CONTRIBUTING.md) | Ambiente local, comandos de teste e qualidade |
| [development-dependencies.md](testing/development-dependencies.md) | Inventário das dependências de desenvolvimento |
| [../THIRD-PARTY-NOTICES.md](../THIRD-PARTY-NOTICES.md) | Licenças das bibliotecas embarcadas |
| [specs/](specs/) e [plans/](plans/) | Design aprovado, revisão estruturada, direção da interface, plano e catálogo de APIs |

## Histórico por fase

Cada registro descreve o estado do projeto ao fim daquela fase. Quando um documento mais novo corrige um antigo, vale o mais novo.

| Fase | Registro |
| --- | --- |
| 1. Base do projeto e loader | [phase-1-verification.md](testing/phase-1-verification.md) |
| 2. Armazenamento | [phase-2-storage.md](testing/phase-2-storage.md) |
| 3. Auditoria | [phase-3-audit.md](testing/phase-3-audit.md) |
| 4. Diagnóstico PHP | [phase-4-diagnostics.md](testing/phase-4-diagnostics.md) |
| 5. Segurança do painel | [phase-5-security.md](testing/phase-5-security.md) |
| 6a. HTTP e REST | [phase-6-http.md](testing/phase-6-http.md) |
| 6b. Interface | [phase-6-ui.md](testing/phase-6-ui.md) |
| 6c. Navegador | [phase-6-e2e.md](testing/phase-6-e2e.md) |
| 7a. Manutenção | [phase-7-maintenance.md](testing/phase-7-maintenance.md) |
| 7b. GeoIP | [phase-7-geoip.md](testing/phase-7-geoip.md) |
| 8a. Matriz de compatibilidade | [verification.md](testing/verification.md) |
| 8b. Desempenho e falhas | [performance.md](testing/performance.md) |
| 8c. Pacote | [package.md](testing/package.md) |
| 8d. Versão 0.1.0 | [releases/0.1.0.md](releases/0.1.0.md) |
