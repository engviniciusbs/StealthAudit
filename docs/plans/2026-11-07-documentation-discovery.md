# StealthAudit — descoberta de documentação

Consultado em 2026-11-07. Material de apoio ao [plano](2026-11-07-implementation-plan.md), não código implementado. Fontes primárias; versões e trechos de branches móveis devem ser fixados no início da execução.

Revalidação de execução da fase 0: ver [compatibilidade](../testing/compatibility.md) e [cobertura de eventos](../testing/event-coverage.md). WordPress 7.1.4 foi confirmado pela API oficial e hooks conferidos na tag. A referência inicial a OTPHP 11.6.x foi corrigida para o release publicado 11.5.0; a branch 11.6.x não será usada como dependência estável. BaconQrCode v3.1.1 e PHP-Scoper 0.18.20 também foram confirmados. Sem dependências instaladas ou código implementado nesta revalidação.

## Evidência local

- Projeto contém a especificação e sua revisão; não há código, manifestos, testes ou repositório Git inicializado.
- Nenhum AGENTS.md encontrado na pasta ou nos ancestrais verificados até C:/.
- Docker Engine 28.3.0 respondeu; Node 22.17.0 e npm 10.9.2 disponíveis.
- php, composer e wp não foram encontrados no PATH. O plano utiliza ferramentas dentro de Docker.
- Nenhuma instalação de dependências, execução de suíte, criação de conta GeoIP ou benchmark ocorreu nesta etapa.

## D1 — plataforma e ambiente reproduzível

