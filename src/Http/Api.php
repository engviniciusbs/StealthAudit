<?php
/**
 * Small REST controllers for stealthaudit/v1: queries, status and MFA operations.
 *
 * @package StealthAudit
 */

declare(strict_types=1);
namespace StealthAudit\Http;

use StealthAudit\Config\Configuration;
use StealthAudit\Security\AttemptsExhausted;
use StealthAudit\Security\PanelAccess;
use StealthAudit\Storage\Storage;

/** Validation runs after permission checks, so anonymous callers learn nothing. */
final class Api {
	/** Runs after core's rest_send_cors_headers, which uses the default priority 10. */
	public const CORS_PRIORITY = 99;

	/** Core CORS grants that would let another site read our responses with cookies. */
	public const CORS_HEADERS = array( 'Access-Control-Allow-Origin', 'Access-Control-Allow-Credentials' );

	/**
	 * POST operations: route => [PanelAccess method, body fields with byte limits].
	 */
	private const OPERATIONS = array(
		'reconfirm'          => array( 'reconfirm', array( 'password' => 4096 ) ),
		'enrollment/begin'   => array( 'begin_enrollment', array() ),
		'enrollment/confirm' => array( 'confirm_enrollment', array( 'code' => 16 ) ),
		'authenticate'       => array( 'authenticate', array( 'code' => 16 ) ),
		'recover'            => array(
			'recover',
			array(
				'password'      => 4096,
				'recovery_code' => 64,
			),
		),
		'replace-factor'     => array(
			'replace_factor',
			array(
				'password' => 4096,
				'code'     => 16,
			),
		),
		'revoke'             => array( 'revoke', array() ),
	);

	/**
	 * Construct.
	 *
	 * @param Guard                                                    $guard Permission policy.
	 * @param \Closure():Storage                                       $storage Ready storage or throws.
	 * @param \Closure():array{status:string,dropped:int,buffered:int} $diagnostics Request diagnostic health.
	 * @param Configuration                                            $configuration Capacity limits.
	 * @param \Closure():int                                           $clock UTC clock.
	 */
	public function __construct(
		private readonly Guard $guard,
		private readonly \Closure $storage,
		private readonly \Closure $diagnostics,
		private readonly Configuration $configuration,
		private readonly \Closure $clock,
	) {}

