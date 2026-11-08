<?php
/**
 * Operator panel, Hallmark ASCII Terminal (app scope).
 *
 * Receives only escaped-at-output values. No log data, QR, recovery code or secret is
 * rendered here: those arrive from the authorized REST API into memory and the DOM only.
 *
 * @package StealthAudit
 *
 * @var array{status:string,panel:string,rest:string,nonce:string,rest_nonce:string,login:string,query:array<string,string>,view:string,filters:array<string,string>,cursor:?string,invalid:bool,tabs:array<string,string>,action:string,hidden:array<string,string>,assets:array<string,string>,site:string,host:string} $stealthaudit_view
 */

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

$stealthaudit_status  = $stealthaudit_view['status'];
$stealthaudit_current = $stealthaudit_view['view'];
$stealthaudit_filters = $stealthaudit_view['filters'];
$stealthaudit_known   = array( 'password_required', 'enrollment_required', 'enrollment_pending', 'challenge_required', 'authorized', 'recovery_locked' );
if ( ! in_array( $stealthaudit_status, $stealthaudit_known, true ) ) {
	// Fail closed on a status this template does not know: no forms, no data.
	$stealthaudit_status = 'unknown';
}
$stealthaudit_authorized = 'authorized' === $stealthaudit_status;

$stealthaudit_labels = array(
	'category'    => array(
		'access'  => __( 'Acesso', 'stealthaudit' ),
		'users'   => __( 'Usuários', 'stealthaudit' ),
		'admin'   => __( 'Administração', 'stealthaudit' ),
		'content' => __( 'Conteúdo', 'stealthaudit' ),
	),
	'result'      => array(
		'success' => __( 'Sucesso', 'stealthaudit' ),
		'failure' => __( 'Falha', 'stealthaudit' ),
		'unknown' => __( 'Desconhecido', 'stealthaudit' ),
	),
	'severity'    => array(
		'fatal'      => __( 'Fatal', 'stealthaudit' ),
		'error'      => __( 'Erro', 'stealthaudit' ),
		'warning'    => __( 'Aviso', 'stealthaudit' ),
		'notice'     => __( 'Nota', 'stealthaudit' ),
		'deprecated' => __( 'Obsoleto', 'stealthaudit' ),
	),
	'origin'      => array(
		'http'    => __( 'Site', 'stealthaudit' ),
		'admin'   => __( 'Painel WordPress', 'stealthaudit' ),
		'rest'    => __( 'REST', 'stealthaudit' ),
		'cron'    => __( 'Cron', 'stealthaudit' ),
		'cli'     => __( 'WP-CLI', 'stealthaudit' ),
		'unknown' => __( 'Desconhecida', 'stealthaudit' ),
	),
	'capture'     => array(
		'available'   => __( 'disponível', 'stealthaudit' ),
		'limited'     => __( 'perdas registradas desde a última manutenção', 'stealthaudit' ),
		'suspended'   => __( 'suspensa por capacidade: novos registros não estão sendo gravados', 'stealthaudit' ),
		'unavailable' => __( 'estado indisponível', 'stealthaudit' ),
	),
	'maintenance' => array(
		'current'     => __( 'em dia', 'stealthaudit' ),
		'backlog'     => __( 'com trabalho pendente; a próxima execução continua de onde parou', 'stealthaudit' ),
		'blocked'     => __( 'recontagem adiada: havia gravação em andamento', 'stealthaudit' ),
		'overdue'     => __( 'atrasada: o WP-Cron depende de visitas ao site', 'stealthaudit' ),
		'failed'      => __( 'a última execução falhou', 'stealthaudit' ),
		'never'       => __( 'ainda não executada', 'stealthaudit' ),
		'unavailable' => __( 'estado indisponível', 'stealthaudit' ),
	),
	'diagnostics' => array(
		'available'                => __( 'ativo nesta requisição', 'stealthaudit' ),
		'waiting_storage'          => __( 'aguardando o armazenamento', 'stealthaudit' ),
		'limited'                  => __( 'limitado nesta requisição', 'stealthaudit' ),
		'limited_previous_handler' => __( 'limitado: outro manipulador de erros do site tem prioridade sobre avisos não fatais', 'stealthaudit' ),
		'storage_unavailable'      => __( 'sem armazenamento', 'stealthaudit' ),
		'disabled'                 => __( 'desativado', 'stealthaudit' ),
	),
);

