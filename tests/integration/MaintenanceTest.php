<?php
// SPDX-License-Identifier: GPL-3.0-or-later
declare(strict_types=1);

use StealthAudit\Bootstrap\Scheduler;
use StealthAudit\Config\Configuration;
use StealthAudit\Storage\Budget;
use StealthAudit\Storage\Database;
use StealthAudit\Storage\Maintenance;
use StealthAudit\Storage\Storage;
use StealthAudit\Storage\StorageException;

/** Phase 7a: retention, leases, reconciliation and WP-Cron on real InnoDB, isolated tables. */
final class MaintenanceTest extends PHPUnit\Framework\TestCase {
	private wpdb $connection;
	private Database $db;
	private Storage $storage;
	private string $prefix;
	private int $now;
	private mixed $cron = null;

	protected function setUp(): void {
		stealthaudit_tests_commit_global_connection();
		$this->connection = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$this->prefix = 'sa_mt_' . bin2hex( random_bytes( 4 ) ) . '_';
		$this->connection->set_prefix( $this->prefix );
		$this->db = new Database( $this->connection );
		global $wpdb;
		$this->db->execute( 'CREATE TABLE %i LIKE %i', $this->connection->options, $wpdb->options );
		$this->storage = $this->fresh();
		self::assertTrue( $this->storage->schema->ensure() );
		$this->now = time();
	}

	protected function tearDown(): void {
		if ( null !== $this->cron ) {
			update_option( 'cron', $this->cron );
		}
		foreach ( array( 'events', 'errors', 'state' ) as $kind ) {
			$this->db->execute( 'DROP TABLE IF EXISTS %i', $this->db->table( $kind ) );
		}
		$this->db->execute( 'DROP TABLE IF EXISTS %i', $this->connection->options );
		$this->connection->close();
	}

	public function test_physical_purge_exact_boundary_ordered_batches_and_index_resume(): void {
		$cutoff = $this->now - 365 * DAY_IN_SECONDS;
		$old    = array();
		for ( $i = 1100; $i >= 1; --$i ) {
			$old[] = $cutoff - $i;
		}
		$this->raw_events( $old );
		$this->raw_events( array( $cutoff, $cutoff + 1 ) );
		$errors_cutoff = $this->now - 30 * DAY_IN_SECONDS;
		$this->raw_errors( array( $errors_cutoff - 1, $errors_cutoff, $errors_cutoff + 1 ) );
		// The DELETE walks the (time,id) index range: no table scan, so it resumes at the head.
		$plan = $this->db->rows( 'EXPLAIN DELETE FROM %i WHERE occurred_at<=%s ORDER BY occurred_at,id LIMIT 500', $this->db->table( 'events' ), gmdate( 'Y-m-d H:i:s', $cutoff ) )[0];
		self::assertSame( 'occurred', $plan['key'] );
		self::assertSame( 'range', $plan['type'] );
		$plan = $this->db->rows( 'EXPLAIN DELETE FROM %i WHERE first_seen<=%s ORDER BY first_seen,id LIMIT 500', $this->db->table( 'errors' ), gmdate( 'Y-m-d H:i:s', $errors_cutoff ) )[0];
		self::assertSame( 'first_time', $plan['key'] );
		// Query retention hides expired rows before any purge.
		$visible = $this->storage->events->window( array(), null, null, null, 100, $this->now )['rows'];
		self::assertSame( array( gmdate( 'Y-m-d H:i:s', $cutoff + 1 ) ), array_column( $visible, 'occurred_at' ) );
		// One batch per run: exactly 500 rows, oldest first.
		$first = $this->storage->maintenance->run( $this->now, 1 );
		self::assertSame( array( 'result' => 'partial', 'batches' => 1, 'purged' => array( 'events' => 500, 'errors' => 0, 'state' => 0 ) ), $first );
		self::assertSame( gmdate( 'Y-m-d H:i:s', $cutoff - 600 ), $this->db->rows( 'SELECT MIN(occurred_at) AS t FROM %i', $this->db->table( 'events' ) )[0]['t'] );
		self::assertSame( 'backlog', $this->storage->maintenance->report( $this->now )['state'] );
		self::assertSame( 500, $this->storage->maintenance->run( $this->now, 1 )['purged']['events'] );
		// A time budget that is already spent runs no batch at all.
		self::assertSame( 0, $this->storage->maintenance->run( $this->now, 100, 1e-9 )['batches'] );
		$last = $this->storage->maintenance->run( $this->now );
		self::assertSame( 'complete', $last['result'] );
		self::assertSame( array( 'events' => 101, 'errors' => 2, 'state' => 0 ), $last['purged'] );
		self::assertSame( array( gmdate( 'Y-m-d H:i:s', $cutoff + 1 ) ), array_column( $this->db->rows( 'SELECT occurred_at FROM %i', $this->db->table( 'events' ) ), 'occurred_at' ) );
		self::assertSame( array( gmdate( 'Y-m-d H:i:s', $errors_cutoff + 1 ) ), array_column( $this->db->rows( 'SELECT first_seen FROM %i', $this->db->table( 'errors' ) ), 'first_seen' ) );
		self::assertSame( 0, $this->storage->maintenance->run( $this->now )['purged']['events'] );
		$this->expectException( StorageException::class );
		$this->storage->maintenance->run( $this->now, 101 );
	}

