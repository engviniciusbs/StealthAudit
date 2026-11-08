<?php
// SPDX-License-Identifier: GPL-3.0-or-later
declare(strict_types=1);

use StealthAudit\Audit\Context;
use StealthAudit\Config\Configuration;
use StealthAudit\Http\Api;
use StealthAudit\Http\Assets;
use StealthAudit\Http\Guard;
use StealthAudit\Http\Panel;
use StealthAudit\Http\Router;
use StealthAudit\Http\Urls;
use StealthAudit\Security\PanelAccess;
use StealthAudit\Security\SecretCipher;
use StealthAudit\Security\TotpProvider;
use StealthAudit\Security\WordPressSession;
use StealthAudit\Storage\Database;
use StealthAudit\Storage\Storage;

/**
 * Real REST dispatch, signed cookies, live sessions and isolated InnoDB tables.
 * HTTPS is simulated in $_SERVER, as in phase 5; this is not real TLS navigation.
 */
final class HttpTest extends PHPUnit\Framework\TestCase {
	private const PASSWORD = 'synthetic-test-password-only';
	private wpdb $connection;
	private Database $db;
	private Storage $storage;
	private Configuration $config;
	private SecretCipher $cipher;
	private Urls $urls;
	private Api $api;
	private int $user;
	private int $now;
	private string $token;
	private string $nonce;
	private ?Closure $access = null;
	private array $cookies;
	private array $server;
	private array $get;
	private mixed $rest_server;
	private array $responses = array();

	protected function setUp(): void {
		global $wp_rest_server, $wpdb;
		$this->cookies = $_COOKIE;
		$this->server = $_SERVER;
		$this->get = $_GET;
		$this->rest_server = $wp_rest_server;
		$this->now = time();
		$this->user = $this->login( 'sa_http_' );
		$_SERVER['HTTPS'] = 'on';
		$_SERVER['REMOTE_ADDR'] = '192.0.2.20';
		$this->nonce = wp_create_nonce( 'stealthaudit' );
		$this->connection = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$this->connection->set_prefix( 'sa_http_' . bin2hex( random_bytes( 4 ) ) . '_' );
		$this->db = new Database( $this->connection );
		$this->db->execute( 'CREATE TABLE %i LIKE %i', $this->connection->options, $wpdb->options );
		$this->config = Configuration::from_array( array( 'authorized_user_ids' => array( $this->user ), 'endpoint' => '/sa-http-panel', 'key_base64' => base64_encode( str_repeat( 'k', 32 ) ), 'key_id' => 'synthetic-test-key', 'installation_id' => 'synthetic-test-installation', 'limits' => array( 'storage_work_ms' => 10000, 'events_per_request' => 500, 'events_per_second' => 500 ) ) );
		$this->storage = new Storage( $this->connection, $this->config );
		self::assertTrue( $this->storage->schema->ensure() );
		$this->cipher = new SecretCipher( str_repeat( 'k', 32 ), 'synthetic-test-key', 'synthetic-test-installation' );
		$this->urls = new Urls( $this->config->endpoint );
		$this->api = new Api( new Guard( $this->config, fn (): PanelAccess => $this->panel_access(), $this->urls ), fn (): Storage => $this->storage, static fn (): array => array( 'status' => 'available', 'dropped' => 0, 'buffered' => 0 ), $this->config, fn (): int => $this->now );
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
		// Replace the site Runtime's registrations with this isolated composition.
		$this->api->register_routes( true );
	}

	protected function tearDown(): void {
		global $wp_rest_server;
		$wp_rest_server = $this->rest_server;
		foreach ( array( 'events', 'errors', 'state' ) as $kind ) {
			$this->db->execute( 'DROP TABLE IF EXISTS %i', $this->db->table( $kind ) );
		}
		$this->db->execute( 'DROP TABLE IF EXISTS %i', $this->connection->options );
		$this->connection->close();
		require_once ABSPATH . 'wp-admin/includes/user.php';
		foreach ( get_users( array( 'search' => 'sa_http_*', 'fields' => 'ID' ) ) as $id ) {
			WP_Session_Tokens::get_instance( (int) $id )->destroy_all();
			wp_delete_user( (int) $id );
		}
		wp_set_current_user( 0 );
		$_COOKIE = $this->cookies;
		$_SERVER = $this->server;
		$_GET = $this->get;
	}

	public function test_rest_permission_matrix_never_returns_data_without_full_policy(): void {
		$this->event( $this->now - 10 );
		$cookie = $_COOKIE[ LOGGED_IN_COOKIE ];
		// Authorized user without StealthAudit proof: closed status only.
		$response = $this->call( 'GET', '/events' );
		$this->assert_denied( $response, 401, 'stealthaudit_proof_required' );
		self::assertSame( 'password_required', $response->get_data()['data']['panel'] );
		$status = $this->call( 'GET', '/status' );
		self::assertSame( 200, $status->get_status() );
		self::assertSame( array( 'status' => 'password_required', 'capture' => null, 'maintenance' => null ), $status->get_data() );
		// No WordPress session.
		wp_set_current_user( 0 );
		unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
		foreach ( array( '/events', '/errors', '/status' ) as $route ) {
			$this->assert_denied( $this->call( 'GET', $route ), 401, 'stealthaudit_unauthenticated' );
		}
		$this->assert_denied( $this->call( 'POST', '/reconfirm', array(), array( 'password' => self::PASSWORD ) ), 401, 'stealthaudit_unauthenticated' );
		// Signed cookie for a user outside the allowlist.
		$this->login( 'sa_http_other_' );
		$this->nonce = wp_create_nonce( 'stealthaudit' );
		$this->assert_denied( $this->call( 'GET', '/events' ), 403, 'stealthaudit_forbidden' );
		$this->assert_denied( $this->call( 'GET', '/status' ), 403, 'stealthaudit_forbidden' );
		// Back to the allowlisted user; plain HTTP is refused.
		wp_set_current_user( $this->user );
		$_COOKIE[ LOGGED_IN_COOKIE ] = $cookie;
		$this->nonce = wp_create_nonce( 'stealthaudit' );
		$_SERVER['HTTPS'] = 'off';
		$this->assert_denied( $this->call( 'GET', '/events' ), 403, 'stealthaudit_forbidden' );
		$_SERVER['HTTPS'] = 'on';
		// Key/configuration unavailable: the composition throws, like Runtime::panel_access().
		$this->access = static function (): PanelAccess {
			throw new RuntimeException( 'Panel unavailable.' );
		};
		$this->assert_denied( $this->call( 'GET', '/events' ), 503, 'stealthaudit_unavailable' );
		$this->assert_denied( $this->call( 'GET', '/status' ), 503, 'stealthaudit_unavailable' );
		$this->assert_denied( $this->call( 'POST', '/reconfirm', array(), array( 'password' => self::PASSWORD ) ), 503, 'stealthaudit_unavailable' );
		$this->access = null;
		// Full policy: data is returned.
		$this->enroll();
		$ok = $this->call( 'GET', '/events' );
		self::assertSame( 200, $ok->get_status() );
		self::assertCount( 1, $ok->get_data()['items'] );
		$status = $this->call( 'GET', '/status' )->get_data();
		self::assertSame( 'authorized', $status['status'] );
		self::assertSame( 'available', $status['capture']['events']['state'] );
		// WordPress logout/session revocation denies even with a valid proof.
		WP_Session_Tokens::get_instance( $this->user )->destroy( $this->token );
		$this->assert_denied( $this->call( 'GET', '/events' ), 401, 'stealthaudit_unauthenticated' );
		$this->assert_private_headers();
	}

