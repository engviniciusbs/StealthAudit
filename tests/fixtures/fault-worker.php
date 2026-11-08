<?php
// SPDX-License-Identifier: GPL-3.0-or-later
// Synthetic fault-injection worker for tests/integration/FaultInjectionTest.php. Development
// only: never shipped, never an HTTP route, and it only touches the isolated table prefix it
// receives (sa_fault_<hex>_). Every mode prints one JSON line (or a marker) on stdout.
//
//   hold    Hold a row lock (SELECT ... FOR UPDATE) on budget:events for hold_ms, then commit.
//   burst   Append events after a ready/go barrier; report per-append latency and outcomes.
//   outage  Lose the database mid-request (KILL + unreachable host) and time the write.
//   storm   Raise thousands of PHP warnings with and without the diagnostics collector.
declare(strict_types=1);
if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}
$input = json_decode( (string) fgets( STDIN ), true, 16, JSON_THROW_ON_ERROR );
if ( ! is_array( $input ) || ! preg_match( '/^sa_fault_[a-f0-9]{8}_$/D', (string) ( $input['prefix'] ?? '' ) ) ) {
	exit( 2 );
}
// Keep the site's own MU StealthAudit out of this process (its loader returns when this is
// defined): the storm baseline must have no collector, and nothing here may write to the test
// site's real tables. Each mode composes the components it exercises on the isolated prefix.
define( 'STEALTHAUDIT_BOOT_STATUS', 'fault_injection_worker' );
require '/var/www/html/wp-load.php';
require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';

$fault_connection = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
$fault_connection->set_prefix( $input['prefix'] );
$fault_ms         = static fn ( int $from ): float => round( ( hrtime( true ) - $from ) / 1e6, 3 );
$fault_event      = array(
	'category'    => 'content',
	'action'      => 'created',
	'result'      => 'success',
	'origin'      => 'cli',
	'object_type' => 'post',
	'object_id'   => 42,
);

switch ( $input['mode'] ) {
	case 'hold':
		$fault_connection->query( 'START TRANSACTION' );
		$fault_connection->query( $fault_connection->prepare( 'SELECT revision FROM %i WHERE state_key=%s FOR UPDATE', $input['prefix'] . 'stealthaudit_state', 'budget:events' ) );
		echo "locked\n";
		usleep( (int) $input['hold_ms'] * 1000 );
		$fault_connection->query( 'COMMIT' );
		echo json_encode( array( 'released' => hrtime( true ) ) ), "\n";
		break;

	case 'burst':
		$storage = new StealthAudit\Storage\Storage( $fault_connection, StealthAudit\Config\Configuration::from_array( array( 'limits' => $input['limits'] ) ) );
		echo "ready\n";
		if ( "go\n" !== fgets( STDIN ) ) {
			exit( 3 );
		}
		$result = array(
			'accepted' => 0,
			'rejected' => 0,
			'samples'  => array(),
			'start'    => hrtime( true ),
			'end'      => 0,
		);
		for ( $i = 0; $i < (int) $input['count']; ++$i ) {
			$started = hrtime( true );
			$stored  = $storage->events->append( $fault_event, time() );
			$result['samples'][] = $fault_ms( $started );
			++$result[ $stored ? 'accepted' : 'rejected' ];
		}
		$result['end'] = hrtime( true );
		echo json_encode( $result ), "\n";
		break;

	case 'outage':
		// Default product limits (100 ms work budget): this is one ordinary request.
		$storage = new StealthAudit\Storage\Storage( $fault_connection, StealthAudit\Config\Configuration::from_array( array() ) );
		if ( $input['after_template_redirect'] ) {
			// wpdb::check_connection() returns false instead of dead_db() once this action ran.
			do_action( 'template_redirect' );
		}
		$started = hrtime( true );
		$healthy = $storage->events->append( $fault_event, time() );
		$out     = array( 'healthy' => $healthy, 'healthy_ms' => $fault_ms( $started ) );
		// The server drops this session and every reconnect is refused: an unreachable database.
		$killer = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$killer->query( $killer->prepare( 'KILL CONNECTION %d', (int) $fault_connection->get_var( 'SELECT CONNECTION_ID()' ) ) );
		$killer->close();
		$fault_connection->dbhost = '127.0.0.1:1';
		echo json_encode( array( 'outage_at' => hrtime( true ) ) ), "\n";
		$started           = hrtime( true );
		$out['first']      = $storage->events->append( $fault_event, time() );
		$out['first_ms']   = $fault_ms( $started );
		$queries           = $fault_connection->num_queries;
		$started           = hrtime( true );
		$out['second']     = $storage->events->append( $fault_event, time() );
		$out['second_ms']  = $fault_ms( $started );
		$out['second_sql'] = $fault_connection->num_queries - $queries;
		echo json_encode( $out ), "\n";
		break;

	case 'storm':
		error_reporting( E_ALL );
		$count = (int) $input['count'];
		$raise = static function ( int $count, bool $distinct ): float {
			$started = hrtime( true );
			for ( $i = 0; $i < $count; ++$i ) {
				trigger_error( $distinct ? 'synthetic storm warning ' . $i : 'synthetic storm warning', E_USER_WARNING ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error
			}
			return ( hrtime( true ) - $started ) / 1e6;
		};
		$out = array( 'count' => $count );
		// Baseline: the same warnings with no collector installed (PHP's default handler, not displayed).
		$out['baseline_ms'] = round( $raise( $count, (bool) $input['distinct'] ), 3 );
		$storage            = new StealthAudit\Storage\Storage( $fault_connection, StealthAudit\Config\Configuration::from_array( array() ) );
		$collector          = new StealthAudit\Diagnostics\Collector( new StealthAudit\Diagnostics\Normalizer( ABSPATH ), fn () => $storage->errors );
		$collector->register();
		$collector->flush();
		$memory              = memory_get_usage();
		$queries             = $fault_connection->num_queries;
		$out['collector_ms'] = round( $raise( $count, (bool) $input['distinct'] ), 3 );
		$out['memory_bytes'] = memory_get_usage() - $memory;
		$out['sql']          = $fault_connection->num_queries - $queries;
		$out['health']       = $collector->health();
		echo json_encode( $out ), "\n";
		break;

	default:
		exit( 4 );
}
