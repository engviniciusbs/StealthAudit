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
	$stealthaudit_root = realpath( (string) getenv( 'STEALTHAUDIT_WORDPRESS_ROOT' ) );
	if ( false === $stealthaudit_root || '' === (string) getenv( 'STEALTHAUDIT_WORDPRESS_ROOT' ) || ! is_file( $stealthaudit_root . '/wp-load.php' ) ) {
		throw new RuntimeException();
	}
	require $stealthaudit_root . '/wp-load.php';
	$stealthaudit_package = realpath( dirname( __DIR__ ) . '/src' );
	if ( ! defined( 'STEALTHAUDIT_BOOT_STATUS' ) || 'ready' !== STEALTHAUDIT_BOOT_STATUS || false === $stealthaudit_package || ! class_exists( StealthAudit\Bootstrap\Runtime::class ) ) {
		throw new RuntimeException();
	}
	if ( ! str_starts_with( (string) realpath( (string) ( new ReflectionClass( StealthAudit\Bootstrap\Runtime::class ) )->getFileName() ), $stealthaudit_package . DIRECTORY_SEPARATOR ) ) {
		throw new RuntimeException();
	}
	$stealthaudit_runtime = StealthAudit\Bootstrap\Runtime::boot();
	$stealthaudit_config  = $stealthaudit_runtime->configuration;
	if ( ! $stealthaudit_runtime->configuration_valid || array() !== $stealthaudit_config->authorized_user_ids ) {
		throw new RuntimeException();
	}
	$stealthaudit_key = $stealthaudit_config->encryption_key();
	if ( null === $stealthaudit_key ) {
		throw new RuntimeException();
	}
	$stealthaudit_operator = new StealthAudit\Security\FactorMaintenance( $stealthaudit_config, $stealthaudit_runtime->storage()->state, new StealthAudit\Security\SecretCipher( $stealthaudit_key, $stealthaudit_config->key_id, $stealthaudit_config->installation_id ) );
	$stealthaudit_action   = (string) ( $argv[1] ?? '' );
	$stealthaudit_argument = (string) ( $argv[2] ?? '0' );
	$stealthaudit_maximum  = (string) PHP_INT_MAX;
	if ( ! preg_match( '/^(?:0|[1-9][0-9]{0,18})$/D', $stealthaudit_argument ) || strlen( $stealthaudit_argument ) > strlen( $stealthaudit_maximum ) || ( strlen( $stealthaudit_argument ) === strlen( $stealthaudit_maximum ) && strcmp( $stealthaudit_argument, $stealthaudit_maximum ) > 0 ) ) {
		throw new RuntimeException();
	}
	$stealthaudit_cursor = (int) $stealthaudit_argument;
	if ( 'reset' === $stealthaudit_action && $stealthaudit_cursor > 0 ) {
		$stealthaudit_operator->reset( $stealthaudit_cursor, time() );
		echo "Factor reset; WordPress sessions revoked.\n";
	} elseif ( 'rotate' === $stealthaudit_action || 'validate' === $stealthaudit_action ) {
		$stealthaudit_file = realpath( (string) getenv( 'STEALTHAUDIT_NEW_CONFIG_FILE' ) );
		if ( false === $stealthaudit_file || '' === (string) getenv( 'STEALTHAUDIT_NEW_CONFIG_FILE' ) || ! is_file( $stealthaudit_file ) || str_starts_with( $stealthaudit_file . DIRECTORY_SEPARATOR, $stealthaudit_root . DIRECTORY_SEPARATOR ) ) {
			throw new RuntimeException();
		}
		$stealthaudit_values = ( static function ( string $path ): mixed {
			return require $path;
		} )( $stealthaudit_file );
		if ( ! is_array( $stealthaudit_values ) ) {
			throw new RuntimeException();
		}
		$stealthaudit_new     = StealthAudit\Config\Configuration::from_array( $stealthaudit_values );
		$stealthaudit_new_key = $stealthaudit_new->encryption_key();
		if ( null === $stealthaudit_new_key || $stealthaudit_new->installation_id !== $stealthaudit_config->installation_id || $stealthaudit_new->key_id === $stealthaudit_config->key_id ) {
			throw new RuntimeException();
		}
		$stealthaudit_replacement = new StealthAudit\Security\SecretCipher( $stealthaudit_new_key, $stealthaudit_new->key_id, $stealthaudit_new->installation_id );
		$stealthaudit_connection  = $GLOBALS['wpdb'] ?? null;
		if ( ! $stealthaudit_connection instanceof wpdb ) {
			throw new RuntimeException();
		}
		$stealthaudit_db       = new StealthAudit\Storage\Database( $stealthaudit_connection );
		$stealthaudit_rows     = $stealthaudit_db->rows( "SELECT state_key,payload FROM %i WHERE state_key LIKE 'factor:%%' AND CAST(SUBSTRING(state_key,8) AS UNSIGNED)>%d ORDER BY CAST(SUBSTRING(state_key,8) AS UNSIGNED) LIMIT 100", $stealthaudit_db->table( 'state' ), $stealthaudit_cursor );
		$stealthaudit_migrated = 0;
		foreach ( $stealthaudit_rows as $stealthaudit_row ) {
			$stealthaudit_cursor = (int) substr( (string) $stealthaudit_row['state_key'], 7 );
			if ( 'rotate' === $stealthaudit_action ) {
				$stealthaudit_migrated += $stealthaudit_operator->rotate( $stealthaudit_cursor, $stealthaudit_replacement, time() ) ? 1 : 0;
			} else {
				$stealthaudit_payload = json_decode( (string) $stealthaudit_row['payload'], true, 16, JSON_THROW_ON_ERROR );
				if ( ! is_array( $stealthaudit_payload ) || ! is_string( $stealthaudit_payload['secret'] ?? null ) || null === $stealthaudit_replacement->decrypt( $stealthaudit_payload['secret'], $stealthaudit_cursor ) ) {
					throw new RuntimeException();
				}
			}
		}
		echo 'Processed ' . count( $stealthaudit_rows ) . ( 'rotate' === $stealthaudit_action ? '; migrated=' . $stealthaudit_migrated : '' ) . '; last_user=' . $stealthaudit_cursor . '; complete=' . ( count( $stealthaudit_rows ) < 100 ? 'yes' : 'no' ) . ".\n";
	} else {
		throw new RuntimeException();
	}
} catch ( Throwable $stealthaudit_failure ) {
	fwrite( STDERR, "Offline maintenance failed; panel must remain paused.\n" );
	exit( 1 );
}
