<?php
// SPDX-License-Identifier: GPL-3.0-or-later
declare(strict_types=1);

use StealthAudit\Audit\Context;
use StealthAudit\Audit\Recorder;
use StealthAudit\Config\Configuration;
use StealthAudit\Storage\Database;
use StealthAudit\Storage\Maintenance;
use StealthAudit\Storage\Storage;

/**
 * Fault injection against real InnoDB tables with an isolated prefix (phase 8b).
 *
 * Every scenario records the time it really took in artifacts/fault-injection/results.json;
 * docs/testing/performance.md reports those numbers. Assertions check behaviour (fail
 * closed, no retry loop, bounded work), never a deadline: a synchronous wpdb call cannot be
 * interrupted, so the budget is only checked between statements.
 *
 * @group fault-injection
 * @group concurrency
 */
final class FaultInjectionTest extends PHPUnit\Framework\TestCase {
	private wpdb $connection;
	private Database $db;
	private string $prefix;
	private int $now;

	protected function setUp(): void {
		stealthaudit_tests_commit_global_connection();
		$this->now        = time();
		$this->connection = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$this->prefix     = 'sa_fault_' . bin2hex( random_bytes( 4 ) ) . '_';
		$this->connection->set_prefix( $this->prefix );
		$this->db = new Database( $this->connection );
		global $wpdb;
		$this->db->execute( 'CREATE TABLE %i LIKE %i', $this->connection->options, $wpdb->options );
		self::assertTrue( $this->storage()->schema->ensure() );
	}

	protected function tearDown(): void {
		$cleanup = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$cleanup->set_prefix( $this->prefix );
		$db = new Database( $cleanup );
		foreach ( array( 'events', 'errors', 'state' ) as $kind ) {
			$db->execute( 'DROP TABLE IF EXISTS %i', $db->table( $kind ) );
		}
		$db->execute( 'DROP TABLE IF EXISTS %i', $cleanup->options );
		$cleanup->close();
		$this->connection->close();
	}

	/** Default product limits unless a scenario says otherwise. */
	private function storage( array $limits = array(), ?wpdb $connection = null ): Storage {
		return new Storage( $connection ?? $this->connection, Configuration::from_array( array( 'limits' => $limits ) ) );
	}

	private function event(): array {
		return array( 'category' => 'content', 'action' => 'created', 'result' => 'success', 'origin' => 'cli', 'object_type' => 'post', 'object_id' => 42 );
	}

	private static function ms( int $from ): float {
		return round( ( hrtime( true ) - $from ) / 1e6, 3 );
	}

	private function rows( string $kind ): int {
		return (int) $this->db->rows( 'SELECT COUNT(*) AS n FROM %i', $this->db->table( $kind ) )[0]['n'];
	}

	private function budget( string $kind ): array {
		return $this->storage()->state->get( 'budget:' . $kind, time() )['payload'];
	}

