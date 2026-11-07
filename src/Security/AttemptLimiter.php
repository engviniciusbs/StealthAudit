<?php
/**
 * Persistent fixed-window attempt limits, denying on unresolved contention.
 *
 * @package StealthAudit
 */

declare(strict_types=1);
namespace StealthAudit\Security;

use StealthAudit\Storage\StateRepository;

/** Account and trusted-origin counters share no client-controlled bucket names. */
final class AttemptLimiter {
	/**
	 * Construct.
	 *
	 * @param StateRepository $state Authoritative CAS store.
	 */
	public function __construct( private readonly StateRepository $state ) {}

	/**
	 * Reserve both attempts before expensive credential checks. Never refund failures.
	 *
	 * @param int         $user Authorized user ID.
	 * @param string|null $ip Canonical trusted origin, null groups unknown peers.
	 * @param int         $now UTC clock.
	 * @throws \RuntimeException When attempts/backend are unavailable.
	 */
	public function consume( int $user, ?string $ip, int $now ): void {
		foreach ( array(
			'account:' . $user               => 10,
			'origin:' . ( $ip ?? 'unknown' ) => 100,
		) as $identity => $maximum ) {
			$key      = 'limit:' . hash( 'sha256', 'mfa:' . $identity );
			$accepted = false;
			for ( $attempt = 0; $attempt < 3; ++$attempt ) {
				$row = $this->state->get( $key, $now );
				if ( null === $row ) {
					$payload  = array( 'count' => 1 );
					$accepted = $this->state->replace_expired( $key, $payload, $now + 300, $now ) || $this->state->create( $key, $payload, $now + 300 );
				} else {
					$count = $row['payload']['count'] ?? null;
					if ( ! is_int( $count ) || $count < 1 || $count >= $maximum || ! is_string( $row['expires_at'] ) ) {
						break;
					}
					$expires = strtotime( $row['expires_at'] . ' UTC' );
					if ( false === $expires ) {
						break;
					}
					$accepted = $this->state->compare_and_swap( $key, $row['revision'], array( 'count' => $count + 1 ), $expires, $now );
				}
				if ( $accepted ) {
					break;
				}
			}
			if ( ! $accepted ) {
				throw new \RuntimeException( 'Panel authentication unavailable.' );
			}
		}
	}
}
