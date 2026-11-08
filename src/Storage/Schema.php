<?php
/**
 * Versioned InnoDB schema with a recoverable installer lease.
 *
 * @package StealthAudit
 */

declare(strict_types=1);
namespace StealthAudit\Storage;

/**
 * No activation hook and no DDL in record-writing methods.
 *
 * Version 2 adds first-occurrence indexes to the errors table for the 30-day query window
 * and the ordered physical purge. Upgrading from 1 reruns dbDelta under the same lease.
 */
final class Schema {
	public const VERSION      = '2';
	private const VERSION_KEY = 'stealthaudit_schema_version';
	private const LEASE_KEY   = 'stealthaudit_schema_lease';

	/**
	 * Construct.
	 *
	 * @param Database $db SQL boundary.
	 */
	public function __construct( private readonly Database $db ) {}

	/**
	 * Install only when needed; a competitor or failure returns false.
	 *
	 * @return bool Whether this version is installed.
	 */
	public function ensure(): bool {
		try {
			if ( $this->current() ) {
				return true;
			}
			$token   = bin2hex( random_bytes( 16 ) );
			$options = $this->db->connection->options;
			$lease   = ( time() + 120 ) . ':' . $token;
			$this->db->execute( 'INSERT IGNORE INTO %i (option_name,option_value,autoload) VALUES (%s,%s,%s)', $options, self::LEASE_KEY, $lease, 'off' );
			$this->db->execute( 'UPDATE %i SET option_value=%s WHERE option_name=%s AND CAST(SUBSTRING_INDEX(option_value,\':\',1) AS UNSIGNED)<%d', $options, $lease, self::LEASE_KEY, time() );
			$rows = $this->db->rows( 'SELECT option_value FROM %i WHERE option_name=%s', $options, self::LEASE_KEY );
			if ( ( $rows[0]['option_value'] ?? '' ) !== $lease ) {
				return false;
			}
			try {
				if ( $this->current() ) {
					return true;
				}
				$this->install();
				$this->verify();
				// Only a still-owned lease may publish the completed version.
				$published = $this->db->execute( 'INSERT INTO %i (option_name,option_value,autoload) SELECT %s,%s,%s FROM %i WHERE option_name=%s AND option_value=%s AND CAST(SUBSTRING_INDEX(option_value,\':\',1) AS UNSIGNED)>=%d ON DUPLICATE KEY UPDATE option_value=VALUES(option_value)', $options, self::VERSION_KEY, self::VERSION, 'off', $options, self::LEASE_KEY, $lease, time() );
				return $published > 0 && $this->current();
			} finally {
				$this->db->execute( 'DELETE FROM %i WHERE option_name=%s AND option_value=%s', $options, self::LEASE_KEY, $lease );
			}
		} catch ( \Throwable $failure ) {
			return false;
		}
	}

	/**
	 * Read the authoritative version without the options cache.
	 *
	 * @phpstan-impure
	 */
	public function current(): bool {
		$rows = $this->db->rows( 'SELECT option_value FROM %i WHERE option_name=%s', $this->db->connection->options, self::VERSION_KEY );
		return ( $rows[0]['option_value'] ?? null ) === self::VERSION;
	}

