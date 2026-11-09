<?php
/**
 * Development only (tests/package/run.mjs): installed for the update step as
 * wp-content/mu-plugins/zz-sa-probe.php on the clean artifact site, then removed. Never in the ZIP.
 *
 * GET /?sa_package_probe=<token> answers which StealthAudit code Apache is EXECUTING (through
 * opcache), as opposed to what the files on disk say: Runtime::VERSION is compiled code, so after
 * a directory swap it shows whether the new version really runs.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) || ! isset( $_GET['sa_package_probe'] ) || ! hash_equals( '__TOKEN__', (string) $_GET['sa_package_probe'] ) ) { // phpcs:ignore WordPress.Security
	return;
}
$sa_probe_status = function_exists( 'opcache_get_status' ) ? opcache_get_status( false ) : false;
header( 'Content-Type: application/json' );
header( 'Cache-Control: no-store' );
echo wp_json_encode(
	array(
		'boot'                => defined( 'STEALTHAUDIT_BOOT_STATUS' ) ? STEALTHAUDIT_BOOT_STATUS : null,
		'runtime_version'     => class_exists( \StealthAudit\Bootstrap\Runtime::class ) ? \StealthAudit\Bootstrap\Runtime::VERSION : null,
		'opcache_enabled'     => is_array( $sa_probe_status ) && ! empty( $sa_probe_status['opcache_enabled'] ),
		'validate_timestamps' => ini_get( 'opcache.validate_timestamps' ),
		'revalidate_freq'     => ini_get( 'opcache.revalidate_freq' ),
		'pid'                 => getmypid(),
	)
);
exit;
