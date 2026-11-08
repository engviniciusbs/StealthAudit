<?php
/**
 * Leased, bounded maintenance: physical retention, expired state and counter reconciliation.
 *
 * @package StealthAudit
 */

declare(strict_types=1);
namespace StealthAudit\Storage;

/**
 * One worker at a time per lease; every step is a short statement or a CAS, so a lost lease,
 * a crash or a racing writer leaves conservative counters and a resumable cursor.
 */
final class Maintenance {
	public const BATCH         = 500;
	public const MAX_BATCHES   = 20;
	public const MAX_SECONDS   = 2.0;
	public const LEASE_SECONDS = 120;
	/** Pending reservations older than this belong to dead requests (see Budget buckets). */
	public const PENDING_STALE = Budget::PENDING_STALE;
	/** A recent pending at the final read is retried this many times within one run. */
	public const PENDING_RETRIES = 3;
	public const PENDING_WAIT_US = 25000;
	/** Settle time between reading the auxiliary budget and counting its rows. */
	public const STATE_SETTLE_US = 100000;
	/** Upper bound of logical bytes for one record (errors: 16+1024+255+120 = 1415). */
	public const RECORD_BYTES = array(
		'events' => 1024,
		'errors' => 1536,
	);
	/** Hourly WP-Cron depends on traffic; three missed hours is reported as overdue. */
	public const OVERDUE    = 10800;
	public const STATUS_KEY = 'maintenance:status';
	/** Physical retention matches the query retention in Records exactly. */
	public const RETENTION = array(
		'events' => array( 'occurred_at', 365 ),
		'errors' => array( 'first_seen', 30 ),
	);

	/**
	 * Construct.
	 *
	 * @param Database        $db SQL boundary.
	 * @param StateRepository $state Uncached budget state.
	 * @param int             $state_capacity Configured auxiliary row ceiling.
	 */
	public function __construct( private readonly Database $db, private readonly StateRepository $state, private readonly int $state_capacity = 10000 ) {}

	/**
	 * Run one bounded maintenance pass under the maintenance lease.
	 *
	 * Order: purge expired audit, purge expired diagnostics, purge expired auxiliary state,
	 * recount auxiliary rows, then resume the event/error recount cursor. Work stops at
	 * $max_batches statements of up to 500 rows or after $seconds, checked between batches.
	 *
	 * @param int   $now UTC timestamp.
	 * @param int   $max_batches Batches per run, 1..100.
	 * @param float $seconds Monotonic time budget per run, checked before each batch.
	 * @return array{result:string,batches:int,purged:array{events:int,errors:int,state:int}} Outcome.
	 * @throws StorageException On invalid bounds or failed database operation.
	 */
	public function run( int $now, int $max_batches = self::MAX_BATCHES, float $seconds = self::MAX_SECONDS ): array {
		if ( $now < 1 || $max_batches < 1 || $max_batches > 100 || $seconds <= 0 ) {
			throw new StorageException( 'Invalid maintenance bounds.' );
		}
		$outcome = array(
			'result'  => 'busy',
			'batches' => 0,
			'purged'  => array(
				'events' => 0,
				'errors' => 0,
				'state'  => 0,
			),
		);
		$owner   = $this->acquire( $now );
		if ( null === $owner ) {
			return $outcome;
		}
		$started = hrtime( true );
		$status  = $this->status( $now );
		$cursors = is_array( $status['cursor'] ?? null ) ? $status['cursor'] : array();
		$blocked = array();
		$waits   = 0;
		$done    = array();
		$room    = function ( int $batches ) use ( $max_batches, $seconds, $started, $owner, $now ): bool {
			return $batches < $max_batches && ( hrtime( true ) - $started ) / 1e9 < $seconds && $this->owns( $owner, $now );
		};
		try {
			foreach ( self::RETENTION as $kind => [ $column, $days ] ) {
				$done[ 'purge_' . $kind ] = false;
				while ( $room( $outcome['batches'] ) ) {
					++$outcome['batches'];
					// The (time,id) index is the resume point: each batch starts at its head.
					$deleted                     = $this->db->execute( 'DELETE FROM %i WHERE %i<=%s ORDER BY %i,id LIMIT %d', $this->db->table( $kind ), $column, gmdate( 'Y-m-d H:i:s', $now - $days * DAY_IN_SECONDS ), $column, self::BATCH );
					$outcome['purged'][ $kind ] += $deleted;
					if ( $deleted < self::BATCH ) {
						$done[ 'purge_' . $kind ] = true;
						break;
					}
				}
			}
			$done['purge_state'] = false;
			while ( $room( $outcome['batches'] ) ) {
				++$outcome['batches'];
				$deleted                     = $this->purge_expired_state( self::BATCH, $now );
				$outcome['purged']['state'] += $deleted;
				if ( $deleted < self::BATCH ) {
					$done['purge_state'] = true;
					break;
				}
			}
			$done['count_state'] = false;
			if ( $room( $outcome['batches'] ) ) {
				++$outcome['batches'];
				$done['count_state'] = $this->reconcile_state( $now );
			}
			foreach ( array( 'events', 'errors' ) as $kind ) {
				$done[ 'count_' . $kind ] = false;
				$cursor                   = self::cursor( $cursors[ $kind ] ?? null );
				while ( $room( $outcome['batches'] ) ) {
					++$outcome['batches'];
					$step = $this->recount( $kind, $cursor, $now );
					if ( 'blocked' === $step && $waits < self::PENDING_RETRIES ) {
						// A recent reservation is usually milliseconds from completing.
						++$waits;
						usleep( self::PENDING_WAIT_US );
						continue;
					}
					if ( 'blocked' === $step ) {
						// Not a backlog: the next run retries the final batch from this cursor.
						$blocked[]                = $kind;
						$done[ 'count_' . $kind ] = true;
						break;
					}
					if ( 'complete' === $step ) {
						$cursor                   = null;
						$done[ 'count_' . $kind ] = true;
						break;
					}
					if ( 'conflict' === $step ) {
						continue;
					}
					$cursor = $step;
				}
				$cursors[ $kind ] = $cursor;
			}
			$outcome['result'] = in_array( false, $done, true ) ? 'partial' : ( array() === $blocked ? 'complete' : 'blocked' );
		} catch ( \Throwable $failure ) {
			$outcome['result'] = 'failed';
		}
		$this->save_status(
			array(
				'ran_at'  => $now,
				'result'  => $outcome['result'],
				'backlog' => in_array( false, $done, true ),
				'blocked' => $blocked,
				'batches' => $outcome['batches'],
				'purged'  => $outcome['purged'],
				'cursor'  => $cursors,
			),
			$owner,
			$now
		);
		$this->release( $owner, $now );
		if ( 'failed' === $outcome['result'] ) {
			throw new StorageException( 'Maintenance failed.' );
		}
		return $outcome;
	}