	/** Hook routes and response hardening; nothing runs outside our namespace. */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_filter( 'rest_pre_dispatch', array( $this, 'pre_dispatch' ), 10, 3 );
		add_filter( 'rest_post_dispatch', array( $this, 'post_dispatch' ), 10, 3 );
		add_filter( 'rest_pre_serve_request', array( $this, 'pre_serve' ), 10, 3 );
		// After core's rest_send_cors_headers (priority 10, added on rest_api_init), preflight included.
		add_filter( 'rest_pre_serve_request', array( $this, 'strip_cors' ), self::CORS_PRIORITY, 3 );
		add_filter( 'rest_json_encode_options', array( $this, 'json_options' ), 10, 2 );
	}

	/** The rest_api_init hook; WordPress passes the server, which is not needed. */
	public function routes(): void {
		$this->register_routes( false );
	}

	/**
	 * Register endpoints; every permission_callback is a real policy check.
	 *
	 * @param bool $override Replace prior registrations (isolated tests only).
	 */
	public function register_routes( bool $override ): void {
		$read = array(
			'events' => 'events',
			'errors' => 'errors',
		);
		foreach ( $read as $route => $method ) {
			register_rest_route(
				Urls::NAMESPACE,
				'/' . $route,
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, $method ),
					'permission_callback' => array( $this->guard, 'data' ),
					'show_in_index'       => false,
				),
				$override
			);
		}
		register_rest_route(
			Urls::NAMESPACE,
			'/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'status' ),
				'permission_callback' => array( $this->guard, 'identity' ),
				'show_in_index'       => false,
			),
			$override
		);
		foreach ( array_keys( self::OPERATIONS ) as $route ) {
			register_rest_route(
				Urls::NAMESPACE,
				'/' . $route,
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'operate' ),
					'permission_callback' => array( $this->guard, 'identity' ),
					'show_in_index'       => false,
				),
				$override
			);
		}
	}

	/**
	 * Audit query: period, user, category, action, result and IP.
	 *
	 * @param \WP_REST_Request $request Authorized request.
	 * @phpstan-param \WP_REST_Request<array<string,mixed>> $request
	 */
	public function events( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->query( $request, 'events' );
	}

	/**
	 * Diagnostic query: period and severity.
	 *
	 * @param \WP_REST_Request $request Authorized request.
	 * @phpstan-param \WP_REST_Request<array<string,mixed>> $request
	 */
	public function errors( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->query( $request, 'errors' );
	}

	/** Closed MFA state; capture state only with a valid proof (no activity refresh). */
	public function status(): \WP_REST_Response|\WP_Error {
		try {
			$access = $this->guard->access();
			$status = $access->status();
			if ( 'denied' === $status ) {
				return Guard::error( 503 );
			}
			return self::respond(
				'authorized' === $status && $access->authorized( false ) ? array(
					'status'      => $status,
					'capture'     => $this->capture( ( $this->storage )(), ( $this->clock )() ),
					'maintenance' => $this->maintenance( ( $this->storage )(), ( $this->clock )() ),
				) : array(
					'status'      => $status,
					'capture'     => null,
					'maintenance' => null,
				)
			);
		} catch ( \Throwable $failure ) {
			return Guard::error( 503 );
		}
	}

	/**
	 * Run one PanelAccess mutation with JSON body fields; never returns logs.
	 *
	 * @param \WP_REST_Request $request Identity-checked POST request.
	 * @phpstan-param \WP_REST_Request<array<string,mixed>> $request
	 */
	public function operate( #[\SensitiveParameter] \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$route = substr( $request->get_route(), strlen( '/' . Urls::NAMESPACE . '/' ) );
		if ( ! isset( self::OPERATIONS[ $route ] ) ) {
			return Guard::error( 400 );
		}
		[ $method, $fields ] = self::OPERATIONS[ $route ];
		$raw                 = trim( $request->get_body() );
		$body                = '' === $raw ? array() : json_decode( $raw, true, 4 );
		if ( ! is_array( $body ) || array_diff( array_keys( $body ), array_keys( $fields ) ) || count( $body ) !== count( $fields ) ) {
			return Guard::error( 400 );
		}
		$arguments = array();
		foreach ( $fields as $field => $max ) {
			if ( ! is_string( $body[ $field ] ) || '' === $body[ $field ] || strlen( $body[ $field ] ) > $max ) {
				return Guard::error( 400 );
			}
			$arguments[] = $body[ $field ];
		}
		$nonce = Guard::nonce( $request );
		try {
			$access = $this->guard->access();
			$result = match ( $method ) {
				'reconfirm'          => $access->reconfirm( $arguments[0], $nonce ),
				'begin_enrollment'   => $access->begin_enrollment( $nonce ),
				'confirm_enrollment' => $access->confirm_enrollment( $arguments[0], $nonce ),
				'authenticate'       => $access->authenticate( $arguments[0], $nonce ),
				'recover'            => $access->recover( $arguments[0], $arguments[1], $nonce ),
				'replace_factor'     => $access->replace_factor( $arguments[0], $arguments[1], $nonce ),
				default              => $access->revoke( $nonce ),
			};
		} catch ( AttemptsExhausted $failure ) {
			// Closed "try later": still a denial, no counters or window timing.
			return Guard::error( 429 );
		} catch ( \Throwable $failure ) {
			return Guard::error( 403 );
		}
		$data = array( 'status' => $access->status() );
		if ( is_array( $result ) && isset( $result['qr'] ) ) {
			$data['qr'] = $result['qr'];
		} elseif ( is_array( $result ) ) {
			$data['recovery_codes'] = $result;
		}
		return self::respond( $data );
	}

	/**
	 * Reject response-shaping transports that could drop headers or wrap JSON as script.
	 *
	 * @param mixed $result Prior short-circuit.
	 * @param mixed $server Server.
	 * @param mixed $request Request.
	 */
	public function pre_dispatch( mixed $result, mixed $server, mixed $request ): mixed {
		if ( null !== $result || ! $request instanceof \WP_REST_Request || ! self::ours( $request ) ) {
			return $result;
		}
		foreach ( array( '_envelope', '_jsonp', '_embed', '_fields', '_wpnonce' ) as $param ) {
			if ( null !== $request->get_param( $param ) || isset( $_GET[ $param ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Presence check only.
				return Guard::error( 400 );
			}
		}
		return $result;
	}

	/**
	 * Attach private headers to every response in our namespace, errors included.
	 *
	 * @param mixed $response Response.
	 * @param mixed $server Server.
	 * @param mixed $request Request.
	 */
	public function post_dispatch( mixed $response, mixed $server, mixed $request ): mixed {
		if ( $response instanceof \WP_HTTP_Response && $request instanceof \WP_REST_Request && self::ours( $request ) ) {
			$response = $this->session_ended( $response, $request );
			foreach ( Headers::private() as $name => $value ) {
				$response->header( $name, $value );
			}
		}
		return $response;
	}

	/**
	 * Tell an ended WordPress session apart from a stale page.
	 *
	 * After logout, revocation or expiry the cookie no longer identifies anyone, so core's
	 * cookie check rejects the X-WP-Nonce as 403 rest_cookie_invalid_nonce before any
	 * permission callback runs. The panel must then offer the WordPress login (401), not a
	 * reload. A valid cookie with a stale nonce keeps core's 403. Either way it is a denial.
	 * Only same-origin (or Origin-less) requests are remapped, so another site never gets a
	 * 401/403 login-state signal, even if a CORS grant were added back by a third party.
	 *
	 * @param \WP_HTTP_Response $response Response in our namespace.
	 * @param \WP_REST_Request  $request Request.
	 * @phpstan-param \WP_REST_Request<array<string,mixed>> $request
	 */
	private function session_ended( \WP_HTTP_Response $response, \WP_REST_Request $request ): \WP_HTTP_Response {
		global $wp_rest_auth_cookie;
		$data = $response->get_data();
		if ( 403 !== $response->get_status() || ! is_array( $data ) || 'rest_cookie_invalid_nonce' !== ( $data['code'] ?? null ) || true === $wp_rest_auth_cookie || is_user_logged_in() || ! $this->guard->same_origin( $request ) ) {
			return $response;
		}
		return rest_convert_error_to_response( Guard::error( 401 ) );
	}

	/**
	 * Send private headers again right before the body, after WordPress's own headers.
	 *
	 * @param mixed $served Whether another filter served the request.
	 * @param mixed $result Response.
	 * @param mixed $request Request.
	 */
	public function pre_serve( mixed $served, mixed $result, mixed $request ): mixed {
		if ( $request instanceof \WP_REST_Request && self::ours( $request ) && ! headers_sent() ) {
			foreach ( Headers::private() as $name => $value ) {
				header( $name . ': ' . $value );
			}
		}
		return $served;
	}

	/**
	 * Drop core's CORS grant for our namespace, preflight (OPTIONS) included.
	 *
	 * WordPress echoes any Origin with Allow-Credentials on every REST route. Without these
	 * headers a cross-site page cannot read status codes or bodies of panel responses.
	 *
	 * @param mixed $served Whether another filter served the request.
	 * @param mixed $result Response.
	 * @param mixed $request Request.
	 */
	public function strip_cors( mixed $served, mixed $result, mixed $request ): mixed {
		if ( $request instanceof \WP_REST_Request && self::ours( $request ) && ! headers_sent() ) {
			foreach ( self::CORS_HEADERS as $name ) {
				header_remove( $name );
			}
		}
		return $served;
	}

	/**
	 * Hex-encode HTML-significant characters so log text is inert even if sniffed.
	 *
	 * @param mixed $options JSON flags.
	 * @param mixed $request Request.
	 */
	public function json_options( mixed $options, mixed $request ): mixed {
		if ( is_int( $options ) && $request instanceof \WP_REST_Request && self::ours( $request ) ) {
			return $options | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
		}
		return $options;
	}

	/**
	 * Whether a request targets this namespace.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string,mixed>> $request
	 */
	public static function ours( \WP_REST_Request $request ): bool {
		$route = $request->get_route();
		return '/' . Urls::NAMESPACE === $route || str_starts_with( $route, '/' . Urls::NAMESPACE . '/' );
	}

	/**
	 * Validate filters, read one keyset window and attach capture state.
	 *
	 * @param \WP_REST_Request $request Authorized request.
	 * @param string           $kind events|errors.
	 * @phpstan-param \WP_REST_Request<array<string,mixed>> $request
	 */
	private function query( \WP_REST_Request $request, string $kind ): \WP_REST_Response|\WP_Error {
		try {
			$query = 'events' === $kind ? Filters::events( $request->get_query_params() ) : Filters::errors( $request->get_query_params() );
		} catch ( \InvalidArgumentException $failure ) {
			return Guard::error( 400 );
		}
		try {
			$storage = ( $this->storage )();
			$now     = ( $this->clock )();
			$records = 'events' === $kind ? $storage->events : $storage->errors;
			$window  = $records->window( $query['filters'], $query['from'], $query['to'], $query['after'], $query['limit'], $now );
			$items   = array_map( 'events' === $kind ? array( self::class, 'event' ) : array( self::class, 'error' ), $window['rows'] );
			return self::respond(
				array(
					'items'       => $items,
					'next_cursor' => Filters::cursor( $window['next'] ),
					'limit'       => $query['limit'],
					'timezone'    => 'UTC',
					'capture'     => $this->capture( $storage, $now ),
					'maintenance' => $this->maintenance( $storage, $now ),
				)
			);
		} catch ( \Throwable $failure ) {
			return Guard::error( 503 );
		}
	}

	/**
	 * Persistent capacity state per kind plus this request's diagnostic health.
	 *
	 * @param Storage $storage Ready storage.
	 * @param int     $now Clock.
	 * @return array<string,mixed> Capture state; never equate empty with healthy.
	 */
	private function capture( Storage $storage, int $now ): array {
		$out = array( 'diagnostics' => ( $this->diagnostics )()['status'] );
		foreach ( array(
			'events' => 'event',
			'errors' => 'error',
		) as $kind => $single ) {
			$row     = $storage->state->get( 'budget:' . $kind, $now );
			$payload = null === $row ? array() : $row['payload'];
			foreach ( array( 'rows', 'bytes', 'dropped' ) as $field ) {
				if ( ! isset( $payload[ $field ] ) || ! is_int( $payload[ $field ] ) || $payload[ $field ] < 0 ) {
					$out[ $kind ] = array(
						'state'   => 'unavailable',
						'dropped' => null,
					);
					continue 2;
				}
			}
			$full         = $payload['rows'] >= $this->configuration->limits[ $single . '_rows' ] || $payload['bytes'] >= $this->configuration->limits[ $single . '_bytes' ];
			$out[ $kind ] = array(
				'state'   => $full ? 'suspended' : ( $payload['dropped'] > 0 ? 'limited' : 'available' ),
				'dropped' => $payload['dropped'],
			);
		}
		return $out;
	}

	/**
	 * Closed maintenance summary: state word, last run (ISO UTC) and losses rotated by it.
	 *
	 * Numbers come only from stored counters; absent values stay null, never zero.
	 *
	 * @param Storage $storage Ready storage.
	 * @param int     $now Clock.
	 * @return array{state:string,last_run:?string,dropped_before:array{events:?int,errors:?int}} Summary.
	 */
	private function maintenance( Storage $storage, int $now ): array {
		$report = $storage->maintenance->report( $now );
		return array(
			'state'          => $report['state'],
			'last_run'       => null === $report['last_run'] ? null : Filters::format( $report['last_run'] ),
			'dropped_before' => $report['dropped_before'],
		);
	}

	/**
	 * Public shape of an audit row; storage internals are not exposed.
	 *
	 * @param array<string,mixed> $row Database row.
	 * @return array<string,mixed> Item.
	 */
	private static function event( array $row ): array {
		$summary = json_decode( (string) $row['summary'], true, 4 );
		return array(
			'id'          => (int) $row['id'],
			'time'        => self::iso( (string) $row['occurred_at'] ),
			'actor_id'    => null === $row['actor_id'] ? null : (int) $row['actor_id'],
			'category'    => (string) $row['category'],
			'action'      => (string) $row['action'],
			'result'      => (string) $row['result'],
			'origin'      => (string) $row['origin'],
			'object_type' => (string) $row['object_type'],
			'object_id'   => null === $row['object_id'] ? null : (int) $row['object_id'],
			'ip'          => null === $row['ip'] ? null : (string) $row['ip'],
			'summary'     => is_array( $summary ) ? (object) $summary : new \stdClass(),
		);
	}

	/**
	 * Public shape of a diagnostic row.
	 *
	 * @param array<string,mixed> $row Database row.
	 * @return array<string,mixed> Item.
	 */
	private static function error( array $row ): array {
		return array(
			'id'          => (int) $row['id'],
			'first_seen'  => self::iso( (string) $row['first_seen'] ),
			'last_seen'   => self::iso( (string) $row['last_seen'] ),
			'severity'    => (string) $row['severity'],
			'message'     => (string) $row['message'],
			'file'        => (string) $row['file'],
			'line'        => (int) $row['line'],
			'occurrences' => (int) $row['occurrences'],
		);
	}

	/**
	 * Convert stored UTC DATETIME to explicit ISO 8601 UTC.
	 *
	 * @param string $value Stored value.
	 */
	private static function iso( string $value ): string {
		$time = strtotime( $value . ' UTC' );
		return false === $time ? '' : Filters::format( $time );
	}

	/**
	 * JSON response with private headers.
	 *
	 * @param array<string,mixed> $data Payload.
	 */
	private static function respond( array $data ): \WP_REST_Response {
		$response = new \WP_REST_Response( $data, 200 );
		foreach ( Headers::private() as $name => $value ) {
			$response->header( $name, $value );
		}
		return $response;
	}
}
