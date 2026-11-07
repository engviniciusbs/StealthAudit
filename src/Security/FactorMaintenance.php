<?php
/**
 * Offline operator factor reset and rotation primitives, never HTTP recovery.
 *
 * @package StealthAudit
 */

declare(strict_types=1);
namespace StealthAudit\Security;

use StealthAudit\Config\Configuration;
use StealthAudit\Storage\StateRepository;

/** Operator has already paused panel authorization in the external configuration. */
final class FactorMaintenance {
	/**
	 * Construct offline-only maintenance boundary.
	 *
	 * @param Configuration   $configuration Current live external settings, empty allowlist.
	 * @param StateRepository $state Authoritative state.
	 * @param SecretCipher    $cipher Current external key.
	 * @throws \RuntimeException Unless CLI and panel is disabled.
	 */
	public function __construct( private readonly Configuration $configuration, private readonly StateRepository $state, private readonly SecretCipher $cipher ) {
		if ( 'cli' !== PHP_SAPI || array() !== $configuration->authorized_user_ids || '' === $configuration->installation_id || ! $configuration->has_key() ) {
			throw new \RuntimeException( 'Offline panel maintenance required.' );
		}
	}

	/**
	 * Replace factor and recovery set atomically; require password and fresh enrollment.
	 *
	 * @param int $user WordPress user ID.
	 * @param int $now UTC clock.
	 * @throws \RuntimeException On unavailable state or CAS failure.
	 */
	public function reset( int $user, int $now ): void {
		$row = $this->state->get( 'factor:' . $user, $now );
		if ( null === $row ) {
			throw new \RuntimeException( 'Factor reset unavailable.' );
		}
		$next = array(
			'generation'  => bin2hex( random_bytes( 32 ) ),
			'active'      => false,
			'established' => false,
			'secret'      => $this->cipher->encrypt( ( new TotpProvider() )->create_secret(), $user ),
			'until'       => 0,
			'owner'       => str_repeat( '0', 64 ),
			'last_step'   => -1,
			'recovery'    => array(),
		);
		if ( ! $this->state->compare_and_swap( 'factor:' . $user, $row['revision'], $next, null, $now ) ) {
			throw new \RuntimeException( 'Factor reset failed.' );
		}
		\WP_Session_Tokens::get_instance( $user )->destroy_all();
	}

	/**
	 * Rotate a single bounded factor row, verifying new envelope before atomic update.
	 *
	 * @param int          $user User ID.
	 * @param SecretCipher $replacement New external key with the same installation ID.
	 * @param int          $now Clock.
	 * @return bool True for migrated, false for already migrated.
	 * @throws \RuntimeException On decrypt, verification or CAS failure.
	 */
	public function rotate( int $user, SecretCipher $replacement, int $now ): bool {
		$row = $this->state->get( 'factor:' . $user, $now );
		if ( null === $row || ! is_string( $row['payload']['secret'] ?? null ) ) {
			throw new \RuntimeException( 'Factor rotation unavailable.' );
		}
		$secret = $this->cipher->decrypt( $row['payload']['secret'], $user );
		if ( null === $secret ) {
			if ( null !== $replacement->decrypt( $row['payload']['secret'], $user ) ) {
				return false;
			}
			throw new \RuntimeException( 'Factor rotation unavailable.' );
		}
		$next           = $row['payload'];
		$next['secret'] = $replacement->encrypt( $secret, $user );
		if ( ! hash_equals( $secret, $replacement->decrypt( $next['secret'], $user ) ?? '' ) ) {
			throw new \RuntimeException( 'Factor rotation verification failed.' );
		}
		$next['generation'] = bin2hex( random_bytes( 32 ) );
		if ( ! $this->state->compare_and_swap( 'factor:' . $user, $row['revision'], $next, null, $now ) ) {
			throw new \RuntimeException( 'Factor rotation failed.' );
		}
		return true;
	}
}
