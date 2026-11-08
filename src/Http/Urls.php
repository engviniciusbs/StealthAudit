<?php
/**
 * Central URL generation; the only place that knows how the panel is addressed.
 *
 * @package StealthAudit
 */

declare(strict_types=1);
namespace StealthAudit\Http;

/** Same-origin path today; a future subdomain transport would replace only this class. */
final class Urls {
	public const QUERY_VAR = 'stealthaudit';
	public const NAMESPACE = 'stealthaudit/v1';

	/**
	 * Construct.
	 *
	 * @param string $endpoint Validated configuration endpoint, for example /stealthaudit.
	 */
	public function __construct( private readonly string $endpoint ) {}

	/** Path relative to the WordPress home, without slashes. */
	public function path(): string {
		return trim( $this->endpoint, '/' );
	}

	/** Rewrite regex relative to the home path (subdirectory installs included). */
	public function rule(): string {
		return '^' . preg_quote( $this->path(), '#' ) . '/?$';
	}

	/**
	 * Panel URL: friendly path with pretty permalinks, query-var fallback otherwise.
	 *
	 * @param array<string,string> $query Already validated, non-secret view filters.
	 */
	public function panel( array $query = array() ): string {
		global $wp_rewrite;
		if ( $wp_rewrite instanceof \WP_Rewrite && $wp_rewrite->using_permalinks() ) {
			$url = home_url( user_trailingslashit( $wp_rewrite->root . $this->path() ) );
		} else {
			$url = add_query_arg( self::QUERY_VAR, 'panel', home_url( '/' ) );
		}
		return array() === $query ? $url : add_query_arg( array_map( 'rawurlencode', $query ), $url );
	}

	/**
	 * Local stylesheet or script served by the router (same origin, even with a CDN content URL).
	 *
	 * @param string $file Name from the closed Assets list.
	 */
	public function asset( string $file ): string {
		return add_query_arg(
			array(
				self::QUERY_VAR => 'asset',
				'file'          => rawurlencode( $file ),
			),
			home_url( '/' )
		);
	}

	/**
	 * Same-origin REST base for the panel.
	 *
	 * @param string $route Route below the namespace.
	 */
	public function rest( string $route = '' ): string {
		return rest_url( self::NAMESPACE . '/' . ltrim( $route, '/' ) );
	}

	/**
	 * Existing WordPress login, returning to a validated local panel URL.
	 *
	 * Forces reauthentication like auth_redirect(): a cookie WordPress still accepts but
	 * the stricter panel session check rejects must not bounce back without a new login.
	 *
	 * @param array<string,string> $query Validated view filters to preserve.
	 */
	public function login( array $query = array() ): string {
		$panel = $this->panel();
		return wp_login_url( wp_validate_redirect( $this->panel( $query ), $panel ), true );
	}

	/** Scheme, host and port of the panel origin, for same-origin checks. */
	public function origin(): string {
		$parts = wp_parse_url( home_url( '/' ) );
		if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) ) {
			return '';
		}
		return strtolower( $parts['scheme'] . '://' . $parts['host'] ) . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
	}
}