	/**
	 * Closed summary for the authorized status API; never invents a number.
	 *
	 * @param int $now UTC timestamp.
	 * @return array{state:string,last_run:?int,dropped_before:array{events:?int,errors:?int}} Summary.
	 * @throws StorageException On database failure.
	 */
	public function report( int $now ): array {
		$dropped = array();
		foreach ( array( 'events', 'errors' ) as $kind ) {
			$row              = $this->state->get( 'budget:' . $kind, $now );
			$value            = null === $row ? null : ( $row['payload']['dropped_before'] ?? null );
			$dropped[ $kind ] = is_int( $value ) && $value >= 0 ? $value : null;
		}
		$status = $this->status( $now );
		if ( null === $status ) {
			// The install creates this row; without it, a maintained budget means it was lost.
			$state = in_array( null, $dropped, true ) ? 'never' : 'unavailable';
			$ran   = null;
		} elseif ( array( 'result' => 'never' ) === $status ) {
			$state = 'never';
			$ran   = null;
		} elseif ( ! is_int( $status['ran_at'] ?? null ) || ! is_string( $status['result'] ?? null ) || ! is_bool( $status['backlog'] ?? null ) ) {
			$state = 'unavailable';
			$ran   = null;
		} else {
			$ran   = $status['ran_at'];
			$state = match ( true ) {
				'failed' === $status['result'] => 'failed',
				$now - $ran > self::OVERDUE => 'overdue',
				'blocked' === $status['result'] => 'blocked',
				$status['backlog'] => 'backlog',
				default => 'current',
			};
		}
		return array(
			'state'          => $state,
			'last_run'       => $ran,
			'dropped_before' => $dropped,
		);
	}