	public function test_two_workers_race_on_the_lease_and_overdue_runs_are_reported(): void {
		$this->raw_events( array_fill( 0, 3000, $this->now - 400 * DAY_IN_SECONDS ) );
		self::assertSame( array( 'busy', 'complete' ), $this->workers( 'maintenance' ) );
		self::assertSame( 0, $this->rows_in( 'events' ) );
		// A live lease of another worker refuses the run; once expired it is taken over.
		$key = 'lease:' . hash( 'sha256', 'stealthaudit-maintenance' );
		$row = $this->storage->state->get( $key, $this->now );
		self::assertSame( '', $row['payload']['owner'] );
		self::assertTrue( $this->storage->state->compare_and_swap( $key, $row['revision'], array( 'owner' => 'crashed-worker' ), $this->now + Maintenance::LEASE_SECONDS, $this->now ) );
		self::assertSame( 'busy', $this->storage->maintenance->run( $this->now )['result'] );
		$late = $this->now + Maintenance::LEASE_SECONDS;
		self::assertSame( 'complete', $this->storage->maintenance->run( $late )['result'] );
		$report = $this->storage->maintenance->report( $late );
		self::assertSame( array( 'state' => 'current', 'last_run' => $late ), array_slice( $report, 0, 2 ) );
		self::assertSame( 'current', $this->storage->maintenance->report( $late + Maintenance::OVERDUE )['state'] );
		self::assertSame( 'overdue', $this->storage->maintenance->report( $late + Maintenance::OVERDUE + 1 )['state'] );
		self::assertSame( array( 'state' => 'never', 'last_run' => null, 'dropped_before' => array( 'events' => null, 'errors' => null ) ), $this->fresh_tables()->maintenance->report( $this->now ) );
	}

	public function test_expired_auxiliary_state_is_purged_while_factors_and_live_limits_stay(): void {
		$state = $this->storage->state;
		$keys  = array();
		foreach ( array( 'live_limit' => 'limit', 'old_limit' => 'limit', 'old_proof' => 'proof', 'live_proof' => 'proof', 'old_lease' => 'lease' ) as $name => $type ) {
			$keys[ $name ] = $type . ':' . hash( 'sha256', $name );
			self::assertTrue( $state->create( $keys[ $name ], array( 'count' => 1 ), str_starts_with( $name, 'live' ) ? $this->now + 300 : $this->now + 1 ) );
		}
		self::assertTrue( $state->create( 'factor:5', array( 'generation' => 1 ), null ) );
		// Overcount the auxiliary budget, as failed creations do.
		$budget = $state->get( 'budget:state', $this->now );
		self::assertTrue( $state->compare_and_swap( 'budget:state', $budget['revision'], array( 'rows' => $budget['payload']['rows'] + 50 ), null, $this->now ) );
		$later  = $this->now + 2;
		$result = $this->storage->maintenance->run( $later );
		self::assertSame( 'complete', $result['result'] );
		self::assertSame( 3, $result['purged']['state'] );
		foreach ( array( 'old_limit', 'old_proof', 'old_lease' ) as $name ) {
			self::assertSame( array(), $this->db->rows( 'SELECT state_key FROM %i WHERE state_key=%s', $this->db->table( 'state' ), $keys[ $name ] ) );
		}
		self::assertSame( 1, $state->get( $keys['live_limit'], $later )['payload']['count'] );
		self::assertNotNull( $state->get( $keys['live_proof'], $later ) );
		self::assertNotNull( $state->get( 'factor:5', $later ) );
		// Counter equals the physical rows: 3 budgets, factor, 2 live rows, lease, status.
		self::assertSame( 8, $this->rows_in( 'state' ) );
		self::assertSame( 8, $state->get( 'budget:state', $later )['payload']['rows'] );
	}

