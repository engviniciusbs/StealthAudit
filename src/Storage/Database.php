<?php
/**
 * Narrow SQL boundary for uncached, conditional persistence.
 *
 * @package StealthAudit
 */

declare(strict_types=1);
namespace StealthAudit\Storage;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Custom tables require direct, uncached SQL; security state must never use cache.

/** Suppresses database output and preserves the caller's error setting. */
final class Database {

	/**
	 * Construct with the current WordPress connection.
	 *
	 * @param \wpdb $connection Connection.
	 */
	public function __construct( public readonly \wpdb $connection ) {}

	/**
	 * Resolve one of the three fixed table names.
	 *
	 * @param string $kind Table suffix.
	 * @throws StorageException On unknown table.
	 */
	public function table( string $kind ): string {
		if ( ! in_array( $kind, array( 'events', 'errors', 'state' ), true ) ) {
			throw new StorageException( 'Invalid storage table.' );
		}
		return $this->connection->prefix . 'stealthaudit_' . $kind;
	}

	/**
	 * Execute prepared SQL without leaking database errors.
	 *
	 * @param string     $sql SQL using placeholders.
	 * @param int|string ...$args Bound values or identifiers.
	 * @return int Affected rows.
	 * @throws StorageException When SQL fails.
	 */
	public function execute( string $sql, int|string ...$args ): int {
		$previous = $this->connection->suppress_errors( true );
		try {
			$result = $this->connection->query( $this->connection->prepare( $sql, ...$args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL template and values are prepared at this boundary.
			if ( false === $result ) {
				throw new StorageException( 'Storage unavailable.' );
			}
			return (int) $result;
		} finally {
			$this->connection->suppress_errors( $previous );
		}
	}

	/**
	 * Query fresh rows; errors differ from an empty result.
	 *
	 * @param string     $sql SQL using placeholders.
	 * @param int|string ...$args Bound values or identifiers.
	 * @return list<array<string,mixed>> Rows.
	 * @throws StorageException When SQL fails.
	 */
	public function rows( string $sql, int|string ...$args ): array {
		$previous = $this->connection->suppress_errors( true );
		try {
			$rows = $this->connection->get_results( $this->connection->prepare( $sql, ...$args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared at this boundary.
			if ( '' !== $this->connection->last_error || ! is_array( $rows ) ) {
				throw new StorageException( 'Storage unavailable.' );
			}
			return $rows;
		} finally {
			$this->connection->suppress_errors( $previous );
		}
	}
}
