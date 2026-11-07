<?php
// SPDX-License-Identifier: GPL-3.0-or-later
// Synthetic full-service concurrency worker; never shipped and never an HTTP route.
if ( PHP_SAPI !== 'cli' ) { exit( 1 ); }
$input = json_decode( fgets( STDIN ), true, 16, JSON_THROW_ON_ERROR );
require '/var/www/html/wp-load.php';
$wpdb->set_prefix( $input['wp_prefix'] );
wp_cache_flush();
wp_set_current_user( $input['uid'] );
$_COOKIE[LOGGED_IN_COOKIE] = wp_generate_auth_cookie( $input['uid'], time() + 86400, 'logged_in', $input['token'] );
$_SERVER['HTTPS'] = 'on';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REMOTE_ADDR'] = '192.0.2.10';
$nonce = wp_create_nonce( 'stealthaudit' );
$connection = new wpdb( $input['db_user'], $input['db_password'], $input['db_name'], $input['db_host'] );
$connection->set_prefix( $input['prefix'] );
$config = StealthAudit\Config\Configuration::from_array( array( 'authorized_user_ids' => array( $input['uid'] ), 'key_base64' => base64_encode( str_repeat( 'k', 32 ) ), 'key_id' => 'synthetic-test-key', 'installation_id' => 'synthetic-test-installation', 'limits' => array( 'storage_work_ms' => 10000 ) ) );
$storage = new StealthAudit\Storage\Storage( $connection, $config );
$service = new StealthAudit\Security\PanelAccess( $config, $storage->state, new StealthAudit\Security\SecretCipher( str_repeat( 'k', 32 ), 'synthetic-test-key', 'synthetic-test-installation' ), new StealthAudit\Security\TotpProvider(), new StealthAudit\Security\WordPressSession(), new StealthAudit\Audit\Context(), static fn(): int => $input['now'] );
echo "ready\n";
if ( "go\n" !== fgets( STDIN ) ) { exit( 2 ); }
try {
	if ( 'recovery' === $input['mode'] ) {
		$service->recover( 'synthetic-test-password-only', $input['code'], $nonce );
	} else {
		$service->authenticate( $input['code'], $nonce );
	}
	echo '1';
} catch ( RuntimeException $failure ) { echo '0'; }
