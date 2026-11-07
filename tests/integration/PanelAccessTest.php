<?php
// SPDX-License-Identifier: GPL-3.0-or-later
use StealthAudit\Audit\Context;
use StealthAudit\Config\Configuration;
use StealthAudit\Security\PanelAccess;
use StealthAudit\Security\SecretCipher;
use StealthAudit\Security\TotpProvider;
use StealthAudit\Security\WordPressSession;
use StealthAudit\Security\AttemptLimiter;
use StealthAudit\Storage\Database;
use StealthAudit\Storage\Storage;

/** Real signed WP cookies, live session records and isolated InnoDB security rows. */
final class PanelAccessTest extends PHPUnit\Framework\TestCase {
	private wpdb $connection;
	private Database $db;
	private Storage $storage;
	private Configuration $config;
	private SecretCipher $cipher;
	private PanelAccess $access;
	private int $user;
	private int $now;
	private string $token;
	private string $nonce;
	private string $prefix;
	private array $cookies;
	private array $server;

	protected function setUp(): void {
		$this->cookies = $_COOKIE;
		$this->server = $_SERVER;
		$this->now = time();
		$this->user = wp_insert_user( array( 'user_login' => 'sa_mfa_' . bin2hex( random_bytes( 6 ) ), 'user_pass' => 'synthetic-test-password-only', 'role' => 'subscriber' ) );
		$this->token = WP_Session_Tokens::get_instance( $this->user )->create( time() + DAY_IN_SECONDS );
		wp_set_current_user( $this->user );
		$_COOKIE[LOGGED_IN_COOKIE] = wp_generate_auth_cookie( $this->user, time() + DAY_IN_SECONDS, 'logged_in', $this->token );
		$_SERVER['HTTPS'] = 'on';
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_SERVER['REMOTE_ADDR'] = '192.0.2.10';
		$this->nonce = wp_create_nonce( 'stealthaudit' );
		$this->connection = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$this->prefix = 'sa_mfa_' . bin2hex( random_bytes( 4 ) ) . '_';
		$this->connection->set_prefix( $this->prefix );
		$this->db = new Database( $this->connection );
		global $wpdb;
		$this->db->execute( 'CREATE TABLE %i LIKE %i', $this->connection->options, $wpdb->options );
		$this->config = Configuration::from_array( array( 'authorized_user_ids' => array( $this->user ), 'key_base64' => base64_encode( str_repeat( 'k', 32 ) ), 'key_id' => 'synthetic-test-key', 'installation_id' => 'synthetic-test-installation', 'limits' => array( 'storage_work_ms' => 10000 ) ) );
		$this->storage = new Storage( $this->connection, $this->config );
		self::assertTrue( $this->storage->schema->ensure() );
		$this->cipher = new SecretCipher( str_repeat( 'k', 32 ), 'synthetic-test-key', 'synthetic-test-installation' );
		$this->access = $this->service();
	}