	public function test_ended_wordpress_session_is_401_not_a_stale_nonce(): void {
		global $wp_rest_auth_cookie;
		$previous = $wp_rest_auth_cookie;
		$this->enroll();
		$rest_nonce = wp_create_nonce( 'wp_rest' );
		// Serve_request() order: core cookie check, then rest_post_dispatch, as in a real browser.
		$authenticate = function () use ( $rest_nonce ): WP_REST_Response {
			$_SERVER['HTTP_X_WP_NONCE'] = $rest_nonce;
			$request = $this->request( 'GET', '/events' );
			$result = rest_cookie_check_errors( null );
			$response = is_wp_error( $result ) ? rest_convert_error_to_response( $result ) : rest_ensure_response( rest_get_server()->dispatch( $request ) );
			return apply_filters( 'rest_post_dispatch', $response, rest_get_server(), $request );
		};
		try {
			// Valid cookie, stale nonce: still core's 403 (the page asks for a reload).
			$wp_rest_auth_cookie = true;
			$_SERVER['HTTP_X_WP_NONCE'] = 'stale';
			$request = $this->request( 'GET', '/events' );
			$stale = apply_filters( 'rest_post_dispatch', rest_convert_error_to_response( rest_cookie_check_errors( null ) ), rest_get_server(), $request );
			self::assertSame( 403, $stale->get_status() );
			self::assertSame( 'rest_cookie_invalid_nonce', $stale->get_data()['code'] );
			// Session revoked or logged out: determine_current_user() found nobody.
			WP_Session_Tokens::get_instance( $this->user )->destroy( $this->token );
			foreach ( array( 'bad_session_token', 'expired', 'malformed' ) as $reason ) {
				$wp_rest_auth_cookie = $reason;
				wp_set_current_user( 0 );
				$ended = $authenticate();
				$this->responses[] = $ended;
				$this->assert_denied( $ended, 401, 'stealthaudit_unauthenticated' );
			}
			// A cross-origin request keeps core's 403: no login-state signal for another site.
			$_SERVER['HTTP_X_WP_NONCE'] = $rest_nonce;
			$cross = $this->request( 'GET', '/events', array(), null, array( 'Origin' => 'https://evil.example' ) );
			$cross_response = apply_filters( 'rest_post_dispatch', rest_convert_error_to_response( rest_cookie_check_errors( null ) ), rest_get_server(), $cross );
			self::assertSame( 403, $cross_response->get_status() );
			self::assertSame( 'rest_cookie_invalid_nonce', $cross_response->get_data()['code'] );
			$same = $this->request( 'GET', '/events', array(), null, array( 'Origin' => home_url() ) );
			self::assertSame( 401, apply_filters( 'rest_post_dispatch', rest_convert_error_to_response( rest_cookie_check_errors( null ) ), rest_get_server(), $same )->get_status() );
			// Another namespace keeps WordPress's own answer.
			$other = apply_filters( 'rest_post_dispatch', rest_convert_error_to_response( rest_cookie_check_errors( null ) ), rest_get_server(), new WP_REST_Request( 'GET', '/wp/v2/users/me' ) );
			self::assertSame( 'rest_cookie_invalid_nonce', $other->get_data()['code'] );
			$this->assert_private_headers();
		} finally {
			$wp_rest_auth_cookie = $previous;
			unset( $_SERVER['HTTP_X_WP_NONCE'] );
		}
	}

	public function test_backend_failure_with_existing_proof_is_503_without_data(): void {
		$this->enroll();
		self::assertSame( 200, $this->call( 'GET', '/errors' )->get_status() );
		$this->db->execute( 'DROP TABLE %i', $this->db->table( 'state' ) );
		$this->assert_denied( $this->call( 'GET', '/errors' ), 503, 'stealthaudit_unavailable' );
		$this->assert_denied( $this->call( 'GET', '/status' ), 503, 'stealthaudit_unavailable' );
		$this->assert_private_headers();
	}

	public function test_csrf_origin_method_and_body_limits_are_enforced(): void {
		$this->enroll();
		self::assertSame( 200, $this->call( 'GET', '/events', array(), null, array( 'Origin' => $this->urls->origin(), 'Sec-Fetch-Site' => 'same-origin' ) )->get_status() );
		$this->assert_denied( $this->call( 'GET', '/events', array(), null, array( 'X-StealthAudit-Nonce' => '' ) ), 403, 'stealthaudit_forbidden' );
		$this->assert_denied( $this->call( 'GET', '/events', array(), null, array( 'X-StealthAudit-Nonce' => 'forged-nonce' ) ), 403, 'stealthaudit_forbidden' );
		$this->assert_denied( $this->call( 'GET', '/events', array(), null, array( 'X-StealthAudit-Nonce' => wp_create_nonce( 'wp_rest' ) ) ), 403, 'stealthaudit_forbidden' );
		$this->assert_denied( $this->call( 'GET', '/events', array(), null, array( 'Origin' => 'https://evil.example' ) ), 403, 'stealthaudit_forbidden' );
		$this->assert_denied( $this->call( 'GET', '/events', array(), null, array( 'Sec-Fetch-Site' => 'cross-site' ) ), 403, 'stealthaudit_forbidden' );
		$this->assert_denied( $this->call( 'POST', '/revoke', array(), array(), array( 'Origin' => 'https://evil.example' ) ), 403, 'stealthaudit_forbidden' );
		// Wrong methods do not match any handler.
		$this->assert_denied( $this->call( 'POST', '/events', array(), array() ), 404, 'rest_no_route' );
		$this->assert_denied( $this->call( 'GET', '/reconfirm' ), 404, 'rest_no_route' );
		$this->assert_denied( $this->call( 'DELETE', '/revoke' ), 404, 'rest_no_route' );
		// Body: JSON only, closed fields, bounded size; secrets never read from the URL.
		$this->assert_denied( $this->call( 'POST', '/reconfirm', array(), array( 'password' => self::PASSWORD ), array( 'Content-Type' => 'application/x-www-form-urlencoded' ) ), 400, 'stealthaudit_invalid_request' );
		$this->assert_denied( $this->call( 'POST', '/reconfirm', array( 'password' => self::PASSWORD ), array() ), 400, 'stealthaudit_invalid_request' );
		$this->assert_denied( $this->call( 'POST', '/reconfirm', array(), array( 'password' => self::PASSWORD, 'admin' => '1' ) ), 400, 'stealthaudit_invalid_request' );
		$this->assert_denied( $this->call( 'POST', '/authenticate', array(), array( 'code' => array( '123456' ) ) ), 400, 'stealthaudit_invalid_request' );
		$this->assert_denied( $this->call( 'POST', '/reconfirm', array(), array( 'password' => str_repeat( 'p', Guard::MAX_BODY ) ) ), 413, 'stealthaudit_too_large' );
		// Response-shaping transport parameters are refused in this namespace.
		foreach ( array( '_envelope', '_jsonp', '_fields', '_wpnonce' ) as $param ) {
			$this->assert_denied( $this->call( 'GET', '/events', array( $param => '1' ) ), 400, 'stealthaudit_invalid_request' );
		}
		// A wrong code is a generic denial.
		$this->assert_denied( $this->call( 'POST', '/authenticate', array(), array( 'code' => '000000' ) ), 403, 'stealthaudit_forbidden' );
		self::assertSame( 200, $this->call( 'GET', '/events' )->get_status() );
		self::assertSame( 'challenge_required', $this->call( 'POST', '/revoke', array(), array() )->get_data()['status'] );
		$this->assert_denied( $this->call( 'GET', '/events' ), 401, 'stealthaudit_proof_required' );
		$this->assert_private_headers();
	}

