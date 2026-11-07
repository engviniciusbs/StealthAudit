<?php
/**
 * Bounded maintenance primitives; scheduling and multi-batch scans come later.
 *
 * @package StealthAudit
 */

declare(strict_types=1);
namespace StealthAudit\Storage;

/** Reservations racing a reconciliation cause its CAS to fail conservatively. */
final class Maintenance {
	/**
	 * Construct.
	 *
	 * @param Database        $db SQL boundary.
	 * @param StateRepository $state Uncached budget state.
	 */
	public function __construct( private readonly Database $db, private readonly StateRepository $state ) {}

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
	 * Purge expired ephemeral state, never factors or budget rows.
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
		$deleted = $this->db->execute( 'DELETE FROM %i WHERE expires_at<=%s AND (state_key LIKE %s OR state_key LIKE %s OR state_key LIKE %s) ORDER BY expires_at,state_key LIMIT %d', $this->db->table( 'state' ), gmdate( 'Y-m-d H:i:s', $now ), 'proof:%', 'limit:%', 'lease:%', $limit );
		if ( $deleted > 0 ) {
			$payload         = $budget['payload'];
			$payload['rows'] = max( 3, $payload['rows'] - $deleted );
			$this->state->compare_and_swap( 'budget:state', $budget['revision'], $payload, null, $now );
		}
		return $deleted;
	}
}