	public function test_recurring_error_never_keeps_a_bucket_older_than_30_days(): void {
		$start   = $this->now - 31 * DAY_IN_SECONDS;
		$storage = $this->fresh( array( 'errors_per_request' => 100, 'errors_per_second' => 100 ) );
		$error   = array( 'severity' => 'warning', 'message' => 'synthetic recurring diagnostic', 'file' => 'wp-content/plugins/sample/a.php', 'line' => 3 );
		$bucket  = intdiv( $start, 300 ) * 300;
		foreach ( array( $bucket, $bucket + 299, $bucket + 300, $bucket + 86400, $this->now ) as $time ) {
			self::assertTrue( $storage->errors->append( $error, $time ) );
		}
		$rows = $this->db->rows( 'SELECT first_seen,last_seen,occurrences FROM %i ORDER BY id', $this->db->table( 'errors' ) );
		self::assertCount( 4, $rows );
		self::assertSame( array( gmdate( 'Y-m-d H:i:s', $bucket ), gmdate( 'Y-m-d H:i:s', $bucket + 299 ), '2' ), array_values( $rows[0] ) );
		foreach ( $rows as $row ) {
			self::assertLessThan( 300, strtotime( $row['last_seen'] . ' UTC' ) - strtotime( $row['first_seen'] . ' UTC' ) );
		}
		$visible = $this->storage->errors->window( array(), null, null, null, 100, $this->now )['rows'];
		self::assertSame( array( gmdate( 'Y-m-d H:i:s', $this->now ) ), array_column( $visible, 'first_seen' ) );
		self::assertSame( 3, $this->storage->maintenance->run( $this->now )['purged']['errors'] );
		$left = $this->db->rows( 'SELECT first_seen,occurrences FROM %i', $this->db->table( 'errors' ) );
		self::assertSame( array( array( 'first_seen' => gmdate( 'Y-m-d H:i:s', $this->now ), 'occurrences' => '1' ) ), $left );
	}