$stealthaudit_messages = array(
	'busy'             => __( 'Enviando…', 'stealthaudit' ),
	'code_invalid'     => __( 'O código tem 6 dígitos. Confira o aplicativo autenticador e digite de novo.', 'stealthaudit' ),
	'recovery_invalid' => __( 'O código de recuperação tem 32 caracteres hexadecimais. Confira e digite de novo.', 'stealthaudit' ),
	'password_empty'   => __( 'Informe a senha do WordPress.', 'stealthaudit' ),
	'ack_required'     => __( 'Confirme que guardou os códigos antes de continuar.', 'stealthaudit' ),
	'denied'           => __( 'Não foi possível confirmar. Confira os dados e tente de novo.', 'stealthaudit' ),
	'try_later'        => __( 'Muitas tentativas em pouco tempo. Aguarde alguns minutos e tente de novo.', 'stealthaudit' ),
	'unavailable'      => __( 'O painel está indisponível agora. Tente mais tarde ou fale com o operador do servidor.', 'stealthaudit' ),
	'network'          => __( 'Sem resposta do servidor. Confira a conexão e tente de novo.', 'stealthaudit' ),
	'stale'            => __( 'A página ficou desatualizada. Recarregue para continuar.', 'stealthaudit' ),
	'qr_alt'           => __( 'Código QR do cadastro no aplicativo autenticador', 'stealthaudit' ),
	'qr_failed'        => __( 'Não foi possível exibir o QR. Confira a senha do WordPress e tente de novo; se o cadastro pendente já expirou, recarregue a página.', 'stealthaudit' ),
	'qr_ready'         => __( 'Código QR exibido. Escaneie e informe o código gerado.', 'stealthaudit' ),
	'codes_ready'      => __( 'Cadastro confirmado. Guarde os códigos de recuperação.', 'stealthaudit' ),
	'expired_heading'  => __( 'A verificação expirou', 'stealthaudit' ),
	'loading'          => __( 'Carregando registros…', 'stealthaudit' ),
	/* translators: %d: number of rows on this page. */
	'loaded'           => __( 'Registros carregados: %d.', 'stealthaudit' ),
	'more'             => __( 'Há mais registros na próxima página.', 'stealthaudit' ),
	'empty_title'      => __( 'Nenhum registro com estes filtros', 'stealthaudit' ),
	'empty_text'       => __( 'Não há registros no período e nos filtros escolhidos. Amplie o período ou limpe os filtros.', 'stealthaudit' ),
	'empty_partial'    => __( 'A lista está vazia, mas a captura não está completa: a ausência de registros não comprova que nada aconteceu.', 'stealthaudit' ),
	'error_title'      => __( 'Não foi possível carregar os registros', 'stealthaudit' ),
	'error_text'       => __( 'O servidor não respondeu à consulta. Tente de novo; se continuar, fale com o operador do servidor.', 'stealthaudit' ),
	'filters_title'    => __( 'Filtros recusados', 'stealthaudit' ),
	'filters_text'     => __( 'O servidor recusou os filtros. Revise o período (formato 2026-11-01T00:00:00Z), o IP e o ID do usuário.', 'stealthaudit' ),
	'retry'            => __( 'Tentar de novo', 'stealthaudit' ),
	'clear'            => __( 'Limpar filtros', 'stealthaudit' ),
	'capture_events'   => __( 'Captura de auditoria:', 'stealthaudit' ),
	'capture_errors'   => __( 'Captura de erros:', 'stealthaudit' ),
	'collector'        => __( 'Coletor de erros:', 'stealthaudit' ),
	'maintenance'      => __( 'Manutenção:', 'stealthaudit' ),
	/* translators: %s: ISO 8601 UTC time of the last maintenance run. */
	'last_run'         => __( 'última execução %s', 'stealthaudit' ),
	/* translators: %d: records discarded in the period closed by the last maintenance. */
	'dropped_before'   => __( '%d descartados antes da última manutenção', 'stealthaudit' ),
	/* translators: %d: cumulative number of discarded records. */
	'dropped'          => __( '%d descartados', 'stealthaudit' ),
	'none'             => __( 'sem registro', 'stealthaudit' ),
	/* translators: %d: WordPress user ID. */
	'user_prefix'      => __( 'ID %d', 'stealthaudit' ),
	/* translators: %d: how many times the error happened. */
	'occurrences'      => __( '%d vezes', 'stealthaudit' ),
);

$stealthaudit_json = (string) wp_json_encode(
	array(
		'messages' => $stealthaudit_messages,
		'labels'   => $stealthaudit_labels,
	),
	JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);

$stealthaudit_title = $stealthaudit_authorized ? ( 'errors' === $stealthaudit_current ? __( 'Erros', 'stealthaudit' ) : __( 'Auditoria', 'stealthaudit' ) ) : __( 'Verificação', 'stealthaudit' );

