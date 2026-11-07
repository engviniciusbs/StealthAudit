# Contrato de cobertura de eventos

Fase 0, 2026-11-07. Fontes fixadas em WordPress **7.1.4**. A tabela descreve o que será instrumentado, não afirma que o coletor já foi implementado ou testado. Ler a [compatibilidade](compatibility.md) e o [design](../specs/2026-11-07-stealthaudit-design.md).

## Envelope e dados permitidos

Um evento contém versão, horário UTC, categoria/ação/resultado, objeto por ID/tipo, ator quando conhecido, origem HTTP/REST/cron/CLI e IP confiável quando disponível. Não copiar argumentos completos dos hooks. Dados como senha, cookies, token, userdata, conteúdo de posts, WP_Error, old/new snapshots e valores indiscriminados de options/usermeta não pertencem ao evento.

O resultado deve distinguir sucesso confirmado, falha explícita e tentativa sem confirmação. Eventos de cron/CLI podem não ter ator humano/IP. Nenhuma ação ausente comprova que não aconteceu.

## Mapa de hooks

accepted_args é o número máximo passado ao adaptador; o adaptador seleciona os campos permitidos, não persiste todos os argumentos.

| Categoria | Hook e accepted_args | Dados permitidos / resultado | Limitação e fixture exigida |
| --- | --- | --- | --- |
| Acesso | wp_login(user_login, user), 2 | ID de user; login bem-sucedido. Não guardar objeto nem senha. | Emissão pelo fluxo wp_signon não cobre toda autenticação de terceiros. Testar login normal. |
| Acesso | wp_login_failed(username, error), 2 | Falha com código de erro selecionado; tentativa não é ator autenticado. | Username pode ser email/entrada arbitrária: não gravar cru como identidade comprovada. Testar entrada maliciosa e limites. |
| Acesso | wp_logout(user_id), 1 | ID recebido e logout explícito concluído. | Usuário atual já pode estar zerado; expiração passiva não emite todo logout. |
| Usuários | user_register(user_id, userdata), 2 | ID e criação concluída. Descartar userdata. | Testar usuário criado por API/REST/admin; nunca serializar credenciais. |
| Usuários | profile_update(user_id, old_user_data, userdata), 3 | ID e resumo de edição; sem valores/snapshots. | Pode se sobrepor a mudança de role; deduplicar mudanças lógicas. |
| Usuários | deleted_user(id, reassign, user), 3 | ID excluído; reassign opcional por ID permitido. | Pós-exclusão single-site; delete_user é pré-evento. Preservar referência histórica sem FK destrutiva. |
| Permissões | set_user_role(user_id, role, old_roles), 3; add_user_role/remove_user_role(user_id, role), 2 | ID, tipo de mudança e nome técnico permitido do role. | set_role pode emitir add/remove; definir deduplicação, sem transformar três hooks em três mudanças idênticas. |
| Permissões | added_user_meta/updated_user_meta/deleted_user_meta(meta_id(s), user_id, meta_key, value), 4 | Apenas mudanças na chave exata de capabilities da instalação; ID e tipo de ação. | value não é persistido. Não existe hook genérico added_user_cap a inventar; testar add_cap/remove_cap e filtro por prefixo. |
| Plugins | activated_plugin(plugin, network_wide), 2; deactivated_plugin(plugin, network_deactivating), 2 | Slug/basename validado e ação concluída. | Caminhos silent podem suprimir hook; não prometer cobertura dessas mudanças sem prova adicional. |
| Plugins | deleted_plugin(plugin_file, deleted), 2 | Slug e resultado booleano: true sucesso, false falha. | delete_plugin é início. Testar exclusão falha e bem-sucedida. |
| Temas | deleted_theme(stylesheet, deleted), 2 | Stylesheet validado e resultado booleano. | delete_theme é pré-evento, não confirmação. |
| Temas | switch_theme(new_name, new_theme, old_theme), 3 | Slugs dos temas e troca concluída, sem serializar WP_Theme. | Não existe ação independente deactivated_theme nesse contrato. |
| Instalação/update | upgrader_process_complete(upgrader, hook_extra), 2 | Tipo, ação e identificadores permitidos, inspecionando resultado por item. | Nome do hook não comprova sucesso total, especialmente bulk. Testar atualização parcial/core rollback/falha precoce. |
| Instalação/update | upgrader_install_package_result(result, hook_extra), 2, filtro | Observar resultado array/WP_Error e escolher código/identificadores permitidos. | Retornar **o mesmo resultado**; falha anterior pode não alcançar o filtro. Não serializar result nem alterar operação. |
| Configuração | added_option(option, value), 2; updated_option(option, old_value, value), 3; deleted_option(option), 1 | Nome de opção permitido e tipo de mudança, após persistência. | Descartar valores e excluir transients/estado interno; não prometer todas opções de plugins. |
| Conteúdo | wp_after_insert_post(post_id, post, update, post_before), 4 | ID, post_type permitido e criação/edição; descartar texto e before. | Remover autosave/revisões, deduplicar anexos; APIs podem suprimir after-hooks. |
| Conteúdo | transition_post_status(new_status, old_status, post), 3 | ID/tipo e mudança de estado, somente se mudou. | Diferenciar lixeira/restauração de exclusão física; não duplicar update geral. |
| Conteúdo | deleted_post(post_id, post), 2 | ID/tipo; exclusão física confirmada. | Pode cobrir anexos; preservar uma confirmação por objeto/operação. |
| Mídia | add_attachment(post_id), 1; edit_attachment(post_id), 1 | ID de attachment e ação. | Não gravar binário/URL privada/conteúdo; deduplicar com hook de post. |
| Mídia | delete_attachment(post_id, post), 2 | No máximo tentativa/início. | Pré-exclusão; aguardar deleted_post para sucesso. |

