<?php
// SPDX-License-Identifier: GPL-3.0-or-later
// Synthetic AttemptLimiter concurrency worker; never shipped and never an HTTP route.
declare(strict_types=1);
if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}
$input = json_decode( fgets( STDIN ), true, 16, JSON_THROW_ON_ERROR );
require '/var/www/html/wp-load.php';
$connection = new wpdb( $input['db_user'], $input['db_password'], $input['db_name'], $input['db_host'] );
$connection->set_prefix( $input['prefix'] );
$storage = new StealthAudit\Storage\Storage( $connection, StealthAudit\Config\Configuration::from_array( array( 'limits' => array( 'storage_work_ms' => 10000 ) ) ) );
$limiter = new StealthAudit\Security\AttemptLimiter( $storage->state );
echo "ready\n";
if ( "go\n" !== fgets( STDIN ) ) {
	exit( 2 );
}
// Each attempt is [user, origin]; the outcome of every call is counted, never retried here.
$counts = array( 'ok' => 0, 'exhausted' => 0, 'unavailable' => 0 );
foreach ( $input['attempts'] as [ $user, $origin ] ) {
	try {
		$limiter->consume( (int) $user, $origin, (int) $input['now'] );
		++$counts['ok'];
	} catch ( StealthAudit\Security\AttemptsExhausted $exhausted ) {
		++$counts['exhausted'];
	} catch ( RuntimeException $unavailable ) {
		++$counts['unavailable'];
	}
}
echo json_encode( $counts );
