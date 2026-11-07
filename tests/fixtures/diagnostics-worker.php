<?php
// SPDX-License-Identifier: GPL-3.0-or-later
declare(strict_types=1);

require '/var/www/html/wp-load.php';
$prefix = $argv[1] ?? '';
if ( ! preg_match( '/^sa_diag_[a-f0-9]{8}_$/D', $prefix ) ) {
	exit( 2 );
}
$connection = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
$connection->set_prefix( $prefix );
$storage = new StealthAudit\Storage\Storage( $connection, StealthAudit\Config\Configuration::from_array( array( 'limits' => array( 'storage_work_ms' => 10000 ) ) ) );
$collector = new StealthAudit\Diagnostics\Collector( new StealthAudit\Diagnostics\Normalizer( ABSPATH ), fn () => $storage->errors );
$collector->register();
$collector->flush();
register_shutdown_function( static function () { echo '@@DIAGNOSTICS@@'; } );
error_reporting( E_ALL );
stealthaudit_intentionally_undefined_function_for_fatal_test();
