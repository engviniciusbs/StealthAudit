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
	 * Read a newest-first (time,id) keyset window with period bounds and retention.
	 *
	 * Fetches one extra row to emit a cursor; never runs a full count.
	 *
	 * @param string                   $kind Fixed table kind.
	 * @param array<string,int|string> $filters Allowed equality filters.
	 * @param list<string>             $allowed Allowed filter columns.
	 * @param int|null                 $from Inclusive UTC lower bound.
	 * @param int|null                 $to Inclusive UTC upper bound.
	 * @param array{0:int,1:int}|null  $after Exclusive (time,id) cursor.
	 * @param int                      $limit Page size 1..100.
	 * @param int                      $now UTC Unix timestamp.
	 * @return array{rows:list<array<string,mixed>>,next:array{0:int,1:int}|null} Window.
	 * @throws StorageException On invalid query or database failure.
	 */
	protected function read_window( string $kind, array $filters, array $allowed, ?int $from, ?int $to, ?array $after, int $limit, int $now ): array {
		$max = 253402300799;
		if ( $limit < 1 || $limit > 100 || array_diff( array_keys( $filters ), $allowed ) || ( null !== $from && ( $from < 0 || $from > $max ) ) || ( null !== $to && ( $to < 0 || $to > $max ) ) || ( null !== $after && ( $after[0] < 0 || $after[0] > $max || $after[1] < 1 ) ) ) {
			throw new StorageException( 'Invalid record query.' );
		}
		$time = 'events' === $kind ? 'occurred_at' : 'first_seen';
		$days = 'events' === $kind ? 365 : 30;
		// Retention is enforced here even before physical cleanup removes old rows.
		$sql  = 'SELECT * FROM %i WHERE %i>%s';
		$args = array( $this->db->table( $kind ), $time, gmdate( 'Y-m-d H:i:s', $now - $days * DAY_IN_SECONDS ) );
		if ( null !== $from ) {
			$sql .= ' AND %i>=%s';
			array_push( $args, $time, gmdate( 'Y-m-d H:i:s', $from ) );
		}
		if ( null !== $to ) {
			$sql .= ' AND %i<=%s';
			array_push( $args, $time, gmdate( 'Y-m-d H:i:s', $to ) );
		}
		foreach ( $filters as $field => $value ) {
			if ( is_string( $value ) && strlen( $value ) > 64 ) {
				throw new StorageException( 'Invalid record filter.' );
			}
			$sql .= ' AND %i=%s';
			array_push( $args, $field, $value );
		}
		if ( null !== $after ) {
			$cursor = gmdate( 'Y-m-d H:i:s', $after[0] );
			$sql   .= ' AND (%i<%s OR (%i=%s AND id<%d))';
			array_push( $args, $time, $cursor, $time, $cursor, $after[1] );
		}
		$sql .= ' ORDER BY %i DESC,id DESC LIMIT %d';
		array_push( $args, $time, $limit + 1 );
		$rows = $this->db->rows( $sql, ...$args );
		$next = null;
		if ( count( $rows ) > $limit ) {
			$rows  = array_slice( $rows, 0, $limit );
			$last  = $rows[ $limit - 1 ];
			$stamp = strtotime( (string) $last[ $time ] . ' UTC' );
			if ( false === $stamp ) {
				throw new StorageException( 'Invalid stored time.' );
			}
			$next = array( $stamp, (int) $last['id'] );
		}
		return array(
			'rows' => $rows,
			'next' => $next,
		);
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
