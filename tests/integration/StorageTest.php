<?php
// SPDX-License-Identifier: GPL-3.0-or-later
declare(strict_types=1);

use StealthAudit\Config\Configuration;
use StealthAudit\Storage\Database;
use StealthAudit\Storage\Schema;
use StealthAudit\Storage\Storage;
use StealthAudit\Storage\StateRepository;
use StealthAudit\Storage\StorageException;

/** Real InnoDB with isolated tables and connection, outside WP's test transaction. */
final class StorageTest extends PHPUnit\Framework\TestCase {
	private wpdb $connection;
	private Database $db;
	private Storage $storage;
	private string $prefix;

	protected function setUp(): void {
		stealthaudit_tests_commit_global_connection();
		$this->connection = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$this->prefix = 'sa_it_' . bin2hex( random_bytes( 4 ) ) . '_';
		$this->connection->set_prefix( $this->prefix );
		$this->db = new Database( $this->connection );
		global $wpdb;
		$this->db->execute( 'CREATE TABLE %i LIKE %i', $this->connection->options, $wpdb->options );
		$this->storage = $this->fresh();
		self::assertTrue( $this->storage->schema->ensure() );
	}

	protected function tearDown(): void {
		foreach ( array( 'events', 'errors', 'state' ) as $kind ) {
			$this->db->execute( 'DROP TABLE IF EXISTS %i', $this->db->table( $kind ) );
		}
		$this->db->execute( 'DROP TABLE IF EXISTS %i', $this->connection->options );
		$this->connection->close();
	}

	private function fresh( array $limits = array() ): Storage {
		return new Storage( $this->connection, Configuration::from_array( array( 'limits' => $limits + array( 'storage_work_ms' => 10000 ) ) ) );
	}

	private function event(): array {
		return array( 'category' => 'content', 'action' => 'created', 'result' => 'success', 'origin' => 'cli', 'object_type' => 'post', 'object_id' => 42 );
	}