/**
 * Render one labelled text field; every value is escaped here.
 *
 * @param string $id Unique id.
 * @param string $name API field name.
 * @param string $label Visible label.
 * @param string $kind password|code|recovery.
 * @param string $hint Short instruction.
 */
$stealthaudit_field = static function ( string $id, string $name, string $label, string $kind, string $hint = '' ): void {
	$attributes = array(
		'password' => 'type="password" autocomplete="current-password"',
		'code'     => 'type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="12" spellcheck="false"',
		'recovery' => 'type="text" autocomplete="off" autocapitalize="none" maxlength="64" spellcheck="false"',
	);
	?>
	<div class="field">
		<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label>
		<?php if ( '' !== $hint ) : ?>
		<p class="field__hint" id="<?php echo esc_attr( $id . '-hint' ); ?>"><?php echo esc_html( $hint ); ?></p>
		<?php endif; ?>
		<input class="input<?php echo 'code' === $kind ? ' input--code' : ''; ?>" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" <?php echo $attributes[ $kind ]; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed literal attribute sets. ?> required aria-required="true"<?php echo '' !== $hint ? ' aria-describedby="' . esc_attr( $id . '-hint ' . $id . '-help' ) . '"' : ' aria-describedby="' . esc_attr( $id . '-help' ) . '"'; ?>>
		<p class="help" id="<?php echo esc_attr( $id . '-help' ); ?>" data-sa-help></p>
	</div>
	<?php
};

/**
 * Open a JSON-API form whose controls stay disabled until the script enables them.
 *
 * @param string $action API operation.
 * @param string $label Accessible form name.
 */
$stealthaudit_open  = static function ( string $action, string $label ): void {
	?>
	<form class="form" method="post" novalidate data-sa-action="<?php echo esc_attr( $action ); ?>" aria-label="<?php echo esc_attr( $label ); ?>">
	<noscript><p class="help"><?php esc_html_e( 'Este formulário precisa de JavaScript: a senha e os códigos são enviados no corpo de um pedido protegido, nunca no endereço.', 'stealthaudit' ); ?></p></noscript>
	<fieldset disabled data-sa-js>
	<?php
};
$stealthaudit_close = static function ( string $submit, bool $primary = true ): void {
	?>
	<p class="help" data-sa-form-status role="status" aria-live="polite"></p>
	<div class="actions"><button type="submit" class="btn<?php echo $primary ? ' btn--primary' : ''; ?>"><?php echo esc_html( $submit ); ?></button></div>
	</fieldset>
	</form>
	<?php
};

/**
 * Screen opening: section, heading that receives focus on switch, intro text.
 *
 * @param string $screen Screen key.
 * @param string $heading Heading text.
 * @param bool   $visible Whether it is the current screen.
 */
