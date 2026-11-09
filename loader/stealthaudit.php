<?php
/**
 * Plugin Name: StealthAudit
 * Description: Operator-managed audit and diagnostics (must-use loader).
 * Version: 0.1.0
 * Requires PHP: 8.3
 * License: GPL-3.0-or-later
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * @package StealthAudit
 *
 * Installed directly in mu-plugins; see https://developer.wordpress.org/advanced-administration/plugins/mu-plugins/.
 */

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

// Keep this loader parseable on PHP 7.4 before including the PHP 8.3 core.
if ( defined( 'STEALTHAUDIT_BOOT_STATUS' ) ) {
	return;
}

if ( version_compare( PHP_VERSION, '8.3.0', '<' ) ) {
	define( 'STEALTHAUDIT_BOOT_STATUS', 'php_unsupported' );
	return;
}

$stealthaudit_root = __DIR__ . '/stealthaudit';
if ( ! is_readable( $stealthaudit_root . '/vendor/autoload.php' ) ) {
	define( 'STEALTHAUDIT_BOOT_STATUS', 'vendor_missing' );
	unset( $stealthaudit_root );
	return;
}
if ( ! is_readable( $stealthaudit_root . '/bootstrap.php' ) ) {
	define( 'STEALTHAUDIT_BOOT_STATUS', 'bootstrap_missing' );
	unset( $stealthaudit_root );
	return;
}

try {
	// A partially copied vendor tree can warn before throwing. Contain those
	// bootstrap warnings too, then restore the site's handler in all cases.
	set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Contain bootstrap warnings; restored in finally.
		static function () {
			throw new RuntimeException( 'StealthAudit bootstrap failed.' );
		}
	);
	require_once $stealthaudit_root . '/bootstrap.php';
	define( 'STEALTHAUDIT_BOOT_STATUS', 'ready' );
} catch ( Throwable $stealthaudit_failure ) {
	// Never echo configuration, exception text or paths into a public request.
	define( 'STEALTHAUDIT_BOOT_STATUS', 'bootstrap_error' );
} finally {
	restore_error_handler();
}
unset( $stealthaudit_root, $stealthaudit_failure );
