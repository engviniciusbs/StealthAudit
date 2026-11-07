<?php
/**
 * Panel page controller: transport, login redirect and placeholder template.
 *
 * @package StealthAudit
 */

declare(strict_types=1);
namespace StealthAudit\Http;

use StealthAudit\Config\Configuration;
use StealthAudit\Security\PanelAccess;
use StealthAudit\Security\WordPressSession;

/** No log data is rendered here; the page only bootstraps the authorized API. */
final class Panel {
	private const VIEWS = array( 'audit', 'errors' );

	/**
	 * Construct.
	 *
	 * @param Configuration          $configuration Operator allowlist.
	 * @param Urls                   $urls Central URL generation.
	 * @param \Closure():PanelAccess $access Throws when panel prerequisites are absent.
	 * @param string                 $template Absolute template path.
	 * @param WordPressSession       $sessions Signed-cookie validator.
	 */
	public function __construct(
		private readonly Configuration $configuration,
		private readonly Urls $urls,
		private readonly \Closure $access,
		private readonly string $template,
		private readonly WordPressSession $sessions = new WordPressSession(),
	) {}

	/**
	 * Decide the page response; never throws.
	 *
	 * @param string       $method HTTP method.
	 * @param array<mixed> $query Raw query parameters, only validated view filters survive.
	 */
	public function respond( string $method, array $query ): Response {
		if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
			return self::page( 405, 'Método não permitido.', array( 'Allow' => 'GET, HEAD' ) );
		}
		if ( ! is_ssl() ) {
			return self::page( 403, 'HTTPS obrigatório.' );
		}
		$user = get_current_user_id();
		if ( $user < 1 ) {
			return $this->login( $query );
		}
		if ( ! $this->configuration->allows_user( $user ) ) {
			return self::page( 403, 'Acesso negado.' );
		}
		try {
			$this->sessions->current( $this->configuration );
		} catch ( \Throwable $failure ) {
			return $this->login( $query );
		}
		try {
			$status = ( $this->access )()->status();
		} catch ( \Throwable $failure ) {
			$status = 'denied';
		}
		if ( 'denied' === $status ) {
			return self::page( 503, 'Painel indisponível.' );
		}
		return $this->render( $status, self::preserved( $query ) );
	}

	/**
	 * Only a closed view and its validated filters may be carried through login.
	 *
	 * @param array<mixed> $query Raw query.
	 * @return array<string,string> Safe view parameters.
	 */
	public static function preserved( array $query ): array {
		$view = $query['view'] ?? 'audit';
		if ( ! is_string( $view ) || ! in_array( $view, self::VIEWS, true ) ) {
			return array();
		}
		unset( $query['view'], $query[ Urls::QUERY_VAR ] );
		try {
			$filters = 'audit' === $view ? Filters::events( $query ) : Filters::errors( $query );
		} catch ( \InvalidArgumentException $failure ) {
			return array( 'view' => $view );
		}
		return array( 'view' => $view ) + $filters['query'];
	}

	/**
	 * Redirect to the existing WordPress login with a validated local return.
	 *
	 * @param array<mixed> $query Raw query.
	 */
	private function login( array $query ): Response {
		$location = wp_sanitize_redirect( $this->urls->login( self::preserved( $query ) ) );
		$location = wp_validate_redirect( $location, wp_login_url() );
		return new Response( 302, Headers::page() + array( 'Location' => $location ), '' );
	}

	/**
	 * Render the placeholder template with escaped values only.
	 *
	 * @param string               $status Closed PanelAccess status.
	 * @param array<string,string> $query Validated view parameters.
	 */
	private function render( string $status, array $query ): Response {
		$view = array(
			'status'     => $status,
			'panel'      => $this->urls->panel(),
			'rest'       => $this->urls->rest(),
			'nonce'      => wp_create_nonce( 'stealthaudit' ),
			'rest_nonce' => wp_create_nonce( 'wp_rest' ),
			'query'      => $query,
		);
		ob_start();
		try {
			( static function ( string $stealthaudit_template, array $stealthaudit_view ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Consumed by the included template.
				require $stealthaudit_template;
			} )( $this->template, $view );
		} finally {
			$body = (string) ob_get_clean();
		}
		return new Response( 200, Headers::page(), $body );
	}

	/**
	 * Minimal generic status page; message is a fixed literal, still escaped.
	 *
	 * @param int                  $status HTTP status.
	 * @param string               $message Generic message.
	 * @param array<string,string> $extra Additional headers.
	 */
	private static function page( int $status, string $message, array $extra = array() ): Response {
		$body = '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="robots" content="noindex,nofollow"><title>StealthAudit</title></head><body><main><h1>StealthAudit</h1><p>' . esc_html( $message ) . '</p></main></body></html>';
		return new Response( $status, Headers::page() + $extra, $body );
	}
}
