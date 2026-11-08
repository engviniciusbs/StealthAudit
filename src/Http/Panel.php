<?php
/**
 * Panel page controller: transport, login redirect and the operator template.
 *
 * @package StealthAudit
 */

declare(strict_types=1);
namespace StealthAudit\Http;

use StealthAudit\Config\Configuration;
use StealthAudit\Security\PanelAccess;
use StealthAudit\Security\WordPressSession;

/** No log data, QR or recovery code is rendered here; the page only bootstraps the authorized API. */
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
			return self::page( 405, __( 'Método não permitido.', 'stealthaudit' ), array( 'Allow' => 'GET, HEAD' ) );
		}
		if ( ! is_ssl() ) {
			return self::page( 403, __( 'HTTPS obrigatório.', 'stealthaudit' ) );
		}
		$user = get_current_user_id();
		if ( $user < 1 ) {
			return $this->login( $query );
		}
		if ( ! $this->configuration->allows_user( $user ) ) {
			return self::page( 403, __( 'Acesso negado.', 'stealthaudit' ) );
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
			// The operator is identified, so the local stylesheet may be served.
			return self::page( 503, __( 'Painel indisponível. O armazenamento, a chave ou a configuração do StealthAudit não estão prontos; consulte o operador do servidor.', 'stealthaudit' ), array(), $this->urls );
		}
		return $this->render( $status, $query );
	}

	/**
	 * Only a closed view and its validated filters may be carried through login.
	 *
	 * @param array<mixed> $query Raw query.
	 * @return array<string,string> Safe view parameters.
	 */
	public static function preserved( array $query ): array {
		$parsed = self::parse( $query );
		if ( null === $parsed ) {
			return array();
		}
		return array( 'view' => $parsed['view'] ) + ( $parsed['valid'] ? $parsed['filters'] : array() );
	}

	/**
	 * Validate the view and its filters once; empty form fields mean "not set".
	 *
	 * @param array<mixed> $query Raw query.
	 * @return array{view:string,filters:array<string,string>,cursor:?string,valid:bool}|null Null for an unknown view.
	 */
	private static function parse( array $query ): ?array {
		$view = $query['view'] ?? 'audit';
		if ( ! is_string( $view ) || ! in_array( $view, self::VIEWS, true ) ) {
			return null;
		}
		unset( $query['view'], $query[ Urls::QUERY_VAR ] );
		// A GET filter form without script submits blank fields; they are absent, not malformed.
		$query = array_filter( $query, static fn ( mixed $value ): bool => '' !== $value );
		try {
			$filters = 'audit' === $view ? Filters::events( $query ) : Filters::errors( $query );
		} catch ( \InvalidArgumentException $failure ) {
			return array(
				'view'    => $view,
				'filters' => array(),
				'cursor'  => null,
				'valid'   => false,
			);
		}
		return array(
			'view'    => $view,
			'filters' => $filters['query'],
			'cursor'  => Filters::cursor( $filters['after'] ),
			'valid'   => true,
		);
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
	 * Render the operator template with escaped values only.
	 *
	 * @param string       $status Closed PanelAccess status.
	 * @param array<mixed> $query Raw query parameters.
	 */
	private function render( string $status, array $query ): Response {
		$parsed  = self::parse( $query ) ?? array(
			'view'    => 'audit',
			'filters' => array(),
			'cursor'  => null,
			'valid'   => false,
		);
		$kept    = self::preserved( $query );
		$base    = $this->urls->panel();
		$hidden  = array();
		$queried = wp_parse_url( $base, PHP_URL_QUERY );
		if ( is_string( $queried ) ) {
			parse_str( $queried, $hidden );
		}
		$assets = array();
		foreach ( array_keys( Assets::FILES ) as $file ) {
			$assets[ $file ] = $this->urls->asset( $file );
		}
		$view = array(
			'status'     => $status,
			'panel'      => $base,
			'rest'       => $this->urls->rest(),
			'nonce'      => wp_create_nonce( 'stealthaudit' ),
			'rest_nonce' => wp_create_nonce( 'wp_rest' ),
			'login'      => $this->urls->login( $kept ),
			'query'      => $kept,
			'view'       => $parsed['view'],
			'filters'    => $parsed['filters'],
			'cursor'     => $parsed['cursor'],
			'invalid'    => ! $parsed['valid'],
			'tabs'       => array(
				'audit'  => $this->urls->panel( array( 'view' => 'audit' ) ),
				'errors' => $this->urls->panel( array( 'view' => 'errors' ) ),
			),
			'action'     => explode( '?', $base, 2 )[0],
			'hidden'     => array_filter( $hidden, 'is_string' ),
			'assets'     => $assets,
			'site'       => (string) get_bloginfo( 'name' ),
			'host'       => (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ),
			'geoip'      => null !== $this->configuration->mmdb_path,
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
	 * Styled only for an identified operator (assets are not served to anyone else).
	 *
	 * @param int                  $status HTTP status.
	 * @param string               $message Generic message.
	 * @param array<string,string> $extra Additional headers.
	 * @param Urls|null            $styled Asset URLs when the operator is identified.
	 */
	private static function page( int $status, string $message, array $extra = array(), ?Urls $styled = null ): Response {
		$links = '';
		if ( null !== $styled ) {
			foreach ( array( 'tokens.css', 'base.css', 'panel.css' ) as $file ) {
				$links .= '<link rel="stylesheet" href="' . esc_url( $styled->asset( $file ) ) . '">'; // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- Standalone status page outside the theme.
			}
		}
		$body = '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow"><meta name="referrer" content="no-referrer"><meta name="description" content="' . esc_attr__( 'Painel do StealthAudit: auditoria e erros do WordPress para operadores autorizados.', 'stealthaudit' ) . '"><title>StealthAudit</title>' . $links . '</head><body><main class="container screen"><section class="auth"><h1>StealthAudit</h1><p>' . esc_html( $message ) . '</p></section></main></body></html>';
		return new Response( $status, Headers::page() + $extra, $body );
	}
}
