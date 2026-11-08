<?php
// SPDX-License-Identifier: GPL-3.0-or-later
declare(strict_types=1);

use StealthAudit\Audit\Collectors;
use StealthAudit\Audit\Context;
use StealthAudit\Audit\Recorder;
use StealthAudit\Audit\Upgrades;
use StealthAudit\Config\Configuration;
use StealthAudit\Storage\Database;
use StealthAudit\Storage\Storage;

/** Real WP APIs and InnoDB; updater returns are synthetic to avoid changing core. */
final class AuditTest extends WP_UnitTestCase {
	private wpdb $connection;
	private Database $db;
	private Storage $storage;
	private Collectors $collectors;
	private Upgrades $upgrades;
	private Recorder $recorder;
	private int $actor;

	public function set_up(): void {
		parent::set_up();
		$this->actor = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->actor );
		$this->connection = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$this->connection->set_prefix( 'sa_audit_' . bin2hex( random_bytes( 4 ) ) . '_' );
		$this->db = new Database( $this->connection );
		global $wpdb;
		// WP's per-test CREATE TEMPORARY TABLE filter hides DDL from the isolated writer. Remove it
		// before the options copy too: MySQL (unlike MariaDB) cannot reopen a TEMPORARY table, so
		// the schema's INSERT ... SELECT lease publish on the same table would fail there.
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		$this->db->execute( 'CREATE TABLE %i LIKE %i', $this->connection->options, $wpdb->options );
		$config = Configuration::from_array( array( 'limits' => array( 'storage_work_ms' => 10000, 'events_per_request' => 200, 'events_per_second' => 200 ) ) );
		$this->storage = new Storage( $this->connection, $config );
		$original = $wpdb;
		try {
			$wpdb = $this->connection;
			self::assertTrue( $this->storage->schema->ensure() );
		} finally {
			$wpdb = $original;
		}
		$this->recorder = new Recorder( fn () => $this->storage->events, new Context() );
		$this->collectors = new Collectors( $this->recorder );
		$this->upgrades = new Upgrades( $this->recorder );
		$this->collectors->register();
		$this->upgrades->register();
	}

	public function tear_down(): void {
		$this->collectors->unregister();
		$this->upgrades->unregister();
		wp_set_current_user( 0 );
		foreach ( array( 'events', 'errors', 'state' ) as $kind ) {
			$this->db->execute( 'DROP TABLE IF EXISTS %i', $this->db->table( $kind ) );
		}
		$this->db->execute( 'DROP TABLE IF EXISTS %i', $this->connection->options );
		$this->connection->close();
		parent::tear_down();
	}

	private function events( array $filters = array() ): array {
		return array_reverse( $this->storage->events->page( $filters, 100, 0, time() ) );
	}

	public function test_access_users_and_deferred_context_do_not_store_credentials(): void {
		$user = wp_insert_user( array( 'user_login' => 'audit-synthetic', 'user_pass' => 'DO-NOT-STORE-password', 'role' => 'subscriber', 'description' => 'DO-NOT-STORE-profile' ) );
		self::assertIsInt( $user );
		$result = wp_signon( array( 'user_login' => 'audit-synthetic', 'user_password' => 'DO-NOT-STORE-password', 'remember' => false ), false );
		self::assertInstanceOf( WP_User::class, $result );
		self::assertWPError( wp_signon( array( 'user_login' => 'DO-NOT-STORE-attacker', 'user_password' => 'DO-NOT-STORE-token' ), false ) );
		wp_set_current_user( $this->actor );
		$target = new WP_User( $user );
		$target->set_role( 'editor' );
		wp_set_current_user( $user );
		$target->add_cap( 'audit_custom' );
		wp_update_user( array( 'ID' => $user, 'description' => 'DO-NOT-STORE-new-profile' ) );
		wp_logout();
		$this->collectors->flush_permissions();
		$permissions = array_values( array_filter( $this->events( array( 'category' => 'users' ) ), fn ( $row ) => in_array( $row['action'], array( 'role_changed', 'capability_changed' ), true ) ) );
		self::assertCount( 3, $permissions );
		self::assertSame( (string) $this->actor, $permissions[0]['actor_id'] );
		self::assertSame( (string) $this->actor, $permissions[1]['actor_id'] );
		self::assertSame( (string) $user, $permissions[2]['actor_id'] );
		$failed = $this->events( array( 'action' => 'login_failed' ) );
		self::assertNull( $failed[0]['actor_id'] );
		$logout = $this->events( array( 'action' => 'logout' ) );
		self::assertSame( (string) $user, $logout[0]['actor_id'] );
		require_once ABSPATH . 'wp-admin/includes/user.php';
		self::assertTrue( wp_delete_user( $user ) );
		self::assertCount( 1, $this->events( array( 'action' => 'deleted', 'category' => 'users' ) ) );
		self::assertStringNotContainsString( 'DO-NOT-STORE', json_encode( $this->events() ) );
	}

	public function test_content_lifecycle_revision_media_and_trash_are_deduplicated(): void {
		$id = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'DO-NOT-STORE-title', 'post_content' => 'DO-NOT-STORE-body' ) );
		wp_update_post( array( 'ID' => $id, 'post_title' => 'DO-NOT-STORE-update' ) );
		wp_insert_post( array( 'post_type' => 'revision', 'post_status' => 'inherit', 'post_parent' => $id, 'post_content' => 'DO-NOT-STORE-revision' ) );
		wp_trash_post( $id );
		wp_untrash_post( $id );
		wp_delete_post( $id, true );
		$rows = array_values( array_filter( $this->events( array( 'category' => 'content' ) ), fn ( $row ) => (int) $row['object_id'] === $id ) );
		self::assertSame( array( 'created', 'updated', 'trashed', 'restored', 'deleted' ), array_column( $rows, 'action' ) );
		$page = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'DO-NOT-STORE-page' ) );
		self::assertGreaterThan( 0, $page );
		$media = wp_insert_attachment( array( 'post_title' => 'DO-NOT-STORE-media', 'post_status' => 'inherit', 'post_mime_type' => 'image/png' ), false );
		wp_update_post( array( 'ID' => $media, 'post_title' => 'DO-NOT-STORE-media-update' ) );
		wp_update_post( array( 'ID' => $media, 'post_status' => 'trash' ) );
		wp_update_post( array( 'ID' => $media, 'post_status' => 'inherit' ) );
		wp_delete_attachment( $media, true );
		$media_rows = array_values( array_filter( $this->events( array( 'category' => 'content' ) ), fn ( $row ) => (int) $row['object_id'] === $media ) );
		self::assertSame( array( 'created', 'updated', 'trashed', 'restored', 'deleted' ), array_column( $media_rows, 'action' ) );
		self::assertStringNotContainsString( 'DO-NOT-STORE', json_encode( $this->events() ) );
	}

	public function test_administration_real_plugin_theme_and_option_apis(): void {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$directory = WP_PLUGIN_DIR . '/audit-fixture';
		wp_mkdir_p( $directory );
		$file = $directory . '/audit.php';
		file_put_contents( $file, "<?php\n/* Plugin Name: Synthetic Audit Fixture */\n" );
		try {
			self::assertNull( activate_plugin( 'audit-fixture/audit.php' ) );
			deactivate_plugins( 'audit-fixture/audit.php' );
			switch_theme( 'twentytwentyfive' );
			update_option( 'blog_public', '0' );
			update_option( 'audit_unlisted_secret', 'DO-NOT-STORE-token' );
			set_transient( 'audit_secret', 'DO-NOT-STORE-transient' );
			$rows = $this->events( array( 'category' => 'admin' ) );
			self::assertContains( 'activated', array_column( $rows, 'action' ) );
			self::assertContains( 'deactivated', array_column( $rows, 'action' ) );
			self::assertContains( 'theme_switched', array_column( $rows, 'action' ) );
			self::assertContains( 'option_changed', array_column( $rows, 'action' ) );
			self::assertContains( 'audit-fixture/audit.php', array_map( fn ( $row ) => json_decode( $row['summary'], true )['reference'], $rows ) );
			self::assertStringContainsString( 'blog_public', json_encode( $rows ) );
			self::assertStringNotContainsString( 'DO-NOT-STORE', json_encode( $rows ) );
			self::assertStringNotContainsString( 'audit_unlisted_secret', json_encode( $rows ) );
		} finally {
			deactivate_plugins( 'audit-fixture/audit.php', true );
			unlink( $file );
			rmdir( $directory );
		}
	}

	public function test_bulk_partial_results_do_not_inherit_the_final_item_success(): void {
		$upgrader = (object) array( 'bulk' => true, 'result' => array( 'destination' => '/DO-NOT-STORE/last' ) );
		$ok = array( 'destination' => '/DO-NOT-STORE/a', 'destination_name' => 'a' );
		$error = new WP_Error( 'DO-NOT-STORE-code', 'DO-NOT-STORE-message' );
		self::assertSame( $ok, $this->upgrades->package_result( $ok, array( 'plugin' => 'a/a.php' ) ) );
		self::assertSame( $error, $this->upgrades->package_result( $error, array( 'plugin' => 'b/b.php' ) ) );
		$this->upgrades->complete( $upgrader, array( 'type' => 'plugin', 'action' => 'update', 'bulk' => true, 'plugins' => array( 'a/a.php', 'b/b.php', 'c/c.php' ) ) );
		$rows = $this->events( array( 'action' => 'upgraded' ) );
		self::assertSame( array( 'success', 'failure', 'unknown' ), array_column( $rows, 'result' ) );
		self::assertSame( array( 'package', 'package', 'completion' ), array_map( fn ( $row ) => json_decode( $row['summary'], true )['stage'], $rows ) );
		self::assertSame( array( 'a/a.php', 'b/b.php', 'c/c.php' ), array_map( fn ( $row ) => json_decode( $row['summary'], true )['reference'], $rows ) );
		self::assertStringNotContainsString( 'DO-NOT-STORE', json_encode( $rows ) );
	}

	public function test_install_correlation_early_failure_and_core_completion_are_conservative(): void {
		$installer = (object) array( 'bulk' => false );
		$extra = array( 'type' => 'plugin', 'action' => 'install' );
		self::assertFalse( $this->upgrades->attempt( false, 'DO-NOT-STORE-package?token=secret', $installer, $extra ) );
		$result = array( 'destination' => '/DO-NOT-STORE/directory', 'destination_name' => 'new-plugin' );
		$this->upgrades->package_result( $result, $extra );
		$this->upgrades->complete( $installer, $extra );
		self::assertCount( 1, $this->events( array( 'action' => 'installed' ) ) );
		$failure = (object) array( 'bulk' => false, 'result' => new WP_Error( 'download_failed', 'DO-NOT-STORE-error' ) );
		$this->upgrades->attempt( false, 'DO-NOT-STORE-url', $failure, array( 'type' => 'theme', 'action' => 'update', 'theme' => 'broken-theme' ) );
		wp_set_current_user( 0 );
		$this->upgrades->flush();
		$early = $this->events( array( 'result' => 'unknown' ) );
		self::assertSame( (string) $this->actor, $early[0]['actor_id'] );
		$this->upgrades->complete( (object) array( 'result' => true ), array( 'type' => 'core', 'action' => 'update' ) );
		$this->upgrades->automatic( array( 'core' => array( (object) array( 'result' => new WP_Error( 'rollback', 'DO-NOT-STORE-rollback' ) ), (object) array( 'result' => '7.1.4' ) ) ) );
		$core = array_values( array_filter( $this->events(), fn ( $row ) => 'core' === $row['object_type'] ) );
		self::assertSame( array( 'unknown', 'failure', 'success' ), array_column( $core, 'result' ) );
		self::assertSame( array( 'completion', 'automatic', 'automatic' ), array_map( fn ( $row ) => json_decode( $row['summary'], true )['stage'], $core ) );
		self::assertStringNotContainsString( 'DO-NOT-STORE', json_encode( $this->events() ) );
	}

	public function test_failure_boundary_and_summary_reference_rejection(): void {
		$broken = new Recorder( static function () { throw new RuntimeException( 'DO-NOT-STORE-database' ); }, new Context() );
		$broken->emit( 'admin', 'option_changed', 'option', null, 'success', array( 'reference' => 'blog_public' ) );
		$event = array( 'category' => 'admin', 'action' => 'updated', 'object_type' => 'plugin', 'result' => 'success', 'origin' => 'cli' );
		foreach ( array( '../secret.php', '/absolute.php', 'https://host/file.php?token=secret', 'plugin/../../secret.php', str_repeat( 'a', 192 ) ) as $reference ) {
			self::assertFalse( $this->storage->events->append( $event + array( 'summary' => array( 'reference' => $reference ) ), time() ) );
		}
		self::assertFalse( $this->storage->events->append( $event + array( 'summary' => array( 'stage' => 'package' ) ), time() ) );
		self::assertFalse( $this->storage->events->append( array_replace( $event, array( 'action' => 'upgraded', 'summary' => array( 'stage' => 'arbitrary-sensitive-stage' ) ) ), time() ) );
		self::assertSame( array(), $this->events() );
	}

	public function test_pre_download_failure_is_not_duplicated_by_bulk_completion(): void {
		$upgrader = (object) array( 'bulk' => true );
		$error = new WP_Error( 'DO-NOT-STORE-download', 'DO-NOT-STORE-message' );
		self::assertSame( $error, $this->upgrades->attempt( $error, 'DO-NOT-STORE-url', $upgrader, array( 'plugin' => 'broken/broken.php', 'type' => 'plugin', 'action' => 'update' ) ) );
		$this->upgrades->complete( $upgrader, array( 'type' => 'plugin', 'action' => 'update', 'bulk' => true, 'plugins' => array( 'broken/broken.php' ) ) );
		$this->upgrades->flush();
		$rows = $this->events( array( 'action' => 'upgraded' ) );
		self::assertCount( 1, $rows );
		self::assertSame( 'failure', $rows[0]['result'] );
		self::assertSame( 'download', json_decode( $rows[0]['summary'], true )['stage'] );
		self::assertStringNotContainsString( 'DO-NOT-STORE', json_encode( $rows ) );
	}
}
