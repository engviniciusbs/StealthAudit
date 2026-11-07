<?php
/**
 * Small REST controllers for stealthaudit/v1: queries, status and MFA operations.
 *
 * @package StealthAudit
 */

declare(strict_types=1);
namespace StealthAudit\Http;

use StealthAudit\Config\Configuration;
use StealthAudit\Security\PanelAccess;
use StealthAudit\Storage\Storage;

/** Validation runs after permission checks, so anonymous callers learn nothing. */
final class Api {
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
				array(
					'status'  => $status,
					'capture' => 'authorized' === $status && $access->authorized( false ) ? $this->capture( ( $this->storage )(), ( $this->clock )() ) : null,
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
			foreach ( Headers::private() as $name => $value ) {
				$response->header( $name, $value );
			}
		}
		return $response;
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
