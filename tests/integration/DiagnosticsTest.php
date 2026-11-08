<?php
// SPDX-License-Identifier: GPL-3.0-or-later
declare(strict_types=1);

use StealthAudit\Config\Configuration;
use StealthAudit\Diagnostics\Collector;
use StealthAudit\Diagnostics\Normalizer;
use StealthAudit\Storage\Database;
use StealthAudit\Storage\Storage;

/** Isolated InnoDB tables; destructive fatal cases always run in children. */
final class DiagnosticsTest extends PHPUnit\Framework\TestCase {
	private wpdb $connection;
	private Database $db;
	private Storage $storage;
	private string $prefix;

	protected function setUp(): void {
		stealthaudit_tests_commit_global_connection();
		$this->connection = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$this->prefix = 'sa_diag_' . bin2hex( random_bytes( 4 ) ) . '_';
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

	public function test_collection_flush_redaction_types_and_aggregation(): void {
		$collector = new Collector( new Normalizer( '/srv/site/' ), fn () => $this->storage->errors, 10 );
		$mask = error_reporting( E_ALL );
		try {
			foreach ( array( E_USER_WARNING, E_USER_NOTICE, E_USER_DEPRECATED ) as $severity ) {
				self::assertFalse( $collector->handle( $severity, 'token=synthetic-secret at /srv/private/file.php', '/srv/site/wp-content/plugins/sample.php', 17 ) );
			}
			self::assertSame( 3, $collector->health()['buffered'] );
			$collector->flush();
		} finally {
			error_reporting( $mask );
		}
		$rows = $this->storage->errors->page( array(), 100, 0, time() );
		self::assertCount( 3, $rows );
		self::assertSame( array( 'deprecated', 'notice', 'warning' ), array_column( $rows, 'severity' ) );
		self::assertStringNotContainsString( 'synthetic-secret', json_encode( $rows ) );
		self::assertStringNotContainsString( '/srv/', json_encode( $rows ) );
		self::assertSame( 'wp-content/plugins/sample.php', $rows[0]['file'] );
		self::assertSame( 'available', $collector->health()['status'] );
	}

	public function test_fixed_window_counts_retention_and_conservative_budget(): void {
		$now = intdiv( time(), 300 ) * 300 + 10;
		$record = array( 'severity' => 'warning', 'message' => 'synthetic repeated', 'file' => 'plugin.php', 'line' => 2 );
		foreach ( array( $now + 2, $now + 5, $now, $now + 300 ) as $time ) {
			self::assertTrue( $this->storage->errors->append( $record, $time ) );
		}
		$rows = $this->storage->errors->page( array(), 100, 0, $now + 300 );
		self::assertCount( 2, $rows );
		self::assertSame( '3', $rows[1]['occurrences'] );
		self::assertSame( gmdate( 'Y-m-d H:i:s', $now ), $rows[1]['first_seen'] );
		self::assertSame( gmdate( 'Y-m-d H:i:s', $now + 5 ), $rows[1]['last_seen'] );
		self::assertCount( 1, $this->storage->errors->page( array(), 100, 0, $now + 30 * DAY_IN_SECONDS ) );
		$budget = $this->storage->state->get( 'budget:errors', $now );
		self::assertSame( 4, $budget['payload']['rows'] );
		self::assertSame( 0, $budget['payload']['pending'] );
		self::assertTrue( $this->storage->maintenance->reconcile_records( 'errors', 100, $now + 300 ) );
		self::assertSame( 2, $this->storage->state->get( 'budget:errors', $now + 300 )['payload']['rows'] );
	}

	public function test_repeated_errors_cannot_bypass_capacity_or_rate(): void {
		$now = time();
		$record = array( 'severity' => 'warning', 'message' => 'synthetic same error' );
		$limited = $this->fresh( array( 'error_rows' => 2, 'errors_per_second' => 1 ) );
		self::assertTrue( $limited->errors->append( $record, $now ) );
		self::assertFalse( $limited->errors->append( $record, $now ) );
		self::assertSame( 'limited', $limited->errors->status() );
		self::assertTrue( $limited->errors->append( $record, $now + 1 ) );
		self::assertFalse( $limited->errors->append( $record, $now + 2 ) );
		self::assertSame( 0, $this->storage->state->get( 'budget:errors', $now )['payload']['pending'] );
	}

	public function test_storage_unavailable_is_distinct_from_limited(): void {
		$this->db->execute( 'DROP TABLE %i', $this->db->table( 'errors' ) );
		$collector = new Collector( new Normalizer( ABSPATH ), fn () => $this->storage->errors );
		$collector->flush();
		$collector->handle( E_USER_WARNING, 'synthetic', __FILE__, __LINE__ );
		self::assertSame( 'storage_unavailable', $collector->health()['status'] );
		self::assertSame( 0, $this->storage->state->get( 'budget:errors', time() )['payload']['pending'] );
	}

	public function test_limited_occurrence_remains_visible_after_a_successful_write(): void {
		$limited = $this->fresh( array( 'error_bytes' => 1 ) );
		$use_limited = true;
		$collector = new Collector( new Normalizer( ABSPATH ), function () use ( &$use_limited, $limited ) {
			return $use_limited ? $limited->errors : $this->storage->errors;
		} );
		$collector->flush();
		$collector->handle( E_USER_WARNING, 'synthetic limited', __FILE__, __LINE__ );
		self::assertSame( 'limited', $collector->health()['status'] );
		self::assertSame( 1, $collector->health()['dropped'] );
		$use_limited = false;
		$collector->handle( E_USER_WARNING, 'synthetic success', __FILE__, __LINE__ );
		self::assertSame( 'limited', $collector->health()['status'] );
		self::assertSame( 1, $collector->health()['dropped'] );
		self::assertCount( 1, $this->storage->errors->page( array(), 100, 0, time() ) );
	}

	public function test_real_fatal_in_child_preserves_core_handler_and_is_persisted(): void {
		$fixture = dirname( __DIR__ ) . '/fixtures/diagnostics-worker.php';
		$command = escapeshellarg( PHP_BINARY ) . ' -d display_errors=0 -d log_errors=0 ' . escapeshellarg( $fixture ) . ' ' . escapeshellarg( $this->prefix ) . ' 2>&1';
		exec( $command, $output, $exit );
		$text = implode( "\n", $output );
		self::assertNotSame( 0, $exit, $text );
		self::assertStringContainsString( '@@DIAGNOSTICS@@', $text );
		$rows = $this->storage->errors->page( array(), 100, 0, time() );
		self::assertCount( 1, $rows );
		self::assertSame( 'fatal', $rows[0]['severity'] );
		self::assertSame( '1', $rows[0]['occurrences'] );
		self::assertStringNotContainsString( '/var/www/', $rows[0]['message'] );
	}
}