	public function test_malformed_filters_are_rejected_after_authorization_only(): void {
		$bad = array( array( 'from' => '2026-13-01T00:00:00Z' ), array( 'action' => str_repeat( 'x', 65 ) ), array( 'ip' => '300.0.0.1' ), array( 'limit' => '101' ), array( 'cursor' => 'not-a-cursor' ), array( 'severity' => 'fatal' ), array( 'orderby' => 'ip' ) );
		foreach ( $bad as $query ) {
			$this->assert_denied( $this->call( 'GET', '/events', $query ), 401, 'stealthaudit_proof_required' );
		}
		$this->enroll();
		foreach ( $bad as $query ) {
			$this->assert_denied( $this->call( 'GET', '/events', $query ), 400, 'stealthaudit_invalid_request' );
		}
		$this->assert_denied( $this->call( 'GET', '/errors', array( 'severity' => 'critical' ) ), 400, 'stealthaudit_invalid_request' );
		$this->assert_denied( $this->call( 'GET', '/errors', array( 'category' => 'access' ) ), 400, 'stealthaudit_invalid_request' );
		$this->assert_private_headers();
	}

	public function test_filters_cursor_pagination_and_query_time_retention(): void {
		$this->enroll();
		$second = $this->now - 100;
		$ids = array();
		for ( $i = 0; $i < 5; ++$i ) {
			$ids[] = $this->event( $second, array( 'ip' => '192.0.2.' . ( 1 + $i % 2 ) ) );
		}
		$this->event( $this->now - 366 * DAY_IN_SECONDS );
		$retained = $this->event( $this->now - 364 * DAY_IN_SECONDS, array( 'category' => 'admin', 'action' => 'activated', 'object_type' => 'plugin' ) );
		$seen = array();
		$cursor = null;
		$pages = 0;
		do {
			$response = $this->call( 'GET', '/events', array( 'limit' => '2' ) + ( null === $cursor ? array() : array( 'cursor' => $cursor ) ) );
			self::assertSame( 200, $response->get_status() );
			$data = $response->get_data();
			self::assertLessThanOrEqual( 2, count( $data['items'] ) );
			self::assertSame( 'UTC', $data['timezone'] );
			$seen = array_merge( $seen, array_column( $data['items'], 'id' ) );
			$cursor = $data['next_cursor'];
			if ( 0 === $pages ) {
				// A record written between pages never shifts or duplicates later pages.
				$this->event( $this->now - 1 );
			}
			++$pages;
		} while ( null !== $cursor && $pages < 10 );
		self::assertSame( array_merge( array_reverse( $ids ), array( $retained ) ), $seen );
		self::assertSame( 3, $pages );
		$all = $this->call( 'GET', '/events', array( 'limit' => '100' ) )->get_data()['items'];
		self::assertCount( 7, $all );
		self::assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $all[0]['time'] );
		// The retention window applies even when the caller asks for an older period.
		$old = $this->call( 'GET', '/events', array( 'from' => gmdate( 'Y-m-d\TH:i:s\Z', $this->now - 400 * DAY_IN_SECONDS ), 'to' => gmdate( 'Y-m-d\TH:i:s\Z', $this->now - 300 * DAY_IN_SECONDS ) ) )->get_data()['items'];
		self::assertSame( array( $retained ), array_column( $old, 'id' ) );
		self::assertSame( array( $retained ), array_column( $this->call( 'GET', '/events', array( 'category' => 'admin', 'action' => 'activated' ) )->get_data()['items'], 'id' ) );
		self::assertCount( 3, $this->call( 'GET', '/events', array( 'ip' => '192.0.2.1', 'user' => (string) $this->user ) )->get_data()['items'] );
		self::assertSame( array(), $this->call( 'GET', '/events', array( 'user' => '999999' ) )->get_data()['items'] );
		$period = $this->call( 'GET', '/events', array( 'from' => gmdate( 'Y-m-d\TH:i:s\Z', $second ), 'to' => gmdate( 'Y-m-d\TH:i:s\Z', $second ) ) )->get_data()['items'];
		self::assertSame( array_reverse( $ids ), array_column( $period, 'id' ) );
		// Errors: 30 days, by first occurrence.
		$this->storage->errors->append( array( 'severity' => 'warning', 'message' => 'expired warning', 'file' => 'wp-content/plugins/demo/a.php', 'line' => 1 ), $this->now - 31 * DAY_IN_SECONDS );
		$this->storage->errors->append( array( 'severity' => 'fatal', 'message' => 'retained fatal', 'file' => 'wp-content/plugins/demo/b.php', 'line' => 2 ), $this->now - 29 * DAY_IN_SECONDS );
		$errors = $this->call( 'GET', '/errors' )->get_data();
		self::assertSame( array( 'retained fatal' ), array_column( $errors['items'], 'message' ) );
		self::assertNull( $errors['next_cursor'] );
		self::assertSame( array(), $this->call( 'GET', '/errors', array( 'severity' => 'warning' ) )->get_data()['items'] );
		$this->assert_private_headers();
	}

	public function test_capture_state_is_returned_with_lists_and_distinguishes_limits(): void {
		$this->enroll();
		$capture = $this->call( 'GET', '/errors' )->get_data()['capture'];
		self::assertSame( array( 'diagnostics' => 'available', 'events' => array( 'state' => 'available', 'dropped' => 0 ), 'errors' => array( 'state' => 'available', 'dropped' => 0 ) ), $capture );
		$this->budget( 'errors', array( 'dropped' => 3 ) );
		$this->budget( 'events', array( 'rows' => $this->config->limits['event_rows'] ) );
		$data = $this->call( 'GET', '/errors' )->get_data();
		self::assertSame( array(), $data['items'] );
		self::assertSame( array( 'state' => 'limited', 'dropped' => 3 ), $data['capture']['errors'] );
		self::assertSame( 'suspended', $this->call( 'GET', '/events' )->get_data()['capture']['events']['state'] );
		$this->db->execute( 'UPDATE %i SET payload=%s WHERE state_key=%s', $this->db->table( 'state' ), '{"rows":"x"}', 'budget:errors' );
		self::assertSame( array( 'state' => 'unavailable', 'dropped' => null ), $this->call( 'GET', '/errors' )->get_data()['capture']['errors'] );
	}

	public function test_maintenance_state_is_exposed_only_when_authorized_and_clears_capture(): void {
		$this->enroll();
		$data = $this->call( 'GET', '/status' )->get_data();
		self::assertSame( array( 'state' => 'never', 'last_run' => null, 'dropped_before' => array( 'events' => null, 'errors' => null ) ), $data['maintenance'] );
		$this->budget( 'errors', array( 'dropped' => 4, 'rows' => 900 ) );
		self::assertSame( array( 'state' => 'limited', 'dropped' => 4 ), $this->call( 'GET', '/errors' )->get_data()['capture']['errors'] );
		self::assertSame( 'complete', $this->storage->maintenance->run( $this->now )['result'] );
		$list = $this->call( 'GET', '/errors' )->get_data();
		self::assertSame( array( 'state' => 'available', 'dropped' => 0 ), $list['capture']['errors'] );
		self::assertSame( array( 'state' => 'current', 'last_run' => gmdate( 'Y-m-d\TH:i:s\Z', $this->now ), 'dropped_before' => array( 'events' => 0, 'errors' => 4 ) ), $list['maintenance'] );
		self::assertSame( $list['maintenance'], $this->call( 'GET', '/status' )->get_data()['maintenance'] );
		self::assertSame( 0, $this->storage->state->get( 'budget:errors', $this->now )['payload']['rows'] );
		// Malformed status is reported as such, never as a healthy default.
		$this->db->execute( 'UPDATE %i SET payload=%s WHERE state_key=%s', $this->db->table( 'state' ), '{"ran_at":"x"}', 'maintenance:status' );
		self::assertSame( 'unavailable', $this->call( 'GET', '/status' )->get_data()['maintenance']['state'] );
		// Without the second factor nothing about maintenance is returned.
		$this->call( 'POST', '/revoke', array(), array() );
		self::assertNull( $this->call( 'GET', '/status' )->get_data()['maintenance'] );
	}

	public function test_core_cors_grant_is_stripped_after_core_for_our_namespace_only(): void {
		global $wp_filter;
		$core = has_filter( 'rest_pre_serve_request', 'rest_send_cors_headers' );
		self::assertSame( 10, $core );
		$ours = null;
		foreach ( $wp_filter['rest_pre_serve_request']->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $callback ) {
				if ( is_array( $callback['function'] ) && $callback['function'][0] instanceof Api && 'strip_cors' === $callback['function'][1] ) {
					$ours = $priority;
				}
			}
		}
		self::assertSame( Api::CORS_PRIORITY, $ours );
		self::assertGreaterThan( $core, $ours );
		self::assertSame( array( 'Access-Control-Allow-Origin', 'Access-Control-Allow-Credentials' ), Api::CORS_HEADERS );
		// The filter passes the served flag through untouched, preflight included.
		foreach ( array( 'GET', 'OPTIONS' ) as $method ) {
			self::assertFalse( $this->api->strip_cors( false, null, new WP_REST_Request( $method, '/' . Urls::NAMESPACE . '/events' ) ) );
			self::assertTrue( $this->api->strip_cors( true, null, new WP_REST_Request( $method, '/wp/v2/users' ) ) );
		}
	}

	public function test_log_text_with_markup_is_returned_inert(): void {
		$this->enroll();
		$payload = '<script>alert(1)</script><img src=x onerror="alert(2)">&\'';
		self::assertTrue( $this->storage->errors->append( array( 'severity' => 'warning', 'message' => $payload, 'file' => 'wp-content/plugins/demo/x.php', 'line' => 3 ), $this->now ) );
		$request = $this->request( 'GET', '/errors' );
		$response = $this->dispatch( $request );
		$body = wp_json_encode( rest_get_server()->response_to_data( $response, false ), apply_filters( 'rest_json_encode_options', 0, $request ) );
		self::assertIsString( $body );
		foreach ( array( '<', '>', '&', "'" ) as $character ) {
			self::assertStringNotContainsString( $character, $body );
		}
		self::assertStringContainsString( '\\u003Cscript\\u003E', $body );
		self::assertSame( $payload, json_decode( $body, true )['items'][0]['message'] );
		self::assertSame( 'nosniff', $response->get_headers()['X-Content-Type-Options'] );
	}

	public function test_panel_page_redirects_locally_and_never_caches(): void {
		$panel = $this->panel();
		$responses = array();
		$responses[] = $method = $panel->respond( 'POST', array() );
		self::assertSame( 405, $method->status );
		self::assertSame( 'GET, HEAD', $method->headers['Allow'] );
		$_SERVER['HTTPS'] = 'off';
		$responses[] = $insecure = $panel->respond( 'GET', array() );
		self::assertSame( 403, $insecure->status );
		$_SERVER['HTTPS'] = 'on';
		$cookie = $_COOKIE[ LOGGED_IN_COOKIE ];
		wp_set_current_user( 0 );
		unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
		$responses[] = $redirect = $panel->respond( 'GET', array( 'view' => 'errors', 'severity' => 'fatal', 'redirect_to' => 'https://evil.example/' ) );
		self::assertSame( 302, $redirect->status );
		$location = $redirect->headers['Location'];
		self::assertStringStartsWith( wp_login_url(), $location );
		parse_str( (string) wp_parse_url( $location, PHP_URL_QUERY ), $args );
		self::assertStringNotContainsString( 'evil.example', $location );
		self::assertSame( '1', $args['reauth'] );
		// Unknown parameters drop every filter; the return target is always the local panel.
		self::assertSame( add_query_arg( 'view', 'errors', $this->urls->panel() ), $args['redirect_to'] );
		$responses[] = $kept = $panel->respond( 'GET', array( 'view' => 'errors', 'severity' => 'fatal', 'from' => '2026-11-01T00:00:00+00:00' ) );
		parse_str( (string) wp_parse_url( $kept->headers['Location'], PHP_URL_QUERY ), $args );
		self::assertSame( wp_parse_url( home_url(), PHP_URL_HOST ), wp_parse_url( $args['redirect_to'], PHP_URL_HOST ) );
		parse_str( (string) wp_parse_url( $args['redirect_to'], PHP_URL_QUERY ), $return );
		self::assertSame( array( 'stealthaudit' => 'panel', 'view' => 'errors', 'severity' => 'fatal', 'from' => '2026-11-01T00:00:00+00:00' ), $return );
		$this->login( 'sa_http_other_' );
		$responses[] = $forbidden = $panel->respond( 'GET', array() );
		self::assertSame( 403, $forbidden->status );
		wp_set_current_user( $this->user );
		$_COOKIE[ LOGGED_IN_COOKIE ] = $cookie;
		$responses[] = $page = $panel->respond( 'GET', array() );
		self::assertSame( 200, $page->status );
		self::assertStringContainsString( 'data-status="password_required"', $page->body );
		self::assertStringContainsString( 'data-nonce="' . esc_attr( wp_create_nonce( 'stealthaudit' ) ) . '"', $page->body );
		self::assertStringContainsString( "frame-ancestors 'none'", $page->headers['Content-Security-Policy'] );
		self::assertSame( 'DENY', $page->headers['X-Frame-Options'] );
		$responses[] = $unavailable = $this->panel( static function (): PanelAccess {
			throw new RuntimeException( 'Panel unavailable.' );
		} )->respond( 'GET', array() );
		self::assertSame( 503, $unavailable->status );
		self::assertStringNotContainsString( 'data-nonce', $unavailable->body );
		WP_Session_Tokens::get_instance( $this->user )->destroy( $this->token );
		$responses[] = $expired = $panel->respond( 'GET', array() );
		self::assertSame( 302, $expired->status );
		foreach ( $responses as $response ) {
			self::assertStringContainsString( 'no-store', $response->headers['Cache-Control'] );
			self::assertSame( 'no-cache', $response->headers['Pragma'] );
			self::assertSame( 'nosniff', $response->headers['X-Content-Type-Options'] );
		}
	}

	public function test_query_var_fallback_friendly_route_and_subdirectory(): void {
		global $wp_rewrite, $wp_filter;
		$structure = (string) get_option( 'permalink_structure' );
		$rules = get_option( 'rewrite_rules' );
		$hooks = $wp_filter['parse_request'] ?? null;
		$router = new Router( $this->urls, $this->panel(), fn () => $this->storage->state, fn (): int => $this->now );
		add_filter( 'query_vars', array( $router, 'query_vars' ) );
		// parse_request must not run the site's real router (which would exit).
		unset( $wp_filter['parse_request'] );
		try {
			$wp_rewrite->set_permalink_structure( '' );
			self::assertSame( home_url( '/?stealthaudit=panel' ), $this->urls->panel() );
			self::assertTrue( $router->matches( $this->parse( '/?stealthaudit=panel', array( 'stealthaudit' => 'panel' ) ) ) );
			self::assertFalse( $router->matches( $this->parse( '/?stealthaudit=other', array( 'stealthaudit' => 'other' ) ) ) );
			self::assertFalse( $router->maybe_flush() );
			$wp_rewrite->set_permalink_structure( '/%postname%/' );
			$router->add_rules();
			flush_rewrite_rules( false );
			self::assertSame( home_url( '/sa-http-panel/' ), $this->urls->panel() );
			self::assertTrue( $router->matches( $this->parse( '/sa-http-panel/' ) ) );
			self::assertTrue( $router->matches( $this->parse( '/sa-http-panel' ) ) );
			self::assertFalse( $router->matches( $this->parse( '/sa-http-panel-x/' ) ) );
			// The query var still works with pretty permalinks (server cannot route the path).
			self::assertTrue( $router->matches( $this->parse( '/?stealthaudit=panel', array( 'stealthaudit' => 'panel' ) ) ) );
			$subdirectory = static fn (): string => 'http://localhost:8880/blog';
			add_filter( 'pre_option_home', $subdirectory );
			try {
				self::assertSame( home_url( '/sa-http-panel/' ), $this->urls->panel() );
				self::assertStringEndsWith( '/blog/sa-http-panel/', $this->urls->panel() );
				self::assertTrue( $router->matches( $this->parse( '/blog/sa-http-panel/' ) ) );
			} finally {
				remove_filter( 'pre_option_home', $subdirectory );
			}
		} finally {
			if ( null !== $hooks ) {
				$wp_filter['parse_request'] = $hooks;
			}
			remove_filter( 'query_vars', array( $router, 'query_vars' ) );
			$wp_rewrite->set_permalink_structure( $structure );
			update_option( 'rewrite_rules', $rules );
		}
	}

	public function test_rewrite_flush_runs_once_under_a_lease(): void {
		global $wp_rewrite;
		$structure = (string) get_option( 'permalink_structure' );
		$rules = get_option( 'rewrite_rules' );
		$flushes = 0;
		$count = static function () use ( &$flushes ): void {
			++$flushes;
		};
		$router = new Router( $this->urls, $this->panel(), fn () => $this->storage->state, fn (): int => $this->now );
		add_action( 'generate_rewrite_rules', $count );
		try {
			$wp_rewrite->set_permalink_structure( '/%postname%/' );
			$router->add_rules();
			$stored = get_option( 'rewrite_rules' );
			$stored = is_array( $stored ) ? $stored : array();
			unset( $stored[ $this->urls->rule() ] );
			$stored['^placeholder/?$'] = 'index.php';
			update_option( 'rewrite_rules', $stored );
			$flushes = 0;
			self::assertTrue( $router->maybe_flush() );
			self::assertSame( 1, $flushes );
			for ( $i = 0; $i < 5; ++$i ) {
				self::assertFalse( $router->maybe_flush() );
			}
			self::assertSame( 1, $flushes );
			self::assertArrayHasKey( $this->urls->rule(), get_option( 'rewrite_rules' ) );
			// Rule lost again (e.g. endpoint changed): the live lease prevents a flush storm.
			update_option( 'rewrite_rules', $stored );
			$rows = (int) $this->db->rows( 'SELECT COUNT(*) AS c FROM %i', $this->db->table( 'state' ) )[0]['c'];
			for ( $i = 0; $i < 5; ++$i ) {
				self::assertFalse( $router->maybe_flush() );
			}
			self::assertSame( 1, $flushes );
			self::assertSame( $rows, (int) $this->db->rows( 'SELECT COUNT(*) AS c FROM %i', $this->db->table( 'state' ) )[0]['c'] );
			// After the lease expires exactly one more flush happens, reclaiming the row.
			$this->now += Router::LEASE_SECONDS;
			self::assertTrue( $router->maybe_flush() );
			self::assertFalse( $router->maybe_flush() );
			self::assertSame( 2, $flushes );
			// Storage unavailable: no flush; the query-var fallback remains.
			update_option( 'rewrite_rules', $stored );
			$this->now += Router::LEASE_SECONDS;
			$broken = new Router( $this->urls, $this->panel(), static function () {
				throw new RuntimeException( 'Storage unavailable.' );
			}, fn (): int => $this->now );
			self::assertFalse( $broken->maybe_flush() );
			self::assertSame( 2, $flushes );
		} finally {
			remove_action( 'generate_rewrite_rules', $count );
			$wp_rewrite->set_permalink_structure( $structure );
			update_option( 'rewrite_rules', $rules );
		}
	}

	public function test_panel_renders_each_mfa_state_without_secrets_or_external_assets(): void {
		$hostile = static fn (): string => '<script>alert("site")</script>&\'';
		add_filter( 'pre_option_blogname', $hostile );
		try {
			$panel = $this->panel();
			$page = $panel->respond( 'GET', array( 'view' => 'errors', 'severity' => 'fatal' ) );
			$this->assert_page( $page, 'password_required', array( 'password' ) );
			self::assertStringNotContainsString( '<script>alert', $page->body );
			self::assertStringContainsString( '&lt;script&gt;alert(&quot;site&quot;)&lt;/script&gt;&amp;', $page->body );
			self::assertStringContainsString( 'autocomplete="current-password"', $page->body );
			self::assertStringContainsString( 'id="sa-h-session"', $page->body );
			self::assertDoesNotMatchRegularExpression( '/data-sa-chrome|data-sa-records/', $page->body );

			self::assertSame( 200, $this->call( 'POST', '/reconfirm', array(), array( 'password' => self::PASSWORD ) )->get_status() );
			$this->assert_page( $panel->respond( 'GET', array() ), 'enrollment_required', array( 'enroll' ) );

			$qr = $this->call( 'POST', '/enrollment/begin', array(), array() )->get_data()['qr'];
			self::assertStringContainsString( '<svg', $qr );
			$pending = $panel->respond( 'GET', array() );
			$this->assert_page( $pending, 'enrollment_pending', array( 'setup' ) );
			self::assertStringContainsString( 'inputmode="numeric"', $pending->body );
			self::assertStringContainsString( 'autocomplete="one-time-code"', $pending->body );
			// One TOTP field per form, never six single-digit boxes.
			self::assertSame( 1, substr_count( $pending->body, 'name="code"' ) );

			$codes = $this->call( 'POST', '/enrollment/confirm', array(), array( 'code' => $this->code() ) )->get_data()['recovery_codes'];
			self::assertCount( 10, $codes );
			$authorized = $panel->respond( 'GET', array( 'view' => 'errors', 'severity' => 'fatal', 'limit' => '25', 'from' => '' ) );
			$this->assert_page( $authorized, 'authorized', array( 'workspace' ) );
			foreach ( $codes as $code ) {
				self::assertStringNotContainsString( $code, $authorized->body );
			}
			self::assertStringContainsString( 'aria-current="page">Erros</a>', $authorized->body );
			self::assertStringContainsString( '<option value="fatal" selected=\'selected\'>Fatal</option>', $authorized->body );
			self::assertStringContainsString( 'data-filters="{&quot;severity&quot;:&quot;fatal&quot;,&quot;limit&quot;:&quot;25&quot;}"', $authorized->body );
			self::assertStringContainsString( 'Horários em UTC.', $authorized->body );
			self::assertStringContainsString( 'data-state="idle"', $authorized->body );
			self::assertStringContainsString( 'Registros indisponíveis sem JavaScript', $authorized->body );
			self::assertStringNotContainsString( 'role="alert"', $authorized->body );
			// The filter form works without script: a local GET form carrying the route parameters.
			self::assertMatchesRegularExpression( '/<form class="filters" method="get" action="' . preg_quote( esc_url( explode( '?', $this->urls->panel(), 2 )[0] ), '/' ) . '"/', $authorized->body );
			self::assertStringContainsString( '<input type="hidden" name="stealthaudit" value="panel">', $authorized->body );
			// Malformed filters are ignored with a visible notice and never echoed back.
			$invalid = $panel->respond( 'GET', array( 'view' => 'audit', 'ip' => '<b>x</b>' ) );
			$this->assert_page( $invalid, 'authorized', array( 'workspace' ) );
			self::assertStringContainsString( 'role="alert"', $invalid->body );
			self::assertStringNotContainsString( '<b>x', $invalid->body );
			self::assertStringNotContainsString( '&lt;b&gt;x', $invalid->body );
			self::assertStringContainsString( 'data-filters="{}"', $invalid->body );

			self::assertSame( 'challenge_required', $this->call( 'POST', '/revoke', array(), array() )->get_data()['status'] );
			$challenge = $panel->respond( 'GET', array( 'view' => 'audit', 'result' => 'failure' ) );
			$this->assert_page( $challenge, 'challenge_required', array( 'challenge' ) );
			self::assertStringContainsString( 'falta só verificar o StealthAudit', $challenge->body );
			self::assertStringContainsString( 'Os filtros do endereço serão aplicados depois da verificação.', $challenge->body );
			self::assertStringContainsString( 'name="recovery_code"', $challenge->body );

			// Abandoned factor replacement past its pending window: offline reset only.
			$this->now += 30;
			self::assertSame( 200, $this->call( 'POST', '/authenticate', array(), array( 'code' => $this->code() ) )->get_status() );
			$this->now += 30;
			self::assertSame( 200, $this->call( 'POST', '/replace-factor', array(), array( 'password' => self::PASSWORD, 'code' => $this->code() ) )->get_status() );
			$this->now += 601;
			$locked = $panel->respond( 'GET', array() );
			$this->assert_page( $locked, 'recovery_locked', array( 'locked' ) );
			self::assertStringNotContainsString( 'data-sa-action', $locked->body );
		} finally {
			remove_filter( 'pre_option_blogname', $hostile );
		}
	}

	public function test_lost_replacement_qr_is_redisplayed_after_reconfirming_password(): void {
		$this->enroll();
		$this->now += 30;
		self::assertSame( 'enrollment_pending', $this->call( 'POST', '/replace-factor', array(), array( 'password' => self::PASSWORD, 'code' => $this->code() ) )->get_data()['status'] );
		// The operator reloads after the password window (5 min) but inside the pending window (10 min).
		$this->now += 301;
		$page = $this->panel()->respond( 'GET', array() );
		$this->assert_page( $page, 'enrollment_pending', array( 'setup' ) );
		self::assertMatchesRegularExpression( '/<div data-sa-redisplay>.*data-sa-action="redisplay".*name="password".*<\/div>/s', $page->body );
		self::assertStringContainsString( 'Não recarregue nem saia desta página antes de confirmar.', $page->body );
		// Without the password the QR stays closed; reconfirm keeps the pending factor and reopens it.
		$this->assert_denied( $this->call( 'POST', '/enrollment/begin', array(), array() ), 403, 'stealthaudit_forbidden' );
		self::assertSame( 'enrollment_pending', $this->call( 'POST', '/reconfirm', array(), array( 'password' => self::PASSWORD ) )->get_data()['status'] );
		$again = $this->call( 'POST', '/enrollment/begin', array(), array() );
		self::assertSame( 200, $again->get_status() );
		self::assertStringContainsString( '<svg', $again->get_data()['qr'] );
		$this->now += 30;
		$confirmed = $this->call( 'POST', '/enrollment/confirm', array(), array( 'code' => $this->code() ) )->get_data();
		self::assertSame( 'authorized', $confirmed['status'] );
		self::assertCount( 10, $confirmed['recovery_codes'] );
		self::assertSame( 200, $this->call( 'GET', '/events' )->get_status() );
		$this->assert_private_headers();
	}

	public function test_unknown_status_renders_a_neutral_closed_screen(): void {
		$view = array(
			'status'     => 'something_new',
			'panel'      => $this->urls->panel(),
			'rest'       => $this->urls->rest(),
			'nonce'      => 'n',
			'rest_nonce' => 'r',
			'login'      => wp_login_url(),
			'query'      => array(),
			'view'       => 'audit',
			'filters'    => array(),
			'cursor'     => null,
			'invalid'    => false,
			'tabs'       => array( 'audit' => $this->urls->panel(), 'errors' => $this->urls->panel() ),
			'action'     => home_url( '/' ),
			'hidden'     => array(),
			'assets'     => array_combine( array_keys( Assets::FILES ), array_map( array( $this->urls, 'asset' ), array_keys( Assets::FILES ) ) ),
			'site'       => 'site',
			'host'       => 'host',
		);
		ob_start();
		( static function ( string $stealthaudit_template, array $stealthaudit_view ): void {
			require $stealthaudit_template;
		} )( dirname( __DIR__, 2 ) . '/templates/panel.php', $view );
		$body = (string) ob_get_clean();
		self::assertStringContainsString( 'data-status="unknown"', $body );
		self::assertStringContainsString( 'Estado desconhecido', $body );
		self::assertStringNotContainsString( 'Recuperação bloqueada', $body );
		self::assertStringNotContainsString( 'data-sa-action', $body );
		self::assertStringNotContainsString( 'data-sa-records', $body );
	}

	public function test_assets_are_local_private_and_only_for_the_operator(): void {
		$directory = dirname( __DIR__, 2 ) . '/assets';
		$assets = new Assets( $this->config, $directory );
		foreach ( Assets::FILES as $file => $type ) {
			$response = $assets->respond( 'GET', array( 'file' => $file ) );
			self::assertSame( 200, $response->status, $file );
			self::assertSame( $type, $response->headers['Content-Type'] );
			self::assertSame( 'nosniff', $response->headers['X-Content-Type-Options'] );
			self::assertStringContainsString( 'no-store', $response->headers['Cache-Control'] );
			self::assertSame( 'same-origin', $response->headers['Cross-Origin-Resource-Policy'] );
			self::assertSame( file_get_contents( $directory . '/' . $file ), $response->body );
			// Nothing remote: no URL scheme, protocol-relative import or font host.
			self::assertDoesNotMatchRegularExpression( '#https?://|url\(\s*["\']?//|@import|googleapis#i', $response->body, $file );
			self::assertStringStartsWith( 'http', $this->urls->asset( $file ) );
			self::assertSame( wp_parse_url( home_url(), PHP_URL_HOST ), wp_parse_url( $this->urls->asset( $file ), PHP_URL_HOST ) );
		}
		self::assertMatchesRegularExpression( '/^\/\* Hallmark · shape: app screen/', (string) file_get_contents( $directory . '/panel.css' ) );
		foreach ( array( array( 'file' => '../composer.json' ), array( 'file' => 'panel.php' ), array( 'file' => array( 'panel.css' ) ), array() ) as $query ) {
			self::assertSame( 404, $assets->respond( 'GET', $query )->status );
		}
		self::assertSame( 405, $assets->respond( 'POST', array( 'file' => 'panel.css' ) )->status );
		$_SERVER['HTTPS'] = 'off';
		self::assertSame( 404, $assets->respond( 'GET', array( 'file' => 'panel.css' ) )->status );
		$_SERVER['HTTPS'] = 'on';
		$cookie = $_COOKIE[ LOGGED_IN_COOKIE ];
		$this->login( 'sa_http_other_' );
		self::assertSame( 404, $assets->respond( 'GET', array( 'file' => 'panel.css' ) )->status );
		wp_set_current_user( 0 );
		unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
		$anonymous = $assets->respond( 'GET', array( 'file' => 'panel.js' ) );
		self::assertSame( 404, $anonymous->status );
		self::assertSame( '', $anonymous->body );
		wp_set_current_user( $this->user );
		$_COOKIE[ LOGGED_IN_COOKIE ] = $cookie;
		WP_Session_Tokens::get_instance( $this->user )->destroy( $this->token );
		self::assertSame( 404, $assets->respond( 'GET', array( 'file' => 'panel.css' ) )->status );
	}

	public function test_unavailable_panel_page_is_styled_for_the_operator_only(): void {
		$unavailable = $this->panel( static function (): PanelAccess {
			throw new RuntimeException( 'Panel unavailable.' );
		} )->respond( 'GET', array() );
		self::assertSame( 503, $unavailable->status );
		self::assertStringContainsString( esc_url( $this->urls->asset( 'panel.css' ) ), $unavailable->body );
		self::assertStringNotContainsString( 'data-nonce', $unavailable->body );
		$this->login( 'sa_http_other_' );
		$forbidden = $this->panel()->respond( 'GET', array() );
		self::assertSame( 403, $forbidden->status );
		self::assertStringNotContainsString( 'stylesheet', $forbidden->body );
	}

	public function test_exhausted_attempts_answer_try_later_and_still_deny(): void {
		$this->event( $this->now - 5 );
		for ( $i = 0; $i < 10; ++$i ) {
			$this->assert_denied( $this->call( 'POST', '/reconfirm', array(), array( 'password' => 'wrong-password' ) ), 403, 'stealthaudit_forbidden' );
		}
		// Even the right password is refused while the window is full; no counters or timing leak.
		$limited = $this->call( 'POST', '/reconfirm', array(), array( 'password' => self::PASSWORD ) );
		$this->assert_denied( $limited, 429, 'stealthaudit_try_later' );
		self::assertSame( array( 'status' => 429 ), $limited->get_data()['data'] );
		self::assertArrayNotHasKey( 'Retry-After', $limited->get_headers() );
		self::assertSame( 'password_required', $this->call( 'GET', '/status' )->get_data()['status'] );
		$this->assert_denied( $this->call( 'GET', '/events' ), 401, 'stealthaudit_proof_required' );
		// The window expires; the state machine continues normally.
		$this->now += 300;
		self::assertSame( 'enrollment_required', $this->call( 'POST', '/reconfirm', array(), array( 'password' => self::PASSWORD ) )->get_data()['status'] );
		$this->assert_private_headers();
	}

	/**
	 * Exactly the expected screens are visible; nothing secret is in the HTML.
	 *
	 * @param list<string> $visible Visible data-sa-screen keys.
	 */
	private function assert_page( \StealthAudit\Http\Response $page, string $status, array $visible ): void {
		self::assertSame( 200, $page->status );
		self::assertStringContainsString( 'data-status="' . $status . '"', $page->body );
		preg_match_all( '/<section[^>]*data-sa-screen="([a-z]+)"[^>]*>/', $page->body, $sections, PREG_SET_ORDER );
		$shown = array();
		foreach ( $sections as $section ) {
			if ( ! preg_match( '/\shidden[\s>]/', $section[0] ) ) {
				$shown[] = $section[1];
			}
		}
		self::assertSame( $visible, $shown, $status );
		foreach ( array( '<svg', 'otpauth', 'secret=', 'data:image', 'recovery_codes' ) as $secret ) {
			self::assertStringNotContainsString( $secret, $page->body );
		}
		// Every resource and link is same-origin; no CDN, font host or inline script.
		preg_match_all( '/(?:src|href|action)="([^"]*)"/', $page->body, $urls );
		foreach ( $urls[1] as $url ) {
			$host = wp_parse_url( html_entity_decode( $url ), PHP_URL_HOST );
			self::assertTrue( null === $host || wp_parse_url( home_url(), PHP_URL_HOST ) === $host, $url );
		}
		preg_match_all( '/<script\b([^>]*)>/', $page->body, $scripts );
		foreach ( $scripts[1] as $attributes ) {
			self::assertMatchesRegularExpression( '/src="|type="application\/json"/', $attributes );
		}
		// Forms that carry credentials are POST + JSON-only and disabled until the script runs.
		preg_match_all( '/<form\b[^>]*>.*?<\/form>/s', $page->body, $forms );
		foreach ( $forms[0] as $form ) {
			if ( str_contains( $form, 'data-sa-action' ) ) {
				self::assertStringContainsString( 'method="post"', $form );
				self::assertStringContainsString( '<fieldset disabled data-sa-js>', $form );
				self::assertDoesNotMatchRegularExpression( '/\saction="/', substr( $form, 0, (int) strpos( $form, '>' ) ) );
			} else {
				self::assertStringNotContainsString( 'type="password"', $form );
			}
		}
		self::assertStringContainsString( "script-src 'self'", $page->headers['Content-Security-Policy'] );
		self::assertStringContainsString( 'no-store', $page->headers['Cache-Control'] );
		self::assertSame( 1, substr_count( $page->body, 'role="status" aria-live="polite" data-sa-live' ) );
	}

	private function panel_access(): PanelAccess {
		if ( null !== $this->access ) {
			return ( $this->access )();
		}
		return new PanelAccess( $this->config, $this->storage->state, $this->cipher, new TotpProvider(), new WordPressSession(), new Context(), fn (): int => $this->now );
	}

	private function panel( ?Closure $access = null ): Panel {
		return new Panel( $this->config, $this->urls, $access ?? fn (): PanelAccess => $this->panel_access(), dirname( __DIR__, 2 ) . '/templates/panel.php' );
	}

	private function login( string $prefix ): int {
		$user = wp_insert_user( array( 'user_login' => $prefix . bin2hex( random_bytes( 6 ) ), 'user_pass' => self::PASSWORD, 'role' => 'subscriber' ) );
		self::assertIsInt( $user );
		$token = WP_Session_Tokens::get_instance( $user )->create( time() + DAY_IN_SECONDS );
		wp_set_current_user( $user );
		$_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $user, time() + DAY_IN_SECONDS, 'logged_in', $token );
		if ( ! isset( $this->token ) ) {
			$this->token = $token;
		}
		return $user;
	}

	private function request( string $method, string $route, array $query = array(), ?array $body = null, array $headers = array() ): WP_REST_Request {
		$_SERVER['REQUEST_METHOD'] = $method;
		$request = new WP_REST_Request( $method, '/' . Urls::NAMESPACE . $route );
		$request->set_query_params( $query );
		$headers += array( 'X-StealthAudit-Nonce' => $this->nonce );
		if ( null !== $body ) {
			$headers += array( 'Content-Type' => 'application/json' );
			$request->set_body( array() === $body ? '' : wp_json_encode( $body ) );
		}
		foreach ( $headers as $name => $value ) {
			$request->set_header( $name, $value );
		}
		return $request;
	}

	private function dispatch( WP_REST_Request $request ): WP_REST_Response {
		$server = rest_get_server();
		// Same filter chain as WP_REST_Server::serve_request() after dispatch.
		$response = apply_filters( 'rest_post_dispatch', rest_ensure_response( $server->dispatch( $request ) ), $server, $request );
		$this->responses[] = $response;
		return $response;
	}

	private function call( string $method, string $route, array $query = array(), ?array $body = null, array $headers = array() ): WP_REST_Response {
		return $this->dispatch( $this->request( $method, $route, $query, $body, $headers ) );
	}

	private function assert_denied( WP_REST_Response $response, int $status, string $code ): void {
		self::assertSame( $status, $response->get_status() );
		$data = $response->get_data();
		self::assertSame( $code, $data['code'] );
		self::assertSame( array( 'code', 'message', 'data' ), array_keys( $data ) );
		self::assertArrayNotHasKey( 'items', $data );
		self::assertStringNotContainsString( 'synthetic', wp_json_encode( $data ) );
	}

	private function assert_private_headers(): void {
		self::assertNotSame( array(), $this->responses );
		foreach ( $this->responses as $response ) {
			$headers = $response->get_headers();
			self::assertStringContainsString( 'no-store', $headers['Cache-Control'] ?? '' );
			self::assertSame( 'no-cache', $headers['Pragma'] ?? '' );
			self::assertSame( 'nosniff', $headers['X-Content-Type-Options'] ?? '' );
		}
	}

	private function enroll(): void {
		self::assertSame( 'enrollment_required', $this->call( 'POST', '/reconfirm', array(), array( 'password' => self::PASSWORD ) )->get_data()['status'] );
		$setup = $this->call( 'POST', '/enrollment/begin', array(), array() );
		self::assertSame( 200, $setup->get_status() );
		self::assertStringContainsString( '<svg', $setup->get_data()['qr'] );
		self::assertSame( 'enrollment_pending', $setup->get_data()['status'] );
		$this->assert_denied( $this->call( 'GET', '/events' ), 401, 'stealthaudit_proof_required' );
		$confirmed = $this->call( 'POST', '/enrollment/confirm', array(), array( 'code' => $this->code() ) )->get_data();
		self::assertCount( 10, $confirmed['recovery_codes'] );
		self::assertSame( 'authorized', $confirmed['status'] );
	}

	private function code(): string {
		$factor = $this->storage->state->get( 'factor:' . $this->user, $this->now )['payload'];
		$secret = $this->cipher->decrypt( $factor['secret'], $this->user );
		$clock = new class() implements Psr\Clock\ClockInterface {
			public function now(): DateTimeImmutable {
				return new DateTimeImmutable();
			}
		};
		return OTPHP\TOTP::createFromSecret( $secret, $clock )->at( $this->now );
	}

	private function event( int $time, array $overrides = array() ): int {
		$record = $overrides + array( 'actor_id' => $this->user, 'category' => 'access', 'action' => 'login', 'result' => 'success', 'origin' => 'http', 'object_type' => 'user', 'object_id' => $this->user, 'ip' => '192.0.2.9' );
		self::assertTrue( $this->storage->events->append( $record, $time ) );
		return (int) $this->db->rows( 'SELECT MAX(id) AS id FROM %i', $this->db->table( 'events' ) )[0]['id'];
	}

	private function budget( string $kind, array $changes ): void {
		$row = $this->storage->state->get( 'budget:' . $kind, $this->now );
		self::assertTrue( $this->storage->state->compare_and_swap( 'budget:' . $kind, $row['revision'], $changes + $row['payload'], null, $this->now ) );
	}

	private function parse( string $uri, array $get = array() ): WP {
		$_SERVER['REQUEST_URI'] = $uri;
		$_SERVER['PHP_SELF'] = '/index.php';
		$_SERVER['REQUEST_METHOD'] = 'GET';
		unset( $_SERVER['PATH_INFO'] );
		$_GET = $get;
		$wp = new WP();
		$wp->parse_request();
		return $wp;
	}
}