	public function test_counters_reconcile_in_resumable_batches_and_rotate_losses(): void {
		$this->raw_events( array_fill( 0, 1200, $this->now - 60 ), 100 );
		$this->budget( 'events', array( 'rows' => 5000, 'bytes' => 9000000, 'dropped' => 7 ) );
		// Purges and the auxiliary recount take four batches; two more read 1000 rows.
		self::assertSame( 'partial', $this->storage->maintenance->run( $this->now, 6 )['result'] );
		$budget = $this->storage->state->get( 'budget:events', $this->now )['payload'];
		self::assertSame( array( 5000, 7 ), array( $budget['rows'], $budget['dropped'] ) );
		$cursor = $this->storage->state->get( Maintenance::STATUS_KEY, $this->now )['payload']['cursor']['events'];
		self::assertSame( array( 1000, 100000 ), array( $cursor['rows'], $cursor['bytes'] ) );
		self::assertSame( 'backlog', $this->storage->maintenance->report( $this->now )['state'] );
		// A write after the cursor is included by the resumed recount.
		self::assertTrue( $this->storage->events->append( array( 'category' => 'content', 'action' => 'created', 'result' => 'success', 'origin' => 'cli' ), $this->now ) );
		$bytes = (int) $this->db->rows( 'SELECT logical_bytes FROM %i ORDER BY id DESC LIMIT 1', $this->db->table( 'events' ) )[0]['logical_bytes'];
		self::assertSame( 'complete', $this->storage->maintenance->run( $this->now )['result'] );
		$budget = $this->storage->state->get( 'budget:events', $this->now )['payload'];
		self::assertSame( 1201, $budget['rows'] );
		self::assertSame( 120000 + $bytes, $budget['bytes'] );
		self::assertSame( 0, $budget['dropped'] );
		self::assertSame( 7, $budget['dropped_before'] );
		self::assertSame( $this->now, $budget['maintained_at'] );
		self::assertSame( array( 'events' => 7, 'errors' => 0 ), $this->storage->maintenance->report( $this->now )['dropped_before'] );
		// An in-flight reservation blocks publishing; a stale one (crash) is cleared later.
		$reserver = new Budget( $this->storage->state, Configuration::from_array( array() ) );
		self::assertTrue( $reserver->reserve( 'events', 50, $this->now ) );
		self::assertSame( 'blocked', $this->storage->maintenance->run( $this->now )['result'] );
		self::assertSame( 'blocked', $this->storage->maintenance->report( $this->now )['state'] );
		self::assertSame( array( 1202, 1 ), array_values( array_intersect_key( $this->storage->state->get( 'budget:events', $this->now )['payload'], array_flip( array( 'rows', 'pending' ) ) ) ) );
		// Buckets are whole minutes: a reservation is stale 900..960 s after it was made.
		$stale = $this->now + Budget::PENDING_STALE + Budget::PENDING_BUCKET;
		self::assertSame( 'complete', $this->storage->maintenance->run( $stale )['result'] );
		$budget = $this->storage->state->get( 'budget:events', $stale )['payload'];
		// The blocked run's two full batches each saw that reservation in flight, so the resumed
		// publish keeps a margin of 2 (it could have committed behind the cursor).
		self::assertSame( array( 1203, 0, $stale ), array( $budget['rows'], $budget['pending'], $budget['pending_reset'] ) );
		// The next recount starts clean and has nothing in flight: exact again.
		self::assertSame( 'complete', $this->storage->maintenance->run( $stale )['result'] );
		self::assertSame( 1201, $this->storage->state->get( 'budget:events', $stale )['payload']['rows'] );
	}

	public function test_schema_migration_from_version_1_is_idempotent_contested_and_keeps_cursors(): void {
		$base = $this->now - DAY_IN_SECONDS;
		$this->raw_errors( array( $base, $base, $base + 10, $base + 10, $base + 10, $base + 20, $base + 30 ) );
		$before = $this->pages();
		$this->db->execute( 'ALTER TABLE %i DROP INDEX first_time, DROP INDEX severity_first', $this->db->table( 'errors' ) );
		$this->db->execute( 'UPDATE %i SET option_value=%s WHERE option_name=%s', $this->connection->options, '1', 'stealthaudit_schema_version' );
		self::assertFalse( $this->storage->schema->current() );
		try {
			$this->storage->schema->verify();
			self::fail( 'Version 1 indexes accepted as version 2.' );
		} catch ( StorageException $failure ) {
			self::assertSame( 'Incompatible storage indexes.', $failure->getMessage() );
		}
		self::assertContains( 1, $this->workers( 'ensure' ) );
		self::assertTrue( $this->storage->schema->current() );
		$this->storage->schema->verify();
		$indexes = count( $this->db->rows( 'SHOW INDEX FROM %i', $this->db->table( 'errors' ) ) );
		self::assertTrue( $this->storage->schema->ensure() );
		$this->db->execute( 'UPDATE %i SET option_value=%s WHERE option_name=%s', $this->connection->options, '1', 'stealthaudit_schema_version' );
		self::assertTrue( $this->storage->schema->ensure() );
		self::assertSame( $indexes, count( $this->db->rows( 'SHOW INDEX FROM %i', $this->db->table( 'errors' ) ) ) );
		self::assertSame( $before, $this->pages() );
		self::assertCount( 7, $before );
		self::assertSame( 7, count( array_unique( $before ) ) );
	}

