<?php
/**
 * Panel-only MFA state machine; no login hooks or HTTP data endpoints.
 *
 * @package StealthAudit
 */

declare(strict_types=1);
namespace StealthAudit\Security;

use StealthAudit\Audit\Context;
use StealthAudit\Audit\Recorder;
use StealthAudit\Config\Configuration;
use StealthAudit\Storage\StateRepository;

/** Server authority for enrollment, challenge, restricted recovery and data access. */
final class PanelAccess {
	/**
	 * Construct dependencies without any login side effects.
	 *
	 * @param Configuration    $configuration External operator policy.
	 * @param StateRepository  $state Authoritative database state.
	 * @param SecretCipher     $cipher Installation-bound encryption.
	 * @param TotpProvider     $totp Library adapter.
	 * @param WordPressSession $sessions Signed-cookie validator.
	 * @param Context          $context Trusted IP resolver.
	 * @param \Closure():int   $clock UTC clock for security TTLs.
	 * @param Recorder|null    $audit Best-effort safe result events.
	 */
	public function __construct(
		private readonly Configuration $configuration,
		private readonly StateRepository $state,
		private readonly SecretCipher $cipher,
		private readonly TotpProvider $totp,
		private readonly WordPressSession $sessions,
		private readonly Context $context,
		private readonly \Closure $clock,
		private readonly ?Recorder $audit = null,
	) {}

	/** Read-only polling never refreshes activity. */
	public function status(): string {
		try {
			$identity = $this->identity();
			$now      = ( $this->clock )();
			$factor   = $this->factor( $identity['user'], $now );
			if ( null === $factor ) {
				return $this->password_fresh( $identity, $now ) ? 'enrollment_required' : 'password_required';
			}
			if ( ! $factor['payload']['active'] ) {
				if ( $factor['payload']['established'] && $factor['payload']['until'] <= $now ) {
					return 'recovery_locked';
				}
				if ( $factor['payload']['until'] > $now ) {
					return hash_equals( $factor['payload']['owner'], $identity['session'] ) ? 'enrollment_pending' : 'password_required';
				}
				// Expired first enrollment or offline reset: only fresh password reopens setup.
				return $this->password_fresh( $identity, $now ) ? 'enrollment_required' : 'password_required';
			}
			return $this->proof_valid( $identity, $factor['payload'], $now, false ) ? 'authorized' : 'challenge_required';
		} catch ( \Throwable $failure ) {
			return 'denied';
		}
	}

	/**
	 * Data gate. Call for each query, after transport nonce/CSRF checks.
	 *
	 * @param bool $activity True only for deliberate protected data requests.
	 */
	public function authorized( bool $activity = true ): bool {
		try {
			$identity = $this->identity();
			$now      = ( $this->clock )();
			$factor   = $this->factor( $identity['user'], $now );
			return null !== $factor && $factor['payload']['active'] && $this->proof_valid( $identity, $factor['payload'], $now, $activity );
		} catch ( \Throwable $failure ) {
			return false;
		}
	}

	/**
	 * Reconfirm existing WordPress password for five minutes, never a data grant.
	 *
	 * @param string $password Current account password.
	 * @param string $nonce Panel CSRF nonce.
	 */
	public function reconfirm( #[\SensitiveParameter] string $password, #[\SensitiveParameter] string $nonce ): void {
		$this->mutate(
			'mfa_password',
			$nonce,
			function ( array $identity, int $now ) use ( $password ): void {
				$this->check_password( $identity['user'], $password );
				$factor = $this->factor( $identity['user'], $now );
				$this->save_proof(
					$identity,
					array(
						'generation'       => $factor['payload']['generation'] ?? str_repeat( '0', 64 ),
						'password_at'      => $now,
						'authenticated_at' => 0,
						'last_activity'    => 0,
					),
					$now + 600,
					$now
				);
			}
		);
	}

	/**
	 * Issue local QR for a short-lived pending factor after password reconfirmation.
	 *
	 * @param string $nonce Panel CSRF nonce.
	 * @return array{qr:string} Sensitive in-memory SVG, never cache or log.
	 */
	public function begin_enrollment( #[\SensitiveParameter] string $nonce ): array {
		return $this->mutate(
			'mfa_enrollment',
			$nonce,
			function ( array $identity, int $now ): array {
				if ( ! $this->password_fresh( $identity, $now ) ) {
					throw new \RuntimeException( 'Password required.' );
				}
				$factor = $this->factor( $identity['user'], $now );
				if ( null !== $factor && $factor['payload']['active'] ) {
					throw new \RuntimeException( 'Existing factor required.' );
				}
				if ( null !== $factor && $factor['payload']['established'] && $factor['payload']['until'] <= $now ) {
					throw new \RuntimeException( 'Offline recovery required.' );
				}
				if ( null !== $factor && $factor['payload']['until'] > $now ) {
					if ( ! hash_equals( $factor['payload']['owner'], $identity['session'] ) ) {
						throw new \RuntimeException( 'Enrollment already pending.' );
					}
					return $this->qr( $factor['payload'], $identity['user'] );
				}
				return $this->new_pending( $identity, $factor, $now );
			}
		);
	}

