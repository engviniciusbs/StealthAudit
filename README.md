# StealthAudit

*English summary: StealthAudit is a WordPress must-use plugin that records audit events and bounded PHP diagnostics for DevOps-managed sites. A panel on the site's own domain shows them only to an explicit allowlist of WordPress users, after a password reconfirmation and a mandatory TOTP code. Version 0.1.0 is prepared for review and not yet published. The documentation is in Portuguese.*

Plugin must-use (MU) de código aberto para WordPress: registra ações de autenticação, usuários, administração e conteúdo, e erros PHP/WordPress, num banco próprio com limites explícitos. Mostra tudo num painel no próprio domínio, só para IDs autorizados pelo operador e com TOTP obrigatório.

## Status

**Versão 0.1.0, preparada para revisão e ainda não publicada.** Não há tag, release no GitHub nem deploy. As notas da versão trazem o SHA-256 do pacote, o que foi testado, as limitações conhecidas e o que falta verificar: [docs/releases/0.1.0.md](docs/releases/0.1.0.md).

Foi testado em ambientes locais (Docker) com dados sintéticos. Não houve uso em produção, auditoria de segurança independente nem os testes que só uma pessoa pode fazer: escanear o QR com um celular, instalar num subdiretório real e passar por uma CDN de produção.

## O que a 0.1.0 faz

- **Auditoria** de login (sucesso, falha, logout), usuários, papéis e capacidades, plugins, temas, instalações e atualizações, opções selecionadas, conteúdo e mídia, pelos hooks do WordPress 7.1.4. Grava resumos com códigos fechados, nunca senha, conteúdo ou objetos completos ([cobertura](docs/testing/event-coverage.md)).
- **Diagnósticos PHP** dentro do nível configurado, agregados e reduzidos, respeitando `@` e o handler anterior ([fase 4](docs/testing/phase-4-diagnostics.md)).
- **Armazenamento limitado** em tabelas InnoDB próprias, com tetos de linhas, bytes, ritmo e trabalho por requisição. Uma falha da coleta não derruba o site.
- **Retenção** de 365 dias para auditoria e 30 para erros, com expurgo em lotes pelo WP-Cron ([manutenção](docs/operations/maintenance-and-data.md)).
- **Painel** em `/stealthaudit` (interface "ASCII Terminal", ativos locais): HTTPS obrigatório, sessão WordPress, allowlist explícita (ser administrador não basta), senha reconfirmada, TOTP compatível com aplicativos Authenticator, códigos de recuperação, expiração por inatividade e revogação.
- **GeoIP opcional**: estima o local na leitura a partir de uma base MMDB fornecida pelo operador. Nada é gravado e nenhum IP sai do servidor ([geoip](docs/operations/geoip.md)).
- **Ferramenta offline** do operador para rotação de chave e reset de fator, sem bypass HTTP ([panel-security](docs/operations/panel-security.md)).
- **Pacote ZIP** com dependências prefixadas (`StealthAuditVendor\`), sem Composer nem Node no servidor ([pacote](docs/testing/package.md)).

## Onde foi testado

Suporte declarado **somente** onde a matriz passou ([compatibilidade](docs/testing/compatibility.md#matriz-verificada-fase-8a)):

| | MariaDB 10.11.19 | MySQL 8.4.13 |
| --- | --- | --- |
| PHP 8.3.36 | aprovado | aprovado |
| PHP 8.4.27 | aprovado | aprovado |
| PHP 8.5.12 | aprovado | aprovado |

Tudo com WordPress 7.1.4 **single-site**, Apache com mod_php e TLS de um proxy local. Multisite, outras versões de WordPress, PHP ou banco, Nginx, PHP-FPM e hospedagens reais não têm evidência. Desempenho medido numa única máquina, sem valor de SLA ([desempenho](docs/testing/performance.md)): as metas de consulta valem até o teto padrão de 100 000 eventos.

## Limites de segurança

O objetivo é evitar a desativação acidental ou casual pela administração do WordPress. O plugin **não** protege a coleta contra quem pode executar PHP, instalar plugins, editar arquivos ou o banco, nem contra quem controla o servidor. Um plugin must-use aparece na seção Must-Use da tela de plugins.

Falhas da coleta preservam o funcionamento normal do site sempre que possível; falhas na validação de acesso negam o painel. A captura não é exaustiva: ausência de registro não prova que nada aconteceu. Segredos, corpos completos de requisição e conteúdo integral ficam fora do escopo.

## Instalação

Pelo ZIP, como must-use: [docs/operations/installation.md](docs/operations/installation.md). Confira o SHA-256 do ZIP com o valor das notas do release obtido por um canal diferente do download. Não rode Composer no servidor.

## Documentação

Índice por assunto (instalação, cobertura, privacidade, capacidade, desempenho, compatibilidade, manutenção, recuperação): [docs/README.md](docs/README.md).

Projeto: [design aprovado](docs/specs/2026-11-07-stealthaudit-design.md), [revisão estruturada](docs/specs/2026-11-07-stealthaudit-review.md), [direção da interface](docs/specs/2026-11-07-frontend-ascii-terminal.md), [plano de implementação](docs/plans/2026-11-07-implementation-plan.md) e [catálogo de APIs verificadas](docs/plans/2026-11-07-documentation-discovery.md).

## Contribuir e relatar vulnerabilidades

- Como preparar o ambiente, rodar os testes e enviar mudanças: [CONTRIBUTING.md](CONTRIBUTING.md).
- Vulnerabilidades: **não** abra issue pública. Use o relato privado do GitHub, como descrito em [SECURITY.md](SECURITY.md).

## Licença

O código do projeto é licenciado sob a [GPL-3.0-or-later](LICENSE). As dependências embarcadas mantêm as próprias licenças, e o build as modifica mecanicamente (prefixo de namespace); ver [THIRD-PARTY-NOTICES.md](THIRD-PARTY-NOTICES.md). Nenhuma base GeoIP é incluída, e a licença da base é assunto entre o operador e o fornecedor. Os testes de desenvolvimento baixam as bases de teste oficiais da MaxMind (Apache-2.0 ou MIT) para `artifacts/geoip`, ignorado pelo git, com commit e checksums fixados; elas nunca são commitadas nem empacotadas.
