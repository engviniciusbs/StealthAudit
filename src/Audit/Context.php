<?php
/**
 * Request context without request bodies or inferred identities.
 *
 * @package StealthAudit
 */

declare(strict_types=1);
namespace StealthAudit\Audit;

/** Resolves only explicitly trusted forwarding chains. */
final class Context {

	/**
	 * Select validated collector inputs.
	 *
	 * @param list<string> $proxies Operator-controlled addresses/CIDRs.
	 */
	public function __construct( private readonly array $proxies = array() ) {}

	/**
	 * Read at event time, after WordPress pluggable APIs are available.
	 *
	 * @return array<string,int|string|null> Minimal envelope.
	 */
	public function current(): array {
		$origin = self::origin( defined( 'WP_CLI' ) && WP_CLI, wp_doing_cron(), defined( 'REST_REQUEST' ) && REST_REQUEST, is_admin() );
		$actor  = get_current_user_id();
		return array(
			'origin'   => $origin,
			'actor_id' => $actor > 0 ? $actor : null,
			'ip'       => in_array( $origin, array( 'cli', 'cron' ), true ) ? null : $this->ip( $_SERVER ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- IP resolver validates selected server fields; no raw fields are persisted.
		);
	}

	/**
	 * Resolve explicit execution modes in precedence order.
	 *
	 * @param bool $cli CLI execution.
	 * @param bool $cron Cron execution.
	 * @param bool $rest REST execution.
	 * @param bool $admin Admin execution.
	 */
	public static function origin( bool $cli, bool $cron, bool $rest, bool $admin ): string {
		return $cli ? 'cli' : ( $cron ? 'cron' : ( $rest ? 'rest' : ( $admin ? 'admin' : 'http' ) ) );
	}

	/**
	 * X-Forwarded-For is accepted only from a trusted direct peer.
	 *
	 * @param array<string,mixed> $server Selected server data.
	 */
	public function ip( array $server ): ?string {
		$peer = self::canonical( $server['REMOTE_ADDR'] ?? null );
		if ( null === $peer || ! $this->trusted( $peer ) ) {
			return $peer;
		}
		$header = $server['HTTP_X_FORWARDED_FOR'] ?? null;
		if ( ! is_string( $header ) || strlen( $header ) > 2048 ) {
			return $peer;
		}
		$chain = explode( ',', $header );
		if ( count( $chain ) > 32 ) {
			return $peer;
		}
		$addresses = array();
		foreach ( $chain as $value ) {
			$address = self::canonical( trim( $value ) );
			if ( null === $address ) {
				return $peer;
			}
			$addresses[] = $address;
		}
		for ( $i = count( $addresses ) - 1; $i >= 0; --$i ) {
			$peer = $addresses[ $i ];
			if ( ! $this->trusted( $peer ) ) {
				return $peer;
			}
		}
		return $peer;
	}

	/**
	 * Select validated collector inputs.
	 *
	 * @param mixed $value Candidate address without port/zone.
	 */
	private static function canonical( mixed $value ): ?string {
		return is_string( $value ) && false !== filter_var( $value, FILTER_VALIDATE_IP ) ? inet_ntop( inet_pton( $value ) ) : null;
	}

	/**
	 * Compare packed bytes; IPv4 and IPv6 never implicitly share trust.
	 *
	 * @param string $ip Valid address.
	 */
	private function trusted( string $ip ): bool {
		$packed = inet_pton( $ip );
		foreach ( $this->proxies as $network ) {
			$parts = explode( '/', $network );
			$base  = inet_pton( $parts[0] );
			if ( false === $base || strlen( $base ) !== strlen( $packed ) ) {
				continue;
			}
			$bits  = isset( $parts[1] ) ? (int) $parts[1] : strlen( $base ) * 8;
			$bytes = intdiv( $bits, 8 );
			$tail  = $bits % 8;
			if ( substr( $base, 0, $bytes ) === substr( $packed, 0, $bytes ) && ( 0 === $tail || ( ord( $base[ $bytes ] ) & ( 255 << ( 8 - $tail ) ) ) === ( ord( $packed[ $bytes ] ) & ( 255 << ( 8 - $tail ) ) ) ) ) {
				return true;
			}
		}
		return false;
	}
}