	/**
	 * Reconcile a small table only; refuse a partial sum or a changed budget.
	 *
	 * @param string $kind Events or errors.
	 * @param int    $limit Maximum rows processed, 1..500.
	 * @param int    $now UTC timestamp.
	 * @return bool False leaves the conservative counter intact.
	 * @throws StorageException On invalid arguments or failed database operation.
	 */
	public function reconcile_records( string $kind, int $limit, int $now ): bool {
		if ( ! in_array( $kind, array( 'events', 'errors' ), true ) || $limit < 1 || $limit > 500 ) {
			throw new StorageException( 'Invalid reconciliation bounds.' );
		}
		$budget = $this->state->get( 'budget:' . $kind, $now );
		if ( null === $budget || ( $budget['payload']['pending'] ?? null ) !== 0 ) {
			return false;
		}
		$rows = $this->db->rows( 'SELECT logical_bytes FROM %i ORDER BY id LIMIT %d', $this->db->table( $kind ), $limit + 1 );
		if ( count( $rows ) > $limit ) {
			return false;
		}
		$payload          = $budget['payload'];
		$payload['rows']  = count( $rows );
		$payload['bytes'] = array_sum( array_map( static fn( array $row ): int => (int) $row['logical_bytes'], $rows ) );
		return $this->state->compare_and_swap( 'budget:' . $kind, $budget['revision'], $payload, null, $now );
	}

	/**
	 * Purge expired ephemeral state, never factors, budgets, maintenance status or its lease.
	 *
	 * Live proofs, limits and leases (expires_at in the future) are never selected.
	 *
	 * @param int $limit Maximum deleted rows, 1..500.
	 * @param int $now UTC timestamp.
	 * @return int Deleted rows. A lost counter CAS intentionally overcounts.
	 * @throws StorageException On invalid bounds or failed database operation.
	 */
	public function purge_expired_state( int $limit, int $now ): int {
		if ( $limit < 1 || $limit > 500 ) {
			throw new StorageException( 'Invalid cleanup bounds.' );
		}
		$budget = $this->state->get( 'budget:state', $now );
		if ( null === $budget || ! isset( $budget['payload']['rows'] ) || ! is_int( $budget['payload']['rows'] ) ) {
			throw new StorageException( 'Invalid auxiliary budget.' );
		}
		// The maintenance lease row is permanent so maintenance never needs free capacity.
		$deleted = $this->db->execute( 'DELETE FROM %i WHERE expires_at<=%s AND (state_key LIKE %s OR state_key LIKE %s OR state_key LIKE %s) AND state_key<>%s ORDER BY expires_at,state_key LIMIT %d', $this->db->table( 'state' ), gmdate( 'Y-m-d H:i:s', $now ), 'proof:%', 'limit:%', 'lease:%', self::lease_key(), $limit );
		if ( $deleted > 0 ) {
			$payload         = $budget['payload'];
			$payload['rows'] = max( 3, $payload['rows'] - $deleted );
			$this->state->compare_and_swap( 'budget:state', $budget['revision'], $payload, null, $now );
		}
		return $deleted;
	}

	/**
	 * Recount auxiliary rows in one statement bounded by the configured capacity.
	 *
	 * The create() method reserves a slot by CAS and inserts in its next statement, with no pending
	 * marker. The budget is read, then read again after STATE_SETTLE_US: a change means a
	 * create may still be inserting, so the recount waits for the next run. A create that
	 * reserved before the first read had the settle time to insert; one after the second
	 * read changes the revision and fails this CAS. The count therefore never drops below
	 * the rows in use, unless an INSERT stalls longer than the settle time (documented).
	 *
	 * @param int $now UTC timestamp.
	 * @return bool Whether the counter now matches the physical rows.
	 * @throws StorageException On failed database operation.
	 */
	private function reconcile_state( int $now ): bool {
		$first = $this->state->get( 'budget:state', $now );
		usleep( self::STATE_SETTLE_US );
		$budget = $this->state->get( 'budget:state', $now );
		if ( null === $first || null === $budget || ! is_int( $budget['payload']['rows'] ?? null ) ) {
			throw new StorageException( 'Invalid auxiliary budget.' );
		}
		if ( $first['revision'] !== $budget['revision'] ) {
			return false;
		}
		$rows  = $this->db->rows( 'SELECT COUNT(*) AS n FROM (SELECT 1 FROM %i LIMIT %d) AS bounded', $this->db->table( 'state' ), $this->state_capacity + 1 );
		$count = max( 3, (int) ( $rows[0]['n'] ?? 0 ) );
		if ( $count === $budget['payload']['rows'] ) {
			return true;
		}
		$payload         = $budget['payload'];
		$payload['rows'] = $count;
		return $this->state->compare_and_swap( 'budget:state', $budget['revision'], $payload, null, $now );
	}

