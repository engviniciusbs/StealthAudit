<?php
/**
 * REST permission policy: transport, identity, CSRF, backend and MFA proof.
 *
 * @package StealthAudit
 */

declare(strict_types=1);
namespace StealthAudit\Http;

use StealthAudit\Config\Configuration;
use StealthAudit\Security\PanelAccess;
use StealthAudit\Security\WordPressSession;

/** Every failure is a generic error without data; nothing here grants by default. */
final class Guard {
	public const NONCE_HEADER = 'X-StealthAudit-Nonce';
	public const MAX_BODY     = 8192;
	private const CODES       = array(
		400 => array( 'stealthaudit_invalid_request', 'Invalid request.' ),
		401 => array( 'stealthaudit_unauthenticated', 'Authentication required.' ),
		403 => array( 'stealthaudit_forbidden', 'Access denied.' ),
		413 => array( 'stealthaudit_too_large', 'Request too large.' ),
		429 => array( 'stealthaudit_try_later', 'Try again later.' ),
		503 => array( 'stealthaudit_unavailable', 'Service unavailable.' ),
	);

	/**
	 * Construct.
	 *
	 * @param Configuration          $configuration Operator allowlist.
	 * @param \Closure():PanelAccess $access Throws when panel prerequisites are absent.
	 * @param Urls                   $urls Origin source.
	 * @param WordPressSession       $sessions Signed-cookie validator.
	 */
	public function __construct(
		private readonly Configuration $configuration,
		private readonly \Closure $access,
		private readonly Urls $urls,
		private readonly WordPressSession $sessions = new WordPressSession(),
	) {}

	/**
	 * Allowlisted user, live session and same-origin nonce; for status and MFA operations.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string,mixed>> $request
	 */
	public function identity( \WP_REST_Request $request ): true|\WP_Error {
		$checked = $this->check( $request );
		return $checked instanceof \WP_Error ? $checked : true;
	}

	/**
	 * Identity plus a valid StealthAudit proof; mandatory for every log query.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string,mixed>> $request
	 */
	public function data( \WP_REST_Request $request ): true|\WP_Error {
		$access = $this->check( $request );
		if ( $access instanceof \WP_Error ) {
			return $access;
		}
		// Short bounded retry for a concurrent activity update; never grants on failure.
		for ( $attempt = 0; $attempt < 2; ++$attempt ) {
			if ( $access->authorized() ) {
				return true;
			}
		}
		$status = $access->status();
		return 'denied' === $status ? self::error( 503 ) : self::error( 401, $status );
	}

	/**
	 * Build the generic error, optionally with the closed MFA status for the UI.
	 *
	 * @param int         $status HTTP status.
	 * @param string|null $panel PanelAccess::status() value.
	 */
	public static function error( int $status, ?string $panel = null ): \WP_Error {
		[ $code, $message ] = self::CODES[ $status ] ?? self::CODES[403];
		$data               = array( 'status' => isset( self::CODES[ $status ] ) ? $status : 403 );
		if ( null !== $panel ) {
			$code          = 'stealthaudit_proof_required';
			$data['panel'] = $panel;
		}
		return new \WP_Error( $code, $message, $data );
	}

	/**
	 * Header nonce for PanelAccess mutations; already verified by check().
	 *
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string,mixed>> $request
	 */
	public static function nonce( \WP_REST_Request $request ): string {
		$nonce = $request->get_header( 'x_stealthaudit_nonce' );
		return is_string( $nonce ) ? $nonce : '';
	}

	/**
	 * Compose the panel service for a callback after the permission check passed.
	 *
	 * @throws \RuntimeException When unavailable.
	 */
	public function access(): PanelAccess {
		return ( $this->access )();
	}

	/**
	 * Ordered checks: HTTPS, user, allowlist, live session, CSRF, body, backend.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string,mixed>> $request
	 */
	private function check( \WP_REST_Request $request ): PanelAccess|\WP_Error {
		if ( ! is_ssl() ) {
			return self::error( 403 );
		}
		$user = get_current_user_id();
		if ( $user < 1 ) {
			return self::error( 401 );
		}
		if ( ! $this->configuration->allows_user( $user ) ) {
			return self::error( 403 );
		}
		try {
			$this->sessions->current( $this->configuration );
		} catch ( \Throwable $failure ) {
			return self::error( 401 );
		}
		if ( ! $this->same_origin( $request ) ) {
			return self::error( 403 );
		}
		$nonce = self::nonce( $request );
		if ( '' === $nonce || strlen( $nonce ) > 128 || false === wp_verify_nonce( $nonce, 'stealthaudit' ) ) {
			return self::error( 403 );
		}
		if ( 'POST' === $request->get_method() ) {
			if ( strlen( $request->get_body() ) > self::MAX_BODY ) {
				return self::error( 413 );
			}
			if ( ! $request->is_json_content_type() ) {
				return self::error( 400 );
			}
		}
		try {
			return ( $this->access )();
		} catch ( \Throwable $failure ) {
			return self::error( 503 );
		}
	}

	/**
	 * Browser-supplied origin metadata must not indicate a cross-site request.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string,mixed>> $request
	 */
	private function same_origin( \WP_REST_Request $request ): bool {
		$origin = $request->get_header( 'origin' );
		if ( null !== $origin && strtolower( rtrim( $origin, '/' ) ) !== $this->urls->origin() ) {
			return false;
		}
		$site = $request->get_header( 'sec_fetch_site' );
		return null === $site || 'same-origin' === $site;
	}
}