$stealthaudit_screen = static function ( string $screen, string $heading, bool $visible ): void {
	?>
	<section class="auth" data-sa-screen="<?php echo esc_attr( $screen ); ?>" aria-labelledby="<?php echo esc_attr( 'sa-h-' . $screen ); ?>"<?php echo $visible ? '' : ' hidden'; ?>>
	<h1 id="<?php echo esc_attr( 'sa-h-' . $screen ); ?>" tabindex="-1"><?php echo esc_html( $heading ); ?></h1>
	<?php
};
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<meta name="referrer" content="no-referrer">
<meta name="description" content="<?php esc_attr_e( 'Painel do StealthAudit: auditoria e erros do WordPress para operadores autorizados.', 'stealthaudit' ); ?>">
<title><?php echo esc_html( 'StealthAudit: ' . $stealthaudit_title ); ?></title>
<?php foreach ( array( 'tokens.css', 'base.css', 'panel.css' ) as $stealthaudit_file ) : ?>
<link rel="stylesheet" href="<?php echo esc_url( $stealthaudit_view['assets'][ $stealthaudit_file ] ); ?>"><?php // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- Standalone page outside the theme; local file served by the router. ?>
<?php endforeach; ?>
<script src="<?php echo esc_url( $stealthaudit_view['assets']['panel.js'] ); ?>" defer></script><?php // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Standalone page outside the theme; local file served by the router. ?>
</head>
<body>
<a class="skip" href="#conteudo"><?php esc_html_e( 'Ir para o conteúdo', 'stealthaudit' ); ?></a>
<header class="bar">
	<div class="container bar__inner">
		<p class="bar__mark"><span aria-hidden="true">[</span>StealthAudit<span aria-hidden="true">]</span></p>
		<p class="bar__site"><span class="visually-hidden"><?php esc_html_e( 'Site:', 'stealthaudit' ); ?></span><span><?php echo esc_html( '' !== $stealthaudit_view['site'] ? $stealthaudit_view['site'] : $stealthaudit_view['host'] ); ?></span><span class="bar__host"><?php echo esc_html( $stealthaudit_view['host'] ); ?></span></p>
		<?php if ( $stealthaudit_authorized ) : ?>
		<nav aria-label="<?php esc_attr_e( 'Áreas do painel', 'stealthaudit' ); ?>" data-sa-chrome>
			<ul class="tabs">
				<li><a href="<?php echo esc_url( $stealthaudit_view['tabs']['audit'] ); ?>"<?php echo 'audit' === $stealthaudit_current ? ' aria-current="page"' : ''; ?>><?php esc_html_e( 'Auditoria', 'stealthaudit' ); ?></a></li>
				<li><a href="<?php echo esc_url( $stealthaudit_view['tabs']['errors'] ); ?>"<?php echo 'errors' === $stealthaudit_current ? ' aria-current="page"' : ''; ?>><?php esc_html_e( 'Erros', 'stealthaudit' ); ?></a></li>
			</ul>
		</nav>
		<div class="bar__end" data-sa-chrome>
			<form method="post" novalidate data-sa-action="revoke" aria-label="<?php esc_attr_e( 'Sair do StealthAudit', 'stealthaudit' ); ?>">
				<fieldset disabled data-sa-js>
					<button type="submit" class="btn" aria-describedby="sa-revoke-note"><?php esc_html_e( 'Sair do StealthAudit', 'stealthaudit' ); ?></button>
				</fieldset>
			</form>
			<p class="visually-hidden" id="sa-revoke-note"><?php esc_html_e( 'Encerra só a verificação do StealthAudit; a sessão do WordPress continua.', 'stealthaudit' ); ?></p>
		</div>
		<?php endif; ?>
	</div>