Lista inicial de options: home, siteurl, users_can_register, default_role, blog_public, show_on_front, page_on_front, page_for_posts, permalink_structure, default_comment_status e default_ping_status. É uma lista explícita a testar, não um coletor universal. Evitar snapshots, mesmo quando o valor parece inofensivo.

## Fontes versionadas e localizadores

- [wp-includes/user.php](https://github.com/WordPress/wordpress-develop/blob/7.1.4/src/wp-includes/user.php): wp_login perto de L138, profile_update L2678, user_register L2711.
- [wp-includes/pluggable.php](https://github.com/WordPress/wordpress-develop/blob/7.1.4/src/wp-includes/pluggable.php): wp_login_failed e wp_logout; APIs pluggable não devem ser chamadas cedo no loader MU.
- [wp-admin/includes/user.php](https://github.com/WordPress/wordpress-develop/blob/7.1.4/src/wp-admin/includes/user.php#L469): deleted_user.
- [class-wp-user.php](https://github.com/WordPress/wordpress-develop/blob/7.1.4/src/wp-includes/class-wp-user.php): roles L578/L606/L666, add_cap/remove_cap usam metadados.
- [meta.php](https://github.com/WordPress/wordpress-develop/blob/7.1.4/src/wp-includes/meta.php): ações dinâmicas posteriores de usermeta; filtrar a chave exata.
- [plugin.php](https://github.com/WordPress/wordpress-develop/blob/7.1.4/src/wp-admin/includes/plugin.php): activated_plugin L736, deactivated_plugin L846 e deleted_plugin L1008.
- [admin theme.php](https://github.com/WordPress/wordpress-develop/blob/7.1.4/src/wp-admin/includes/theme.php#L98) e [theme.php](https://github.com/WordPress/wordpress-develop/blob/7.1.4/src/wp-includes/theme.php#L875): exclusão e switch.
- [class-wp-upgrader.php](https://github.com/WordPress/wordpress-develop/blob/7.1.4/src/wp-admin/includes/class-wp-upgrader.php): localizar os símbolos upgrader_install_package_result e upgrader_process_complete; conferir também [Plugin_Upgrader](https://github.com/WordPress/wordpress-develop/blob/7.1.4/src/wp-admin/includes/class-plugin-upgrader.php), [Theme_Upgrader](https://github.com/WordPress/wordpress-develop/blob/7.1.4/src/wp-admin/includes/class-theme-upgrader.php) e [Core_Upgrader](https://github.com/WordPress/wordpress-develop/blob/7.1.4/src/wp-admin/includes/class-core-upgrader.php), cujos caminhos de erro/lote diferem.
- [option.php](https://github.com/WordPress/wordpress-develop/blob/7.1.4/src/wp-includes/option.php): ações posteriores L1030/L1186/L1273.
- [post.php](https://github.com/WordPress/wordpress-develop/blob/7.1.4/src/wp-includes/post.php): deleted_post L3996/L6904, anexos L5186/L5209/L6875 e wp_after_insert_post L6021; transition_post_status no mesmo arquivo.

As linhas são localizadores da tag consultada; a assinatura e o ponto de emissão, não um número de linha de trunk, definem o contrato.

## Diagnóstico PHP e WordPress

Usar [set_error_handler](https://www.php.net/manual/en/function.set-error-handler.php) com callback de quatro argumentos, respeitando error_reporting/supressão e o tratador anterior. Registrar gravidade, mensagem reduzida, arquivo/linha e horário quando disponíveis. Não ativar WP_DEBUG ou display_errors automaticamente.

[register_shutdown_function](https://www.php.net/manual/en/function.register-shutdown-function.php) e [error_get_last](https://www.php.net/manual/en/function.error-get-last.php) permitem certos fatais apenas quando o processo chega ao shutdown com recursos suficientes. Falhas anteriores ao loader, terminação forçada, indisponibilidade do banco, erros de servidor e chamadas error_log arbitrárias não têm cobertura garantida. Não prometer equivalência integral a debug.log.

## Gates de teste para os coletores

Fixtures por categoria e pelo resultado real: sucesso, falha, lote parcial, exclusão/lixeira, duplicação de roles/post/anexo e origem sem ator/IP. SQL direto, filesystem externo, hooks suprimidos e autenticação fora das APIs instrumentadas são lacunas explícitas. Nunca preencher um evento de sucesso para encobrir ausência de confirmação.

Registros que incluam dados sensíveis por entrada de plugin/usuário são reduzidos e escapados. Capacidade/ritmo e falhas da própria coleta devem ser testados sem bloquear o site; nenhum contador de saúde comprova ausência de perdas quando o armazenamento falha.