	/** Merge one scenario's observed numbers into the shared results file (gitignored). */
	private static function record( string $scenario, array $observed ): void {
		$dir = dirname( __DIR__, 2 ) . '/artifacts/fault-injection';
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0700, true );
		}
		$file    = $dir . '/results.json';
		$results = is_file( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : array();
		$results = is_array( $results ) ? $results : array();
		global $wpdb;
		$results['_environment'] = array(
			'php'      => PHP_VERSION,
			'database' => $wpdb->db_server_info(),
			'at'       => gmdate( 'c' ),
		);
		$results[ $scenario ]    = $observed;
		ksort( $results );
		file_put_contents( $file, json_encode( $results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
	}

	/**
	 * Start a fault worker; returns the process and its pipes after the first stdout line.
	 *
	 * @return array{0:resource,1:array<int,resource>,2:string}
	 */
	private function worker( array $input, bool $close_stdin = true ): array {
		$process = proc_open( array( PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=0', dirname( __DIR__ ) . '/fixtures/fault-worker.php' ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		self::assertIsResource( $process );
		fwrite( $pipes[0], json_encode( $input + array( 'prefix' => $this->prefix ) ) . "\n" );
		if ( $close_stdin ) {
			fclose( $pipes[0] );
		}
		stream_set_timeout( $pipes[1], 60 );
		$first = (string) fgets( $pipes[1] );
		return array( $process, $pipes, $first );
	}

	private function finish( $process, array $pipes ): array {
		$rest  = stream_get_contents( $pipes[1] );
		$error = stream_get_contents( $pipes[2] );
		foreach ( array( 1, 2 ) as $pipe ) {
			fclose( $pipes[ $pipe ] );
		}
		$exit = proc_close( $process );
		return array( $exit, (string) $rest, (string) $error );
	}

	public function test_slow_database_runs_one_operation_past_the_budget_then_stops_writing(): void {
		// Simulated latency on every statement of this connection (not of WordPress itself).
		$slow = new class( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST ) extends wpdb {
			public int $delay_us = 0;
			public int $calls    = 0;
			// phpcs:ignore Generic.CodeAnalysis.UselessOverridingMethod
			public function query( $query ) {
				++$this->calls;
				usleep( $this->delay_us );
				return parent::query( $query );
			}
		};
		$slow->set_prefix( $this->prefix );
		$storage = $this->storage( array(), $slow );
		$started = hrtime( true );
		self::assertTrue( $storage->events->append( $this->event(), $this->now ) );
		$healthy = self::ms( $started );

		$slow->delay_us = 60000;
		$calls          = $slow->calls;
		$started        = hrtime( true );
		$first          = $storage->events->append( $this->event(), $this->now );
		$first_ms       = self::ms( $started );
		$first_sql      = $slow->calls - $calls;
		// The first slow write is not interrupted: it overruns the 100 ms work budget.
		self::assertTrue( $first );
		self::assertGreaterThan( 100, $first_ms );

		$calls     = $slow->calls;
		$started   = hrtime( true );
		$next      = array();
		$recorder  = new Recorder( fn () => $storage->events, new Context() );
		for ( $i = 0; $i < 10; ++$i ) {
			$next[] = $storage->events->append( $this->event(), $this->now );
			$recorder->emit( 'content', 'updated', 'post', 7 );
		}
		$after_ms  = self::ms( $started );
		$after_sql = $slow->calls - $calls;
		// No new attempt in this request once the budget is spent: zero statements, nothing stored.
		self::assertSame( array_fill( 0, 10, false ), $next );
		self::assertSame( 0, $after_sql );
		self::assertSame( 2, $this->rows( 'events' ) );
		self::record(
			'slow_database',
			array(
				'injected_latency_per_statement_ms' => 60,
				'work_budget_ms'                    => 100,
				'healthy_append_ms'                 => $healthy,
				'slow_append_ms'                    => $first_ms,
				'slow_append_statements'            => $first_sql,
				'next_20_writes_ms'                 => $after_ms,
				'next_20_writes_statements'         => $after_sql,
			)
		);
	}

	public function test_contended_state_row_blocks_for_the_lock_holder_then_the_request_stops(): void {
		$storage = $this->storage();
		self::assertTrue( $storage->events->append( $this->event(), $this->now ) );
		[ $holder, $pipes, $first ] = $this->worker( array( 'mode' => 'hold', 'hold_ms' => 1500 ) );
		self::assertSame( "locked\n", $first );
		$started = hrtime( true );
		$blocked = $storage->events->append( $this->event(), time() );
		$wait_ms = self::ms( $started );
		[ $exit, $rest, $error ] = $this->finish( $holder, $pipes );
		self::assertSame( 0, $exit, $error );
		// The CAS UPDATE waits for the other transaction's row lock, far past the 100 ms budget.
		self::assertTrue( $blocked );
		self::assertGreaterThan( 1000, $wait_ms );
		$queries = $this->connection->num_queries;
		self::assertFalse( $storage->events->append( $this->event(), time() ) );
		self::assertSame( $queries, $this->connection->num_queries );

		// Same contention with a 1 s lock wait timeout on this session: the write fails closed.
		$short = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$short->set_prefix( $this->prefix );
		$short->query( 'SET SESSION innodb_lock_wait_timeout = 1' );
		$storage = $this->storage( array(), $short );
		[ $holder, $pipes, $first ] = $this->worker( array( 'mode' => 'hold', 'hold_ms' => 3000 ) );
		self::assertSame( "locked\n", $first );
		$started  = hrtime( true );
		$timedout = $storage->events->append( $this->event(), time() );
		$fail_ms  = self::ms( $started );
		$cause    = $short->last_error;
		$queries  = $short->num_queries;
		$started  = hrtime( true );
		$retry    = $storage->events->append( $this->event(), time() );
		$retry_ms = self::ms( $started );
		self::assertSame( $queries, $short->num_queries );
		[ $exit, $rest, $error ] = $this->finish( $holder, $pipes );
		self::assertSame( 0, $exit, $error );
		self::assertFalse( $timedout );
		// It failed because the 1 s lock wait expired, not for any other reason (connection, SQL):
		// the server's own error, after about the session timeout and before the 3 s holder released.
		self::assertStringContainsString( 'Lock wait timeout', $cause );
		self::assertGreaterThan( 900, $fail_ms );
		self::assertLessThan( 2900, $fail_ms );
		self::assertFalse( $retry );
		$short->close();
		// No reservation leaked: the failed CAS never committed, and a new request writes again.
		self::assertSame( 0, $this->budget( 'events' )['pending'] );
		self::assertSame( $this->rows( 'events' ), $this->budget( 'events' )['rows'] );
		self::assertTrue( $this->storage()->events->append( $this->event(), time() ) );
		$timeout = (int) $this->connection->get_var( 'SELECT @@GLOBAL.innodb_lock_wait_timeout' );
		self::record(
			'state_contention',
			array(
				'lock_held_ms'                        => 1500,
				'blocked_append_ms'                   => $wait_ms,
				'server_innodb_lock_wait_timeout_s'   => $timeout,
				'session_lock_wait_timeout_s'         => 1,
				'timed_out_append_ms'                 => $fail_ms,
				'next_append_after_failure_ms'        => $retry_ms,
				'next_append_after_failure_statements' => 0,
			)
		);
	}

	public function test_concurrent_bursts_keep_counters_exact_and_report_latency(): void {
		$limits   = array( 'events_per_request' => 1000, 'events_per_second' => 100000, 'storage_work_ms' => 100000 );
		$children = array();
		for ( $i = 0; $i < 4; ++$i ) {
			[ $process, $pipes, $first ] = $this->worker( array( 'mode' => 'burst', 'count' => 50, 'limits' => $limits ), false );
			self::assertSame( "ready\n", $first );
			$children[] = array( $process, $pipes );
		}
		foreach ( $children as [ , $pipes ] ) {
			fwrite( $pipes[0], "go\n" );
			fclose( $pipes[0] );
		}
		$outcomes = array();
		foreach ( $children as [ $process, $pipes ] ) {
			[ $exit, $rest, $error ] = $this->finish( $process, $pipes );
			self::assertSame( 0, $exit, $error );
			$outcomes[] = json_decode( $rest, true, 8, JSON_THROW_ON_ERROR );
		}
		self::assertLessThan( min( array_column( $outcomes, 'end' ) ), max( array_column( $outcomes, 'start' ) ), 'burst workers did not overlap' );
		$accepted = array_sum( array_column( $outcomes, 'accepted' ) );
		$samples  = array_merge( ...array_column( $outcomes, 'samples' ) );
		sort( $samples );
		// Nearest-rank percentiles, the same method as benchmarks/ (ceil(q*n)-1).
		$rank     = static fn ( float $q ): float => $samples[ (int) max( 0, ceil( $q * count( $samples ) ) - 1 ) ];
		self::assertSame( 200, $accepted + array_sum( array_column( $outcomes, 'rejected' ) ) );
		// Lost CAS rounds may reject (fail closed); every accepted write is one row and one count.
		self::assertSame( $accepted, $this->rows( 'events' ) );
		$budget = $this->budget( 'events' );
		self::assertSame( $accepted, $budget['rows'] );
		// A completion that loses three CAS rounds leaves its slot pending (never refunded early);
		// that request then stops writing, so at most one per process. Maintenance ages it out.
		self::assertLessThanOrEqual( 4, $budget['pending'] );
		self::record(
			'concurrent_burst',
			array(
				'processes'      => 4,
				'appends_each'   => 50,
				'accepted'       => $accepted,
				'rejected'       => 200 - $accepted,
				'append_p50_ms'  => $rank( 0.50 ),
				'append_p95_ms'  => $rank( 0.95 ),
				'append_max_ms'  => end( $samples ),
				'counter_exact'  => true,
				'pending_left'   => $budget['pending'],
			)
		);
	}

	public function test_database_lost_mid_request_is_bounded_by_wpdb_reconnects_not_by_the_plugin(): void {
		// After template_redirect wpdb gives up and returns false: one write pays the reconnect
		// attempts, the circuit breaker then stops every later write of the request.
		[ $process, $pipes, $first ] = $this->worker( array( 'mode' => 'outage', 'after_template_redirect' => true ) );
		self::assertStringContainsString( 'outage_at', $first );
		[ $exit, $rest, $error ] = $this->finish( $process, $pipes );
		self::assertSame( 0, $exit, $error . $rest );
		$late = json_decode( trim( $rest ), true, 4, JSON_THROW_ON_ERROR );
		self::assertTrue( $late['healthy'] );
		self::assertFalse( $late['first'] );
		self::assertFalse( $late['second'] );
		self::assertSame( 0, $late['second_sql'] );

		// Earlier in the request wpdb calls dead_db() itself after the same attempts: WordPress,
		// not StealthAudit, ends the request. Time from the outage to the process exit.
		$started = hrtime( true );
		[ $process, $pipes, $first ] = $this->worker( array( 'mode' => 'outage', 'after_template_redirect' => false ) );
		$marker = json_decode( $first, true, 4, JSON_THROW_ON_ERROR );
		[ $exit, $rest, $error ] = $this->finish( $process, $pipes );
		$ended = hrtime( true );
		self::assertStringContainsString( 'Error establishing a database connection', $rest );
		self::assertStringNotContainsString( '"second"', $rest );
		self::record(
			'database_outage',
			array(
				'healthy_append_ms'                       => $late['healthy_ms'],
				'after_template_redirect_failed_append_ms' => $late['first_ms'],
				'after_template_redirect_next_append_ms'  => $late['second_ms'],
				'after_template_redirect_next_statements' => $late['second_sql'],
				'before_template_redirect_outage_to_exit_ms' => round( ( $ended - $marker['outage_at'] ) / 1e6, 3 ),
				'before_template_redirect_exit_code'      => $exit,
				'worker_total_ms'                         => self::ms( $started ),
			)
		);
	}

	public function test_delayed_cron_catches_up_in_bounded_runs_without_touching_live_rows(): void {
		// Twelve thousand audit rows past the 365-day retention, 2,000 within it, and three
		// thousand diagnostics past 30 days: what a cron missed for a long time leaves behind.
		$this->seed_events( 12000, $this->now - 400 * DAY_IN_SECONDS );
		$this->seed_events( 2000, $this->now - 10 * DAY_IN_SECONDS );
		$this->seed_errors( 3000, $this->now - 40 * DAY_IN_SECONDS );
		$storage = $this->storage();
		$runs    = array();
		do {
			$started = hrtime( true );
			$outcome = $storage->maintenance->run( $this->now );
			$runs[]  = array( 'ms' => self::ms( $started ) ) + $outcome;
			self::assertLessThanOrEqual( Maintenance::MAX_BATCHES, $outcome['batches'] );
			self::assertLessThan( 20, count( $runs ), 'maintenance never completed' );
		} while ( 'complete' !== $outcome['result'] );
		self::assertSame( 2000, $this->rows( 'events' ) );
		self::assertSame( 0, $this->rows( 'errors' ) );
		self::assertSame( 12000, array_sum( array_map( static fn ( array $run ): int => $run['purged']['events'], $runs ) ) );
		self::assertSame( 2000, $this->budget( 'events' )['rows'] );
		$durations = array_column( $runs, 'ms' );
		self::record(
			'delayed_cron',
			array(
				'expired_events'    => 12000,
				'expired_errors'    => 3000,
				'live_events'       => 2000,
				'runs_to_complete'  => count( $runs ),
				'run_ms'            => $durations,
				'max_run_ms'        => max( $durations ),
				'batches_per_run'   => array_column( $runs, 'batches' ),
				'results'           => array_column( $runs, 'result' ),
				'run_limit_seconds' => Maintenance::MAX_SECONDS,
				'run_limit_batches' => Maintenance::MAX_BATCHES,
			)
		);
	}

	/** @dataProvider storms */
	public function test_warning_storm_stores_a_bounded_sample_and_keeps_counting( bool $distinct ): void {
		[ $process, $pipes, $first ] = $this->worker( array( 'mode' => 'storm', 'count' => 10000, 'distinct' => $distinct ) );
		[ $exit, $rest, $error ] = $this->finish( $process, $pipes );
		self::assertSame( 0, $exit, $error . $rest );
		$storm = json_decode( trim( $first ), true, 4, JSON_THROW_ON_ERROR );
		// Five occurrences per request (default) reach the database; the rest are counted only.
		self::assertSame( 5, $this->stored_occurrences() );
		self::assertSame( 9995, $storm['health']['dropped'] );
		self::assertSame( 'limited', $storm['health']['status'] );
		self::assertLessThan( 1048576, $storm['memory_bytes'] );
		// At most five appends of six statements each (two CAS rounds + write): no retry after refusal.
		self::assertLessThanOrEqual( 30, $storm['sql'] );
		self::record(
			$distinct ? 'warning_storm_distinct' : 'warning_storm_repeated',
			array(
				'warnings'                 => $storm['count'],
				'baseline_ms'              => $storm['baseline_ms'],
				'with_collector_ms'        => $storm['collector_ms'],
				'added_per_warning_us'     => round( ( $storm['collector_ms'] - $storm['baseline_ms'] ) * 1000 / $storm['count'], 3 ),
				'statements'               => $storm['sql'],
				'stored_occurrences'       => $this->stored_occurrences(),
				'dropped_counted'          => $storm['health']['dropped'],
				'memory_growth_bytes'      => $storm['memory_bytes'],
			)
		);
	}

	public static function storms(): array {
		return array(
			'one repeated warning' => array( false ),
			'distinct warnings'    => array( true ),
		);
	}

	private function stored_occurrences(): int {
		return (int) $this->db->rows( 'SELECT COALESCE(SUM(occurrences),0) AS n FROM %i', $this->db->table( 'errors' ) )[0]['n'];
	}

	/** Bulk synthetic rows straight into the isolated table, counted into the budget like real ones. */
	private function seed_events( int $count, int $time ): void {
		$this->db->execute(
			"INSERT INTO %i (record_version,occurred_at,actor_id,category,action,result,origin,object_type,object_id,ip,summary,logical_bytes)
			SELECT 1, %s - INTERVAL n SECOND, NULL, 'content', 'created', 'success', 'cli', 'post', 42, NULL, '[]', 120 FROM (" . self::numbers( $count ) . ') AS seq',
			$this->db->table( 'events' ),
			gmdate( 'Y-m-d H:i:s', $time )
		);
		$this->add_to_budget( 'events', $count, $count * 120 );
	}

	private function seed_errors( int $count, int $time ): void {
		$this->db->execute(
			"INSERT INTO %i (record_version,first_seen,last_seen,severity,message,file,line,fingerprint,occurrences,logical_bytes)
			SELECT 1, %s - INTERVAL n SECOND, %s - INTERVAL n SECOND, 'warning', CONCAT('synthetic ', n), 'x.php', 1, SHA2(CONCAT('s', n), 256), 1, 160 FROM (" . self::numbers( $count ) . ') AS seq',
			$this->db->table( 'errors' ),
			gmdate( 'Y-m-d H:i:s', $time ),
			gmdate( 'Y-m-d H:i:s', $time )
		);
		$this->add_to_budget( 'errors', $count, $count * 160 );
	}

	private function add_to_budget( string $kind, int $rows, int $bytes ): void {
		$state  = $this->storage()->state;
		$budget = $state->get( 'budget:' . $kind, time() );
		$next   = $budget['payload'];
		$next['rows']  += $rows;
		$next['bytes'] += $bytes;
		self::assertTrue( $state->compare_and_swap( 'budget:' . $kind, $budget['revision'], $next, null, time() ) );
	}

	/** Derived table of n = 0 .. count-1 without recursion limits (MariaDB and MySQL). */
	private static function numbers( int $count ): string {
		$digits = '(SELECT 0 d UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9)';
		return 'SELECT a.d + 10*b.d + 100*c.d + 1000*e.d + 10000*f.d AS n FROM ' . $digits . ' a, ' . $digits . ' b, ' . $digits . ' c, ' . $digits . ' e, ' . $digits . ' f HAVING n < ' . (int) $count;
	}
}
