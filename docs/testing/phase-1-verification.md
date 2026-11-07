# Verificação da fase 1

Data: 2026-11-07. Árvore de trabalho antes do commit de revisão. Ambiente Linux em Docker Desktop no Windows. Resultado limitado à base do projeto; nenhum coletor, armazenamento, TOTP de acesso ou painel implementado.

| Comando / verificação | Resultado |
| --- | --- |
| `npm run env:test:start` | PASS; WP 7.1.4 em 127.0.0.1:8880, MariaDB imagem 10.11 |
| `npm run test:unit` | PASS, PHPUnit 9.6.38, 21 testes / 33 assertions |
| `npm run test:integration` | PASS, 2 testes / 8 assertions; MU real carregado uma vez, sem ativação |
| `npm run quality` | PASS; lint PHP, WPCS/PHPCompatibilityWP e PHPStan nível 6 |
| `composer validate --strict` no CLI PHP 8.3 | PASS |
| `composer check-platform-reqs` no CLI PHP 8.3 | PASS |
| `composer audit --locked` | PASS, nenhuma advisory Composer encontrada |
| `php tools/notices.php` | PASS, dez dependências de produção e inventário dev a partir do lock |
| HTTP GET homepage e wp-login.php | HTTP 200 em ambos |
| PHP 8.2: `php tests/fixtures/unsupported-php-loader.php` | PASS, `php_unsupported`, sem incluir core PHP 8.3 |
| Portas em `docker ps` / Compose gerado | HTTP e MySQL exclusivamente em 127.0.0.1 |
| `git diff --check` | PASS |

Cobertura adicional: allowlist ausente/vazia nega inclusive administrador; IDs inválidos, chave curta/malformada, endpoint externo/traversal, proxy inválido e limites inválidos são rejeitados. Ausência de extensões do painel preserva os requisitos de coleta. Dependência OTPHP foi exercitada com PSR Clock fake e vetor RFC 6238. Loader direto é silencioso; vendor/bootstrap ausentes e erro/exceção em instalação parcial não expõem mensagens, e o handler anterior é restaurado. Essas verificações não substituem os testes de autorização/MFA das fases seguintes.

Durante a implementação, `copy()` de arquivo do bind Docker Desktop para `/tmp` anexou NULs após uma edição que encurtou o arquivo. A fixture passou a copiar os bytes por leitura/gravação, mantendo as mesmas assertions e evidenciando o output do subprocesso caso falhe. A rodada posterior passou.

Pendências registradas: avisos Node/npm das transitivas Playground e sete advisories npm moderadas de desenvolvimento (ver [setup](local-setup.md)); HTTPS/MFA, demais versões PHP/bancos, render Hallmark e prefixação/ZIP de distribuição nas fases previstas. As bibliotecas de produção ainda estão sem prefixo no workspace de desenvolvimento; o pacote final precisará do build isolado da fase 8. Não usar este scaffold como pacote de produção.