</header>
<main id="conteudo" class="container screen" tabindex="-1"
	data-sa-root
	data-status="<?php echo esc_attr( $stealthaudit_status ); ?>"
	data-view="<?php echo esc_attr( $stealthaudit_current ); ?>"
	data-rest="<?php echo esc_url( $stealthaudit_view['rest'] ); ?>"
	data-panel="<?php echo esc_url( $stealthaudit_view['panel'] ); ?>"
	data-nonce="<?php echo esc_attr( $stealthaudit_view['nonce'] ); ?>"
	data-rest-nonce="<?php echo esc_attr( $stealthaudit_view['rest_nonce'] ); ?>"
	data-filters="<?php echo esc_attr( (string) wp_json_encode( (object) $stealthaudit_filters ) ); ?>"
	data-cursor="<?php echo esc_attr( (string) $stealthaudit_view['cursor'] ); ?>">
	<noscript><p class="notice"><?php esc_html_e( 'O StealthAudit precisa de JavaScript para verificar o acesso e carregar os registros. Esta página mostra o estado atual, mas os formulários ficam desativados sem script.', 'stealthaudit' ); ?></p></noscript>
	<p class="visually-hidden" role="status" aria-live="polite" data-sa-live></p>

	<?php $stealthaudit_screen( 'session', __( 'Sessão do WordPress encerrada', 'stealthaudit' ), false ); ?>
		<p><?php esc_html_e( 'A sessão do WordPress terminou ou foi revogada. Entre de novo no WordPress; depois você volta para esta página com os mesmos filtros.', 'stealthaudit' ); ?></p>
		<div class="actions"><a class="btn btn--primary" href="<?php echo esc_url( $stealthaudit_view['login'] ); ?>"><?php esc_html_e( 'Entrar no WordPress', 'stealthaudit' ); ?></a></div>
	</section>

	<?php if ( 'password_required' === $stealthaudit_status ) : ?>
		<?php $stealthaudit_screen( 'password', __( 'Confirme sua senha', 'stealthaudit' ), true ); ?>
		<p><?php esc_html_e( 'Você está conectado ao WordPress. Para abrir o StealthAudit, confirme a senha desta conta; a confirmação vale por cinco minutos e não dá acesso aos registros sozinha.', 'stealthaudit' ); ?></p>
		<?php $stealthaudit_open( 'reconfirm', __( 'Confirmar senha', 'stealthaudit' ) ); ?>
			<?php $stealthaudit_field( 'sa-password', 'password', __( 'Senha do WordPress', 'stealthaudit' ), 'password' ); ?>
		<?php $stealthaudit_close( __( 'Confirmar senha', 'stealthaudit' ) ); ?>
	</section>
	<?php endif; ?>

	<?php if ( 'enrollment_required' === $stealthaudit_status ) : ?>
		<?php $stealthaudit_screen( 'enroll', __( 'Cadastre o autenticador', 'stealthaudit' ), true ); ?>
		<p><?php esc_html_e( 'O StealthAudit exige um código de um aplicativo autenticador (TOTP), separado do login do WordPress. Gere o código QR e escaneie no aplicativo do seu celular.', 'stealthaudit' ); ?></p>
		<?php $stealthaudit_open( 'enrollment/begin', __( 'Gerar código QR', 'stealthaudit' ) ); ?>
		<?php $stealthaudit_close( __( 'Gerar código QR', 'stealthaudit' ) ); ?>
	</section>
	<?php endif; ?>

	<?php if ( in_array( $stealthaudit_status, array( 'enrollment_required', 'enrollment_pending', 'challenge_required', 'authorized' ), true ) ) : ?>
		<?php $stealthaudit_screen( 'setup', __( 'Confirme o cadastro', 'stealthaudit' ), 'enrollment_pending' === $stealthaudit_status ); ?>
		<p><?php esc_html_e( 'Escaneie o código QR no aplicativo autenticador e digite o código de 6 dígitos que ele mostrar. O cadastro pendente expira em dez minutos; até a confirmação, o código anterior não abre o painel.', 'stealthaudit' ); ?></p>
		<p class="lead-in"><strong><?php esc_html_e( 'Não recarregue nem saia desta página antes de confirmar.', 'stealthaudit' ); ?></strong> <?php esc_html_e( 'O QR some ao sair; para exibi-lo de novo é preciso confirmar a senha do WordPress.', 'stealthaudit' ); ?></p>
		<div class="qr" data-sa-qr hidden></div>
		<div data-sa-redisplay>
			<p><?php esc_html_e( 'O QR deste cadastro não está na tela. Confirme a senha do WordPress para exibi-lo de novo; o cadastro pendente continua o mesmo.', 'stealthaudit' ); ?></p>
			<?php $stealthaudit_open( 'redisplay', __( 'Exibir o código QR', 'stealthaudit' ) ); ?>
				<?php $stealthaudit_field( 'sa-redisplay-password', 'password', __( 'Senha do WordPress', 'stealthaudit' ), 'password' ); ?>
			<?php $stealthaudit_close( __( 'Exibir o código QR', 'stealthaudit' ), false ); ?>
		</div>
		<?php $stealthaudit_open( 'enrollment/confirm', __( 'Confirmar cadastro', 'stealthaudit' ) ); ?>
			<?php $stealthaudit_field( 'sa-setup-code', 'code', __( 'Código do autenticador', 'stealthaudit' ), 'code', __( '6 dígitos; pode colar com espaços.', 'stealthaudit' ) ); ?>
		<?php $stealthaudit_close( __( 'Confirmar cadastro', 'stealthaudit' ) ); ?>
	</section>

		<?php $stealthaudit_screen( 'codes', __( 'Guarde os códigos de recuperação', 'stealthaudit' ), false ); ?>
		<p><?php esc_html_e( 'Estes códigos aparecem só agora e cada um vale uma vez. Com a senha do WordPress, um deles permite cadastrar outro autenticador se o celular for perdido. Guarde fora deste computador.', 'stealthaudit' ); ?></p>
		<ol class="codes" data-sa-codes></ol>
		<form class="form" method="post" novalidate data-sa-action="codes-ack" aria-label="<?php esc_attr_e( 'Abrir o painel', 'stealthaudit' ); ?>">
			<fieldset disabled data-sa-js>
				<div class="check"><input type="checkbox" id="sa-codes-ack" name="ack" aria-describedby="sa-codes-ack-help"><label for="sa-codes-ack"><?php esc_html_e( 'Guardei os códigos em local seguro', 'stealthaudit' ); ?></label></div>
				<p class="help" id="sa-codes-ack-help" data-sa-help></p>
				<div class="actions"><button type="submit" class="btn btn--primary"><?php esc_html_e( 'Abrir o painel', 'stealthaudit' ); ?></button></div>
			</fieldset>
		</form>
	</section>
	<?php endif; ?>

	<?php if ( in_array( $stealthaudit_status, array( 'challenge_required', 'authorized' ), true ) ) : ?>
		<?php $stealthaudit_screen( 'challenge', __( 'Informe o código do autenticador', 'stealthaudit' ), 'challenge_required' === $stealthaudit_status ); ?>
		<p data-sa-variant="first"><?php esc_html_e( 'Você está conectado ao WordPress; falta só verificar o StealthAudit. Digite o código de 6 dígitos do aplicativo autenticador.', 'stealthaudit' ); ?></p>
		<p data-sa-variant="expired" hidden><?php esc_html_e( 'Os registros continuam guardados e os filtros desta página foram mantidos. Digite um novo código para voltar.', 'stealthaudit' ); ?></p>
		<?php if ( array() !== $stealthaudit_view['query'] && ! $stealthaudit_authorized ) : ?>
		<p class="lead-in"><?php esc_html_e( 'Os filtros do endereço serão aplicados depois da verificação.', 'stealthaudit' ); ?></p>
		<?php endif; ?>
		<?php $stealthaudit_open( 'authenticate', __( 'Verificar código', 'stealthaudit' ) ); ?>
			<?php $stealthaudit_field( 'sa-challenge-code', 'code', __( 'Código do autenticador', 'stealthaudit' ), 'code', __( '6 dígitos; pode colar com espaços.', 'stealthaudit' ) ); ?>
		<?php $stealthaudit_close( __( 'Verificar', 'stealthaudit' ) ); ?>
		<details>
			<summary><?php esc_html_e( 'Perdi o acesso ao autenticador', 'stealthaudit' ); ?></summary>
			<p><?php esc_html_e( 'Use a senha do WordPress e um código de recuperação. Isso só permite cadastrar outro autenticador; os registros abrem depois da confirmação do novo código.', 'stealthaudit' ); ?></p>
			<?php $stealthaudit_open( 'recover', __( 'Recuperar acesso', 'stealthaudit' ) ); ?>
				<?php $stealthaudit_field( 'sa-recover-password', 'password', __( 'Senha do WordPress', 'stealthaudit' ), 'password' ); ?>
				<?php $stealthaudit_field( 'sa-recover-code', 'recovery_code', __( 'Código de recuperação', 'stealthaudit' ), 'recovery', __( '32 caracteres, como foram exibidos no cadastro.', 'stealthaudit' ) ); ?>
			<?php $stealthaudit_close( __( 'Recuperar acesso', 'stealthaudit' ), false ); ?>
		</details>
	</section>
	<?php endif; ?>

	<?php if ( 'recovery_locked' === $stealthaudit_status ) : ?>
		<?php $stealthaudit_screen( 'locked', __( 'Recuperação bloqueada', 'stealthaudit' ), true ); ?>
		<p><?php esc_html_e( 'A troca do autenticador não foi concluída a tempo. Por segurança, esta conta não pode reabrir o cadastro só com senha e sessão.', 'stealthaudit' ); ?></p>
		<p><?php esc_html_e( 'Peça ao operador do servidor o reset offline do fator, descrito no runbook de segurança do painel. Depois, recarregue esta página para cadastrar o novo autenticador.', 'stealthaudit' ); ?></p>
	</section>
	<?php endif; ?>

	<?php if ( 'unknown' === $stealthaudit_status ) : ?>
		<?php $stealthaudit_screen( 'unknown', __( 'Estado desconhecido', 'stealthaudit' ), true ); ?>
		<p><?php esc_html_e( 'Não foi possível determinar o estado do acesso ao StealthAudit, então nada foi liberado. Recarregue a página; se continuar, fale com o operador do servidor.', 'stealthaudit' ); ?></p>
	</section>
	<?php endif; ?>

	<?php if ( $stealthaudit_authorized ) : ?>
	<section class="view" data-sa-screen="workspace" aria-labelledby="sa-view-title">
		<div class="view__head">
			<h1 id="sa-view-title" tabindex="-1"><?php echo esc_html( $stealthaudit_title ); ?></h1>
			<p class="view__tz"><?php esc_html_e( 'Horários em UTC.', 'stealthaudit' ); ?></p>
		</div>
		<?php if ( $stealthaudit_view['invalid'] ) : ?>
		<p class="notice" role="alert"><?php esc_html_e( 'Os filtros do endereço não são válidos e foram ignorados. A lista abaixo não tem filtros.', 'stealthaudit' ); ?></p>
		<?php endif; ?>

		<form class="filters" method="get" action="<?php echo esc_url( $stealthaudit_view['action'] ); ?>" aria-label="<?php esc_attr_e( 'Filtros', 'stealthaudit' ); ?>" data-sa-filters>
			<?php foreach ( $stealthaudit_view['hidden'] as $stealthaudit_key => $stealthaudit_value ) : ?>
			<input type="hidden" name="<?php echo esc_attr( $stealthaudit_key ); ?>" value="<?php echo esc_attr( $stealthaudit_value ); ?>">
			<?php endforeach; ?>
			<input type="hidden" name="view" value="<?php echo esc_attr( $stealthaudit_current ); ?>">
			<div class="filters__grid">
				<div class="field">
					<label for="sa-from"><?php esc_html_e( 'De (UTC)', 'stealthaudit' ); ?></label>
					<input class="input" id="sa-from" name="from" type="text" inputmode="text" spellcheck="false" placeholder="2026-11-01T00:00:00Z" value="<?php echo esc_attr( $stealthaudit_filters['from'] ?? '' ); ?>">
				</div>
				<div class="field">
					<label for="sa-to"><?php esc_html_e( 'Até (UTC)', 'stealthaudit' ); ?></label>
					<input class="input" id="sa-to" name="to" type="text" inputmode="text" spellcheck="false" placeholder="2026-11-07T23:59:59Z" value="<?php echo esc_attr( $stealthaudit_filters['to'] ?? '' ); ?>">
				</div>
				<?php if ( 'audit' === $stealthaudit_current ) : ?>
				<div class="field">
					<label for="sa-user"><?php esc_html_e( 'ID do usuário', 'stealthaudit' ); ?></label>
					<input class="input" id="sa-user" name="user" type="text" inputmode="numeric" spellcheck="false" value="<?php echo esc_attr( $stealthaudit_filters['user'] ?? '' ); ?>">
				</div>
					<?php
					foreach ( array(
						'category' => array( __( 'Categoria', 'stealthaudit' ), $stealthaudit_labels['category'] ),
						'action'   => array( __( 'Ação', 'stealthaudit' ), array_combine( \StealthAudit\Storage\EventRepository::ACTIONS, \StealthAudit\Storage\EventRepository::ACTIONS ) ),
						'result'   => array( __( 'Resultado', 'stealthaudit' ), $stealthaudit_labels['result'] ),
					) as $stealthaudit_key => $stealthaudit_select ) :
						?>
				<div class="field">
					<label for="<?php echo esc_attr( 'sa-' . $stealthaudit_key ); ?>"><?php echo esc_html( $stealthaudit_select[0] ); ?></label>
					<select class="select" id="<?php echo esc_attr( 'sa-' . $stealthaudit_key ); ?>" name="<?php echo esc_attr( $stealthaudit_key ); ?>">
						<option value=""><?php esc_html_e( 'Todas', 'stealthaudit' ); ?></option>
						<?php foreach ( $stealthaudit_select[1] as $stealthaudit_code => $stealthaudit_label ) : ?>
						<option value="<?php echo esc_attr( $stealthaudit_code ); ?>"<?php selected( $stealthaudit_filters[ $stealthaudit_key ] ?? '', $stealthaudit_code ); ?>><?php echo esc_html( $stealthaudit_label ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<?php endforeach; ?>
				<div class="field">
					<label for="sa-ip"><?php esc_html_e( 'IP', 'stealthaudit' ); ?></label>
					<input class="input" id="sa-ip" name="ip" type="text" spellcheck="false" autocomplete="off" value="<?php echo esc_attr( $stealthaudit_filters['ip'] ?? '' ); ?>">
				</div>
				<?php else : ?>
				<div class="field">
					<label for="sa-severity"><?php esc_html_e( 'Gravidade', 'stealthaudit' ); ?></label>
					<select class="select" id="sa-severity" name="severity">
						<option value=""><?php esc_html_e( 'Todas', 'stealthaudit' ); ?></option>
						<?php foreach ( $stealthaudit_labels['severity'] as $stealthaudit_code => $stealthaudit_label ) : ?>
						<option value="<?php echo esc_attr( $stealthaudit_code ); ?>"<?php selected( $stealthaudit_filters['severity'] ?? '', $stealthaudit_code ); ?>><?php echo esc_html( $stealthaudit_label ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<?php endif; ?>
				<div class="field">
					<label for="sa-limit"><?php esc_html_e( 'Linhas por página', 'stealthaudit' ); ?></label>
					<select class="select" id="sa-limit" name="limit">
						<?php foreach ( array( '25', '50', '100' ) as $stealthaudit_limit ) : ?>
						<option value="<?php echo esc_attr( $stealthaudit_limit ); ?>"<?php selected( $stealthaudit_filters['limit'] ?? '50', $stealthaudit_limit ); ?>><?php echo esc_html( $stealthaudit_limit ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
			</div>
			<div class="actions">
				<button type="submit" class="btn btn--primary"><?php esc_html_e( 'Filtrar', 'stealthaudit' ); ?></button>
				<a class="btn" href="<?php echo esc_url( $stealthaudit_view['tabs'][ $stealthaudit_current ] ); ?>"><?php esc_html_e( 'Limpar filtros', 'stealthaudit' ); ?></a>
			</div>
		</form>

		<div class="capture" data-sa-capture aria-live="polite" hidden></div>

		<div class="records" data-sa-records data-state="idle">
			<noscript>
				<div class="records__message">
					<h2><?php esc_html_e( 'Registros indisponíveis sem JavaScript', 'stealthaudit' ); ?></h2>
					<p><?php esc_html_e( 'Os registros são carregados pela API protegida do painel depois da verificação. Ative o JavaScript para consultar; os filtros acima continuam no endereço da página.', 'stealthaudit' ); ?></p>
				</div>
			</noscript>
			<div class="records__message" data-sa-message hidden>
				<h2 data-sa-message-title></h2>
				<p data-sa-message-text></p>
				<p data-sa-message-extra hidden></p>
				<div class="actions" data-sa-message-actions></div>
			</div>
			<div class="records__scroll" data-sa-table role="region" aria-labelledby="sa-caption" tabindex="0" hidden>
				<table class="ledger">
					<caption id="sa-caption"><span class="ascii" aria-hidden="true">+-- </span><?php echo esc_html( 'errors' === $stealthaudit_current ? __( 'Erros e avisos do PHP, do mais recente ao mais antigo', 'stealthaudit' ) : __( 'Eventos de auditoria, do mais recente ao mais antigo', 'stealthaudit' ) ); ?></caption>
					<thead>
						<tr>
							<?php if ( 'errors' === $stealthaudit_current ) : ?>
							<th scope="col"><?php esc_html_e( 'Primeira vez (UTC)', 'stealthaudit' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Gravidade', 'stealthaudit' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Mensagem', 'stealthaudit' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Arquivo e linha', 'stealthaudit' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Ocorrências', 'stealthaudit' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Última vez (UTC)', 'stealthaudit' ); ?></th>
							<?php else : ?>
							<th scope="col"><?php esc_html_e( 'Horário (UTC)', 'stealthaudit' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Resultado', 'stealthaudit' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Ação', 'stealthaudit' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Categoria', 'stealthaudit' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Usuário', 'stealthaudit' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Objeto', 'stealthaudit' ); ?></th>
							<th scope="col"><?php esc_html_e( 'IP', 'stealthaudit' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Origem', 'stealthaudit' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Detalhes', 'stealthaudit' ); ?></th>
							<?php endif; ?>
						</tr>
					</thead>
					<tbody data-sa-rows></tbody>
				</table>
			</div>
		</div>

		<nav class="pager" aria-label="<?php esc_attr_e( 'Paginação', 'stealthaudit' ); ?>" data-sa-pager hidden>
			<a class="btn" data-sa-first href="<?php echo esc_url( $stealthaudit_view['tabs'][ $stealthaudit_current ] ); ?>" hidden><?php esc_html_e( 'Primeira página', 'stealthaudit' ); ?></a>
			<a class="btn" data-sa-next href="<?php echo esc_url( $stealthaudit_view['tabs'][ $stealthaudit_current ] ); ?>" hidden><?php esc_html_e( 'Próxima página', 'stealthaudit' ); ?></a>
		</nav>

		<details class="account">
			<summary><?php esc_html_e( 'Trocar o autenticador', 'stealthaudit' ); ?></summary>
			<p><?php esc_html_e( 'Exige a senha do WordPress e um código do autenticador atual. O novo QR substitui o fator e os códigos de recuperação anteriores depois da confirmação.', 'stealthaudit' ); ?></p>
			<?php $stealthaudit_open( 'replace-factor', __( 'Trocar o autenticador', 'stealthaudit' ) ); ?>
				<?php $stealthaudit_field( 'sa-replace-password', 'password', __( 'Senha do WordPress', 'stealthaudit' ), 'password' ); ?>
				<?php $stealthaudit_field( 'sa-replace-code', 'code', __( 'Código do autenticador atual', 'stealthaudit' ), 'code' ); ?>
			<?php $stealthaudit_close( __( 'Gerar novo código QR', 'stealthaudit' ), false ); ?>
		</details>
	</section>
	<?php endif; ?>
</main>
<script type="application/json" id="stealthaudit-i18n"><?php echo $stealthaudit_json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Hex-encoded JSON data block (never executed; CSP allows only self scripts). ?></script>
</body>
</html>