	/**
	 * One recount batch by primary key; the batch that reaches the end publishes by CAS.
	 *
	 * A consistent read can pass an id whose INSERT commits later. Every such writer has
	 * reserved first and completes only after its INSERT, so for each non-final batch the
	 * budget is read before and after the scan: recent pending at the first read plus the
	 * rows/bytes reserved between the two reads bound what the scan may have missed. That
	 * margin (in-flight reservations at RECORD_BYTES each) is added at publish, so the
	 * published counter never falls below the rows physically in use. The final batch
	 * reads the budget first: a reservation after that read changes the revision and the
	 * publish fails (a new batch); a recent pending at that read blocks the publish.
	 *
	 * Pending reservations age per minute (Budget pending_by_minute): only buckets younger
	 * than PENDING_STALE block; older ones belong to dead requests and are cleared, however
	 * busy the site is. Publishing moves dropped to dropped_before and stamps maintained_at.
	 *
	 * @param string                                                                 $kind Events or errors.
	 * @param array{id:int,rows:int,bytes:int,margin_rows:int,margin_bytes:int}|null $cursor Persisted partial sum.
	 * @param int                                                                    $now UTC timestamp.
	 * @return array{id:int,rows:int,bytes:int,margin_rows:int,margin_bytes:int}|string Next cursor, or complete|blocked|conflict.
	 * @throws StorageException On malformed budget or failed database operation.
	 */
	private function recount( string $kind, ?array $cursor, int $now ): array|string {
		$budget   = $this->budget( $kind, $now );
		$cursor ??= array(
			'id'           => 0,
			'rows'         => 0,
			'bytes'        => 0,
			'margin_rows'  => 0,
			'margin_bytes' => 0,
		);
		$batch    = $this->db->rows( 'SELECT id,logical_bytes FROM %i WHERE id>%d ORDER BY id LIMIT %d', $this->db->table( $kind ), $cursor['id'], self::BATCH );
		$next     = array(
			'id'           => count( $batch ) > 0 ? (int) $batch[ count( $batch ) - 1 ]['id'] : $cursor['id'],
			'rows'         => $cursor['rows'] + count( $batch ),
			'bytes'        => $cursor['bytes'] + array_sum( array_map( static fn( array $row ): int => (int) $row['logical_bytes'], $batch ) ),
			'margin_rows'  => $cursor['margin_rows'],
			'margin_bytes' => $cursor['margin_bytes'],
		);
		$recent   = Budget::recent_pending( $budget['payload'], $now );
		if ( count( $batch ) === self::BATCH ) {
			$after                 = $this->budget( $kind, $now );
			$next['margin_rows']  += $recent + max( 0, $after['payload']['rows'] - $budget['payload']['rows'] );
			$next['margin_bytes'] += $recent * self::RECORD_BYTES[ $kind ] + max( 0, $after['payload']['bytes'] - $budget['payload']['bytes'] );
			return $next;
		}
		$payload = $budget['payload'];
		// Budgets written before the per-minute buckets existed fall back to "no reservation
		// at all for PENDING_STALE seconds".
		$legacy = ! array_key_exists( 'pending_by_minute', $payload ) && $payload['pending'] > 0 && $now - $payload['second'] < self::PENDING_STALE;
		if ( $recent > 0 || $legacy ) {
			return 'blocked';
		}
		if ( $payload['pending'] > 0 ) {
			$payload['pending']       = 0;
			$payload['pending_reset'] = $now;
		}
		$payload['pending_by_minute'] = array();
		$payload['rows']              = $next['rows'] + $next['margin_rows'];
		$payload['bytes']             = $next['bytes'] + $next['margin_bytes'];
		$payload['dropped_before']    = $payload['dropped'];
		$payload['dropped']           = 0;
		$payload['maintained_at']     = $now;
		if ( $this->state->compare_and_swap( 'budget:' . $kind, $budget['revision'], $payload, null, $now ) ) {
			return 'complete';
		}
		return 'conflict';
	}

	/**
	 * Read and validate an event/error budget row.
	 *
	 * @param string $kind Events or errors.
	 * @param int    $now UTC timestamp.
	 * @return array{revision:int,payload:array<string,mixed>,expires_at:?string} Row.
	 * @throws StorageException On a missing or malformed budget.
	 */
	private function budget( string $kind, int $now ): array {
		$budget = $this->state->get( 'budget:' . $kind, $now );
		if ( null === $budget ) {
			throw new StorageException( 'Invalid capacity state.' );
		}
		foreach ( array( 'rows', 'bytes', 'second', 'dropped', 'pending' ) as $field ) {
			if ( ! is_int( $budget['payload'][ $field ] ?? null ) || $budget['payload'][ $field ] < 0 ) {
				throw new StorageException( 'Invalid capacity state.' );
			}
		}
		return $budget;
	}