	public function test_cron_schedules_once_keeps_an_overdue_event_and_tolerates_wp_error(): void {
		$this->cron = get_option( 'cron' );
		self::assertNotFalse( has_action( Scheduler::HOOK ), 'Runtime registers the cron handler on every request.' );
		$scheduler = new Scheduler( fn (): Storage => $this->storage, fn (): int => $this->now );
		wp_clear_scheduled_hook( Scheduler::HOOK );
		self::assertTrue( $scheduler->schedule() );
		$next = wp_next_scheduled( Scheduler::HOOK, array() );
		self::assertTrue( $scheduler->schedule() );
		self::assertSame( $next, wp_next_scheduled( Scheduler::HOOK, array() ) );
		self::assertSame( 1, $this->scheduled() );
		self::assertSame( 'hourly', wp_get_schedule( Scheduler::HOOK, array() ) );
		// Overdue (traffic stopped for two hours): kept as is, never duplicated.
		wp_clear_scheduled_hook( Scheduler::HOOK );
		self::assertTrue( wp_schedule_event( $this->now - 7200, 'hourly', Scheduler::HOOK, array(), true ) );
		self::assertTrue( $scheduler->schedule() );
		self::assertSame( $this->now - 7200, wp_next_scheduled( Scheduler::HOOK, array() ) );
		self::assertSame( 1, $this->scheduled() );
		// A WP_Error from the scheduler is "try later", never an exception on the page.
		wp_clear_scheduled_hook( Scheduler::HOOK );
		$veto = static fn (): WP_Error => new WP_Error( 'synthetic_veto' );
		add_filter( 'pre_schedule_event', $veto );
		try {
			self::assertFalse( $scheduler->schedule() );
		} finally {
			remove_filter( 'pre_schedule_event', $veto );
		}
		self::assertFalse( wp_next_scheduled( Scheduler::HOOK, array() ) );
		$broken = new Scheduler(
			static function (): Storage {
				throw new StorageException( 'Storage unavailable.' );
			},
			fn (): int => $this->now
		);
		self::assertFalse( $broken->schedule() );
		$broken->run();
		// A delayed run catches up on a backlog much older than the retention windows.
		$this->raw_events( array_fill( 0, 700, $this->now - 400 * DAY_IN_SECONDS ) );
		$this->raw_errors( array_fill( 0, 3, $this->now - 40 * DAY_IN_SECONDS ) );
		$scheduler->run();
		self::assertSame( array( 0, 0 ), array( $this->rows_in( 'events' ), $this->rows_in( 'errors' ) ) );
		self::assertSame( 'current', $this->storage->maintenance->report( $this->now )['state'] );
	}

	public function test_orphaned_pending_ages_out_even_while_the_site_keeps_writing(): void {
		$start  = $this->now;
		$budget = new Budget( $this->storage->state, Configuration::from_array( array( 'limits' => array( 'events_per_request' => 1000 ) ) ) );
		// One request dies between reserve() and complete().
		self::assertTrue( $budget->reserve( 'events', 100, $start ) );
		// The site keeps reserving and completing every minute for 16 minutes.
		for ( $time = $start + 60; $time <= $start + 960; $time += 60 ) {
			self::assertTrue( $budget->reserve( 'events', 100, $time ) );
			$budget->complete( 'events', $time );
			$payload = $this->storage->state->get( 'budget:events', $time )['payload'];
			self::assertLessThanOrEqual( 17, count( $payload['pending_by_minute'] ) );
			self::assertSame( 1, $payload['pending'] );
			self::assertSame( $time, $payload['second'] );
			if ( $start + 300 === $time ) {
				// Five minutes later the orphan is still recent: conservative, publish waits.
				self::assertSame( 'blocked', $this->storage->maintenance->run( $time )['result'] );
			}
		}
		// The old rule (no reservation at all for 900 s) would never clear it on this site.
		$late   = $start + 960;
		$result = $this->storage->maintenance->run( $late );
		self::assertSame( 'complete', $result['result'] );
		$payload = $this->storage->state->get( 'budget:events', $late )['payload'];
		self::assertSame( array( 0, array(), $late, 0 ), array( $payload['pending'], $payload['pending_by_minute'], $payload['pending_reset'], $payload['rows'] ) );
	}

