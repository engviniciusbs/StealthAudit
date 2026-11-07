<?php
// SPDX-License-Identifier: GPL-3.0-or-later
require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';
$tests_dir = getenv( 'WP_TESTS_DIR' ) ?: '/wordpress-phpunit';
define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__, 2 ) . '/vendor/yoast/phpunit-polyfills' );
// WordPress discovers the real mapped MU loader itself. Do not require it via a hook.
require_once $tests_dir . '/includes/bootstrap.php';