Fontes: [requisitos WordPress](https://wordpress.org/about/requirements/), [releases](https://wordpress.org/download/releases/), [suporte PHP](https://www.php.net/supported-versions.php), [wp-env](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/).

Achados: WordPress 7.1.4 é o release estável observado. WordPress recomenda PHP 8.3+, MariaDB 10.11+ ou MySQL 8.0+ e HTTPS. PHP 8.2 está perto do fim do suporte de segurança; baseline do plano: PHP 8.3, testes adicionais em 8.4/8.5. Isso é decisão técnica do projeto, não o mínimo do core.

wp-env 12.1.0 exige Node >=22.13.0, verificado pelo agente nos [metadados npm](https://registry.npmjs.org/@wordpress/env/12.1.0). Seções copiáveis: “Using included WordPress PHPUnit test files”, “Using composer, phpunit, and wp-cli tools”, “Running parallel environments” e “Add mu-plugins and other mapped directories”. Usar mappings MU e configurações distintas para desenvolvimento e testes. testsEnvironment/env.tests estão depreciados no guia consultado. WP_TESTS_DIR identifica a biblioteca de teste incluída. Não presumir que CLI e web de uma mesma configuração usam bancos separados.

## D2 — bootstrap, roteamento e APIs privadas

Fontes: [MU, Caveats](https://developer.wordpress.org/advanced-administration/plugins/mu-plugins/), [add_rewrite_rule](https://developer.wordpress.org/reference/functions/add_rewrite_rule/), [flush_rewrite_rules](https://developer.wordpress.org/reference/functions/flush_rewrite_rules/), [REST endpoints, Permissions Callback](https://developer.wordpress.org/rest-api/extending-the-rest-api/adding-custom-endpoints/), [autenticação REST](https://developer.wordpress.org/rest-api/using-the-rest-api/authentication/).

APIs permitidas:

- `add_rewrite_rule(string $regex, string|array $query, string $after = 'bottom')`, no hook init; filtro query_vars registra a variável de rota. Copiar o padrão de exemplo, selecionando template próprio.
- `flush_rewrite_rules(bool $hard = true)`: caro, somente após instalação/mudança de rota; não a cada requisição. Não depender de activation hook MU.
- `register_rest_route($namespace, $route, $args, $override = false)`, em rest_api_init, sempre com permission_callback adequado à operação e validação dos parâmetros.
- Cookie WordPress e nonce wp_rest não substituem a política StealthAudit nem a prova de 2FA. Rotas de logs nunca públicas.

O loader é PHP na raiz MU e inclui o núcleo em subdiretório. Carrega antes dos plugins convencionais, mas APIs pluggable/de usuário não podem ser chamadas prematuramente. MU não recebe hooks normais de ativação ou notificações normais de atualização. all_plugins e views_plugins não são promessa de ocultação completa.

## D3 — esquema e atomicidade

Fontes: [tabelas de plugins](https://developer.wordpress.org/plugins/creating-tables-with-plugins/), [dbDelta](https://developer.wordpress.org/reference/functions/dbdelta/), [wpdb::prepare](https://developer.wordpress.org/reference/classes/wpdb/prepare/), [MySQL UPDATE](https://dev.mysql.com/doc/refman/8.4/en/update.html), [InnoDB locking reads](https://dev.mysql.com/doc/refman/8.4/en/innodb-locking-reads.html), [transients](https://developer.wordpress.org/apis/transients/).

Copiar o padrão de tabela prefixada por `$wpdb->prefix`, charset/collation da instalação e include de wp-admin/includes/upgrade.php apenas no caminho de migração. `dbDelta($queries, $execute = true)` não retorna uma garantia booleana de sucesso: verificar tabela, engine e índices depois.

`$wpdb->prepare($query, ...$args)` admite %d, %s, %f e %i; placeholders sem aspas, quantidade de argumentos correspondente e nomes de coluna/order por lista permitida. Usar CAS (compare-and-swap): UPDATE condicional por chave/revisão, incrementando revisão e exigindo uma linha afetada. Uma resposta zero é disputa, false é falha; não conceder acesso em nenhum desses casos sem nova validação.

SELECT FOR UPDATE só protege dentro de transação ativa. Transients, caches, options e usermeta não oferecem contrato suficiente para consumo único de TOTP/recovery. Testar atomicidade no InnoDB real, em processos separados. Evitar abrir/fechar transações que possam interferir em trabalho de outro plugin usando a conexão global WordPress; preferir mudanças condicionais de uma linha para o estado de segurança.

## D4 — hooks observáveis de auditoria

Fontes e trechos copiáveis: blocos Source das referências [wp_login](https://developer.wordpress.org/reference/hooks/wp_login/), [wp_login_failed](https://developer.wordpress.org/reference/hooks/wp_login_failed/), [wp_logout](https://developer.wordpress.org/reference/hooks/wp_logout/), [wp_after_insert_post](https://developer.wordpress.org/reference/hooks/wp_after_insert_post/), [upgrader_process_complete](https://developer.wordpress.org/reference/hooks/upgrader_process_complete/); código oficial em [user.php](https://github.com/WordPress/wordpress-develop/blob/trunk/src/wp-includes/user.php), [class-wp-user.php](https://github.com/WordPress/wordpress-develop/blob/trunk/src/wp-includes/class-wp-user.php), [meta.php](https://github.com/WordPress/wordpress-develop/blob/trunk/src/wp-includes/meta.php), [option.php](https://github.com/WordPress/wordpress-develop/blob/trunk/src/wp-includes/option.php), [post.php](https://github.com/WordPress/wordpress-develop/blob/trunk/src/wp-includes/post.php) e wp-admin/includes/{user,plugin,theme,class-wp-upgrader,class-plugin-upgrader,class-theme-upgrader,class-core-upgrader}.php. O agente leu os pontos de emissão no trunk; revalidar os mesmos símbolos na tag 7.1.4 antes de codificar.

| Hook / argumentos essenciais | Semântica de implementação |
| --- | --- |
| wp_login(user_login, WP_User) | Login bem-sucedido via wp_signon, não toda autenticação possível. |
| wp_login_failed(username, WP_Error) | Falha; selecionar código permitido, sem serializar erro ou credenciais. |
| wp_logout(user_id) | Encerramento explícito; não detecta toda expiração passiva. |
| user_register(user_id, userdata); profile_update(user_id, old_user, userdata) | Depois da persistência; userdata pode conter segredos, não registrar argumentos inteiros. |
| deleted_user(id, reassign, WP_User) | Após exclusão single-site; delete_user é anterior. |
| set_user_role(user_id, role, old_roles); add_user_role/remove_user_role(user_id, role) | Roles podem emitir eventos sobrepostos; deduplicar. |
| added_user_meta/updated_user_meta/deleted_user_meta(meta_id(s), user_id, meta_key, value) | Para capacidades individuais filtrar exclusivamente a chave de capabilities da instalação; nunca logar usermeta inteiro. |
| activated_plugin/deactivated_plugin(plugin, network_flag) | Pós-evento comum; caminhos silent podem não emitir. |
| deleted_plugin(plugin_file, deleted); deleted_theme(stylesheet, deleted) | deleted booleano é o resultado; pre-hooks não comprovam exclusão. |
| switch_theme(new_name, new_theme, old_theme) | Troca de tema ativo; não inventar deactivated_theme. |
| upgrader_process_complete(upgrader, hook_extra) | Conclusão não prova sucesso; examinar resultado por item, tipo e bulk. |
| upgrader_install_package_result(result, hook_extra), filtro | Observar resultado array/WP_Error e retorná-lo intacto. Falha precoce pode não alcançá-lo. |
| added_option(option, value); updated_option(option, old_value, value); deleted_option(option) | Registrar só nome/ação de opções selecionadas, não valores. Excluir transients/estado interno. |
| wp_after_insert_post(post_id, post, update, post_before) | Resumo depois de salvar; remover autosave/revisão e descartar conteúdo/snapshot. |
| deleted_post(post_id, post) | Exclusão física concluída; distinguir lixeira e anexos. |
| add_attachment/edit_attachment(post_id); delete_attachment(post_id, post) | delete_attachment é pré-evento; usar confirmação posterior e deduplicar com hooks gerais. |

Nenhum hook cobre escrita SQL direta, alteração de arquivos fora das APIs ou hooks deliberadamente suprimidos. O coletor de atualização precisa de fixtures de sucesso, falha, bulk parcial e falha anterior à instalação.

## D5 — diagnóstico PHP

Fontes: [set_error_handler](https://www.php.net/manual/en/function.set-error-handler.php), [register_shutdown_function](https://www.php.net/manual/en/function.register-shutdown-function.php), [error_get_last](https://www.php.net/manual/en/function.error-get-last.php), [diagnóstico WordPress](https://developer.wordpress.org/advanced-administration/debug/debug-wordpress/). Copiar assinaturas/limites do manual, não handlers que lancem exceções sobre warnings.

- `set_error_handler(?callable $callback, int $error_levels = E_ALL): ?callable`; callback moderno com errno, errstr, errfile, errline. Respeitar `error_reporting() & $errno` explicitamente e preservar handler anterior/retorno.
- `register_shutdown_function(callable $callback, mixed ...$args): void` e `error_get_last(): ?array`, com type/message/file/line, para fatais observáveis e recursos disponíveis.
- Não cobrem falhas anteriores ao registro, todo fatal, todo error_log(), encerramento forçado ou logs do servidor. Não ativar WP_DEBUG nem exibição pública pelo plugin.

## D6 — identidade, TOTP, QR e cifra

Fontes copiáveis:

- [wp_get_session_token](https://developer.wordpress.org/reference/functions/wp_get_session_token/), [WP_Session_Tokens, Methods/Source](https://developer.wordpress.org/reference/classes/wp_session_tokens/), [wp_check_password](https://developer.wordpress.org/reference/functions/wp_check_password/), [wp_verify_nonce](https://developer.wordpress.org/reference/functions/wp_verify_nonce/).
- [OTPHP doc/index.md](https://github.com/Spomky-Labs/otphp/blob/11.5.0/doc/index.md), [TOTP.php](https://raw.githubusercontent.com/Spomky-Labs/otphp/11.5.0/src/TOTP.php), [composer.json](https://raw.githubusercontent.com/Spomky-Labs/otphp/11.5.0/composer.json), [SECURITY.md](https://raw.githubusercontent.com/Spomky-Labs/otphp/11.5.0/SECURITY.md).
- [RFC 6238, §5.2 e apêndice B](https://www.rfc-editor.org/rfc/rfc6238), [OWASP MFA](https://cheatsheetseries.owasp.org/cheatsheets/Multifactor_Authentication_Cheat_Sheet.html).
- [BaconQrCode v3.1.1 README, Example usage/backends](https://github.com/Bacon/BaconQrCode/tree/v3.1.1#readme), [Writer.php v3.1.1](https://raw.githubusercontent.com/Bacon/BaconQrCode/v3.1.1/src/Writer.php), [SVG backend v3.1.1](https://raw.githubusercontent.com/Bacon/BaconQrCode/v3.1.1/src/Renderer/Image/SvgImageBackEnd.php).
- [sodium encrypt](https://www.php.net/manual/en/function.sodium-crypto-aead-xchacha20poly1305-ietf-encrypt.php), [decrypt](https://www.php.net/manual/en/function.sodium-crypto-aead-xchacha20poly1305-ietf-decrypt.php), Parameters/Examples.

APIs permitidas e armadilhas:

| API | Uso e limite |
| --- | --- |
| wp_get_session_token(): string | Extrai token, não valida a autenticidade do cookie. Validar sessão WordPress e usuário, não confiar na extração isolada. |
| WP_Session_Tokens::get_instance(user_id)->verify(token) | Valida existência/expiração; get/destroy/destroy_all para operações documentadas. Usar derivação do token na prova local, nunca armazená-lo em logs. |
| wp_check_password(password, hash, user_id = ''): bool | Reconfirma senha; não redefinir senha nem implementar esquema de hash do WordPress. |
| TOTP::generate(clock, secretSize); TOTP::createFromSecret(secret, clock) | OTPHP 11.5.0, MIT, PHP >=8.1. Clock PSR explícito. create() e setters mutáveis têm deprecações. |
| verify(code, timestamp = null, leeway = null): bool; at(timestamp): string | Retorno não consome código; leeway é segundos e menor que period. Não passar 30 para período 30. Identificar passo aceito e consumi-lo atomicamente. |
| getProvisioningUri(): string | Gerar URI local. Não usar QR externo da documentação, pois revela segredo. |
| Bacon ImageRenderer + RendererStyle + SvgImageBackEnd; Writer::writeString(content, ...) | SVG local em memória; PHP ^8.1, iconv e XMLWriter. Sem Imagick obrigatório, arquivos de QR ou scripts externos. |
| sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(message, aad, nonce, key) | Chave 32 bytes, nonce novo 24 bytes, AAD vinculando contexto. Envelope versionado. |
| sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(ciphertext, aad, nonce, key) | string ou false; erro/tamper/chave ausente nega painel. Nunca registrar plaintext/segredos. |

## D7 — GeoIP local e licença separada dos dados

Fontes: [GeoIP PHP, Database Reader/City Example](https://maxmind.github.io/GeoIP2-php/), [manifesto v3.4.0](https://raw.githubusercontent.com/maxmind/GeoIP2-php/v3.4.0/composer.json), [Reader.php v3.4.0](https://raw.githubusercontent.com/maxmind/GeoIP2-php/v3.4.0/src/Database/Reader.php), [GeoLite integração](https://dev.maxmind.com/geoip/geolite2-free-geolocation-data/), [EULA atualizada em 2026-02-12](https://www.maxmind.com/en/geolite/eula), [manutenção](https://support.maxmind.com/knowledge-base/articles/maintain-up-to-date-data).

`GeoIp2\Database\Reader::__construct(string $filename, array $locales = ['en'])`, city(string): City, country(string): Country, metadata(), close(). Reader PHP puro disponível; extensão C é otimização opcional. Exceções: GeoIp2\Exception\AddressNotFoundException, InvalidArgumentException e MaxMind\Db\Reader\InvalidDatabaseException. Campos podem ser nulos.

Biblioteca geoip2/geoip2 3.4.0 é Apache-2.0 e requer PHP >=8.1; licença da biblioteca não licencia o MMDB. Não incluir base ou chave de download no ZIP. Operador fornece arquivo licenciado e cuida de atualização/atribuição; considerar os termos atuais, inclusive §6.3 sobre dados antigos, sem prometer uma conclusão jurídica geral. Decisão conservadora do plano: geolocalizar na consulta usando base atual e não persistir geodados históricos. Medir tamanho/recursos do arquivo real; não foi encontrado tamanho fixo confiável nas fontes.

## D8 — testes e empacotamento

Fontes: [WordPress composer.json 7.1.4](https://raw.githubusercontent.com/WordPress/wordpress-develop/7.1.4/composer.json), [phpunit.xml.dist 7.1.4](https://raw.githubusercontent.com/WordPress/wordpress-develop/7.1.4/phpunit.xml.dist), [WPCS Installation](https://github.com/WordPress/WordPress-Coding-Standards#installation), [Plugin Check How to use](https://github.com/WordPress/plugin-check#how-to-use), [PHP-Scoper 0.18.20 Usage](https://github.com/humbug/php-scoper/tree/0.18.20#usage), [configuração 0.18.20](https://github.com/humbug/php-scoper/blob/0.18.20/docs/configuration.md), [Composer CLI](https://getcomposer.org/doc/03-cli.md).

Copiar padrões de bootstrap PHPUnit da versão do core; não impor a versão mais nova de PHPUnit sem provar compatibilidade. WPCS com PHPCS e PHPCompatibility; Plugin Check auxilia análise e pode reportar diferenças de distribuição MU a justificar. Não tratar aprovação da ferramenta como certificação de segurança.

Prefixar dependências de runtime no build com PHP-Scoper (prefixo estável StealthAuditVendor), preservando símbolos WordPress e testando referências dinâmicas. Ferramenta isolada do vendor de produção; versão publicada observada 0.18.20/PHP ^8.2, revalidar lock. Regenerar autoloader após scoping. Scripts Composer disponíveis: install, dump-autoload, check-platform-reqs, audit e licenses. ZIP final inclui vendor de produção já preparado, sem exigir Composer no servidor. Não incluir o vendor de desenvolvimento nem alterar o vendor do workspace ao empacotar.

## Proveniência e confiança

Extrações delegadas a /root/plan_wp_docs, /root/plan_mfa_docs e /root/plan_geo_release_docs segundo make-plan; os três entregaram fontes, APIs, localizadores e lacunas. Síntese e escolhas do plano feitas pelo agente principal. Confiança alta nas APIs lidas; compatibilidade final depende do lockfile e testes na matriz. Nenhuma afirmação aqui representa teste de runtime do plugin, que ainda não existe.