	/**
	 * Activate only after TOTP consumption CAS; recovery codes are returned once.
	 *
	 * @param string $code Six-digit authenticator code.
	 * @param string $nonce Panel CSRF nonce.
	 * @return list<string> New high-entropy recovery codes.
	 */
	public function confirm_enrollment( #[\SensitiveParameter] string $code, #[\SensitiveParameter] string $nonce ): array {
		return $this->mutate(
			'mfa_enrolled',
			$nonce,
			function ( array $identity, int $now ) use ( $code ): array {
				$row = $this->factor( $identity['user'], $now );
				if ( null === $row || $row['payload']['active'] || $row['payload']['until'] <= $now || ! hash_equals( $row['payload']['owner'], $identity['session'] ) ) {
					throw new \RuntimeException( 'Enrollment unavailable.' );
				}
				$next              = $row['payload'];
				$next['last_step'] = $this->step( $next, $identity['user'], $code, $now );
				$codes             = array();
				$next['recovery']  = array();
				for ( $i = 0; $i < 10; ++$i ) {
					$value              = bin2hex( random_bytes( 16 ) );
					$codes[]            = $value;
					$next['recovery'][] = hash( 'sha256', $value );
				}
				$next['active']      = true;
				$next['established'] = true;
				$next['until']       = 0;
				$this->swap_factor( $identity['user'], $row['revision'], $next, $now );
				$this->grant( $identity, $next['generation'], $now );
				return $codes;
			}
		);
	}

	/**
	 * Consume a never-before-used TOTP step and establish this session's proof.
	 *
	 * @param string $code Authenticator code.
	 * @param string $nonce Panel CSRF nonce.
	 */
	public function authenticate( #[\SensitiveParameter] string $code, #[\SensitiveParameter] string $nonce ): void {
		$this->mutate(
			'mfa_access',
			$nonce,
			function ( array $identity, int $now ) use ( $code ): void {
				$row               = $this->active_factor( $identity['user'], $now );
				$next              = $row['payload'];
				$next['last_step'] = $this->step( $next, $identity['user'], $code, $now );
				$this->swap_factor( $identity['user'], $row['revision'], $next, $now );
				$this->grant( $identity, $next['generation'], $now );
			}
		);
	}

	/**
	 * Password plus recovery grants only replacement enrollment, never data access.
	 *
	 * @param string $password Current password.
	 * @param string $code One-use recovery code.
	 * @param string $nonce Panel CSRF nonce.
	 * @return array{qr:string} Replacement setup QR.
	 */
	public function recover( #[\SensitiveParameter] string $password, #[\SensitiveParameter] string $code, #[\SensitiveParameter] string $nonce ): array {
		return $this->mutate(
			'mfa_recovery',
			$nonce,
			function ( array $identity, int $now ) use ( $password, $code ): array {
				$this->check_password( $identity['user'], $password );
				$row = $this->active_factor( $identity['user'], $now );
				if ( ! preg_match( '/^[a-f0-9]{32}$/D', $code ) || ! in_array( hash( 'sha256', $code ), $row['payload']['recovery'], true ) ) {
					throw new \RuntimeException( 'Recovery denied.' );
				}
				return $this->new_pending( $identity, $row, $now );
			}
		);
	}

	/**
	 * Replace active factor only with password AND current authenticator.
	 *
	 * @param string $password WordPress password.
	 * @param string $code Current authenticator code.
	 * @param string $nonce Panel nonce.
	 * @return array{qr:string} Replacement setup QR.
	 */
	public function replace_factor( #[\SensitiveParameter] string $password, #[\SensitiveParameter] string $code, #[\SensitiveParameter] string $nonce ): array {
		return $this->mutate(
			'mfa_replacement',
			$nonce,
			function ( array $identity, int $now ) use ( $password, $code ): array {
				$this->check_password( $identity['user'], $password );
				$row = $this->active_factor( $identity['user'], $now );
				$this->step( $row['payload'], $identity['user'], $code, $now );
				return $this->new_pending( $identity, $row, $now );
			}
		);
	}

