<?php
/**
 * Offline operator tool, shipped in the package as mu-plugins/stealthaudit/bin/.
 *
 * CLI only: over HTTP it exits before doing anything. No secrets in arguments or output. Runs
 * with the PHP of the WordPress host and the package's own prefixed autoloader (loaded by the MU
 * loader); Composer is not needed on that host. It refuses to run against any StealthAudit other
 * than the copy it ships with, so an old or foreign copy of this script cannot act on a newer
 * install. It loads WordPress itself, so it cannot require ABSPATH like the other files.
 *
 * Its variables live in WordPress's global scope while wp-load.php runs every plugin, including
 * the MU loader, which uses and unset()s $stealthaudit_root / $stealthaudit_failure. Hence the
 * distinct $stealthaudit_cli_ prefix: never reuse a name another global-scope file uses.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * @package StealthAudit
 */

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Terminal output of integers and fixed strings, never HTML.
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- fwrite( STDERR ) of a CLI tool.
if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}
try {
	$stealthaudit_cli_root = realpath( (string) getenv( 'STEALTHAUDIT_WORDPRESS_ROOT' ) );
	if ( false === $stealthaudit_cli_root || '' === (string) getenv( 'STEALTHAUDIT_WORDPRESS_ROOT' ) || ! is_file( $stealthaudit_cli_root . '/wp-load.php' ) ) {
		throw new RuntimeException();
	}
	require $stealthaudit_cli_root . '/wp-load.php';
	$stealthaudit_cli_package = realpath( dirname( __DIR__ ) . '/src' );
	if ( ! defined( 'STEALTHAUDIT_BOOT_STATUS' ) || 'ready' !== STEALTHAUDIT_BOOT_STATUS || false === $stealthaudit_cli_package || ! class_exists( StealthAudit\Bootstrap\Runtime::class ) ) {
		throw new RuntimeException();
	}
	if ( ! str_starts_with( (string) realpath( (string) ( new ReflectionClass( StealthAudit\Bootstrap\Runtime::class ) )->getFileName() ), $stealthaudit_cli_package . DIRECTORY_SEPARATOR ) ) {
		throw new RuntimeException();
	}
	$stealthaudit_cli_runtime = StealthAudit\Bootstrap\Runtime::boot();
	$stealthaudit_cli_config  = $stealthaudit_cli_runtime->configuration;
	if ( ! $stealthaudit_cli_runtime->configuration_valid || array() !== $stealthaudit_cli_config->authorized_user_ids ) {
		throw new RuntimeException();
	}
	$stealthaudit_cli_key = $stealthaudit_cli_config->encryption_key();
	if ( null === $stealthaudit_cli_key ) {
		throw new RuntimeException();
	}
	$stealthaudit_cli_operator = new StealthAudit\Security\FactorMaintenance( $stealthaudit_cli_config, $stealthaudit_cli_runtime->storage()->state, new StealthAudit\Security\SecretCipher( $stealthaudit_cli_key, $stealthaudit_cli_config->key_id, $stealthaudit_cli_config->installation_id ) );
	$stealthaudit_cli_action   = (string) ( $argv[1] ?? '' );
	$stealthaudit_cli_argument = (string) ( $argv[2] ?? '0' );
	$stealthaudit_cli_maximum  = (string) PHP_INT_MAX;
	if ( ! preg_match( '/^(?:0|[1-9][0-9]{0,18})$/D', $stealthaudit_cli_argument ) || strlen( $stealthaudit_cli_argument ) > strlen( $stealthaudit_cli_maximum ) || ( strlen( $stealthaudit_cli_argument ) === strlen( $stealthaudit_cli_maximum ) && strcmp( $stealthaudit_cli_argument, $stealthaudit_cli_maximum ) > 0 ) ) {
		throw new RuntimeException();
	}
	$stealthaudit_cli_cursor = (int) $stealthaudit_cli_argument;
	if ( 'reset' === $stealthaudit_cli_action && $stealthaudit_cli_cursor > 0 ) {
		$stealthaudit_cli_operator->reset( $stealthaudit_cli_cursor, time() );
		echo "Factor reset; WordPress sessions revoked.\n";
	} elseif ( 'rotate' === $stealthaudit_cli_action || 'validate' === $stealthaudit_cli_action ) {
		$stealthaudit_cli_file = realpath( (string) getenv( 'STEALTHAUDIT_NEW_CONFIG_FILE' ) );
		if ( false === $stealthaudit_cli_file || '' === (string) getenv( 'STEALTHAUDIT_NEW_CONFIG_FILE' ) || ! is_file( $stealthaudit_cli_file ) || str_starts_with( $stealthaudit_cli_file . DIRECTORY_SEPARATOR, $stealthaudit_cli_root . DIRECTORY_SEPARATOR ) ) {
			throw new RuntimeException();
		}
		$stealthaudit_cli_values = ( static function ( string $path ): mixed {
			return require $path;
		} )( $stealthaudit_cli_file );
		if ( ! is_array( $stealthaudit_cli_values ) ) {
			throw new RuntimeException();
		}
		$stealthaudit_cli_new     = StealthAudit\Config\Configuration::from_array( $stealthaudit_cli_values );
		$stealthaudit_cli_new_key = $stealthaudit_cli_new->encryption_key();
		if ( null === $stealthaudit_cli_new_key || $stealthaudit_cli_new->installation_id !== $stealthaudit_cli_config->installation_id || $stealthaudit_cli_new->key_id === $stealthaudit_cli_config->key_id ) {
			throw new RuntimeException();
		}
		$stealthaudit_cli_replacement = new StealthAudit\Security\SecretCipher( $stealthaudit_cli_new_key, $stealthaudit_cli_new->key_id, $stealthaudit_cli_new->installation_id );
		$stealthaudit_cli_connection  = $GLOBALS['wpdb'] ?? null;
		if ( ! $stealthaudit_cli_connection instanceof wpdb ) {
			throw new RuntimeException();
		}
		$stealthaudit_cli_db       = new StealthAudit\Storage\Database( $stealthaudit_cli_connection );
		$stealthaudit_cli_rows     = $stealthaudit_cli_db->rows( "SELECT state_key,payload FROM %i WHERE state_key LIKE 'factor:%%' AND CAST(SUBSTRING(state_key,8) AS UNSIGNED)>%d ORDER BY CAST(SUBSTRING(state_key,8) AS UNSIGNED) LIMIT 100", $stealthaudit_cli_db->table( 'state' ), $stealthaudit_cli_cursor );
		$stealthaudit_cli_migrated = 0;
		foreach ( $stealthaudit_cli_rows as $stealthaudit_cli_row ) {
			$stealthaudit_cli_cursor = (int) substr( (string) $stealthaudit_cli_row['state_key'], 7 );
			if ( 'rotate' === $stealthaudit_cli_action ) {
				$stealthaudit_cli_migrated += $stealthaudit_cli_operator->rotate( $stealthaudit_cli_cursor, $stealthaudit_cli_replacement, time() ) ? 1 : 0;
			} else {
				$stealthaudit_cli_payload = json_decode( (string) $stealthaudit_cli_row['payload'], true, 16, JSON_THROW_ON_ERROR );
				if ( ! is_array( $stealthaudit_cli_payload ) || ! is_string( $stealthaudit_cli_payload['secret'] ?? null ) || null === $stealthaudit_cli_replacement->decrypt( $stealthaudit_cli_payload['secret'], $stealthaudit_cli_cursor ) ) {
					throw new RuntimeException();
				}
			}
		}
		echo 'Processed ' . count( $stealthaudit_cli_rows ) . ( 'rotate' === $stealthaudit_cli_action ? '; migrated=' . $stealthaudit_cli_migrated : '' ) . '; last_user=' . $stealthaudit_cli_cursor . '; complete=' . ( count( $stealthaudit_cli_rows ) < 100 ? 'yes' : 'no' ) . ".\n";
	} else {
		throw new RuntimeException();
	}
} catch ( Throwable $stealthaudit_cli_failure ) {
	fwrite( STDERR, "Offline maintenance failed; panel must remain paused.\n" );
	exit( 1 );
}
