# Como contribuir

Obrigado pelo interesse. Este guia cobre o ambiente, os testes e o formato das mudanças. A documentação do projeto é em português; código, comentários, mensagens de commit e nomes de testes são em inglês.

**Vulnerabilidades não entram por aqui:** siga o [SECURITY.md](SECURITY.md) e não abra issue pública.

## Antes de começar

- Leia o [design aprovado](docs/specs/2026-11-07-stealthaudit-design.md) e [o que o StealthAudit não faz](README.md#o-que-o-stealthaudit-não-faz). Mudanças que ampliem o que é coletado (conteúdo, senhas, corpos de requisição), que abram o painel sem a política completa ou que enviem IPs para fora do servidor contrariam o design e não serão aceitas.
- Para mudanças grandes, abra uma issue antes, descrevendo o problema e a proposta.
- Suporte só é declarado onde há teste: uma mudança que diz suportar algo novo precisa trazer a evidência ([compatibility.md](docs/testing/compatibility.md)).

## Ambiente

Ferramentas no host: Docker (Desktop ou Engine) e Node ≥ 22.13. PHP e Composer **não** precisam estar no host: rodam nos contêineres do wp-env. O e2e precisa também de OpenSSL e Google Chrome locais. Os comandos funcionam em PowerShell e em Git Bash.

```text
npm ci
npm run env:test:start                 # ambiente de testes em 127.0.0.1:8880
node tools/wp-env.cjs run cli --env-cwd=wp-content/mu-plugins/stealthaudit composer install
```

Use sempre `tools/wp-env.cjs` (os scripts `npm` já usam). Ele publica as portas só em 127.0.0.1; o `npx wp-env` direto publicaria em todas as interfaces. Detalhes, portas e cuidados: [docs/testing/local-setup.md](docs/testing/local-setup.md). A primeira execução dos testes baixa as bases MMDB de **teste** da MaxMind para `artifacts/geoip/` (precisa de rede uma vez).

## Testes e qualidade

| Comando | O que roda |
| --- | --- |
| `npm run test:unit` | PHPUnit unitário |
| `npm run test:integration` | PHPUnit com WordPress real, incluindo processos PHP concorrentes |
| `npm run test:concurrency` | só o grupo `concurrency` (processos reais e injeção de falhas) |
| `npm run quality` | lint PHP, WPCS/PHPCompatibilityWP e PHPStan nível 6 |
| `npm run test:e2e` | navegador real pelo proxy TLS local; restaura o site ao final ([phase-6-e2e.md](docs/testing/phase-6-e2e.md)) |
| `npm run matrix -- --php 8.4 --db mysql:8.4.13 --e2e` | uma combinação da matriz num ambiente adicional e descartável ([verification.md](docs/testing/verification.md)) |
| `npm run benchmark` | benchmark num ambiente adicional ([performance.md](docs/testing/performance.md)) |
| `npm run package` / `npm run verify-package` | ZIP de distribuição e sua verificação ([package.md](docs/testing/package.md)) |
| `npm run test:package` | instala o ZIP num WordPress limpo e roda o fluxo completo (≈ 30 min, ambiente adicional) |

Uma mudança em `src/`, `templates/`, `assets/`, `bin/` ou no loader precisa passar em `test:unit`, `test:integration`, `quality` e, se tocar o painel, `test:e2e`. Mudanças no build ou no empacotamento passam também em `package`, `verify-package -- --against-source` e `test:package`. `benchmark` e `test:package` conferem o espaço livre em disco antes de começar; a matriz não confere. Os três destroem o ambiente adicional ao final, exceto quando chamados com `--keep` (aceito pelos três). Se uma execução for interrompida, veja [verification.md](docs/testing/verification.md#execução-interrompida).

Regras para os testes:

- Dados sempre sintéticos. Nunca use credenciais, chaves, códigos de recuperação ou bases GeoIP reais.
- Um defeito corrigido vem com um teste que falha sem a correção.
- Não afirme resultado que não foi observado. Se algo não pôde ser testado, diga isso na descrição.

## Código

- PHP 8.3+, `declare(strict_types=1)`, namespace `StealthAudit\`, docblock de arquivo com `@package StealthAudit`. Todo o código do projeto é GPL-3.0-or-later; arquivos novos de ferramentas e testes trazem `SPDX-License-Identifier: GPL-3.0-or-later`.
- Estilo WordPress (WPCS), conferido pelo `npm run quality`. Uma exclusão de regra precisa de comentário com o motivo.
- Falhar fechado: um erro na validação de acesso nega o painel; um erro na coleta não derruba o site e não cria fila nem nova tentativa no mesmo request.
- Dependências de produção só com licença compatível com GPL-3.0 (MIT, BSD, Apache-2.0, ISC) e com `THIRD-PARTY-NOTICES.md` regenerado (`php tools/notices.php`). O build recusa notices desatualizadas.
- Mudança de esquema muda `Schema::VERSION` e traz migração testada a partir da versão publicada anterior. A partir da 0.1.0 o esquema v2 está distribuído e não muda mais sem migração.
- Ao mudar a versão, altere juntos `Runtime::VERSION`, o cabeçalho do loader, `package.json` e `package-lock.json`. O teste unitário e o build conferem que todos concordam.

## Commits e pull requests

- [Conventional Commits](https://www.conventionalcommits.org/) em inglês (`fix:`, `feat:`, `test:`, `docs:`, `build:`…), um assunto coerente por commit.
- Não commite `.env`, `config/stealthaudit.php`, chaves, `*.mmdb`, `dist/`, `build/` ou `artifacts/` (o `.gitignore` já cobre).
- Na descrição do pull request, diga o que mudou, por quê, quais comandos rodaram e com qual resultado, e o que ficou sem teste.
- Contribuições são aceitas sob a mesma licença do projeto, [GPL-3.0-or-later](LICENSE).