	/** Create/upgrade tables; errors are verified independently of dbDelta output. */
	private function install(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset  = $this->db->connection->get_charset_collate();
		$events   = $this->db->table( 'events' );
		$errors   = $this->db->table( 'errors' );
		$state    = $this->db->table( 'state' );
		$previous = $this->db->connection->suppress_errors( true );
		try {
			dbDelta(
				"CREATE TABLE $events (
id bigint unsigned NOT NULL AUTO_INCREMENT,
record_version smallint unsigned NOT NULL,
occurred_at datetime NOT NULL,
actor_id bigint unsigned DEFAULT NULL,
category varchar(16) NOT NULL,
action varchar(48) NOT NULL,
result varchar(16) NOT NULL,
origin varchar(16) NOT NULL,
object_type varchar(24) NOT NULL,
object_id bigint unsigned DEFAULT NULL,
ip varchar(45) DEFAULT NULL,
summary varchar(1024) NOT NULL,
logical_bytes int unsigned NOT NULL,
PRIMARY KEY  (id),
KEY occurred (occurred_at,id),
KEY category_time (category,occurred_at,id),
KEY actor_time (actor_id,occurred_at,id),
KEY ip_time (ip,occurred_at,id)
) ENGINE=InnoDB $charset;"
			);
			dbDelta(
				"CREATE TABLE $errors (
id bigint unsigned NOT NULL AUTO_INCREMENT,
record_version smallint unsigned NOT NULL,
first_seen datetime NOT NULL,
last_seen datetime NOT NULL,
severity varchar(16) NOT NULL,
message varchar(1024) NOT NULL,
file varchar(255) NOT NULL,
line int unsigned NOT NULL,
fingerprint char(64) NOT NULL,
occurrences bigint unsigned NOT NULL,
logical_bytes int unsigned NOT NULL,
PRIMARY KEY  (id),
KEY seen (last_seen,id),
KEY severity_time (severity,last_seen,id),
KEY fingerprint (fingerprint),
KEY first_time (first_seen,id),
KEY severity_first (severity,first_seen,id)
) ENGINE=InnoDB $charset;"
			);
			dbDelta(
				"CREATE TABLE $state (
state_key varchar(96) NOT NULL,
revision bigint unsigned NOT NULL,
payload text NOT NULL,
expires_at datetime DEFAULT NULL,
PRIMARY KEY  (state_key),
KEY expires (expires_at,state_key)
) ENGINE=InnoDB $charset;"
			);
		} finally {
			$this->db->connection->suppress_errors( $previous );
		}
		foreach ( array( 'events', 'errors', 'state' ) as $kind ) {
			$payload = 'state' === $kind ? '{"rows":3}' : '{"rows":0,"bytes":0,"second":0,"rate":0,"dropped":0,"pending":0}';
			$this->db->execute( 'INSERT IGNORE INTO %i (state_key,revision,payload,expires_at) VALUES (%s,1,%s,NULL)', $state, 'budget:' . $kind, $payload );
		}
	}

	/**
	 * Verify engine, required columns and indexes after DDL.
	 *
	 * @throws StorageException On partial/incompatible installation.
	 */
	public function verify(): void {
		$required = array(
			'events' => array(
				array(
					'id'             => 'bigint unsigned',
					'record_version' => 'smallint unsigned',
					'occurred_at'    => 'datetime',
					'actor_id'       => 'bigint unsigned',
					'category'       => 'varchar(16)',
					'action'         => 'varchar(48)',
					'result'         => 'varchar(16)',
					'origin'         => 'varchar(16)',
					'object_type'    => 'varchar(24)',
					'object_id'      => 'bigint unsigned',
					'ip'             => 'varchar(45)',
					'summary'        => 'varchar(1024)',
					'logical_bytes'  => 'int unsigned',
				),
				array(
					'PRIMARY'       => array( 'id' ),
					'occurred'      => array( 'occurred_at', 'id' ),
					'category_time' => array( 'category', 'occurred_at', 'id' ),
					'actor_time'    => array( 'actor_id', 'occurred_at', 'id' ),
					'ip_time'       => array( 'ip', 'occurred_at', 'id' ),
				),
			),
			'errors' => array(
				array(
					'id'             => 'bigint unsigned',
					'record_version' => 'smallint unsigned',
					'first_seen'     => 'datetime',
					'last_seen'      => 'datetime',
					'severity'       => 'varchar(16)',
					'message'        => 'varchar(1024)',
					'file'           => 'varchar(255)',
					'line'           => 'int unsigned',
					'fingerprint'    => 'char(64)',
					'occurrences'    => 'bigint unsigned',
					'logical_bytes'  => 'int unsigned',
				),
				array(
					'PRIMARY'        => array( 'id' ),
					'seen'           => array( 'last_seen', 'id' ),
					'severity_time'  => array( 'severity', 'last_seen', 'id' ),
					'fingerprint'    => array( 'fingerprint' ),
					'first_time'     => array( 'first_seen', 'id' ),
					'severity_first' => array( 'severity', 'first_seen', 'id' ),
				),
			),
			'state'  => array(
				array(
					'state_key'  => 'varchar(96)',
					'revision'   => 'bigint unsigned',
					'payload'    => 'text',
					'expires_at' => 'datetime',
				),
				array(
					'PRIMARY' => array( 'state_key' ),
					'expires' => array( 'expires_at', 'state_key' ),
				),
			),
		);
		foreach ( $required as $kind => [ $columns, $indexes ] ) {
			$table          = $this->db->table( $kind );
			$status         = $this->db->rows( 'SELECT ENGINE,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table );
			$actual_columns = $this->db->rows( 'SHOW COLUMNS FROM %i', $table );
			$actual_indexes = $this->db->rows( 'SHOW INDEX FROM %i', $table );
			if ( 'InnoDB' !== ( $status[0]['ENGINE'] ?? '' ) || $this->db->connection->collate !== ( $status[0]['TABLE_COLLATION'] ?? '' ) ) {
				throw new StorageException( 'Incompatible storage schema.' );
			}
			$types = array();
			foreach ( $actual_columns as $column ) {
				$types[ $column['Field'] ] = preg_replace( '/\b(bigint|smallint|int)\([0-9]+\)/', '$1', strtolower( $column['Type'] ) );
				$nullable                  = in_array( $column['Field'], array( 'actor_id', 'object_id', 'ip', 'expires_at' ), true );
				if ( ( $nullable ? 'YES' : 'NO' ) !== $column['Null'] || ( 'id' === $column['Field'] && ! str_contains( $column['Extra'], 'auto_increment' ) ) ) {
					throw new StorageException( 'Incompatible storage column constraints.' );
				}
			}
			foreach ( $columns as $name => $type ) {
				if ( ( $types[ $name ] ?? '' ) !== $type ) {
					throw new StorageException( 'Incompatible storage columns.' );
				}
			}
			$index_columns = array();
			foreach ( $actual_indexes as $index ) {
				if ( null !== $index['Sub_part'] ) {
					throw new StorageException( 'Incompatible storage index prefix.' );
				}
				$index_columns[ $index['Key_name'] ][ (int) $index['Seq_in_index'] ] = $index['Column_name'];
				if ( isset( $indexes[ $index['Key_name'] ] ) && ( 'PRIMARY' === $index['Key_name'] ? 0 : 1 ) !== (int) $index['Non_unique'] ) {
					throw new StorageException( 'Incompatible storage primary key.' );
				}
			}
			foreach ( $indexes as $name => $fields ) {
				$actual = $index_columns[ $name ] ?? array();
				ksort( $actual );
				if ( array_values( $actual ) !== $fields ) {
					throw new StorageException( 'Incompatible storage indexes.' );
				}
			}
		}
	}
}