	protected function tearDown(): void {
		foreach ( array( 'events', 'errors', 'state' ) as $kind ) {
			$this->db->execute( 'DROP TABLE IF EXISTS %i', $this->db->table( $kind ) );
		}
		$this->db->execute( 'DROP TABLE IF EXISTS %i', $this->connection->options );
		$this->connection->close();
		WP_Session_Tokens::get_instance( $this->user )->destroy_all();
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $this->user );
		wp_set_current_user( 0 );
		$_COOKIE = $this->cookies;
		$_SERVER = $this->server;
	}

	private function service( ?Configuration $config = null, ?SecretCipher $cipher = null ): PanelAccess {
		return new PanelAccess( $config ?? $this->config, $this->storage->state, $cipher ?? $this->cipher, new TotpProvider(), new WordPressSession(), new Context(), fn(): int => $this->now );
	}

	private function code( ?int $time = null ): string {
		$factor = $this->storage->state->get( 'factor:' . $this->user, $this->now )['payload'];
		$secret = $this->cipher->decrypt( $factor['secret'], $this->user );
		$clock = new class implements Psr\Clock\ClockInterface { public function now(): DateTimeImmutable { return new DateTimeImmutable(); } };
		return OTPHP\TOTP::createFromSecret( $secret, $clock )->at( $time ?? $this->now );
	}

	private function enroll(): array {
		$this->access->reconfirm( 'synthetic-test-password-only', $this->nonce );
		$setup = $this->access->begin_enrollment( $this->nonce );
		self::assertStringContainsString( '<svg', $setup['qr'] );
		self::assertFalse( $this->access->authorized() );
		return $this->access->confirm_enrollment( $this->code(), $this->nonce );
	}

	private function denied( Closure $operation ): void {
		try { $operation(); self::fail( 'Expected access denial.' ); } catch ( RuntimeException $failure ) { self::assertSame( 'Panel authentication denied.', $failure->getMessage() ); }
	}

	public function test_real_cookie_enrollment_and_atomic_replay(): void {
		self::assertSame( 'password_required', $this->access->status() );
		self::assertFalse( $this->access->authorized() );
		$codes = $this->enroll();
		self::assertCount( 10, array_unique( $codes ) );
		self::assertSame( 'authorized', $this->access->status() );
		self::assertTrue( $this->access->authorized() );
		$this->denied( fn() => $this->access->authenticate( $this->code(), $this->nonce ) );
		$this->now += 30;
		$this->access->authenticate( $this->code(), $this->nonce );
		self::assertTrue( $this->access->authorized() );
		$row = $this->storage->state->get( 'factor:' . $this->user, $this->now );
		self::assertStringNotContainsString( $codes[0], json_encode( $row ) );
		$raw = $this->db->rows( 'SELECT state_key,payload FROM %i', $this->db->table( 'state' ) );
		self::assertStringNotContainsString( $this->token, json_encode( $raw ) );
		self::assertStringNotContainsString( 'synthetic-test-password-only', json_encode( $raw ) );
	}

	public function test_current_user_nonce_or_application_password_cannot_substitute_cookie(): void {
		$this->enroll();
		$original = $_COOKIE[LOGGED_IN_COOKIE];
		unset( $_COOKIE[LOGGED_IN_COOKIE] );
		self::assertFalse( $this->access->authorized() );
		$this->denied( fn() => $this->access->authenticate( '123456', $this->nonce ) );
		$_COOKIE[LOGGED_IN_COOKIE] = $original . 'tampered';
		self::assertFalse( $this->access->authorized() );
		$_COOKIE[LOGGED_IN_COOKIE] = $original;
		wp_set_current_user( 0 );
		self::assertFalse( $this->access->authorized() );
		wp_set_current_user( $this->user );
		WP_Session_Tokens::get_instance( $this->user )->destroy( $this->token );
		self::assertFalse( $this->access->authorized() );
	}

	public function test_https_allowlist_and_csrf_are_required(): void {
		$this->denied( fn() => $this->access->reconfirm( 'synthetic-test-password-only', 'invalid-nonce' ) );
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$this->denied( fn() => $this->access->reconfirm( 'synthetic-test-password-only', $this->nonce ) );
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_SERVER['HTTPS'] = 'off';
		$this->denied( fn() => $this->access->reconfirm( 'synthetic-test-password-only', $this->nonce ) );
		$_SERVER['HTTPS'] = 'on';
		self::assertFalse( $this->service( Configuration::from_array( array() ) )->authorized() );
		$this->denied( fn() => $this->access->reconfirm( 'wrong-password', $this->nonce ) );
		self::assertFalse( $this->access->authorized() );
	}

	public function test_expired_physical_proof_can_reauthenticate_without_cron(): void {
		$this->enroll();
		$session = ( new WordPressSession() )->current( $this->config );
		$original = $this->storage->state->get( 'proof:' . $session['session'], $this->now );
		$this->now += 1800;
		self::assertSame( 'challenge_required', $this->access->status() );
		self::assertFalse( $this->access->authorized() );
		self::assertNull( $this->storage->state->get( 'proof:' . $session['session'], $this->now ) );
		$this->access->authenticate( $this->code(), $this->nonce );
		self::assertTrue( $this->access->authorized() );
		self::assertFalse( $this->storage->state->compare_and_swap( 'proof:' . $session['session'], $original['revision'], $original['payload'], $this->now + 100, $this->now ) );
	}

	public function test_polling_does_not_extend_idle_and_activity_never_extends_absolute(): void {
		$this->enroll();
		$this->now += 1799;
		self::assertSame( 'authorized', $this->access->status() );
		$this->now += 1;
		self::assertFalse( $this->access->authorized() );
		$this->access->authenticate( $this->code(), $this->nonce );
		for ( $i = 0; $i < 16; ++$i ) {
			$this->now += 1700;
			self::assertTrue( $this->access->authorized() );
		}
		$this->now += 1600;
		self::assertFalse( $this->access->authorized() );
	}

	public function test_recovery_invalidates_old_proofs_and_codes_and_requires_new_totp(): void {
		$codes = $this->enroll();
		$old = $this->storage->state->get( 'factor:' . $this->user, $this->now )['payload'];
		$setup = $this->access->recover( 'synthetic-test-password-only', $codes[0], $this->nonce );
		self::assertStringContainsString( '<svg', $setup['qr'] );
		self::assertFalse( $this->access->authorized() );
		self::assertSame( 'enrollment_pending', $this->access->status() );
		$new = $this->storage->state->get( 'factor:' . $this->user, $this->now )['payload'];
		self::assertNotSame( $old['generation'], $new['generation'] );
		self::assertSame( array(), $new['recovery'] );
		$this->denied( fn() => $this->access->recover( 'synthetic-test-password-only', $codes[0], $this->nonce ) );
		$new_codes = $this->access->confirm_enrollment( $this->code(), $this->nonce );
		self::assertNotSame( $codes, $new_codes );
		self::assertTrue( $this->access->authorized() );
	}

	public function test_active_factor_never_replaced_with_password_only_after_enrollment_expiry(): void {
		$this->enroll();
		$this->now += 601;
		$this->access->reconfirm( 'synthetic-test-password-only', $this->nonce );
		$this->denied( fn() => $this->access->begin_enrollment( $this->nonce ) );
		self::assertFalse( $this->access->authorized() );
		$this->access->replace_factor( 'synthetic-test-password-only', $this->code(), $this->nonce );
		self::assertFalse( $this->access->authorized() );
	}

	public function test_password_confirmation_and_pending_expire_and_pending_is_session_bound(): void {
		$this->access->reconfirm( 'synthetic-test-password-only', $this->nonce );
		$this->now += 300;
		$this->denied( fn() => $this->access->begin_enrollment( $this->nonce ) );
		$this->access->reconfirm( 'synthetic-test-password-only', $this->nonce );
		$this->access->begin_enrollment( $this->nonce );
		$old = $this->storage->state->get( 'factor:' . $this->user, $this->now )['payload'];
		$this->now += 600;
		$this->denied( fn() => $this->access->confirm_enrollment( $this->code(), $this->nonce ) );
		$this->access->reconfirm( 'synthetic-test-password-only', $this->nonce );
		$this->access->begin_enrollment( $this->nonce );
		self::assertNotSame( $old['generation'], $this->storage->state->get( 'factor:' . $this->user, $this->now )['payload']['generation'] );
		$second = WP_Session_Tokens::get_instance( $this->user )->create( time() + DAY_IN_SECONDS );
		$_COOKIE[LOGGED_IN_COOKIE] = wp_generate_auth_cookie( $this->user, time() + DAY_IN_SECONDS, 'logged_in', $second );
		$this->nonce = wp_create_nonce( 'stealthaudit' );
		$this->denied( fn() => $this->access->confirm_enrollment( $this->code(), $this->nonce ) );
		self::assertFalse( $this->access->authorized() );
	}

	public function test_persistent_account_rate_window_resets_expired_row_without_cron(): void {
		$limiter = new AttemptLimiter( $this->storage->state );
		for ( $i = 0; $i < 10; ++$i ) { $limiter->consume( $this->user, '192.0.2.10', $this->now ); }
		try { $limiter->consume( $this->user, '192.0.2.10', $this->now ); self::fail(); } catch ( RuntimeException $failure ) { self::assertSame( 'Panel authentication unavailable.', $failure->getMessage() ); }
		$limiter->consume( $this->user, '192.0.2.10', $this->now + 300 );
		self::assertSame( 1, $this->storage->state->get( 'limit:' . hash( 'sha256', 'mfa:account:' . $this->user ), $this->now + 300 )['payload']['count'] );
	}

	public function test_wrong_key_corrupt_state_and_backend_failure_deny_existing_proof(): void {
		$this->enroll();
		self::assertFalse( $this->service( null, new SecretCipher( str_repeat( 'x', 32 ), 'synthetic-test-key', 'synthetic-test-installation' ) )->authorized() );
		$key = 'factor:' . $this->user;
		$this->db->execute( 'UPDATE %i SET payload=%s WHERE state_key=%s', $this->db->table( 'state' ), '{}', $key );
		self::assertFalse( $this->access->authorized() );
		$this->denied( fn() => $this->access->begin_enrollment( $this->nonce ) );
		$this->db->execute( 'DROP TABLE %i', $this->db->table( 'state' ) );
		self::assertFalse( $this->access->authorized() );
		$this->denied( fn() => $this->access->reconfirm( 'synthetic-test-password-only', $this->nonce ) );
	}

	public function test_two_real_processes_have_one_winner_for_totp_and_recovery(): void {
		$codes = $this->enroll();
		$this->now += 30;
		self::assertSame( array( 0, 1 ), $this->workers( 'totp', $this->code() ) );
		self::assertSame( array( 0, 1 ), $this->workers( 'recovery', $codes[0] ) );
		self::assertFalse( $this->access->authorized() );
		self::assertSame( 'enrollment_pending', $this->access->status() );
	}

	public function test_sensitive_parameters_are_absent_from_exception_traces(): void {
		$previous = ini_set( 'zend.exception_ignore_args', '0' );
		try {
			try { $this->access->recover( 'secret-password-sentinel', 'secret-recovery-sentinel', $this->nonce ); self::fail(); }
			catch ( RuntimeException $failure ) {
				// PHPUnit caller frames include this test object's own credentials; inspect service frames only.
				$frames = array_filter( $failure->getTrace(), static fn( array $frame ): bool => ( $frame['class'] ?? '' ) === PanelAccess::class );
				$trace = print_r( $frames, true );
				self::assertFalse( str_contains( $trace, 'secret-password-sentinel' ) );
				self::assertFalse( str_contains( $trace, 'secret-recovery-sentinel' ) );
				self::assertFalse( str_contains( $trace, $this->nonce ) );
				self::assertFalse( str_contains( $trace, $this->token ) );
			}
		} finally { ini_set( 'zend.exception_ignore_args', $previous ); }
	}

	/** @dataProvider abandonedReplacementModes */
	public function test_abandoned_replacement_or_recovery_never_reopens_password_only_enrollment( string $mode ): void {
		$codes = $this->enroll();
		if ( 'recovery' === $mode ) {
			$this->access->recover( 'synthetic-test-password-only', $codes[0], $this->nonce );
		} else {
			$this->now += 30;
			$this->access->replace_factor( 'synthetic-test-password-only', $this->code(), $this->nonce );
		}
		$this->now += 600;
		self::assertSame( 'recovery_locked', $this->access->status() );
		$this->access->reconfirm( 'synthetic-test-password-only', $this->nonce );
		$this->denied( fn() => $this->access->begin_enrollment( $this->nonce ) );
		$second = WP_Session_Tokens::get_instance( $this->user )->create( time() + DAY_IN_SECONDS );
		$_COOKIE[LOGGED_IN_COOKIE] = wp_generate_auth_cookie( $this->user, time() + DAY_IN_SECONDS, 'logged_in', $second );
		$this->nonce = wp_create_nonce( 'stealthaudit' );
		$this->access->reconfirm( 'synthetic-test-password-only', $this->nonce );
		$this->denied( fn() => $this->access->begin_enrollment( $this->nonce ) );
		self::assertFalse( $this->access->authorized() );
	}

	public static function abandonedReplacementModes(): array { return array( array( 'recovery' ), array( 'replacement' ) ); }

	public function test_offline_rotation_verification_and_controlled_reset(): void {
		$this->enroll();
		$offline = Configuration::from_array( array( 'authorized_user_ids' => array(), 'key_base64' => base64_encode( str_repeat( 'k', 32 ) ), 'key_id' => 'synthetic-test-key', 'installation_id' => 'synthetic-test-installation' ) );
		$operator = new StealthAudit\Security\FactorMaintenance( $offline, $this->storage->state, $this->cipher );
		$replacement = new SecretCipher( str_repeat( 'n', 32 ), 'synthetic-next-key', 'synthetic-test-installation' );
		self::assertTrue( $operator->rotate( $this->user, $replacement, $this->now ) );
		self::assertFalse( $operator->rotate( $this->user, $replacement, $this->now ) );
		self::assertFalse( $this->access->authorized() );
		$config_new = Configuration::from_array( array( 'authorized_user_ids' => array( $this->user ), 'key_base64' => base64_encode( str_repeat( 'n', 32 ) ), 'key_id' => 'synthetic-next-key', 'installation_id' => 'synthetic-test-installation' ) );
		$service = $this->service( $config_new, $replacement );
		self::assertSame( 'challenge_required', $service->status() );
		$new_offline = Configuration::from_array( array( 'key_base64' => base64_encode( str_repeat( 'n', 32 ) ), 'key_id' => 'synthetic-next-key', 'installation_id' => 'synthetic-test-installation' ) );
		$operator = new StealthAudit\Security\FactorMaintenance( $new_offline, $this->storage->state, $replacement );
		$operator->reset( $this->user, $this->now );
		self::assertFalse( WP_Session_Tokens::get_instance( $this->user )->verify( $this->token ) );
		self::assertFalse( $service->authorized() );
		$row = $this->storage->state->get( 'factor:' . $this->user, $this->now );
		self::assertFalse( $row['payload']['active'] );
		self::assertSame( array(), $row['payload']['recovery'] );
		self::assertSame( 'denied', $service->status() );
		$fresh = WP_Session_Tokens::get_instance( $this->user )->create( time() + DAY_IN_SECONDS );
		$_COOKIE[LOGGED_IN_COOKIE] = wp_generate_auth_cookie( $this->user, time() + DAY_IN_SECONDS, 'logged_in', $fresh );
		$this->nonce = wp_create_nonce( 'stealthaudit' );
		self::assertSame( 'password_required', $service->status() );
		$this->denied( fn() => $service->begin_enrollment( $this->nonce ) );
		$service->reconfirm( 'synthetic-test-password-only', $this->nonce );
		self::assertSame( 'enrollment_required', $service->status() );
		$service->begin_enrollment( $this->nonce );
		self::assertSame( 'enrollment_pending', $service->status() );
		self::assertFalse( $service->authorized() );
		$this->cipher = $replacement;
		self::assertCount( 10, $service->confirm_enrollment( $this->code(), $this->nonce ) );
		self::assertTrue( $service->authorized() );
	}

	public function test_new_browser_session_has_no_proof_and_revocation_denies_current_session(): void {
		$this->enroll();
		$old_cookie = $_COOKIE[LOGGED_IN_COOKIE];
		$second = WP_Session_Tokens::get_instance( $this->user )->create( time() + DAY_IN_SECONDS );
		$_COOKIE[LOGGED_IN_COOKIE] = wp_generate_auth_cookie( $this->user, time() + DAY_IN_SECONDS, 'logged_in', $second );
		self::assertFalse( $this->access->authorized() );
		$_COOKIE[LOGGED_IN_COOKIE] = $old_cookie;
		$this->access->revoke( $this->nonce );
		self::assertFalse( $this->access->authorized() );
		self::assertTrue( WP_Session_Tokens::get_instance( $this->user )->verify( $this->token ) );
		$this->now += 30;
		$this->access->authenticate( $this->code(), $this->nonce );
		self::assertTrue( $this->access->authorized() );
	}

	public function test_recorder_writes_mfa_results_without_credentials(): void {
		$this->access = new PanelAccess( $this->config, $this->storage->state, $this->cipher, new TotpProvider(), new WordPressSession(), new Context(), fn(): int => $this->now, new StealthAudit\Audit\Recorder( fn() => $this->storage->events, new Context() ) );
		$this->denied( fn() => $this->access->reconfirm( 'wrong-password-sentinel', $this->nonce ) );
		$codes = $this->enroll();
		$this->now += 30;
		$otp = $this->code();
		$this->access->authenticate( $otp, $this->nonce );
		$this->denied( fn() => $this->access->authenticate( $otp, $this->nonce ) );
		$this->denied( fn() => $this->access->recover( 'synthetic-test-password-only', str_repeat( 'f', 32 ), $this->nonce ) );
		$this->access->revoke( $this->nonce );
		$rows = $this->db->rows( 'SELECT * FROM %i ORDER BY id', $this->db->table( 'events' ) );
		$seen = array_map( static fn( array $row ): string => $row['action'] . ':' . $row['result'], $rows );
		self::assertSame( array( 'mfa_password:failure', 'mfa_password:success', 'mfa_enrollment:success', 'mfa_enrolled:success', 'mfa_access:success', 'mfa_access:failure', 'mfa_recovery:failure', 'mfa_revoked:success' ), $seen );
		foreach ( $rows as $row ) {
			self::assertSame( 'access', $row['category'] );
			self::assertSame( (string) $this->user, $row['actor_id'] );
			self::assertSame( '192.0.2.10', $row['ip'] );
			self::assertSame( '[]', $row['summary'] );
		}
		$raw = json_encode( $rows );
		$session = ( new WordPressSession() )->current( $this->config )['session'];
		foreach ( array_merge( array( 'synthetic-test-password-only', 'wrong-password-sentinel', $otp, $this->token, $session, $this->nonce, str_repeat( 'f', 32 ) ), $codes ) as $secret ) {
			self::assertStringNotContainsString( $secret, $raw );
		}
	}

	public function test_lost_activity_cas_rereads_proof_once_and_grants_only_if_still_valid(): void {
		$this->enroll();
		$session = ( new WordPressSession() )->current( $this->config )['session'];
		$key = 'proof:' . $session;
		$this->now += 10;
		// A concurrent query of the same session touches the proof between read and CAS.
		self::assertTrue( $this->with_concurrent_write( $key, fn( array $p ): array => array( 'last_activity' => $this->now ) + $p, fn() => $this->access->authorized() ) );
		self::assertSame( $this->now, $this->storage->state->get( $key, $this->now )['payload']['last_activity'] );
		$this->now += 10;
		// A concurrent revocation wins: the re-read sees no valid proof.
		self::assertFalse( $this->with_concurrent_write( $key, static fn( array $p ): array => array( 'generation' => str_repeat( '0', 64 ), 'authenticated_at' => 0 ) + $p, fn() => $this->access->authorized() ) );
		self::assertFalse( $this->access->authorized() );
		$this->now += 30;
		$this->access->authenticate( $this->code(), $this->nonce );
		$this->now += 10;
		// A concurrent factor replacement changes generation: never granted after the conflict.
		$factor = 'factor:' . $this->user;
		$swap = function () use ( $factor ): void {
			$row = $this->storage->state->get( $factor, $this->now );
			self::assertTrue( $this->storage->state->compare_and_swap( $factor, $row['revision'], array( 'generation' => str_repeat( 'e', 64 ) ) + $row['payload'], null, $this->now ) );
		};
		self::assertFalse( $this->with_concurrent_write( $key, fn( array $p ): array => array( 'last_activity' => $this->now ) + $p, fn() => $this->access->authorized(), $swap ) );
		self::assertFalse( $this->access->authorized() );
	}

	/**
	 * Run $operation while a 'query' filter applies one competing CAS just before the
	 * operation's first proof UPDATE reaches the database.
	 */
	private function with_concurrent_write( string $key, Closure $change, Closure $operation, ?Closure $also = null ): bool {
		$fired = false;
		$filter = function ( string $query ) use ( &$fired, $key, $change, $also ): string {
			if ( ! $fired && str_starts_with( $query, 'UPDATE' ) && str_contains( $query, "'" . $key . "'" ) ) {
				$fired = true;
				$row = $this->storage->state->get( $key, $this->now );
				self::assertTrue( $this->storage->state->compare_and_swap( $key, $row['revision'], $change( $row['payload'] ), $this->now + 600, $this->now ) );
				if ( null !== $also ) {
					$also();
				}
			}
			return $query;
		};
		add_filter( 'query', $filter );
		try {
			$result = $operation();
		} finally {
			remove_filter( 'query', $filter );
		}
		self::assertTrue( $fired );
		return $result;
	}

	private function workers( string $mode, string $code ): array {
		global $wpdb;
		$children = array();
		for ( $i = 0; $i < 2; ++$i ) {
			$process = proc_open( array( PHP_BINARY, dirname( __DIR__ ) . '/fixtures/security-worker.php' ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
			self::assertIsResource( $process );
			fwrite( $pipes[0], json_encode( array( 'db_user' => DB_USER, 'db_password' => DB_PASSWORD, 'db_name' => DB_NAME, 'db_host' => DB_HOST, 'wp_prefix' => $wpdb->prefix, 'prefix' => $this->prefix, 'uid' => $this->user, 'token' => $this->token, 'now' => $this->now, 'mode' => $mode, 'code' => $code ) ) . "\n" );
			stream_set_timeout( $pipes[1], 30 );
			self::assertSame( "ready\n", fgets( $pipes[1] ) );
			$children[] = array( $process, $pipes );
		}
		foreach ( $children as [ $process, $pipes ] ) { fwrite( $pipes[0], "go\n" ); fclose( $pipes[0] ); }
		$results = array();
		foreach ( $children as [ $process, $pipes ] ) {
			$results[] = (int) trim( stream_get_contents( $pipes[1] ) );
			$error = stream_get_contents( $pipes[2] );
			fclose( $pipes[1] ); fclose( $pipes[2] );
			self::assertSame( 0, proc_close( $process ), $error );
			self::assertSame( '', $error );
		}
		sort( $results );
		return $results;
	}
}
