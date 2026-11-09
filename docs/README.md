# Documentação do StealthAudit

Índice por assunto. Cada tópico aponta para o documento que já trata dele; nada é repetido aqui. Os runbooks em `operations/` são para quem instala e opera. Os registros em `testing/` dizem o que foi medido ou testado, com comandos e limites.

Versão atual: **0.1.0**, preparada para revisão e não publicada. Notas, SHA-256 do pacote, limitações conhecidas e pendências: [releases/0.1.0.md](releases/0.1.0.md).

## Para operar

| Assunto | Onde |
| --- | --- |
| Instalação pelo ZIP, configuração segura, visibilidade em Must-Use, atualização e rollback | [operations/installation.md](operations/installation.md) |
| Capacidade: limites padrão, envelope validado (`event_rows` até 100 000), perdas que o painel não mostra | [operations/installation.md#capacidade-e-limites](operations/installation.md#capacidade-e-limites) |
| Manutenção (WP-Cron ou cron de servidor), backups, recuperação, desativação emergencial, remoção dos dados | [operations/maintenance-and-data.md](operations/maintenance-and-data.md) |
| Chave, rotação offline, reset de fator perdido, ferramenta `bin/security-operator.php` | [operations/panel-security.md](operations/panel-security.md) |
| GeoIP: base do operador, termos e atribuição, substituição atômica | [operations/geoip.md](operations/geoip.md) |
| Como relatar uma vulnerabilidade | [../SECURITY.md](../SECURITY.md) |

## O que o plugin registra (cobertura)

| Assunto | Onde |
| --- | --- |
| Contrato de eventos: hooks, campos permitidos, o que fica de fora | [testing/event-coverage.md](testing/event-coverage.md) |
| Semântica observada da auditoria, lacunas e limites | [testing/phase-3-audit.md](testing/phase-3-audit.md) |
| Diagnóstico PHP: o que é capturado, agregação e o que escapa | [testing/phase-4-diagnostics.md](testing/phase-4-diagnostics.md) |

A captura não é exaustiva: ausência de registro não prova ausência de ação ou erro.

## Privacidade

| Assunto | Onde |
| --- | --- |
| Decisões de projeto sobre IP, localização e dados fora do escopo | [specs/2026-11-07-stealthaudit-design.md](specs/2026-11-07-stealthaudit-design.md) ("IP, localização e privacidade") |
| Campos aceitos pela gravação; nenhum conteúdo, senha ou objeto completo | [testing/phase-2-storage.md](testing/phase-2-storage.md) e [testing/event-coverage.md](testing/event-coverage.md#envelope-e-dados-permitidos) |
| Redução de mensagens de erro e credenciais | [testing/phase-4-diagnostics.md](testing/phase-4-diagnostics.md#privacidade-limites-e-agregação) |
| Localização estimada na leitura, nunca gravada nem enviada a serviço web | [operations/geoip.md](operations/geoip.md) |
| Retenção (365/30 dias), backups e cópias externas que o expurgo não alcança | [operations/maintenance-and-data.md](operations/maintenance-and-data.md#backups-e-cópias-externas) |

## Capacidade e desempenho

| Assunto | Onde |
| --- | --- |
| Orçamentos de linhas, bytes, ritmo e trabalho; contagem de perdas | [testing/phase-2-storage.md](testing/phase-2-storage.md#capacidade-e-manutenção) |
| Benchmark local (10 mil / 100 mil / 1 milhão), metas, limite aceito e o que não foi medido | [testing/performance.md](testing/performance.md) |
| Injeção de falhas: banco lento ou perdido, disputa, cron atrasado, tempestade de avisos | [testing/performance.md#injeção-de-falhas](testing/performance.md#injeção-de-falhas) |
| Retenção física, lotes e estado observável da manutenção | [testing/phase-7-maintenance.md](testing/phase-7-maintenance.md) |

## Compatibilidade

| Assunto | Onde |
| --- | --- |
| Combinações aprovadas (PHP 8.3/8.4/8.5 × MariaDB 10.11.19 / MySQL 8.4.13, WordPress 7.1.4) | [testing/compatibility.md#matriz-verificada-fase-8a](testing/compatibility.md#matriz-verificada-fase-8a) |
| Procedimento da matriz, versões e digests, limpeza de execução interrompida | [testing/verification.md](testing/verification.md) |

Suporte declarado só onde passou. Multisite, outras versões e outros bancos não têm evidência.

## Segurança do painel

| Assunto | Onde |
| --- | --- |
| Autorização, TOTP, recuperação, limites de tentativa | [testing/phase-5-security.md](testing/phase-5-security.md) |
| Rota, REST, cabeçalhos, CSRF e CORS | [testing/phase-6-http.md](testing/phase-6-http.md) |
| Interface e acessibilidade | [testing/phase-6-ui.md](testing/phase-6-ui.md) |
| Verificação ponta a ponta no navegador e o que não foi possível testar | [testing/phase-6-e2e.md](testing/phase-6-e2e.md) |

## Pacote e desenvolvimento

| Assunto | Onde |
| --- | --- |
| Build prefixado, ZIP, `verify-package`, instalação pelo artefato, convivência com outro plugin | [testing/package.md](testing/package.md) |
| Ambiente local, comandos de teste, qualidade | [testing/local-setup.md](testing/local-setup.md) e [../CONTRIBUTING.md](../CONTRIBUTING.md) |
| Inventário de dependências de desenvolvimento | [testing/development-dependencies.md](testing/development-dependencies.md) |
| Avisos de terceiros embarcados | [../THIRD-PARTY-NOTICES.md](../THIRD-PARTY-NOTICES.md) |
| Design aprovado, revisão estruturada, direção da interface, plano e catálogo de APIs | [specs/](specs/) e [plans/](plans/) |

Registros por fase (o que foi feito em cada etapa): [fase 1](testing/phase-1-verification.md), [2](testing/phase-2-storage.md), [3](testing/phase-3-audit.md), [4](testing/phase-4-diagnostics.md), [5](testing/phase-5-security.md), [6a](testing/phase-6-http.md), [6b](testing/phase-6-ui.md), [6c](testing/phase-6-e2e.md), [7a](testing/phase-7-maintenance.md), [7b](testing/phase-7-geoip.md), [8a](testing/verification.md), [8b](testing/performance.md), [8c](testing/package.md). Um registro de fase descreve o estado daquela fase; quando um documento mais novo o corrige, vale o mais novo.