	public function test_a_recent_pending_is_retried_within_the_run_before_blocking(): void {
		// Deterministic: a pending that never completes costs exactly three retries.
		$budget = new Budget( $this->storage->state, Configuration::from_array( array() ) );
		self::assertTrue( $budget->reserve( 'errors', 100, $this->now ) );
		$run = $this->storage->maintenance->run( $this->now );
		self::assertSame( 'blocked', $run['result'] );
		// 3 purges + auxiliary count + events publish + errors (1 + 3 retries).
		self::assertSame( 9, $run['batches'] );
		$budget->complete( 'errors', $this->now );
		self::assertSame( 'complete', $this->storage->maintenance->run( $this->now )['result'] );
		// Real concurrency: another process completes its reservation 40 ms after "go".
		[ $process, $pipes ] = $this->worker( 'pending' );
		fwrite( $pipes[0], "go\n" );
		fclose( $pipes[0] );
		$result = $this->storage->maintenance->run( $this->now );
		self::assertSame( '1', trim( stream_get_contents( $pipes[1] ) ) );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		self::assertSame( 0, proc_close( $process ) );
		self::assertSame( 'complete', $result['result'] );
		self::assertSame( 0, $this->storage->state->get( 'budget:events', $this->now )['payload']['pending'] );
	}

	public function test_multi_batch_recount_adds_a_margin_for_rows_committed_behind_the_cursor(): void {
		// ids 1..1200 without 100: id 100 stands for an INSERT allocated but not yet committed.
		$values = array();
		for ( $id = 1; $id <= 1200; ++$id ) {
			if ( 100 !== $id ) {
				$values[] = $this->connection->prepare( "(%d,1,%s,'content','created','success','cli','post','{}',100)", $id, gmdate( 'Y-m-d H:i:s', $this->now - 60 ) );
			}
		}
		$this->db->execute( 'INSERT INTO %i (id,record_version,occurred_at,category,action,result,origin,object_type,summary,logical_bytes) VALUES ' . implode( ',', $values ), $this->db->table( 'events' ) );
		$writer = new Budget( $this->storage->state, Configuration::from_array( array() ) );
		self::assertTrue( $writer->reserve( 'events', 100, $this->now ) );
		// Three purges, the auxiliary count and one recount batch (ids 1..501) in flight.
		self::assertSame( 'partial', $this->storage->maintenance->run( $this->now, 5 )['result'] );
		$cursor = $this->storage->state->get( Maintenance::STATUS_KEY, $this->now )['payload']['cursor']['events'];
		self::assertSame( array( 501, 500, 1, Maintenance::RECORD_BYTES['events'] ), array( $cursor['id'], $cursor['rows'], $cursor['margin_rows'], $cursor['margin_bytes'] ) );
		// The writer commits behind the cursor, then completes before the final read.
		$this->db->execute( "INSERT INTO %i (id,record_version,occurred_at,category,action,result,origin,object_type,summary,logical_bytes) VALUES (100,1,%s,'content','created','success','cli','post','{}',100)", $this->db->table( 'events' ), gmdate( 'Y-m-d H:i:s', $this->now ) );
		$writer->complete( 'events', $this->now );
		self::assertSame( 'complete', $this->storage->maintenance->run( $this->now )['result'] );
		$budget   = $this->storage->state->get( 'budget:events', $this->now )['payload'];
		$physical = $this->db->rows( 'SELECT COUNT(*) AS n, SUM(logical_bytes) AS b FROM %i', $this->db->table( 'events' ) )[0];
		self::assertSame( 1200, (int) $physical['n'] );
		// The scan saw 1,199 rows; the margin keeps the counter at the physical count.
		self::assertSame( 1200, $budget['rows'] );
		self::assertGreaterThanOrEqual( (int) $physical['b'], $budget['bytes'] );
	}

	public function test_maintenance_runs_and_reports_when_auxiliary_capacity_is_full(): void {
		// Capacity equal to the five fixed rows: no proof, limit or lease can be created.
		$full = $this->fresh( array( 'state_rows' => 5 ) );
		self::assertFalse( $full->state->create( 'proof:' . hash( 'sha256', 'x' ), array( 'a' => 1 ), $this->now + 60 ) );
		self::assertSame( 'never', $full->maintenance->report( $this->now )['state'] );
		self::assertSame( 'complete', $full->maintenance->run( $this->now )['result'] );
		self::assertSame( array( 'state' => 'current', 'last_run' => $this->now ), array_slice( $full->maintenance->report( $this->now ), 0, 2 ) );
		self::assertSame( 5, $this->rows_in( 'state' ) );
		// A lost status row with maintained budgets is reported as unavailable, not "never".
		$this->db->execute( 'DELETE FROM %i WHERE state_key=%s', $this->db->table( 'state' ), Maintenance::STATUS_KEY );
		self::assertSame( 'unavailable', $full->maintenance->report( $this->now )['state'] );
	}