	public function test_schema_prefix_engine_indexes_collation_and_idempotence(): void {
		$this->storage->schema->verify();
		self::assertTrue( $this->storage->events->append( $this->event(), time() ) );
		self::assertTrue( $this->storage->schema->ensure() );
		self::assertCount( 1, $this->storage->events->page( array(), 100, 0, time() ) );
		foreach ( array( 'events', 'errors', 'state' ) as $kind ) {
			$row = $this->db->rows( 'SELECT ENGINE,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $this->db->table( $kind ) )[0];
			self::assertSame( 'InnoDB', $row['ENGINE'] );
			self::assertSame( $this->connection->collate, $row['TABLE_COLLATION'] );
		}
	}

	public function test_schema_installs_through_its_own_connection_not_the_global_one(): void {
		// Regression (found on MySQL 8.4): dbDelta() ran DESCRIBE/CREATE through the global $wpdb.
		// When that session was inside a transaction (as WordPress test cases leave it), it kept
		// metadata locks on the new tables and a DROP from the schema's own connection waited
		// forever on MySQL. The install must not touch any other session.
		global $wpdb;
		$storage = $this->fresh();
		$this->db->execute( 'DELETE FROM %i WHERE option_name=%s', $this->connection->options, 'stealthaudit_schema_version' );
		foreach ( array( 'events', 'errors', 'state' ) as $kind ) {
			$this->db->execute( 'DROP TABLE %i', $this->db->table( $kind ) );
		}
		$global  = $wpdb;
		$queries = $wpdb->num_queries;
		self::assertTrue( $storage->schema->ensure() );
		self::assertSame( $global, $wpdb, 'The global connection of the caller is restored.' );
		self::assertSame( $queries, $wpdb->num_queries, 'No schema query ran through the global connection.' );
		$storage->schema->verify();
	}

	public function test_state_cas_conflict_generation_atomicity_and_expiry(): void {
		$now = time();
		$state = $this->storage->state;
		self::assertTrue( $state->create( 'factor:7', array( 'generation' => 1, 'ciphertext' => 'synthetic-envelope', 'recovery_hashes' => array( 'synthetic-hash' ) ), null ) );
		$original = $state->get( 'factor:7', $now );
		self::assertGreaterThan( 0, $original['revision'] );
		self::assertTrue( $state->compare_and_swap( 'factor:7', $original['revision'], array( 'generation' => 2, 'ciphertext' => 'replacement-envelope', 'recovery_hashes' => array() ), null, $now ) );
		self::assertFalse( $state->compare_and_swap( 'factor:7', $original['revision'], array( 'generation' => 1 ), null, $now ) );
		$updated = $state->get( 'factor:7', $now );
		self::assertSame( 2, $updated['payload']['generation'] );
		self::assertSame( array(), $updated['payload']['recovery_hashes'] );
		$key = 'proof:' . hash( 'sha256', 'synthetic-session' );
		self::assertTrue( $state->create( $key, array( 'generation' => 1 ), $now + 1 ) );
		$proof_revision = $state->get( $key, $now )['revision'];
		self::assertNull( $state->get( $key, $now + 1 ) );
		self::assertFalse( $state->compare_and_swap( $key, $proof_revision, array( 'generation' => 2 ), $now + 100, $now + 1 ) );
		$this->db->execute( 'DELETE FROM %i WHERE state_key=%s', $this->db->table( 'state' ), $key );
		self::assertTrue( $state->create( $key, array( 'generation' => 3 ), $now + 100 ) );
		self::assertNotSame( $proof_revision, $state->get( $key, $now )['revision'] );
		self::assertFalse( $state->compare_and_swap( $key, $proof_revision, array( 'generation' => 2 ), $now + 100, $now ) );
	}

	public function test_capacity_bytes_rate_request_and_auxiliary_limits(): void {
		$now = time();
		$limited = $this->fresh( array( 'event_rows' => 2, 'events_per_second' => 1 ) );
		self::assertTrue( $limited->events->append( $this->event(), $now ) );
		self::assertFalse( $limited->events->append( $this->event(), $now ) );
		self::assertTrue( $limited->events->append( $this->event(), $now + 1 ) );
		self::assertFalse( $limited->events->append( $this->event(), $now + 2 ) );
		$budget = $this->storage->state->get( 'budget:events', $now );
		self::assertSame( 2, $budget['payload']['rows'] );
		self::assertSame( 2, $budget['payload']['dropped'] );
		self::assertFalse( $this->fresh( array( 'error_bytes' => 1 ) )->errors->append( array( 'severity' => 'warning', 'message' => 'synthetic diagnostic' ), $now ) );
		$one = $this->fresh( array( 'errors_per_request' => 1 ) );
		self::assertTrue( $one->errors->append( array( 'severity' => 'warning', 'message' => 'synthetic diagnostic' ), $now ) );
		self::assertFalse( $one->errors->append( array( 'severity' => 'warning', 'message' => 'synthetic diagnostic' ), $now ) );
		// Five fixed rows: three budgets plus the maintenance lease and status (schema install).
		$state = new StateRepository( $this->db, 6 );
		self::assertTrue( $state->create( 'factor:1', array( 'generation' => 1 ), null ) );
		self::assertFalse( $state->create( 'factor:2', array( 'generation' => 1 ), null ) );
	}

	public function test_records_are_structured_utc_canonical_and_keyset_paginated(): void {
		$now = 1800000000;
		$event = $this->event() + array( 'ip' => '2001:0db8:0:0:0:0:0:1', 'actor_id' => 999999 );
		self::assertFalse( $this->storage->events->append( $event + array( 'password' => 'synthetic-forbidden' ), $now ) );
		self::assertFalse( $this->storage->events->append( $event + array( 'summary' => array( 'content' => 'synthetic-forbidden' ) ), $now ) );
		self::assertTrue( $this->storage->events->append( $event, $now ) );
		self::assertTrue( $this->storage->events->append( $event, $now ) );
		$first = $this->storage->events->page( array( 'actor_id' => 999999 ), 1, 0, $now );
		self::assertSame( '2001:db8::1', $first[0]['ip'] );
		self::assertSame( gmdate( 'Y-m-d H:i:s', $now ), $first[0]['occurred_at'] );
		$second = $this->storage->events->page( array(), 1, (int) $first[0]['id'], $now );
		self::assertCount( 1, $second );
		self::assertNotSame( $first[0]['id'], $second[0]['id'] );
		self::assertSame( array(), $this->storage->events->page( array(), 100, 0, $now + 365 * DAY_IN_SECONDS ) );
		$this->expectException( StorageException::class );
		$this->storage->events->page( array( 'id OR 1=1' => 'x' ), 101, 0, $now );
	}

	public function test_errors_reject_paths_and_redact_common_secret_shapes(): void {
		$now = time();
		self::assertFalse( $this->storage->errors->append( array( 'severity' => 'fatal', 'message' => 'synthetic', 'file' => '/srv/private/config.php' ), $now ) );
		self::assertTrue( $this->storage->errors->append( array( 'severity' => 'warning', 'message' => 'password=synthetic-only', 'file' => 'wp-content/plugins/sample/file.php', 'line' => 7 ), $now ) );
		$row = $this->storage->errors->page( array( 'severity' => 'warning' ), 100, 0, $now )[0];
		self::assertSame( '[redacted diagnostic]', $row['message'] );
		self::assertSame( 64, strlen( $row['fingerprint'] ) );
		self::assertSame( '1', $row['occurrences'] );
		self::assertSame( array(), $this->storage->errors->page( array(), 100, 0, $now + 30 * DAY_IN_SECONDS ) );
	}

	public function test_late_worker_cannot_reset_a_newer_rate_bucket(): void {
		$now = time();
		$limited = $this->fresh( array( 'events_per_second' => 1 ) );
		self::assertTrue( $limited->events->append( $this->event(), $now + 1 ) );
		self::assertFalse( $limited->events->append( $this->event(), $now ) );
		self::assertFalse( $limited->events->append( $this->event(), $now + 1 ) );
		self::assertSame( $now + 1, $this->storage->state->get( 'budget:events', $now )['payload']['second'] );
	}

	public function test_idle_before_collection_does_not_consume_storage_work_budget(): void {
		$limited = $this->fresh( array( 'storage_work_ms' => 1 ) );
		usleep( 10000 );
		self::assertTrue( $limited->events->append( $this->event(), time() ) );
		// Real SQL above takes >1ms here, but avoid hardware-dependent timing assertions.
		$state = $this->storage->state;
		$budget = new StealthAudit\Storage\Budget( $state, Configuration::from_array( array( 'limits' => array( 'storage_work_ms' => 1 ) ) ) );
		$start = $budget->begin();
		usleep( 2000 );
		$budget->finish( $start );
		self::assertNull( $budget->begin() );
	}

	public function test_verification_rejects_wrong_type_index_order_and_nullable_key(): void {
		$this->db->execute( 'ALTER TABLE %i MODIFY revision int unsigned NOT NULL', $this->db->table( 'state' ) );
		try {
			$this->storage->schema->verify();
			self::fail( 'Wrong state revision type accepted.' );
		} catch ( StorageException $failure ) {
			self::assertSame( 'Incompatible storage columns.', $failure->getMessage() );
		}
		$this->db->execute( 'ALTER TABLE %i MODIFY revision bigint unsigned NOT NULL', $this->db->table( 'state' ) );
		$this->db->execute( 'ALTER TABLE %i DROP INDEX category_time, ADD INDEX category_time (occurred_at,category,id)', $this->db->table( 'events' ) );
		$this->expectException( StorageException::class );
		$this->storage->schema->verify();
	}

	public function test_failed_insert_overcounts_and_state_failure_throws(): void {
		$now = time();
		$this->db->execute( 'DROP TABLE %i', $this->db->table( 'events' ) );
		self::assertFalse( $this->storage->events->append( $this->event(), $now ) );
		self::assertSame( 1, $this->storage->state->get( 'budget:events', $now )['payload']['rows'] );
		$this->db->execute( 'DROP TABLE %i', $this->db->table( 'state' ) );
		ob_start();
		try {
			$this->storage->state->get( 'factor:1', $now );
			self::fail( 'Authoritative state failure must throw.' );
		} catch ( StorageException $failure ) {
			self::assertSame( 'Storage unavailable.', $failure->getMessage() );
		} finally {
			self::assertSame( '', ob_get_clean() );
		}
	}

	public function test_migration_lease_busy_expired_and_partial_failure(): void {
		$this->db->execute( 'DELETE FROM %i WHERE option_name=%s', $this->connection->options, 'stealthaudit_schema_version' );
		$this->db->execute( 'INSERT INTO %i (option_name,option_value,autoload) VALUES (%s,%s,%s)', $this->connection->options, 'stealthaudit_schema_lease', ( time() + 300 ) . ':competitor', 'off' );
		self::assertFalse( $this->storage->schema->ensure() );
		$this->db->execute( 'UPDATE %i SET option_value=%s WHERE option_name=%s', $this->connection->options, '1:abandoned', 'stealthaudit_schema_lease' );
		self::assertTrue( $this->storage->schema->ensure() );
		$this->db->execute( 'DELETE FROM %i WHERE option_name=%s', $this->connection->options, 'stealthaudit_schema_version' );
		$this->db->execute( 'ALTER TABLE %i ENGINE=MyISAM', $this->db->table( 'events' ) );
		self::assertFalse( $this->storage->schema->ensure() );
		self::assertFalse( $this->storage->schema->current() );
	}

	public function test_real_two_process_cas_and_capacity_reservations(): void {
		self::assertTrue( $this->storage->state->create( 'factor:4', array( 'generation' => 1 ), null ) );
		$revision = $this->storage->state->get( 'factor:4', time() )['revision'];
		self::assertSame( array( 0, 1 ), $this->workers( 'cas' ) );
		self::assertSame( $revision + 1, $this->storage->state->get( 'factor:4', time() )['revision'] );
		self::assertSame( array( 0, 1 ), $this->workers( 'capacity' ) );
		self::assertCount( 1, $this->storage->events->page( array(), 100, 0, time() ) );
		self::assertSame( 1, $this->storage->state->get( 'budget:events', time() )['payload']['rows'] );
	}

	public function test_bounded_idempotent_reconciliation_and_state_expiry_cleanup(): void {
		$now = time();
		self::assertTrue( $this->storage->events->append( $this->event(), $now ) );
		$budget = $this->storage->state->get( 'budget:events', $now );
		$overcounted = $budget['payload'];
		$overcounted['rows'] = 100;
		$overcounted['bytes'] += 1000;
		self::assertTrue( $this->storage->state->compare_and_swap( 'budget:events', $budget['revision'], $overcounted, null, $now ) );
		self::assertTrue( $this->storage->maintenance->reconcile_records( 'events', 500, $now ) );
		self::assertSame( $budget['payload'], $this->storage->state->get( 'budget:events', $now )['payload'] );
		self::assertTrue( $this->storage->maintenance->reconcile_records( 'events', 500, $now ) );
		self::assertTrue( $this->storage->events->append( $this->event(), $now ) );
		self::assertFalse( $this->storage->maintenance->reconcile_records( 'events', 1, $now ) );
		$key = 'proof:' . hash( 'sha256', 'synthetic-expiring' );
		self::assertTrue( $this->storage->state->create( $key, array( 'generation' => 1 ), $now + 1 ) );
		self::assertTrue( $this->storage->state->create( 'factor:88', array( 'generation' => 1 ), null ) );
		self::assertSame( 1, $this->storage->maintenance->purge_expired_state( 1, $now + 1 ) );
		self::assertSame( 0, $this->storage->maintenance->purge_expired_state( 1, $now + 1 ) );
		self::assertNotNull( $this->storage->state->get( 'factor:88', $now + 1 ) );
	}

	public function test_reconciliation_refuses_inflight_reservation_until_settled(): void {
		$now = time();
		$budget = new StealthAudit\Storage\Budget( $this->storage->state, Configuration::from_array( array() ) );
		self::assertTrue( $budget->reserve( 'events', 123, $now ) );
		self::assertFalse( $this->storage->maintenance->reconcile_records( 'events', 500, $now ) );
		self::assertSame( 1, $this->storage->state->get( 'budget:events', $now )['payload']['rows'] );
		self::assertSame( 1, $this->storage->state->get( 'budget:events', $now )['payload']['pending'] );
		// Simulate a failed insertion returning: capacity stays reserved until maintenance.
		$budget->complete( 'events', $now );
		self::assertSame( 0, $this->storage->state->get( 'budget:events', $now )['payload']['pending'] );
		self::assertTrue( $this->storage->maintenance->reconcile_records( 'events', 500, $now ) );
		self::assertSame( 0, $this->storage->state->get( 'budget:events', $now )['payload']['rows'] );
		// Unsettled crash markers are deliberately never expired by ordinary cleanup.
		self::assertTrue( $budget->reserve( 'events', 100, $now ) );
		self::assertSame( 0, $this->storage->maintenance->purge_expired_state( 500, $now + 86400 ) );
		self::assertFalse( $this->storage->maintenance->reconcile_records( 'events', 500, $now + 86400 ) );
	}

	public function test_verification_rejects_unique_secondary_fingerprint(): void {
		$this->db->execute( 'ALTER TABLE %i DROP INDEX fingerprint, ADD UNIQUE INDEX fingerprint (fingerprint)', $this->db->table( 'errors' ) );
		$this->expectException( StorageException::class );
		$this->storage->schema->verify();
	}

	private function workers( string $mode ): array {
		$children = array();
		$fixture = dirname( __DIR__ ) . '/fixtures/storage-worker.php';
		for ( $i = 0; $i < 2; ++$i ) {
			$process = proc_open( array( PHP_BINARY, $fixture ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
			self::assertIsResource( $process );
			fwrite( $pipes[0], json_encode( array( 'user' => DB_USER, 'password' => DB_PASSWORD, 'name' => DB_NAME, 'host' => DB_HOST, 'prefix' => $this->prefix, 'mode' => $mode ) ) . "\n" );
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
			$results[] = (int) trim( stream_get_contents( $pipes[1] ) );
			$error = stream_get_contents( $pipes[2] );
			fclose( $pipes[1] );
			fclose( $pipes[2] );
			self::assertSame( 0, proc_close( $process ), $error );
		}
		sort( $results );
		return $results;
	}
}
