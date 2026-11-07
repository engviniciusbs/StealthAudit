<?php
/**
 * Bounded technical object references, never arbitrary summaries.
 *
 * @package StealthAudit
 */

declare(strict_types=1);
namespace StealthAudit\Audit;

/** Shared storage/collector reference contract. */
final class Reference {
	public const CORE    = 'WordPress';
	public const OPTIONS = array( 'home', 'siteurl', 'users_can_register', 'default_role', 'blog_public', 'show_on_front', 'page_on_front', 'page_for_posts', 'permalink_structure', 'default_comment_status', 'default_ping_status' );

	/**
	 * Select validated collector inputs.
	 *
	 * @param string $type Closed object type.
	 * @param mixed  $value Technical reference.
	 */
	public static function valid( string $type, mixed $value ): bool {
		if ( ! is_string( $value ) || strlen( $value ) > 191 ) {
			return false;
		}
		return match ( $type ) {
			'option' => in_array( $value, self::OPTIONS, true ),
			'plugin' => 1 === preg_match( '~^[a-zA-Z0-9_-]+(?:/[a-zA-Z0-9_-]+)*\.php$~D', $value ) || 1 === preg_match( '/^[a-zA-Z0-9_-]+$/D', $value ),
			'theme' => 1 === preg_match( '/^[a-zA-Z0-9_-]+$/D', $value ),
			'core' => self::CORE === $value,
			default => false,
		};
	}
}