	/**
	 * Validate a stored cursor; anything malformed restarts the recount from the start.
	 *
	 * @param mixed $value Stored cursor.
	 * @return array{id:int,rows:int,bytes:int,margin_rows:int,margin_bytes:int}|null Cursor.
	 */
	private static function cursor( mixed $value ): ?array {
		if ( ! is_array( $value ) ) {
			return null;
		}
		$out = array();
		foreach ( array( 'id', 'rows', 'bytes', 'margin_rows', 'margin_bytes' ) as $field ) {
			$item = $value[ $field ] ?? ( str_starts_with( $field, 'margin' ) ? 0 : null );
			if ( ! is_int( $item ) || $item < 0 ) {
				return null;
			}
			$out[ $field ] = $item;
		}
		return array(
			'id'           => $out['id'],
			'rows'         => $out['rows'],
			'bytes'        => $out['bytes'],
			'margin_rows'  => $out['margin_rows'],
			'margin_bytes' => $out['margin_bytes'],
		);
	}

	/** Lease identity; a digest like the other lease keys. */
	public static function lease_key(): string {
		return 'lease:' . hash( 'sha256', 'stealthaudit-maintenance' );
	}

	/**
	 * Take the lease if absent, expired or released (empty owner); null when another runs.
	 *
	 * @param int $now UTC timestamp.
	 * @throws StorageException On failed database operation.
	 */
	private function acquire( int $now ): ?string {
		$owner   = bin2hex( random_bytes( 16 ) );
		$payload = array( 'owner' => $owner );
		$expires = $now + self::LEASE_SECONDS;
		$row     = $this->state->get( self::lease_key(), $now );
		if ( null === $row ) {
			$taken = $this->state->replace_expired( self::lease_key(), $payload, $expires, $now ) || $this->state->create( self::lease_key(), $payload, $expires );
		} else {
			$taken = '' === ( $row['payload']['owner'] ?? null ) && $this->state->compare_and_swap( self::lease_key(), $row['revision'], $payload, $expires, $now );
		}
		return $taken ? $owner : null;
	}

	/**
	 * Whether this worker still holds a live lease; checked before every batch.
	 *
	 * @param string $owner Token.
	 * @param int    $now UTC timestamp.
	 */
	private function owns( string $owner, int $now ): bool {
		try {
			$row = $this->state->get( self::lease_key(), $now );
		} catch ( \Throwable $failure ) {
			return false;
		}
		return null !== $row && ( $row['payload']['owner'] ?? null ) === $owner;
	}

	/**
	 * Release by clearing the owner, keeping the row (and its capacity slot) for reuse.
	 *
	 * @param string $owner Token.
	 * @param int    $now UTC timestamp.
	 */
	private function release( string $owner, int $now ): void {
		try {
			$row = $this->state->get( self::lease_key(), $now );
			if ( null !== $row && ( $row['payload']['owner'] ?? null ) === $owner ) {
				$this->state->compare_and_swap( self::lease_key(), $row['revision'], array( 'owner' => '' ), $now + self::LEASE_SECONDS, $now );
			}
		} catch ( \Throwable $failure ) {
			// An unreleased lease simply expires.
			return;
		}
	}

	/**
	 * Stored status payload, or null when maintenance never ran.
	 *
	 * @param int $now UTC timestamp.
	 * @return array<string,mixed>|null Payload.
	 * @throws StorageException On failed database operation.
	 */
	private function status( int $now ): ?array {
		$row = $this->state->get( self::STATUS_KEY, $now );
		return null === $row ? null : $row['payload'];
	}

	/**
	 * Persist status by CAS (or create) while still owning the lease.
	 *
	 * A worker that lost its lease must not overwrite the new owner's status and cursor.
	 * A failure keeps the previous status visible.
	 *
	 * @param array<string,mixed> $payload Status.
	 * @param string              $owner Lease token.
	 * @param int                 $now UTC timestamp.
	 */
	private function save_status( array $payload, string $owner, int $now ): void {
		if ( ! $this->owns( $owner, $now ) ) {
			return;
		}
		try {
			$row = $this->state->get( self::STATUS_KEY, $now );
			if ( null === $row ) {
				$this->state->create( self::STATUS_KEY, $payload, null );
			} else {
				$this->state->compare_and_swap( self::STATUS_KEY, $row['revision'], $payload, null, $now );
			}
		} catch ( \Throwable $failure ) {
			return;
		}
	}
}