	public function test_auxiliary_recount_never_drops_below_a_stalled_create(): void {
		$key    = 'proof:' . hash( 'sha256', 'synthetic-stalled-create' );
		$table  = $this->db->table( 'state' );
		$locker = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$locker->set_prefix( $this->prefix );
		try {
			// Another connection holds the gap where the key would go: the INSERT waits.
			self::assertNotFalse( $locker->query( 'START TRANSACTION' ) );
			self::assertNotFalse( $locker->query( $locker->prepare( 'SELECT state_key FROM %i WHERE state_key=%s FOR UPDATE', $table, $key ) ) );
			[ $process, $pipes ] = $this->worker( 'create', array( 'key' => $key ) );
			fwrite( $pipes[0], "go\n" );
			fclose( $pipes[0] );
			$deadline = microtime( true ) + 10;
			do {
				usleep( 20000 );
				$budget = $this->storage->state->get( 'budget:state', $this->now )['payload'];
			} while ( ( $budget['pending'] ?? 0 ) < 1 && microtime( true ) < $deadline );
			self::assertSame( 1, $budget['pending'] );
			// Far longer than any sleep-based guess; the INSERT is still blocked.
			usleep( 500000 );
			self::assertSame( array(), $this->db->rows( 'SELECT state_key FROM %i WHERE state_key=%s', $table, $key ) );
			$run = $this->storage->maintenance->run( $this->now );
			self::assertSame( 'partial', $run['result'] );
			$physical = $this->rows_in( 'state' );
			$counted  = $this->storage->state->get( 'budget:state', $this->now )['payload']['rows'];
			self::assertGreaterThanOrEqual( $physical, $counted );
			self::assertSame( $physical + 1, $counted );
		} finally {
			$locker->query( 'ROLLBACK' );
			$locker->close();
		}
		self::assertSame( '1', trim( stream_get_contents( $pipes[1] ) ) );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		self::assertSame( 0, proc_close( $process ) );
		// The INSERT landed and its slot settled; the counter still covers every physical row
		// (a recount published during the stall would now be one below), and the next is exact.
		self::assertSame( 0, $this->storage->state->get( 'budget:state', $this->now )['payload']['pending'] );
		self::assertGreaterThanOrEqual( $this->rows_in( 'state' ), $this->storage->state->get( 'budget:state', $this->now )['payload']['rows'] );
		self::assertSame( 'complete', $this->storage->maintenance->run( $this->now )['result'] );
		self::assertSame( $this->rows_in( 'state' ), $this->storage->state->get( 'budget:state', $this->now )['payload']['rows'] );
		self::assertNotNull( $this->storage->state->get( $key, $this->now ) );
	}

