<?php
/**
 * Bounded private-table reads and strict structured field validation.
 *
 * @package StealthAudit
 */

declare(strict_types=1);
namespace StealthAudit\Storage;

/** Storage only: callers must authorize before reading. */
abstract class Records {
	/**
	 * Construct.
	 *
	 * @param Database $db SQL boundary.
	 * @param Budget   $budget Shared request budget.
	 */
	public function __construct( protected readonly Database $db, protected readonly Budget $budget ) {}

	/**
	 * Read a bounded newest-first keyset page with logical retention applied.
	 *
	 * @param string                   $kind Fixed table kind.
	 * @param array<string,int|string> $filters Allowed equality filters.
	 * @param list<string>             $allowed Allowed filter columns.
	 * @param int                      $limit Page size 1..100.
	 * @param int                      $before_id Optional exclusive id cursor.
	 * @param int                      $now UTC Unix timestamp.
	 * @return list<array<string,mixed>> Rows; database failures throw.
	 * @throws StorageException On invalid query.
	 */
	protected function read_page( string $kind, array $filters, array $allowed, int $limit, int $before_id, int $now ): array {
		if ( $limit < 1 || $limit > 100 || $before_id < 0 || array_diff( array_keys( $filters ), $allowed ) ) {
			throw new StorageException( 'Invalid record query.' );
		}
		// id order gives stable cursors even when multiple records share a second.
		$time = 'events' === $kind ? 'occurred_at' : 'first_seen';
		$days = 'events' === $kind ? 365 : 30;
		$sql  = 'SELECT * FROM %i WHERE %i>%s';
		$args = array( $this->db->table( $kind ), $time, gmdate( 'Y-m-d H:i:s', $now - $days * DAY_IN_SECONDS ) );
		foreach ( $filters as $field => $value ) {
			if ( is_string( $value ) && strlen( $value ) > 64 ) {
				throw new StorageException( 'Invalid record filter.' );
			}
			$sql .= ' AND %i=%s';
			array_push( $args, $field, $value );
		}
		if ( $before_id > 0 ) {
			$sql   .= ' AND id<%d';
			$args[] = $before_id;
		}
		$sql   .= ' ORDER BY id DESC LIMIT %d';
		$args[] = $limit;
		return $this->db->rows( $sql, ...$args );
	}

	/**
	 * Check a closed set of event/error codes.
	 *
	 * @param mixed        $value Candidate.
	 * @param list<string> $allowed Allowed values.
	 * @throws StorageException On unknown code.
	 */
	protected static function code( mixed $value, array $allowed ): string {
		if ( ! is_string( $value ) || ! in_array( $value, $allowed, true ) ) {
			throw new StorageException( 'Invalid record code.' );
		}
		return $value;
	}

	/**
	 * Require positive numeric identities without retaining user input.
	 *
	 * @param mixed $value Candidate.
	 * @throws StorageException On invalid identity.
	 */
	protected static function identity( mixed $value ): int {
		if ( ! is_int( $value ) || $value < 1 ) {
			throw new StorageException( 'Invalid record identity.' );
		}
		return $value;
	}
}
