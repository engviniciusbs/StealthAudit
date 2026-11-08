<?php
// SPDX-License-Identifier: GPL-3.0-or-later
// Synthetic integration worker, not a web endpoint and never shipped.
declare(strict_types=1);
if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}
$input = json_decode( fgets( STDIN ), true, 16, JSON_THROW_ON_ERROR );
require '/var/www/html/wp-load.php';
$connection = new wpdb( $input['user'], $input['password'], $input['name'], $input['host'] );
$connection->set_prefix( $input['prefix'] );
$storage = new StealthAudit\Storage\Storage( $connection, StealthAudit\Config\Configuration::from_array( array( 'limits' => array( 'event_rows' => 1, 'storage_work_ms' => 10000 ) ) ) );
$revision = 'cas' === $input['mode'] ? $storage->state->get( 'factor:4', time() )['revision'] : 0;
echo "ready\n";
if ( "go\n" !== fgets( STDIN ) ) {
	exit( 2 );
}
if ( 'cas' === $input['mode'] ) {
	$result = $storage->state->compare_and_swap( 'factor:4', $revision, array( 'generation' => 2 ), null, time() );
} elseif ( 'ensure' === $input['mode'] ) {
	$result = $storage->schema->ensure();
} elseif ( 'maintenance' === $input['mode'] ) {
	// Prints the run result word instead of 0/1.
	echo $storage->maintenance->run( (int) $input['now'] )['result'];
	exit( 0 );
} else {
	$result = $storage->events->append( array( 'category' => 'content', 'action' => 'created', 'result' => 'success', 'origin' => 'cli' ), time() );
}
echo $result ? '1' : '0';
