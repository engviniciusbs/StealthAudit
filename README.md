# StealthAudit

*English summary: StealthAudit is a WordPress must-use plugin that records audit events (logins, users, plugins, themes, settings, content) and PHP errors in its own bounded tables. A panel on the site's own domain shows them only to an explicit allowlist of WordPress users, after a password reconfirmation and a mandatory TOTP code. Version 0.1.0 is ready for review, not yet published. Documentation is in Portuguese.*

Plugin must-use para WordPress que responde a duas perguntas de quem opera o site: **quem fez o quê** (login, usuários, plugins, temas, configurações, conteúdo) e **o que está quebrando** (avisos e erros PHP). Tudo fica em tabelas próprias, com limites de tamanho, e só aparece num painel protegido por lista de usuários autorizados, senha e código 2FA.

**Status:** versão **0.1.0**, pronta para revisão e ainda não publicada (sem tag nem release). Notas, SHA-256 do pacote e limitações: [docs/releases/0.1.0.md](docs/releases/0.1.0.md).

## Sumário

- [Como funciona](#como-funciona)
- [Exemplo do que aparece no painel](#exemplo-do-que-aparece-no-painel)
- [Requisitos](#requisitos)
- [Instalação em 5 passos](#instalação-em-5-passos)
- [Configuração](#configuração)
- [Usando o painel](#usando-o-painel)
- [Operação](#operação)
- [O que o StealthAudit não faz](#o-que-o-stealthaudit-não-faz)
- [Desenvolvimento](#desenvolvimento)
- [Documentação, segurança e licença](#documentação-segurança-e-licença)

## Como funciona

```text
 Ações no WordPress            Erros PHP
 (login, plugins, posts…)      (warning, fatal…)
          │                          │
          ▼                          ▼
   hooks do WordPress        handler de erros + shutdown
          └──────────┬───────────────┘
                     ▼
       gravação com limites (linhas, bytes, ritmo, tempo)
                     ▼
   wp_stealthaudit_events · wp_stealthaudit_errors · wp_stealthaudit_state
                     ▼
   painel /stealthaudit  ◄── HTTPS + login WordPress + ID na allowlist
                             + senha de novo + código TOTP
```

- **Coleta sempre ligada.** Começa na primeira requisição depois da instalação, mesmo sem configuração. Se algo falhar na coleta, o site continua funcionando; o evento é descartado e contado como perda.
- **Painel fechado por padrão.** Sem configuração, ninguém entra, nem administradores. Ser administrador não autoriza: o ID precisa estar na lista do operador.
- **Nada sai do servidor.** Sem telemetria, sem CDN, sem consulta externa de IP. A localização (GeoIP) é opcional e calculada na hora da leitura, a partir de uma base local.
- **Retenção automática.** Auditoria por 365 dias e erros por 30, apagados em lotes pelo WP-Cron.

## Exemplo do que aparece no painel

Dados fictícios, só para mostrar o formato. A aba **Auditoria** lista eventos:

| Horário (UTC) | Resultado | Ação | Categoria | Usuário | Objeto | IP | Origem | Detalhes |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 2026-11-09T14:02:11Z | Falha | `login_failed` | Acesso | sem registro | none | 203.0.113.7 | Site | reason: authentication_failed |
| 2026-11-09T14:03:40Z | Sucesso | `login` | Acesso | ID 1 | user #1 | 203.0.113.7 | Site | |
| 2026-11-09T14:05:02Z | Sucesso | `activated` | Administração | ID 1 | plugin | 203.0.113.7 | Painel WordPress | reference: akismet/akismet.php |
| 2026-11-09T14:07:19Z | Sucesso | `updated` | Conteúdo | ID 1 | page #42 | 203.0.113.7 | REST | |
| 2026-11-09T15:00:03Z | Sucesso | `option_changed` | Administração | sem registro | option | sem registro | Cron | reference: blog_public |

A aba **Erros** agrupa ocorrências repetidas:

| Primeira vez (UTC) | Gravidade | Mensagem | Arquivo e linha | Ocorrências |
| --- | --- | --- | --- | --- |
| 2026-11-09T13:58:10Z | Aviso | Cannot modify header information - headers already sent by (output started at [path]:1) | `wp-includes/functions.php:7169` | 37 vezes |

Caminhos dentro das mensagens viram `[path]`, e o arquivo aparece relativo à pasta do WordPress. O evento guarda **o que aconteceu**, não o conteúdo: o ID do post, nunca o texto; o nome da opção, nunca o valor; o login com falha, nunca a senha digitada. A lista completa do que é e do que não é registrado está em [event-coverage.md](docs/testing/event-coverage.md).

## Requisitos

| Item | Mínimo | Testado |
| --- | --- | --- |
| WordPress | single-site | 7.1.4 |
| PHP | 8.3, com `mysqli` e `json` (coleta) e `sodium`, `iconv`, `xmlwriter` (painel) | 8.3.36, 8.4.27, 8.5.12 |
| Banco | MariaDB ou MySQL com InnoDB | MariaDB 10.11.19, MySQL 8.4.13 |
| Site | HTTPS (o painel recusa HTTP) | Apache com TLS via proxy local |

As seis combinações PHP × banco passaram na matriz de testes ([compatibility.md](docs/testing/compatibility.md#matriz-verificada-fase-8a)). Multisite, outras versões, Nginx e PHP-FPM ainda não têm evidência. Composer e Node **não** são necessários no servidor: o ZIP já traz tudo.

## Instalação em 5 passos

Exemplo para um WordPress em `/var/www/site`, com acesso SSH e WP-CLI. Sem SSH, os mesmos passos funcionam pelo gerenciador de arquivos da hospedagem; o guia completo está em [installation.md](docs/operations/installation.md).

**1. Confira o pacote.** Compare com o SHA-256 publicado nas [notas da versão](docs/releases/0.1.0.md), obtido por um canal diferente do download:

```bash
sha256sum stealthaudit-0.1.0.zip
# fdf1046f199296dcef9ffd95272a90edb581e271fc1c22b44581d6b5df787890  stealthaudit-0.1.0.zip
```

**2. Copie o núcleo e, por último, o loader.** O loader (`stealthaudit.php`) é o interruptor: enviado por último e por renomeação, nenhuma requisição carrega um plugin pela metade.

```bash
unzip stealthaudit-0.1.0.zip -d ~/stealthaudit-0.1.0
cp -r ~/stealthaudit-0.1.0/mu-plugins/stealthaudit /var/www/site/wp-content/mu-plugins/
cp ~/stealthaudit-0.1.0/mu-plugins/stealthaudit.php /var/www/site/wp-content/mu-plugins/stealthaudit.php.new
mv /var/www/site/wp-content/mu-plugins/stealthaudit.php.new /var/www/site/wp-content/mu-plugins/stealthaudit.php
```

Na próxima visita ao site, as tabelas são criadas e a coleta começa. Em **Plugins → Must-Use** aparece "StealthAudit".

**3. Crie a configuração fora de qualquer pasta pública.** Este comando parte do exemplo do pacote, gera uma chave aleatória de 32 bytes direto no arquivo (sem mostrá-la na tela) e autoriza o usuário de ID 1. Troque o ID e os caminhos:

```bash
mkdir -p ~/stealthaudit-config && chmod 700 ~/stealthaudit-config
umask 077
php -r '$c = require "/var/www/site/wp-content/mu-plugins/stealthaudit/config/stealthaudit.example.php";
$c["authorized_user_ids"] = [1];
$c["key_base64"] = base64_encode(random_bytes(32));
$c["key_id"] = "site-2026-11";
$c["installation_id"] = "site-".bin2hex(random_bytes(16));
file_put_contents(getenv("HOME")."/stealthaudit-config/site.php", "<?php\nreturn ".var_export($c, true).";\n");'
chmod 600 ~/stealthaudit-config/site.php
```

Guarde uma cópia desse arquivo num lugar seguro, **separado do backup do banco**: sem a chave, os cadastros 2FA não podem ser recuperados.

**4. Aponte o `wp-config.php` para o arquivo**, acima da linha "That's all, stop editing":

```php
define( 'STEALTHAUDIT_CONFIG_FILE', '/home/usuario/stealthaudit-config/site.php' );
```

**5. Confira e entre no painel.**

```bash
cd /var/www/site
wp eval 'var_dump( \StealthAudit\Bootstrap\Runtime::boot()->configuration_valid );'
# bool(true)  → configuração aceita
# bool(false) → algum valor é inválido; o painel continua fechado para todos
```

Abra `https://seu-site/stealthaudit/`, faça login com o usuário autorizado, digite a senha de novo, escaneie o QR num app autenticador (Google Authenticator, Authy, 1Password…), digite o código de 6 dígitos e **guarde os 10 códigos de recuperação**.

## Configuração

O arquivo de configuração retorna um array PHP. Exemplo com valores não secretos:

```php
<?php
return array(
	'authorized_user_ids' => array( 1, 7 ),          // IDs explícitos; vazio = ninguém entra
	'endpoint'            => '/stealthaudit',         // caminho do painel no mesmo domínio
	'key_base64'          => '<32 bytes em base64>',  // gerada no passo 3; é segredo
	'key_id'              => 'site-2026-11',          // rótulo da chave (letras, números, _ e -)
	'installation_id'     => 'site-3f9a…',            // fixo para sempre nesta instalação
	'trusted_proxies'     => array( '10.0.0.0/8' ),   // só se houver proxy reverso; vazio ignora X-Forwarded-For
	'limits'              => array(),                 // tetos de capacidade; vazio usa o padrão
	'mmdb_path'           => null,                    // base GeoIP local (opcional)
	'mmdb_max_age_days'   => 30,                      // base mais velha que isso não é usada
);
```

- **Um valor inválido desliga a configuração inteira**: o painel fecha para todos e a coleta segue com os padrões. Confira sempre com o comando do passo 5.
- O arquivo **não pode** ficar dentro da pasta do WordPress (o plugin recusa). Com o WordPress num subdiretório (por exemplo `public_html/blog`), deixe o arquivo fora de `public_html` também.
- Alternativa sem arquivo: `define( 'STEALTHAUDIT_CONFIG', array( … ) );` no próprio `wp-config.php`, que então passa a guardar a chave.
- Tetos de capacidade (`limits`) e o que acontece quando são atingidos: [installation.md](docs/operations/installation.md#capacidade-e-limites). GeoIP: [geoip.md](docs/operations/geoip.md).

## Usando o painel

| Endereço | O que mostra |
| --- | --- |
| `https://seu-site/stealthaudit/` | Auditoria (padrão) |
| `https://seu-site/stealthaudit/?view=errors` | Erros PHP |
| `https://seu-site/?stealthaudit=panel` | O mesmo painel, para sites com permalinks simples |

Os filtros ficam na URL, então uma busca pode ser salva ou compartilhada com outro operador autorizado:

```text
/stealthaudit/?view=audit&action=login_failed&from=2026-11-01T00:00:00Z
/stealthaudit/?view=audit&category=admin&result=failure
/stealthaudit/?view=audit&user=7&to=2026-11-09T23:59:59-03:00
/stealthaudit/?view=errors&severity=fatal
```

| Filtro | Valores |
| --- | --- |
| `category` | `access`, `users`, `admin`, `content` |
| `action` | `login`, `login_failed`, `logout`, `created`, `updated`, `deleted`, `trashed`, `restored`, `activated`, `deactivated`, `installed`, `upgraded`, `theme_switched`, `option_changed`, `role_changed`, `capability_changed` e os eventos do próprio painel (`mfa_*`) |
| `result` | `success`, `failure`, `unknown` |
| `user`, `ip` | ID numérico do usuário; IPv4 ou IPv6 exato |
| `severity` (erros) | `fatal`, `error`, `warning`, `notice`, `deprecated` |
| `from`, `to` | data e hora ISO 8601 com fuso: `2026-11-01T00:00:00Z` ou `…-03:00` |
| `limit` | linhas por página, até 100 (padrão 50) |

A verificação 2FA vale por 30 minutos sem uso e por no máximo 8 horas. **Sair do StealthAudit** encerra a verificação sem sair do WordPress. Perdeu o celular? Use um código de recuperação na tela de login do painel.

## Operação

| Tarefa | Como |
| --- | --- |
| Dar ou tirar acesso | Edite `authorized_user_ids` no arquivo de configuração; vale na próxima requisição. |
| Pausar o painel sem parar a coleta | Deixe `authorized_user_ids` vazio. |
| Desligar tudo numa emergência | `mv wp-content/mu-plugins/stealthaudit.php wp-content/mu-plugins/stealthaudit.php.off` (nenhum dado é apagado; renomeie de volta para religar). |
| Resetar o 2FA de quem perdeu celular e códigos | Pause o painel e rode o CLI offline (abaixo). |
| Trocar a chave | Rotação offline com o mesmo CLI: [panel-security.md](docs/operations/panel-security.md#rotação-de-chave-e-migração). |
| Atualizar a versão | Troque a pasta por renomeação e **reinicie o PHP** (ou limpe o opcache), senão o código antigo continua rodando: [installation.md](docs/operations/installation.md#atualização-de-arquivos-e-migração). |
| Apagar todos os dados | Procedimento manual e deliberado: [maintenance-and-data.md](docs/operations/maintenance-and-data.md#remoção-explícita-dos-dados). |

Reset de 2FA do usuário 7, rodando no servidor com o painel pausado:

```bash
STEALTHAUDIT_WORDPRESS_ROOT=/var/www/site \
  php /var/www/site/wp-content/mu-plugins/stealthaudit/bin/security-operator.php reset 7
# Factor reset; WordPress sessions revoked.
```

O CLI só roda em linha de comando, nunca recebe senha ou código como argumento e recusa agir se o painel não estiver pausado. Depois, reative o ID: o usuário faz login, confirma a senha e cadastra um novo 2FA.

## O que o StealthAudit não faz

- **Não protege contra quem controla o servidor.** Quem pode editar arquivos, o banco ou instalar plugins consegue desligar ou contornar a coleta. O objetivo é evitar a desativação acidental ou casual pelo painel do WordPress.
- **Não é exaustivo.** Ausência de registro não prova que nada aconteceu. Algumas perdas (disputa no banco, banco fora do ar) podem não ser contadas, e o contador de perdas mostra só o último período de manutenção (cerca de 1 a 2 horas).
- **Não guarda conteúdo**: nem texto de posts, nem senhas, nem valores de opções, nem corpos de requisição.
- **Não é rápido em qualquer volume.** As metas de consulta valem até o teto padrão de 100 000 eventos. Numa tabela de 1 milhão, filtros por ação ou resultado raros levaram de 2 a 8 s ([performance.md](docs/testing/performance.md)).

A lista completa de limitações conhecidas está nas [notas da 0.1.0](docs/releases/0.1.0.md#limitações-conhecidas).

## Desenvolvimento

Pré-requisitos no seu computador: Docker e Node 22.13 ou mais novo. PHP e Composer rodam dentro dos contêineres.

```bash
npm ci
npm run env:test:start     # WordPress de testes em http://127.0.0.1:8880
node tools/wp-env.cjs run cli --env-cwd=wp-content/mu-plugins/stealthaudit composer install
npm run test:unit          # testes unitários
npm run test:integration   # testes com WordPress real
npm run quality            # lint, padrão WordPress e PHPStan
npm run package            # gera dist/stealthaudit-<versão>.zip
```

Todos os comandos, regras de teste e o formato dos commits estão em [CONTRIBUTING.md](CONTRIBUTING.md).

## Documentação, segurança e licença

- **Documentação por assunto** (instalação, operação, privacidade, desempenho, testes): [docs/README.md](docs/README.md).
- **Vulnerabilidades:** não abra issue pública; siga o [SECURITY.md](SECURITY.md).
- **Licença:** [GPL-3.0-or-later](LICENSE). As bibliotecas embarcadas mantêm as próprias licenças e são modificadas pelo build só para prefixar o namespace ([THIRD-PARTY-NOTICES.md](THIRD-PARTY-NOTICES.md)). Nenhuma base GeoIP é distribuída.
