<?php
/**
 * Unstyled semantic placeholder; phase 6b replaces it with the Terminal UI.
 *
 * Receives only escaped-at-output values. No log data, secret or QR is rendered here.
 *
 * @package StealthAudit
 *
 * @var array{status:string,panel:string,rest:string,nonce:string,rest_nonce:string,query:array<string,string>} $stealthaudit_view
 */

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

$stealthaudit_labels = array(
	'password_required'   => 'Confirme sua senha do WordPress para continuar.',
	'enrollment_required' => 'Cadastre o aplicativo autenticador.',
	'enrollment_pending'  => 'Confirme o cadastro com um código do autenticador.',
	'challenge_required'  => 'Informe o código do autenticador.',
	'authorized'          => 'Sessão StealthAudit ativa.',
	'recovery_locked'     => 'Recuperação bloqueada: procure o operador para o procedimento offline.',
);
$stealthaudit_status = $stealthaudit_view['status'];
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>StealthAudit</title>
</head>
<body>
<main id="stealthaudit" data-rest="<?php echo esc_url( $stealthaudit_view['rest'] ); ?>" data-panel="<?php echo esc_url( $stealthaudit_view['panel'] ); ?>" data-nonce="<?php echo esc_attr( $stealthaudit_view['nonce'] ); ?>" data-rest-nonce="<?php echo esc_attr( $stealthaudit_view['rest_nonce'] ); ?>" data-status="<?php echo esc_attr( $stealthaudit_status ); ?>">
<h1>StealthAudit</h1>
<p role="status"><?php echo esc_html( $stealthaudit_labels[ $stealthaudit_status ] ?? 'Estado desconhecido.' ); ?></p>
<?php if ( array() !== $stealthaudit_view['query'] ) : ?>
<dl>
	<?php foreach ( $stealthaudit_view['query'] as $stealthaudit_key => $stealthaudit_value ) : ?>
	<dt><?php echo esc_html( $stealthaudit_key ); ?></dt>
	<dd><?php echo esc_html( $stealthaudit_value ); ?></dd>
	<?php endforeach; ?>
</dl>
<?php endif; ?>
<p>Horários em UTC.</p>
</main>
</body>
</html>
