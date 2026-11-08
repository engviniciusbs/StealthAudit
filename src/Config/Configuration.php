<?php
/**
 * Operator configuration. No automatic administrator grants.
 *
 * @license GPL-3.0-or-later
 * @package StealthAudit
 */

declare(strict_types=1);

namespace StealthAudit\Config;

use InvalidArgumentException;

/** Validated immutable operator settings; debug output is redacted. */
final readonly class Configuration {
	/** Default GeoIP age policy: a database built longer ago than this is not used. */
	public const DEFAULT_MMDB_MAX_AGE_DAYS = 30;

	public const DEFAULT_LIMITS = array(
		'event_rows'         => 100000,
		'event_bytes'        => 134217728,
		'error_rows'         => 20000,
		'error_bytes'        => 33554432,
		'events_per_request' => 25,
		'errors_per_request' => 5,
		'events_per_second'  => 60,
		'errors_per_second'  => 20,
		'state_rows'         => 10000,
		'storage_work_ms'    => 100,
	);

	/**
	 * Store validated settings.
	 *
	 * @param list<int>         $authorized_user_ids Explicit WordPress IDs.
	 * @param string            $endpoint Same-origin path.
	 * @param string|null       $key Decoded installation key.
	 * @param string            $key_id Operator key identifier.
	 * @param list<string>      $trusted_proxies Canonical IPs or CIDRs.
	 * @param array<string,int> $limits Resource budgets.
	 * @param string|null       $mmdb_path Optional local database path.
	 * @param string            $installation_id Stable operator-owned cipher context.
	 * @param int               $mmdb_max_age_days Oldest accepted database build, in days.
	 */
	private function __construct(
		public array $authorized_user_ids,
		public string $endpoint,
		private ?string $key,
		public string $key_id,
		public array $trusted_proxies,
		public array $limits,
		public ?string $mmdb_path,
		public string $installation_id,
		public int $mmdb_max_age_days,
	) {}

	/**
	 * Validate operator settings, denying on malformed security input.
	 *
	 * @param array<string,mixed> $values Operator-controlled input.
	 * @throws InvalidArgumentException When configuration is malformed.
	 */
	public static function from_array( array $values ): self {
		$ids = $values['authorized_user_ids'] ?? array();
		if ( ! is_array( $ids ) || ! array_is_list( $ids ) ) {
			throw new InvalidArgumentException( 'Invalid authorized user list.' );
		}
		foreach ( $ids as $id ) {
			if ( ! is_int( $id ) || $id < 1 ) {
				throw new InvalidArgumentException( 'Invalid authorized user ID.' );
			}
		}
		$endpoint = $values['endpoint'] ?? '/stealthaudit';
		if ( ! is_string( $endpoint ) || ! preg_match( '~^/[a-z0-9][a-z0-9/_-]{0,127}$~D', $endpoint ) || str_contains( $endpoint, '//' ) ) {
			throw new InvalidArgumentException( 'Invalid relative endpoint.' );
		}
		$encoded_key = $values['key_base64'] ?? '';
		$key_id      = $values['key_id'] ?? '';
		if ( ! is_string( $encoded_key ) || ! is_string( $key_id ) || ! preg_match( '/^[a-zA-Z0-9_-]{0,64}$/D', $key_id ) ) {
			throw new InvalidArgumentException( 'Invalid key configuration.' );
		}
		$key = '' === $encoded_key ? null : base64_decode( $encoded_key, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decode operator encryption key, never executable code.
		if ( false === $key || ( null !== $key && ( 32 !== strlen( $key ) || '' === $key_id ) ) ) {
			throw new InvalidArgumentException( 'Invalid key configuration.' );
		}
		$proxies = $values['trusted_proxies'] ?? array();
		if ( ! is_array( $proxies ) || ! array_is_list( $proxies ) || count( $proxies ) > 128 ) {
			throw new InvalidArgumentException( 'Invalid trusted proxies.' );
		}
		foreach ( $proxies as $proxy ) {
			if ( ! is_string( $proxy ) || ! self::valid_network( $proxy ) ) {
				throw new InvalidArgumentException( 'Invalid trusted proxy.' );
			}
		}
		$overrides = $values['limits'] ?? array();
		if ( ! is_array( $overrides ) || array_diff_key( $overrides, self::DEFAULT_LIMITS ) ) {
			throw new InvalidArgumentException( 'Invalid resource limits.' );
		}
		foreach ( $overrides as $limit ) {
			if ( ! is_int( $limit ) || $limit < 1 || $limit > 2147483647 ) {
				throw new InvalidArgumentException( 'Invalid resource limit.' );
			}
		}
		$mmdb = $values['mmdb_path'] ?? null;
		if ( null !== $mmdb && ( ! is_string( $mmdb ) || ! str_starts_with( $mmdb, '/' ) || str_contains( $mmdb, "\0" ) ) ) {
			throw new InvalidArgumentException( 'Invalid MMDB path.' );
		}
		$max_age = $values['mmdb_max_age_days'] ?? self::DEFAULT_MMDB_MAX_AGE_DAYS;
		if ( ! is_int( $max_age ) || $max_age < 1 || $max_age > 3650 ) {
			throw new InvalidArgumentException( 'Invalid MMDB age policy.' );
		}
		$installation = $values['installation_id'] ?? '';
		if ( ! is_string( $installation ) || ! preg_match( '/^[a-zA-Z0-9_-]{0,128}$/D', $installation ) ) {
			throw new InvalidArgumentException( 'Invalid installation identity.' );
		}
		return new self( array_values( array_unique( $ids ) ), $endpoint, $key, $key_id, $proxies, array_replace( self::DEFAULT_LIMITS, $overrides ), $mmdb, $installation, $max_age );
	}

	/**
	 * Validate an exact proxy address or CIDR range.
	 *
	 * @param string $network IP or CIDR.
	 */
	private static function valid_network( string $network ): bool {
		$parts = explode( '/', $network );
		if ( count( $parts ) > 2 || false === filter_var( $parts[0], FILTER_VALIDATE_IP ) ) {
			return false;
		}
		if ( 1 === count( $parts ) ) {
			return true;
		}
		return ctype_digit( $parts[1] ) && (int) $parts[1] <= ( str_contains( $parts[0], ':' ) ? 128 : 32 );
	}

	/** Whether the operator supplied a valid installation key. */
	public function has_key(): bool {
		return null !== $this->key;
	}

	/** Only the encryption service should consume this value; never log it. */
	public function encryption_key(): ?string {
		return $this->key;
	}

	/**
	 * Check the explicit allowlist (this alone does not authenticate).
	 *
	 * @param int $user_id WordPress user ID.
	 */
	public function allows_user( int $user_id ): bool {
		return in_array( $user_id, $this->authorized_user_ids, true );
	}

	/**
	 * Redact all configuration from debugger output.
	 *
	 * @return array<string,string> Safe debugger representation.
	 */
	public function __debugInfo(): array {
		return array( 'configuration' => 'redacted' );
	}
}