	/**
	 * Revoke current panel proof without changing ordinary WordPress login.
	 *
	 * @param string $nonce Panel nonce.
	 */
	public function revoke( #[\SensitiveParameter] string $nonce ): void {
		$this->mutate(
			'mfa_revoked',
			$nonce,
			function ( array $identity, int $now ): void {
				$this->save_proof(
					$identity,
					array(
						'generation'       => str_repeat( '0', 64 ),
						'password_at'      => 0,
						'authenticated_at' => 0,
						'last_activity'    => 0,
					),
					$now + 1,
					$now
				);
			}
		);
	}

	/**
	 * Execute only scoped POST actions; no global authentication filters.
	 *
	 * @param string   $action Closed audit action supplied by methods above.
	 * @param string   $nonce CSRF nonce.
	 * @param \Closure $operation Validated mutation callback.
	 * @return mixed Service result.
	 * @throws AttemptsExhausted When the attempt window is full (still a denial).
	 * @throws \RuntimeException On any policy, storage, crypto or contention failure.
	 */
	private function mutate( string $action, #[\SensitiveParameter] string $nonce, #[\SensitiveParameter] \Closure $operation ): mixed {
		$identity = null;
		try {
			$identity = $this->identity();
			if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || strlen( $nonce ) > 128 || ! wp_verify_nonce( $nonce, 'stealthaudit' ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Exact comparison only; nonce validated by WordPress.
				throw new \RuntimeException( 'Invalid panel request.' );
			}
			$now = ( $this->clock )();
			( new AttemptLimiter( $this->state ) )->consume( $identity['user'], $this->context->ip( $_SERVER ), $now ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Context strictly validates IP/proxy fields.
			$result = $operation( $identity, $now );
			$this->audit?->emit( 'access', $action, 'user', $identity['user'], 'success', array(), $identity['user'] );
			return $result;
		} catch ( \Throwable $failure ) {
			if ( null !== $identity ) {
				$this->audit?->emit( 'access', $action, 'user', $identity['user'], 'failure', array(), $identity['user'] );
			}
			// Same opaque message; only the type tells transport to answer "try later".
			if ( $failure instanceof AttemptsExhausted ) {
				throw new AttemptsExhausted( 'Panel authentication denied.' );
			}
			throw new \RuntimeException( 'Panel authentication denied.' );
		}
	}

	/**
	 * Check immutable external prerequisites on every service entry.
	 *
	 * @return array{user:int,session:string} Verified identity.
	 * @throws \RuntimeException When unavailable.
	 */
	private function identity(): array {
		if ( ! $this->configuration->has_key() || '' === $this->configuration->installation_id ) {
			throw new \RuntimeException( 'Panel configuration unavailable.' );
		}
		return $this->sessions->current( $this->configuration );
	}

	/**
	 * Validate authoritative factor shape rather than coercing corrupt state.
	 *
	 * @param int $user User ID.
	 * @param int $now UTC time.
	 * @return array{revision:int,payload:array<string,mixed>,expires_at:?string}|null Factor row.
	 * @throws \RuntimeException On corruption.
	 */
	private function factor( int $user, int $now ): ?array {
		$row = $this->state->get( 'factor:' . $user, $now );
		if ( null === $row ) {
			return null;
		}
		$p = $row['payload'];
		if ( array_diff( array_keys( $p ), array( 'generation', 'active', 'established', 'secret', 'until', 'owner', 'last_step', 'recovery' ) ) || count( $p ) !== 8 || ! is_string( $p['generation'] ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/D', $p['generation'] ) || ! is_bool( $p['active'] ?? null ) || ! is_bool( $p['established'] ?? null ) || ( $p['active'] && ! $p['established'] ) || ! is_string( $p['secret'] ?? null ) || ! is_int( $p['until'] ?? null ) || ! is_string( $p['owner'] ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/D', $p['owner'] ) || ! is_int( $p['last_step'] ?? null ) || ! is_array( $p['recovery'] ?? null ) || ! array_is_list( $p['recovery'] ) || count( $p['recovery'] ) > 10 || null !== $row['expires_at'] ) {
			throw new \RuntimeException( 'Invalid factor state.' );
		}
		foreach ( $p['recovery'] as $hash ) {
			if ( ! is_string( $hash ) || ! preg_match( '/^[a-f0-9]{64}$/D', $hash ) ) {
				throw new \RuntimeException( 'Invalid recovery state.' );
			}
		}
		if ( null === $this->cipher->decrypt( $p['secret'], $user ) ) {
			throw new \RuntimeException( 'Factor unavailable.' );
		}
		return $row;
	}

	/**
	 * Require an active factor.
	 *
	 * @param int $user User ID.
	 * @param int $now UTC clock.
	 * @return array{revision:int,payload:array<string,mixed>,expires_at:?string} Factor row.
	 * @throws \RuntimeException On missing factor.
	 */
	private function active_factor( int $user, int $now ): array {
		$row = $this->factor( $user, $now );
		if ( null === $row || ! $row['payload']['active'] ) {
			throw new \RuntimeException( 'Active factor required.' );
		}
		return $row;
	}

	/**
	 * Check bounded password input directly against WordPress's current hash.
	 *
	 * @param int    $user Account ID.
	 * @param string $password In-memory password.
	 * @throws \RuntimeException On mismatch.
	 */
	private function check_password( int $user, #[\SensitiveParameter] string $password ): void {
		$account = get_userdata( $user );
		if ( '' === $password || strlen( $password ) > 4096 || ! $account || ! wp_check_password( $password, $account->user_pass, $user ) ) {
			throw new \RuntimeException( 'Password denied.' );
		}
	}

	/**
	 * Validate a proof payload.
	 *
	 * @param array{user:int,session:string} $identity Current browser session.
	 * @param int                            $now Clock.
	 * @return array{revision:int,payload:array<string,mixed>,expires_at:?string}|null Proof row.
	 * @throws \RuntimeException On malformed proof.
	 */
	private function proof( array $identity, int $now ): ?array {
		$row = $this->state->get( 'proof:' . $identity['session'], $now );
		if ( null !== $row ) {
			$p = $row['payload'];
			if ( count( $p ) !== 4 || ! is_string( $p['generation'] ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/D', $p['generation'] ) ) {
				throw new \RuntimeException( 'Invalid proof.' );
			}
			foreach ( array( 'password_at', 'authenticated_at', 'last_activity' ) as $field ) {
				if ( ! is_int( $p[ $field ] ?? null ) || $p[ $field ] < 0 || $p[ $field ] > $now ) {
					throw new \RuntimeException( 'Invalid proof time.' );
				}
			}
		}
		return $row;
	}

	/**
	 * Password confirmation cannot outlive its session or five-minute lifetime.
	 *
	 * @param array{user:int,session:string} $identity Identity.
	 * @param int                            $now Clock.
	 */
	private function password_fresh( array $identity, int $now ): bool {
		$row = $this->proof( $identity, $now );
		return null !== $row && $row['payload']['password_at'] > $now - 300;
	}

	/**
	 * Validate and optionally CAS-touch an active proof.
	 *
	 * A lost activity CAS re-reads factor and proof exactly once; any other failure denies.
	 *
	 * @param array{user:int,session:string} $identity Identity.
	 * @param array<string,mixed>            $factor Current factor.
	 * @param int                            $now Clock.
	 * @param bool                           $activity Whether protected data is being requested.
	 */
	private function proof_valid( array $identity, array $factor, int $now, bool $activity ): bool {
		$row = $this->proof( $identity, $now );
		if ( null === $row || ! self::proof_fresh( $factor, $row['payload'], $now ) ) {
			return false;
		}
		$p = $row['payload'];
		if ( ! $activity || $p['last_activity'] === $now ) {
			return true;
		}
		$p['last_activity'] = $now;
		if ( $this->state->compare_and_swap( 'proof:' . $identity['session'], $row['revision'], $p, min( $now + 1800, $p['authenticated_at'] + 28800 ), $now ) ) {
			return true;
		}
		// A concurrent query of this session may have touched the proof first. Grant only
		// if a fresh read still shows the same session proof for the current generation.
		$current = $this->factor( $identity['user'], $now );
		$again   = $this->proof( $identity, $now );
		return null !== $current && $current['payload']['active'] && null !== $again && self::proof_fresh( $current['payload'], $again['payload'], $now );
	}

	/**
	 * Generation, authentication, absolute and idle limits for a proof payload.
	 *
	 * @param array<string,mixed> $factor Factor payload.
	 * @param array<string,mixed> $proof Validated proof payload.
	 * @param int                 $now Clock.
	 */
	private static function proof_fresh( array $factor, array $proof, int $now ): bool {
		return hash_equals( $factor['generation'], $proof['generation'] ) && $proof['authenticated_at'] > 0 && $proof['authenticated_at'] + 28800 > $now && $proof['last_activity'] + 1800 > $now;
	}

	/**
	 * Store proof with no expired-row delete/recreate race.
	 *
	 * @param array{user:int,session:string} $identity Browser identity.
	 * @param array<string,mixed>            $payload Proof.
	 * @param int                            $expires Absolute TTL.
	 * @param int                            $now Clock.
	 * @throws \RuntimeException When CAS fails.
	 */
	private function save_proof( array $identity, array $payload, int $expires, int $now ): void {
		$key = 'proof:' . $identity['session'];
		$row = $this->proof( $identity, $now );
		$ok  = null !== $row ? $this->state->compare_and_swap( $key, $row['revision'], $payload, $expires, $now ) : ( $this->state->replace_expired( $key, $payload, $expires, $now ) || $this->state->create( $key, $payload, $expires ) );
		if ( ! $ok ) {
			throw new \RuntimeException( 'Proof persistence failed.' );
		}
	}

	/**
	 * Establish a fresh proof after atomic factor verification only.
	 *
	 * @param array{user:int,session:string} $identity Identity.
	 * @param string                         $generation Current factor generation.
	 * @param int                            $now Clock.
	 */
	private function grant( array $identity, string $generation, int $now ): void {
		$this->save_proof(
			$identity,
			array(
				'generation'       => $generation,
				'password_at'      => 0,
				'authenticated_at' => $now,
				'last_activity'    => $now,
			),
			$now + 1800,
			$now
		);
	}

	/**
	 * Verify library code and reject any step at or below the consumed watermark.
	 *
	 * @param array<string,mixed> $factor Factor payload.
	 * @param int                 $user Account.
	 * @param string              $code OTP input.
	 * @param int                 $now Clock.
	 * @throws \RuntimeException On failed or replayed code.
	 */
	private function step( array $factor, int $user, #[\SensitiveParameter] string $code, int $now ): int {
		$secret = $this->cipher->decrypt( $factor['secret'], $user );
		$step   = null === $secret ? null : $this->totp->matching_step( $secret, $code, $now );
		if ( null === $step || $step <= $factor['last_step'] ) {
			throw new \RuntimeException( 'Authenticator denied.' );
		}
		return $step;
	}

	/**
	 * Atomic factor changes invalidate every old proof and recovery set together.
	 *
	 * @param int                 $user Account.
	 * @param int                 $revision Revision observed before credential verification.
	 * @param array<string,mixed> $payload Complete replacement.
	 * @param int                 $now Clock.
	 * @throws \RuntimeException On contention.
	 */
	private function swap_factor( int $user, int $revision, array $payload, int $now ): void {
		if ( ! $this->state->compare_and_swap( 'factor:' . $user, $revision, $payload, null, $now ) ) {
			throw new \RuntimeException( 'Factor persistence failed.' );
		}
	}

	/**
	 * Create a pending generation bound to this browser, consuming old factor state.
	 *
	 * @param array{user:int,session:string}                                          $identity Identity.
	 * @param array{revision:int,payload:array<string,mixed>,expires_at:?string}|null $row Observed row.
	 * @param int                                                                     $now Clock.
	 * @return array{qr:string} Local QR.
	 * @throws \RuntimeException On failed persistence.
	 */
	private function new_pending( array $identity, ?array $row, int $now ): array {
		$secret = $this->totp->create_secret();
		$next   = array(
			'generation'  => bin2hex( random_bytes( 32 ) ),
			'active'      => false,
			'established' => $row['payload']['established'] ?? false,
			'secret'      => $this->cipher->encrypt( $secret, $identity['user'] ),
			'until'       => $now + 600,
			'owner'       => $identity['session'],
			'last_step'   => -1,
			'recovery'    => array(),
		);
		if ( null === $row ) {
			if ( ! $this->state->create( 'factor:' . $identity['user'], $next, null ) ) {
				throw new \RuntimeException( 'Enrollment persistence failed.' );
			}
		} else {
			$this->swap_factor( $identity['user'], $row['revision'], $next, $now );
		}
		return $this->qr( $next, $identity['user'] );
	}

	/**
	 * Render sensitive local provisioning SVG only for the pending owner.
	 *
	 * @param array<string,mixed> $factor Pending state.
	 * @param int                 $user Account.
	 * @return array{qr:string} Local SVG.
	 * @throws \RuntimeException On unavailable secret.
	 */
	private function qr( array $factor, int $user ): array {
		$secret = $this->cipher->decrypt( $factor['secret'], $user );
		if ( null === $secret ) {
			throw new \RuntimeException( 'Enrollment unavailable.' );
		}
		return array( 'qr' => $this->totp->qr( $secret, 'operator-' . $user ) );
	}
}
