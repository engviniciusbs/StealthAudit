<?php
// SPDX-License-Identifier: GPL-3.0-or-later
// Offline operator tool, shipped in the package as mu-plugins/stealthaudit/bin/. CLI only: over
// HTTP it exits before doing anything. No secrets in arguments/output. Runs with the PHP of the
// WordPress host and the package's own prefixed autoloader (loaded by the MU loader); Composer
// is not needed on that host. It refuses to run against any StealthAudit other than the copy it
// ships with, so an old or foreign copy of this script cannot act on a newer install.
if ( 'cli' !== PHP_SAPI ) { exit( 1 ); }
try {
	$root = realpath( getenv( 'STEALTHAUDIT_WORDPRESS_ROOT' ) ?: '' );
	if ( false === $root || ! is_file( $root . '/wp-load.php' ) ) { throw new RuntimeException(); }
	require $root . '/wp-load.php';
	$package = realpath( dirname( __DIR__ ) . '/src' );
	if ( ! defined( 'STEALTHAUDIT_BOOT_STATUS' ) || 'ready' !== STEALTHAUDIT_BOOT_STATUS || false === $package || ! class_exists( StealthAudit\Bootstrap\Runtime::class ) || ! str_starts_with( (string) realpath( (string) ( new ReflectionClass( StealthAudit\Bootstrap\Runtime::class ) )->getFileName() ), $package . DIRECTORY_SEPARATOR ) ) { throw new RuntimeException(); }
	$runtime = StealthAudit\Bootstrap\Runtime::boot();
	$config = $runtime->configuration;
	if ( ! $runtime->configuration_valid || array() !== $config->authorized_user_ids ) { throw new RuntimeException(); }
	$key = $config->encryption_key();
	if ( null === $key ) { throw new RuntimeException(); }
	$operator = new StealthAudit\Security\FactorMaintenance( $config, $runtime->storage()->state, new StealthAudit\Security\SecretCipher( $key, $config->key_id, $config->installation_id ) );
	$action = $argv[1] ?? '';
	$cursor = $argv[2] ?? '0';
	$maximum_id = (string) PHP_INT_MAX;
	if ( ! preg_match( '/^(?:0|[1-9][0-9]{0,18})$/D', $cursor ) || strlen( $cursor ) > strlen( $maximum_id ) || ( strlen( $cursor ) === strlen( $maximum_id ) && strcmp( $cursor, $maximum_id ) > 0 ) ) { throw new RuntimeException(); }
	if ( 'reset' === $action && (int) $cursor > 0 ) {
		$operator->reset( (int) $cursor, time() );
		echo "Factor reset; WordPress sessions revoked.\n";
	} elseif ( 'rotate' === $action || 'validate' === $action ) {
		$file = realpath( getenv( 'STEALTHAUDIT_NEW_CONFIG_FILE' ) ?: '' );
		if ( false === $file || ! is_file( $file ) || str_starts_with( $file . DIRECTORY_SEPARATOR, $root . DIRECTORY_SEPARATOR ) ) { throw new RuntimeException(); }
		$values = ( static function ( string $path ): mixed { return require $path; } )( $file );
		if ( ! is_array( $values ) ) { throw new RuntimeException(); }
		$new = StealthAudit\Config\Configuration::from_array( $values );
		$new_key = $new->encryption_key();
		if ( null === $new_key || $new->installation_id !== $config->installation_id || $new->key_id === $config->key_id ) { throw new RuntimeException(); }
		$replacement = new StealthAudit\Security\SecretCipher( $new_key, $new->key_id, $new->installation_id );
		$db = new StealthAudit\Storage\Database( $wpdb );
		$rows = $db->rows( "SELECT state_key,payload FROM %i WHERE state_key LIKE 'factor:%%' AND CAST(SUBSTRING(state_key,8) AS UNSIGNED)>%d ORDER BY CAST(SUBSTRING(state_key,8) AS UNSIGNED) LIMIT 100", $db->table( 'state' ), (int) $cursor );
		$migrated = 0;
		foreach ( $rows as $row ) {
			$cursor = (int) substr( $row['state_key'], 7 );
			if ( 'rotate' === $action ) {
				$migrated += $operator->rotate( $cursor, $replacement, time() ) ? 1 : 0;
			} else {
				$payload = json_decode( $row['payload'], true, 16, JSON_THROW_ON_ERROR );
				if ( ! is_string( $payload['secret'] ?? null ) || null === $replacement->decrypt( $payload['secret'], $cursor ) ) { throw new RuntimeException(); }
			}
		}
		echo 'Processed ' . count( $rows ) . ( 'rotate' === $action ? '; migrated=' . $migrated : '' ) . '; last_user=' . $cursor . '; complete=' . ( count( $rows ) < 100 ? 'yes' : 'no' ) . ".\n";
	} else { throw new RuntimeException(); }
} catch ( Throwable $failure ) {
	fwrite( STDERR, "Offline maintenance failed; panel must remain paused.\n" );
	exit( 1 );
}
