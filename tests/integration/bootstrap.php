<?php
// SPDX-License-Identifier: GPL-3.0-or-later
require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';
$tests_dir = getenv( 'WP_TESTS_DIR' ) ?: '/wordpress-phpunit';
define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__, 2 ) . '/vendor/yoast/phpunit-polyfills' );
// WordPress discovers the real mapped MU loader itself. Do not require it via a hook.
require_once $tests_dir . '/includes/bootstrap.php';

/**
 * Plain PHPUnit test cases call this in setUp(). A preceding WP_UnitTestCase leaves the global
 * $wpdb session with autocommit=0 and an open transaction (its tear_down only rolls back), so
 * users and session tokens written afterwards stay invisible to the real worker processes, and
 * the session's metadata locks can block DDL from another connection. Commit and restore
 * autocommit so every test starts from the ordinary WordPress connection state.
 */
function stealthaudit_tests_commit_global_connection(): void {
	global $wpdb;
	$wpdb->query( 'COMMIT' );
	$wpdb->query( 'SET autocommit = 1' );
}
