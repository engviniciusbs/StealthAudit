# Política de segurança

## Versões cobertas

| Versão | Recebe correções de segurança |
| --- | --- |
| 0.1.x | sim, enquanto for a linha mais recente |
| anteriores a 0.1.0 (`0.1.0-dev`, árvores de desenvolvimento) | não |

A 0.1.0 é a primeira versão preparada para distribuição. Até haver um release publicado, correções entram no ramo `main`.

## Como relatar uma vulnerabilidade

**Não abra issue, pull request ou discussão pública** com detalhes de uma vulnerabilidade.

Relate em privado pelo GitHub Security Advisories do repositório [engviniciusbs/StealthAudit](https://github.com/engviniciusbs/StealthAudit):

1. Abra <https://github.com/engviniciusbs/StealthAudit/security/advisories/new> (aba **Security** → **Report a vulnerability**). É preciso estar logado no GitHub.
2. Descreva a versão (ou o commit), o ambiente (WordPress, PHP, banco, servidor web, proxy/CDN), os passos para reproduzir, o impacto esperado e, se tiver, uma prova de conceito mínima.
3. Não inclua dados reais de terceiros, segredos válidos, chaves do painel, códigos de recuperação nem bases GeoIP licenciadas. Use dados sintéticos.

Se o botão **Report a vulnerability** não aparecer, o relato privado ainda não está ativo no repositório. Nesse caso abra uma issue curta pedindo um canal privado, **sem nenhum detalhe técnico**, e espere a resposta. Não existe endereço de e-mail de segurança do projeto.

## O que esperar

O projeto é mantido por voluntários e não tem prazo garantido de resposta. O relato é confirmado no próprio advisory; a correção e a divulgação são combinadas ali, e o advisory é publicado quando houver versão corrigida. Quem relatar recebe crédito no advisory, se quiser.

## Escopo

Interessam especialmente:

- acesso ao painel ou à API `stealthaudit/v1` sem cumprir a política inteira (HTTPS, sessão WordPress, allowlist, senha reconfirmada, TOTP), inclusive por CSRF, CORS, cache ou replay;
- vazamento de dados de auditoria, erros, segredos TOTP, códigos de recuperação ou da chave;
- gravação de dados que deveriam ficar fora do registro (senhas, conteúdo, tokens);
- uma falha da coleta que derrube o site ou quebre a resposta pública;
- problemas no pacote ZIP (arquivo sensível incluído, dependência sem prefixo que colida com outro plugin).

Fora do escopo, por decisão de projeto ([README](README.md#limites-de-segurança)): quem já pode executar PHP, instalar plugins, editar arquivos ou o banco, ou controla o servidor, consegue desativar ou contornar a coleta. Um plugin must-use visível na tela de plugins também não é vulnerabilidade. Problemas da hospedagem, do WordPress ou de bibliotecas de terceiros devem ser relatados aos respectivos projetos; avise aqui se o StealthAudit precisar reagir.

## Integridade do pacote

Confira o SHA-256 do ZIP com o valor publicado nas notas do release ([docs/releases/](docs/releases/)), obtido por um canal diferente do download. O `.zip.sha256` distribuído junto com o ZIP só detecta corrupção: quem troca um troca o outro.
