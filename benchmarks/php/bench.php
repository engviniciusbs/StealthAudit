<?php
// SPDX-License-Identifier: GPL-3.0-or-later
/**
 * Benchmark actions, run by benchmarks/run.mjs through WP-CLI inside the disposable tag=bench
 * environment only:   wp eval-file benchmarks/php/bench.php <action> [json]
 * Never shipped. Every action prints one JSON object on stdout.
 *
 * seed     Replace all audit/diagnostic rows with N synthetic ones (uniform over the retention
 *          window, ids ascending with time) and set the capacity counters to the real sums.
 * collect  Repeat one WordPress action that records exactly one audit event (a failed
 *          wp_signon) and time every repetition; also memory. Works with the plugin off.
 * query    Time the storage windows (100 rows) and the REST handler, with and without GeoIP.
 * cleanup  Add expired rows and time maintenance runs of one 500-row batch, then a full run.
 * user     Create the synthetic panel operator (random password) and print its credentials.
 * count    Rows and capacity counters.
 *
 * @package StealthAudit
 */

if ( ! defined( 'WP_CLI' ) || 'local' !== wp_get_environment_type() || ! is_dir( '/opt/stealthaudit-bench' ) ) {
	fwrite( STDERR, "Benchmark environment only.\n" );
	exit( 1 );
}

