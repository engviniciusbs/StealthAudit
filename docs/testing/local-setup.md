# Ambiente local e verificações da fase 1

Este documento registra a base da fase 1. O armazenamento foi acrescentado depois; ver [verificação da fase 2](phase-2-storage.md) para tabelas, migração e limites atuais.

Verificado em 2026-11-07. Código em desenvolvimento: esta fase não contém coletores, tabelas, rotas de acesso ou painel. `panel_configured()` informa pré-requisitos, nunca autentica ou autoriza uma consulta.

## Preparação

Docker Desktop/Engine ativo e Node/npm são ferramentas de desenvolvimento. PHP e Composer não precisam estar no host. Use os scripts abaixo na raiz do projeto:

```text
npm ci
npm run env:test:start
node tools/wp-env.cjs run cli --env-cwd=wp-content/mu-plugins/stealthaudit composer install
npm run test:unit
npm run test:integration
npm run quality
npm run env:test:stop
```

O `composer install` usa as versões exatas do lockfile; não executar `update` implicitamente. As restrições `~` dos três pacotes de produção permitem apenas patches da minor contratada em uma atualização deliberada. O lock fixa OTPHP 11.5.0, BaconQrCode 3.1.1 e GeoIP2 3.4.0.

`npm run env:start` / `npm run env:stop` operam o desenvolvimento em 8870. Os testes usam 8880 e outro `WP_ENV_HOME`, cache Compose e volume de banco. Cada config tem `testsEnvironment: false`: o comando correto em ambas é `run cli`, não `tests-cli`. A integração WordPress recria tabelas no banco da config de testes; nunca executá-la na config de desenvolvimento ou produção.

O wrapper `tools/wp-env.cjs` adapta exclusivamente o gerador Docker de wp-env 12.1.0 para publicar HTTP/MySQL em **127.0.0.1**. Não usar `npx wp-env start` diretamente: o padrão upstream publica em todas as interfaces. O adaptador não altera `node_modules`; futuras atualizações de wp-env exigem conferir o gerador e as portas com `docker ps`. A seleção de config usa a opção publicada `--config`.

Os mappings montam o loader em `wp-content/mu-plugins/stealthaudit.php` e o projeto em `wp-content/mu-plugins/stealthaudit`. Nenhum plugin convencional é ativado. A suíte de integração deixa o WordPress carregar o MU pelo fluxo real, sem um segundo require em hooks de teste.

## Versões observadas e limites

| Componente | Resultado observado |
| --- | --- |
| WordPress release e biblioteca de testes | 7.1.4, download/tag selecionados por wp-env |
| PHP CLI | 8.3.36 |
| MariaDB | Imagem 10.11; InnoDB será verificado na fase 2 |
| wp-env | 12.1.0 |
| PHPUnit global da imagem wp-env | 10.5.66; não usado nos scripts do projeto |
| PHPUnit do lock do projeto | 9.6.38; unidade e integração aprovadas |
| Polyfills do projeto | 4.0.0; compatibilidade comprovada com esse runner/core |
| Composer da imagem wp-env | 2.11.1 |
| Extensões CLI | mysqli, json, sodium, iconv, xmlwriter presentes; check-platform-reqs aprovado |
| Node/npm observados | 22.17.0 / 10.9.2; caminho Docker executou os testes |

O npm instalou com avisos de engine: transitivas Playground 3.1.62 exigem Node >=24.18.0 e npm >=11.16.0 apesar do requisito raiz de wp-env >=22.13.0. Playground não foi usado nem validado. Há sete advisories npm moderados na árvore de desenvolvimento; não são dependências de hospedagem, mas exigem resolução/revalidação antes da fase 8. Não declarar toda a árvore Node compatível com Node 22. O lock permite reproduzir o estado observado; preferir uma toolchain isolada que satisfaça as engines ao renovar a árvore.

A primeira execução constrói imagens e pode levar vários minutos sem output intermediário. Executar uma subida por vez para evitar disputa de containers. Neste ambiente a resolução inicial do Composer usou `composer:2` em Docker, seguida de testes na imagem PHP 8.3. Se arquivos criados por esse fallback tiverem ownership diferente, corrigir o ownership do workspace de desenvolvimento antes de instalar pelo usuário padrão do wp-env; não alterar permissões de uma instalação de produção.

HTTP local foi usado apenas para smoke de homepage/login e bootstrap. Nenhum fluxo de acesso foi implementado ou dispensado de HTTPS; testes de MFA/HTTPS e versões PHP 8.4/8.5 ficam nas respectivas fases. Não há afirmação de suporte MySQL ou Windows PHP: nesta fase os caminhos externos são absolutos Unix no runtime Linux do container.

## Configuração e falhas seguras

O operador pode definir `STEALTHAUDIT_CONFIG` (array) em wp-config.php, ou `STEALTHAUDIT_CONFIG_FILE` no wp-config/ambiente, apontando para um arquivo PHP legível **fora do webroot** que retorne array. Não há busca de configuração em requests, options ou usermeta. O exemplo `config/stealthaudit.example.php` contém allowlist vazia e nenhuma chave. Não copiar esse exemplo para um caminho público como configuração efetiva.

Sem configuração, usuário algum é permitido, inclusive administrador. Configuração inválida volta aos defaults que negam o painel; o runtime mantém os requisitos independentes de coleta. Sem chave válida ou sodium/iconv/XMLWriter, o painel não fica configurado. MMDB é opcional, local e não é aberto no bootstrap.

O loader retorna silenciosamente fora de WordPress, verifica PHP antes do core e publica somente um código não sensível em `STEALTHAUDIT_BOOT_STATUS`: `php_unsupported`, `vendor_missing`, `bootstrap_missing`, `bootstrap_error` ou `ready`. `ready` significa bootstrap carregado, não painel autorizado. Inclusão duplicada é idempotente. Avisos/exceções no bootstrap são contidos, sem imprimir caminhos/segredos, e o handler anterior é restaurado. Verificar o status em ferramentas do operador, sem criar endpoint público de diagnóstico.

## Qualidade e licenças

`quality` roda lint PHP, WPCS/PHPCompatibilityWP e PHPStan nível 6. Exceções estreitas documentadas: nomes PSR-4 no diretório src, nome explícito do exemplo, anotações PHPStan `list<T>` que WPCS não entende, handler de bootstrap e decodificação de chave. Não há baseline geral de erros ignorados.

`php tools/notices.php` gera THIRD-PARTY-NOTICES.md com as licenças/copyrights reais da árvore de produção e o inventário dev separado. O texto GPLv3 foi obtido de https://www.gnu.org/licenses/gpl-3.0.txt. Nenhuma MMDB ou fonte visual foi incorporada. Hallmark SKILL.md e flow.md/App screens foram lidos; Node está disponível, mas renderer/Chrome/fontes locais serão validados na implementação visual da fase 6; não há render visual para declarar nesta fase.
