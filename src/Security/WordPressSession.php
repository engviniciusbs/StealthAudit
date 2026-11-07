<?php
/**
 * Validated WordPress browser identity, exclusive to panel access.
 *
 * @package StealthAudit
 */

declare(strict_types=1);
namespace StealthAudit\Security;

use StealthAudit\Config\Configuration;

/** Never accepts current-user, nonce or Application Password alone. */
final class WordPressSession {
	/**
	 * Resolve the same user and live session from a signed logged-in cookie.
	 *
	 * @param Configuration $configuration Operator allowlist.
	 * @return array{user:int,session:string} Hashed session binding only.
	 * @throws \RuntimeException When identity is unavailable.
	 */
	public function current( Configuration $configuration ): array {
		if ( ! is_ssl() ) {
			throw new \RuntimeException( 'Panel access denied.' );
		}
		if ( ! defined( 'LOGGED_IN_COOKIE' ) ) {
			throw new \RuntimeException( 'Panel access denied.' );
		}
		$cookie = $_COOKIE[ constant( 'LOGGED_IN_COOKIE' ) ] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Verified cryptographically below, never echoed or persisted.
		if ( ! is_string( $cookie ) || strlen( $cookie ) > 4096 || '' === $cookie ) {
			throw new \RuntimeException( 'Panel access denied.' );
		}
		$user  = wp_validate_auth_cookie( $cookie, 'logged_in' );
		$parts = wp_parse_auth_cookie( $cookie, 'logged_in' );
		if ( ! $user || ! is_array( $parts ) || (int) $parts['expiration'] <= time() || $user !== get_current_user_id() || ! $configuration->allows_user( $user ) || ! \WP_Session_Tokens::get_instance( $user )->verify( $parts['token'] ) ) {
			throw new \RuntimeException( 'Panel access denied.' );
		}
		return array(
			'user'    => $user,
			'session' => hash( 'sha256', $user . ':' . $parts['token'] ),
		);
	}
}