global $wpdb;
$bench_action = $args[0] ?? '';
$bench_input  = isset( $args[1] ) ? json_decode( $args[1], true, 16, JSON_THROW_ON_ERROR ) : array();
$bench_table  = static fn ( string $kind ): string => $wpdb->prefix . 'stealthaudit_' . $kind;
$bench_stats  = static function ( array $samples ): array {
	sort( $samples );
	$count = count( $samples );
	$at    = static fn ( float $q ): float => round( $samples[ (int) min( $count - 1, max( 0, ceil( $q * $count ) - 1 ) ) ], 3 );
	return array(
		'n'    => $count,
		'mean' => round( array_sum( $samples ) / max( 1, $count ), 3 ),
		'p50'  => $at( 0.50 ),
		'p95'  => $at( 0.95 ),
		'p99'  => $at( 0.99 ),
		'max'  => round( $samples[ $count - 1 ], 3 ),
	);
};
$bench_ms     = static fn ( int $from ): float => ( hrtime( true ) - $from ) / 1e6;
// n = 0 .. count-1 from a cross join of digit tables: no recursion limits on MariaDB or MySQL.
$bench_numbers = static function ( int $offset, int $count ): string {
	$digits = '(SELECT 0 d UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9)';
	return '(SELECT ' . $offset . ' + a.d + 10*b.d + 100*c.d + 1000*e.d + 10000*f.d AS n FROM ' . $digits . ' a, ' . $digits . ' b, ' . $digits . ' c, ' . $digits . ' e, ' . $digits . ' f WHERE a.d + 10*b.d + 100*c.d + 1000*e.d + 10000*f.d < ' . $count . ')';
};
$bench_runtime = static function (): \StealthAudit\Bootstrap\Runtime {
	if ( ! defined( 'STEALTHAUDIT_BOOT_STATUS' ) || 'ready' !== STEALTHAUDIT_BOOT_STATUS ) {
		WP_CLI::error( 'This action needs the plugin on (benchmark mode "on").' );
	}
	return \StealthAudit\Bootstrap\Runtime::boot();
};
$bench_counters = static function () use ( $wpdb, $bench_table ): void {
	foreach ( array( 'events', 'errors' ) as $kind ) {
		$sum = $wpdb->get_row( $wpdb->prepare( 'SELECT COUNT(*) AS n, COALESCE(SUM(logical_bytes),0) AS b FROM %i', $bench_table( $kind ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET revision=revision+1, payload=%s WHERE state_key=%s', $bench_table( 'state' ), wp_json_encode( array( 'rows' => (int) $sum['n'], 'bytes' => (int) $sum['b'], 'second' => 0, 'rate' => 0, 'dropped' => 0, 'pending' => 0, 'pending_by_minute' => array() ) ), 'budget:' . $kind ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
};

switch ( $bench_action ) {
	case 'seed':
		$bench_runtime();
		$events = (int) $bench_input['events'];
		$errors = (int) $bench_input['errors'];
		$now    = time() - 60;
		$out    = array( 'events' => $events, 'errors' => $errors );
		$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', $bench_table( 'events' ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', $bench_table( 'errors' ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$started = hrtime( true );
		// 300 days of audit history, oldest first; 8 action shapes; IPs from the benchmark range
		// 198.18.0.0/15 plus addresses the MaxMind City *test* database knows (GeoIP hits).
		$span = 300 * DAY_IN_SECONDS;
		for ( $offset = 0; $offset < $events; $offset += 50000 ) {
			$chunk = min( 50000, $events - $offset );
			$sql   = "INSERT INTO %i (record_version,occurred_at,actor_id,category,action,result,origin,object_type,object_id,ip,summary,logical_bytes)
				SELECT 1, FROM_UNIXTIME(%d + FLOOR(n * %d / %d)),
					CASE WHEN MOD(n, 8) IN (1,7) THEN NULL ELSE 1 + MOD(n, 50) END,
					ELT(1 + MOD(n, 8), 'access','access','access','content','content','content','users','admin'),
					ELT(1 + MOD(n, 8), 'login','login_failed','logout','created','updated','deleted','updated','option_changed'),
					CASE WHEN MOD(n, 8) = 1 THEN 'failure' ELSE 'success' END,
					ELT(1 + MOD(n, 4), 'http','admin','rest','cron'),
					ELT(1 + MOD(n, 8), 'user','user','user','post','post','page','user','option'),
					CASE WHEN MOD(n, 8) IN (3,4,5) THEN 1 + MOD(n, 1000) WHEN MOD(n, 8) = 6 THEN 1 + MOD(n, 50) ELSE NULL END,
					CASE MOD(n, 6) WHEN 0 THEN CONCAT('81.2.69.', 160 + MOD(n, 32)) WHEN 1 THEN CONCAT('175.16.199.', MOD(n, 256)) WHEN 2 THEN CONCAT('89.160.20.', 112 + MOD(n, 16)) ELSE CONCAT('198.18.', MOD(FLOOR(n / 256), 256), '.', MOD(n, 256)) END,
					CASE WHEN MOD(n, 8) = 1 THEN '{\"reason\":\"authentication_failed\"}' ELSE '[]' END,
					120
				FROM " . $bench_numbers( $offset, $chunk ) . ' AS seq';
			$wpdb->query( $wpdb->prepare( $sql, $bench_table( 'events' ), $now - $span, $span, max( 1, $events - 1 ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
			if ( '' !== $wpdb->last_error ) {
				WP_CLI::error( 'Event seed failed: ' . $wpdb->last_error );
			}
		}
		// 29 days of diagnostics (query window 30 days); distinct fingerprints, 5 severities.
		$span = 29 * DAY_IN_SECONDS;
		for ( $offset = 0; $offset < $errors; $offset += 50000 ) {
			$chunk = min( 50000, $errors - $offset );
			$sql   = "INSERT INTO %i (record_version,first_seen,last_seen,severity,message,file,line,fingerprint,occurrences,logical_bytes)
				SELECT 1, FROM_UNIXTIME(%d + FLOOR(n * %d / %d)), FROM_UNIXTIME(%d + FLOOR(n * %d / %d)),
					ELT(1 + MOD(n, 5), 'warning','notice','deprecated','warning','fatal'),
					CONCAT('Synthetic benchmark diagnostic number ', n, ' in a plugin function'),
					CONCAT('wp-content/plugins/bench-', MOD(n, 40), '/file-', MOD(n, 7), '.php'), 1 + MOD(n, 900),
					SHA2(CONCAT('bench', n), 256), 1 + MOD(n, 5), 230
				FROM " . $bench_numbers( $offset, $chunk ) . ' AS seq';
			$wpdb->query( $wpdb->prepare( $sql, $bench_table( 'errors' ), $now - $span, $span, max( 1, $errors - 1 ), $now - $span, $span, max( 1, $errors - 1 ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
			if ( '' !== $wpdb->last_error ) {
				WP_CLI::error( 'Error seed failed: ' . $wpdb->last_error );
			}
		}
		foreach ( array( 'events', 'errors' ) as $kind ) {
			$wpdb->query( $wpdb->prepare( 'ANALYZE TABLE %i', $bench_table( $kind ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		$bench_counters();
		$out['seconds'] = round( $bench_ms( $started ) / 1000, 1 );
		$out['size']    = $wpdb->get_results( $wpdb->prepare( 'SELECT TABLE_NAME AS t, TABLE_ROWS AS approx_rows, ROUND((DATA_LENGTH+INDEX_LENGTH)/1048576,1) AS mib FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'stealthaudit_' ) . '%' ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		echo wp_json_encode( $out ), "\n";
		break;

	case 'collect':
		$iterations = (int) $bench_input['iterations'];
		$warmup     = (int) $bench_input['warmup'];
		$before_max = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $bench_table( 'events' ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$memory     = memory_get_usage();
		$samples    = array();
		$credentials = array(
			'user_login'    => 'bench-no-such-user',
			'user_password' => 'wrong-password',
		);
		for ( $i = 0; $i < $warmup + $iterations; ++$i ) {
			$started = hrtime( true );
			wp_signon( $credentials, false );
			if ( $i >= $warmup ) {
				$samples[] = $bench_ms( $started );
			}
		}
		$after_max = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $bench_table( 'events' ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		echo wp_json_encode(
			array(
				'status'         => defined( 'STEALTHAUDIT_BOOT_STATUS' ) ? STEALTHAUDIT_BOOT_STATUS : null,
				'events_written' => $after_max - $before_max,
				'expected'       => $warmup + $iterations,
				'ms'             => $bench_stats( $samples ),
				// Row counts, not MAX(id): a bulk INSERT ... SELECT leaves auto-increment gaps.
				// Memory of the booted request before the loop (plugin footprint) and its growth.
				'memory_at_start' => $memory,
				'memory_growth'   => memory_get_usage() - $memory,
				'memory_peak'     => memory_get_peak_usage(),
			)
		), "\n";
		break;

	case 'query':
		$runtime = $bench_runtime();
		$storage = $runtime->storage();
		$reps    = (int) ( $bench_input['reps'] ?? 20 );
		$now     = time();
		$time    = static function ( callable $run ) use ( $reps, $bench_stats, $bench_ms ): array {
			for ( $i = 0; $i < 3; ++$i ) {
				$rows = $run();
			}
			$samples = array();
			for ( $i = 0; $i < $reps; ++$i ) {
				$started   = hrtime( true );
				$rows      = $run();
				$samples[] = $bench_ms( $started );
			}
			return array( 'rows' => $rows ) + $bench_stats( $samples );
		};
		$events = $storage->events;
		$errors = $storage->errors;
		$middle = $wpdb->get_row( $wpdb->prepare( 'SELECT id, UNIX_TIMESTAMP(occurred_at) AS t FROM %i ORDER BY id LIMIT 1 OFFSET %d', $bench_table( 'events' ), (int) floor( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $bench_table( 'events' ) ) ) / 2 ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$window  = static fn ( $records, array $filters, ?int $from = null, ?int $to = null, ?array $after = null ) => static fn (): int => count( $records->window( $filters, $from, $to, $after, 100, $now )['rows'] );
		$out     = array( 'events' => array(), 'errors' => array() );
		$plans   = array(
			'newest'                   => $window( $events, array() ),
			'action'                   => $window( $events, array( 'action' => 'login_failed' ) ),
			'user'                     => $window( $events, array( 'actor_id' => 7 ) ),
			'ip'                       => $window( $events, array( 'ip' => '198.18.3.4' ) ),
			'category'                 => $window( $events, array( 'category' => 'content' ) ),
			'last_24h'                 => $window( $events, array(), $now - DAY_IN_SECONDS, $now ),
			'middle_cursor'            => $window( $events, array(), null, null, null === $middle ? null : array( (int) $middle['t'], (int) $middle['id'] ) ),
			// Worst case: no row matches, so the newest-first scan reads the whole indexed range.
			'no_match_category_action' => $window( $events, array( 'category' => 'access', 'action' => 'created' ) ),
			'no_match_result'          => $window( $events, array( 'result' => 'unknown' ) ),
		);
		foreach ( $plans as $name => $run ) {
			$out['events'][ $name ] = $time( $run );
		}
		foreach ( array(
			'newest'   => $window( $errors, array() ),
			'severity' => $window( $errors, array( 'severity' => 'warning' ) ),
			'last_24h' => $window( $errors, array(), $now - DAY_IN_SECONDS, $now ),
		) as $name => $run ) {
			$out['errors'][ $name ] = $time( $run );
		}
		// The REST handler itself (window + public shape + capture + maintenance summary),
		// without the HTTP/authentication layers, with no GeoIP and with the City test MMDB. Filtered on
		// action=logout: only seeded rows (mixed IPs, many known to the test MMDB), never the
		// benchmark's own login_failed rows, which all share one Docker gateway address.
		$config = $runtime->configuration;
		$clock  = static fn (): int => time();
		$guard  = new \StealthAudit\Http\Guard( $config, static fn () => throw new \RuntimeException( 'unused' ), new \StealthAudit\Http\Urls( $config->endpoint ) );
		$health = static fn (): array => array( 'status' => 'available', 'dropped' => 0, 'buffered' => 0 );
		$apis   = array( 'api_events_no_geoip' => new \StealthAudit\Http\Api( $guard, static fn () => $storage, $health, $config, $clock ) );
		if ( isset( $bench_input['mmdb'] ) ) {
			$apis['api_events_geoip'] = new \StealthAudit\Http\Api( $guard, static fn () => $storage, $health, $config, $clock, new \StealthAudit\Geo\Geolocation( $bench_input['mmdb'], 3650, ABSPATH, $clock ) );
		}
		foreach ( $apis as $name => $api ) {
			$out['api'][ $name ] = $time(
				static function () use ( $api ): int {
					$request = new WP_REST_Request( 'GET', '/stealthaudit/v1/events' );
					$request->set_query_params( array( 'limit' => '100', 'action' => 'logout' ) );
					$data = $api->events( $request )->get_data();
					if ( ! isset( $data['items'] ) ) {
						throw new RuntimeException( 'REST handler failed.' );
					}
					return count( $data['items'] );
				}
			);
			if ( 'api_events_geoip' === $name ) {
				$request = new WP_REST_Request( 'GET', '/stealthaudit/v1/events' );
				$request->set_query_params( array( 'limit' => '100', 'action' => 'logout' ) );
				$data                           = $api->events( $request )->get_data();
				$out['api']['geoip_state']      = $data['geolocation']['state'] ?? null;
				$out['api']['geoip_estimates']  = count( array_filter( $data['items'], static fn ( array $item ): bool => 'estimate' === $item['location']['status'] ) );
				$out['api']['geoip_unique_ips'] = count( array_unique( array_column( $data['items'], 'ip' ) ) );
			}
		}
		echo wp_json_encode( $out ), "\n";
		break;

	case 'cleanup':
		$runtime = $bench_runtime();
		$storage = $runtime->storage();
		$expired = (int) $bench_input['expired'];
		$now     = time();
		$wpdb->query( $wpdb->prepare( "INSERT INTO %i (record_version,occurred_at,actor_id,category,action,result,origin,object_type,object_id,ip,summary,logical_bytes) SELECT 1, FROM_UNIXTIME(%d + n), NULL, 'content','updated','success','cron','post',1,NULL,'[]',120 FROM " . $bench_numbers( 0, $expired ) . ' AS seq', $bench_table( 'events' ), $now - 400 * DAY_IN_SECONDS ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		$bench_counters();
		$single = array();
		$purged = 0;
		// One 500-row batch per run (the maintenance lease and status write are included).
		for ( $i = 0; $i < 10 && $purged < $expired - 500; ++$i ) {
			$started  = hrtime( true );
			$outcome  = $storage->maintenance->run( $now, 1 );
			$single[] = $bench_ms( $started );
			$purged  += $outcome['purged']['events'];
		}
		// The bare DELETE statement the maintenance batch issues, timed alone.
		$statement = array();
		for ( $i = 0; $i < 5 && $purged < $expired - 500; ++$i ) {
			$started     = hrtime( true );
			$purged     += (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE %i<=%s ORDER BY %i,id LIMIT %d', $bench_table( 'events' ), 'occurred_at', gmdate( 'Y-m-d H:i:s', $now - 365 * DAY_IN_SECONDS ), 'occurred_at', \StealthAudit\Storage\Maintenance::BATCH ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$statement[] = $bench_ms( $started );
		}
		// Then a default run (20 batches / 2 s) over what is left and over the recount.
		$full    = array();
		$results = array();
		for ( $i = 0; $i < 3; ++$i ) {
			$started   = hrtime( true );
			$outcome   = $storage->maintenance->run( $now );
			$full[]    = round( $bench_ms( $started ), 3 );
			$results[] = $outcome['result'] . ':' . $outcome['batches'] . ':' . $outcome['purged']['events'];
		}
		$bench_counters();
		echo wp_json_encode(
			array(
				'expired_rows'       => $expired,
				'run_one_batch_ms'   => $bench_stats( $single ),
				'delete_500_ms'      => $bench_stats( $statement ),
				'full_runs_ms'       => $full,
				'full_runs'          => $results,
				'left_expired'       => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE occurred_at<=%s', $bench_table( 'events' ), gmdate( 'Y-m-d H:i:s', $now - 365 * DAY_IN_SECONDS ) ) ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			)
		), "\n";
		break;

	case 'user':
		$login    = 'sa-bench-operator-' . bin2hex( random_bytes( 3 ) );
		$password = 'bench-' . bin2hex( random_bytes( 12 ) );
		$id       = wp_insert_user(
			array(
				'user_login' => $login,
				'user_pass'  => $password,
				'user_email' => $login . '@example.invalid',
				'role'       => 'administrator',
			)
		);
		if ( is_wp_error( $id ) ) {
			WP_CLI::error( 'Cannot create the benchmark operator.' );
		}
		echo wp_json_encode( array( 'id' => $id, 'login' => $login, 'password' => $password ) ), "\n";
		break;

	case 'count':
		$out = array();
		foreach ( array( 'events', 'errors' ) as $kind ) {
			$out[ $kind ] = array(
				'rows'   => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $bench_table( $kind ) ) ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				'budget' => json_decode( (string) $wpdb->get_var( $wpdb->prepare( 'SELECT payload FROM %i WHERE state_key=%s', $bench_table( 'state' ), 'budget:' . $kind ) ), true ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			);
		}
		echo wp_json_encode( $out ), "\n";
		break;

	default:
		WP_CLI::error( 'Unknown benchmark action.' );
}
