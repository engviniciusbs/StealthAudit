<?php
/**
 * Atomic conservative reservations, rate limits and per-request work bounds.
 *
 * @package StealthAudit
 */

declare(strict_types=1);
namespace StealthAudit\Storage;

use StealthAudit\Config\Configuration;

/** Shared request scope must be reused by collectors. */
final class Budget {
	/**
	 * Attempt counts in this request.
	 *
	 * @var array<string,int>
	 */
	private array $attempts = array(
		'events' => 0,
		'errors' => 0,
	);
	/**
	 * Whether the circuit breaker observed storage failure.
	 *
	 * @var bool
	 */
	private bool $failed = false;
	/**
	 * Cumulative collector work, excluding time spent in other plugins.
	 *
	 * @var int
	 */
	private int $spent = 0;

	/**
	 * Construct.
	 *
	 * @param StateRepository $state State CAS.
	 * @param Configuration   $config Limits.
	 */
	public function __construct( private readonly StateRepository $state, private readonly Configuration $config ) {
	}

	/** Start a collector operation, or refuse after the work budget. */
	public function begin(): ?int {
		return $this->failed || $this->spent / 1000000 >= $this->config->limits['storage_work_ms'] ? null : hrtime( true );
	}

	/** Whether this request has observed unavailable or malformed storage state. */
	public function storage_failed(): bool {
		return $this->failed;
	}

	/**
	 * Charge synchronous work after returning; this cannot interrupt SQL in flight.
	 *
	 * @param int $started Monotonic start time.
	 */
	public function finish( int $started ): void {
		$this->spent += hrtime( true ) - $started;
	}

	// phpcs:disable Squiz.Commenting.FunctionCommentThrowTag.Missing -- Corrupt-state exceptions are caught inside reserve().
	/**
	 * Reserve before inserting; insertion failures intentionally never refund.
	 *
	 * @param string $kind Events or errors.
	 * @param int    $bytes Logical encoded bytes.
	 * @param int    $now UTC Unix time.
	 */
	public function reserve( string $kind, int $bytes, int $now ): bool {
		if ( ! isset( $this->attempts[ $kind ] ) || $bytes < 1 || $this->failed ) {
			return false;
		}
		$singular = 'events' === $kind ? 'event' : 'error';
		if ( ++$this->attempts[ $kind ] > $this->config->limits[ $kind . '_per_request' ] ) {
			return false;
		}
		try {
			for ( $attempt = 0; $attempt < 3; ++$attempt ) {
				$row = $this->state->get( 'budget:' . $kind, $now );
				if ( null === $row ) {
					$this->failed = true;
					return false;
				}
				$next = $row['payload'];
				foreach ( array( 'rows', 'bytes', 'second', 'rate', 'dropped', 'pending' ) as $field ) {
					if ( ! isset( $next[ $field ] ) || ! is_int( $next[ $field ] ) || $next[ $field ] < 0 ) {
						throw new StorageException( 'Invalid capacity state.' );
					}
				}
				if ( $now > $next['second'] ) {
					$next['second'] = $now;
					$next['rate']   = 0;
				}
				$allowed = $next['rows'] < $this->config->limits[ $singular . '_rows' ] && $bytes <= $this->config->limits[ $singular . '_bytes' ] - $next['bytes'] && $next['rate'] < $this->config->limits[ $kind . '_per_second' ];
				if ( $allowed ) {
					++$next['rows'];
					$next['bytes'] += $bytes;
					++$next['rate'];
					++$next['pending'];
				} else {
					$next['dropped'] = min( PHP_INT_MAX - 1, $next['dropped'] + 1 );
				}
				if ( $this->state->compare_and_swap( 'budget:' . $kind, $row['revision'], $next, null, $now ) ) {
					return $allowed;
				}
			}
		} catch ( \Throwable $failure ) {
			$this->failed = true;
		}
		return false;
	}

	// phpcs:enable Squiz.Commenting.FunctionCommentThrowTag.Missing
	/**
	 * Finish exactly one reserved operation after its insert returns.
	 *
	 * @param string $kind Reserved record table kind.
	 * @param int    $now UTC timestamp.
	 */
	public function complete( string $kind, int $now ): void {
		try {
			for ( $attempt = 0; $attempt < 3; ++$attempt ) {
				$row = $this->state->get( 'budget:' . $kind, $now );
				if ( null === $row || ! isset( $row['payload']['pending'] ) || ! is_int( $row['payload']['pending'] ) || $row['payload']['pending'] < 1 ) {
					$this->failed = true;
					return;
				}
				$payload = $row['payload'];
				--$payload['pending'];
				if ( $this->state->compare_and_swap( 'budget:' . $kind, $row['revision'], $payload, null, $now ) ) {
					return;
				}
			}
		} catch ( \Throwable $failure ) {
			$this->failed = true;
		}
		$this->failed = true;
	}
}