	private function worker( string $mode, array $extra = array() ): array {
		$process = proc_open( array( PHP_BINARY, dirname( __DIR__ ) . '/fixtures/storage-worker.php' ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		self::assertIsResource( $process );
		fwrite( $pipes[0], json_encode( array( 'user' => DB_USER, 'password' => DB_PASSWORD, 'name' => DB_NAME, 'host' => DB_HOST, 'prefix' => $this->prefix, 'mode' => $mode, 'now' => $this->now ) + $extra ) . "\n" );
		stream_set_timeout( $pipes[1], 30 );
		self::assertSame( "ready\n", fgets( $pipes[1] ) );
		return array( $process, $pipes );
	}

	private function fresh( array $limits = array() ): Storage {
		return new Storage( $this->connection, Configuration::from_array( array( 'limits' => $limits + array( 'storage_work_ms' => 10000 ) ) ) );
	}

	/** Same connection, fresh tables: drop and reinstall. */
	private function fresh_tables(): Storage {
		foreach ( array( 'events', 'errors', 'state' ) as $kind ) {
			$this->db->execute( 'DROP TABLE %i', $this->db->table( $kind ) );
		}
		$this->db->execute( 'DELETE FROM %i WHERE option_name=%s', $this->connection->options, 'stealthaudit_schema_version' );
		$storage = $this->fresh();
		self::assertTrue( $storage->schema->ensure() );
		return $storage;
	}

	private function rows_in( string $kind ): int {
		return (int) $this->db->rows( 'SELECT COUNT(*) AS n FROM %i', $this->db->table( $kind ) )[0]['n'];
	}

	private function scheduled(): int {
		$found = 0;
		foreach ( (array) _get_cron_array() as $hooks ) {
			$found += count( $hooks[ Scheduler::HOOK ] ?? array() );
		}
		return $found;
	}

	private function budget( string $kind, array $changes ): void {
		$row = $this->storage->state->get( 'budget:' . $kind, $this->now );
		self::assertTrue( $this->storage->state->compare_and_swap( 'budget:' . $kind, $row['revision'], $changes + $row['payload'], null, $this->now ) );
	}

	/** Raw rows bypass the budget, like data written before reconciliation existed. */
	private function raw_events( array $times, int $bytes = 100 ): void {
		foreach ( array_chunk( $times, 500 ) as $chunk ) {
			$values = array();
			foreach ( $chunk as $time ) {
				$values[] = $this->connection->prepare( "(1,%s,'content','created','success','cli','post','{}',%d)", gmdate( 'Y-m-d H:i:s', $time ), $bytes );
			}
			$this->db->execute( 'INSERT INTO %i (record_version,occurred_at,category,action,result,origin,object_type,summary,logical_bytes) VALUES ' . implode( ',', $values ), $this->db->table( 'events' ) );
		}
	}

	private function raw_errors( array $times ): void {
		foreach ( $times as $i => $time ) {
			$stamp = gmdate( 'Y-m-d H:i:s', $time );
			$this->db->execute( 'INSERT INTO %i (record_version,first_seen,last_seen,severity,message,file,line,fingerprint,occurrences,logical_bytes) VALUES (1,%s,%s,%s,%s,%s,%d,%s,1,120)', $this->db->table( 'errors' ), $stamp, $stamp, 'warning', 'synthetic diagnostic ' . $i, 'a.php', 1, hash( 'sha256', (string) $i ) );
		}
	}

	/** Every id, walking the 6a (first_seen,id) keyset cursor three rows at a time. */
	private function pages(): array {
		$ids   = array();
		$after = null;
		do {
			$window = $this->storage->errors->window( array( 'severity' => 'warning' ), null, null, $after, 3, $this->now );
			$ids    = array_merge( $ids, array_map( 'intval', array_column( $window['rows'], 'id' ) ) );
			$after  = $window['next'];
		} while ( null !== $after );
		return $ids;
	}

	private function workers( string $mode ): array {
		$children = array();
		$fixture  = dirname( __DIR__ ) . '/fixtures/storage-worker.php';
		for ( $i = 0; $i < 2; ++$i ) {
			$process = proc_open( array( PHP_BINARY, $fixture ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
			self::assertIsResource( $process );
			fwrite( $pipes[0], json_encode( array( 'user' => DB_USER, 'password' => DB_PASSWORD, 'name' => DB_NAME, 'host' => DB_HOST, 'prefix' => $this->prefix, 'mode' => $mode, 'now' => $this->now ) ) . "\n" );
			stream_set_timeout( $pipes[1], 30 );
			self::assertSame( "ready\n", fgets( $pipes[1] ) );
			$children[] = array( $process, $pipes );
		}
		foreach ( $children as [ $process, $pipes ] ) {
			fwrite( $pipes[0], "go\n" );
			fclose( $pipes[0] );
		}
		$results = array();
		foreach ( $children as [ $process, $pipes ] ) {
			$out       = trim( stream_get_contents( $pipes[1] ) );
			$results[] = ctype_digit( $out ) ? (int) $out : $out;
			$error     = stream_get_contents( $pipes[2] );
			fclose( $pipes[1] );
			fclose( $pipes[2] );
			self::assertSame( 0, proc_close( $process ), $error );
		}
		sort( $results );
		return $results;
	}
}
