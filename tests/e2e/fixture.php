<?php
// SPDX-License-Identifier: GPL-3.0-or-later
/**
 * Development only: disposable fixture for the browser E2E suite (tests/e2e/run.mjs).
 *
 * Run inside the existing test environment, never on a real site:
 *   node tools/wp-env.cjs run cli --env-cwd=wp-content/mu-plugins/stealthaudit wp eval-file tests/e2e/fixture.php <action> [json]
 *
 * setup     Snapshot wp-config.php, .htaccess, options, users, StealthAudit tables; create
 *           synthetic users, a random key and a temporary STEALTHAUDIT_CONFIG block.
 * config    Rewrite the temporary block (home URL, endpoint, key present or missing, MMDB path).
 * geoip     Copy MaxMind's official City test MMDB outside the webroot and add events with known
 *           test IPs ("on"), or delete the copy ("off"). Never a real GeoLite/GeoIP database.
 * age-proof Move the idle clock of every proof created by this run past 1,800 s.
 * inject    Store an HTML/script payload as a diagnostic (real repository) and as a raw event row.
 * theme     Switch to a bundled theme (or "restore").
 * subdir    Create or remove the /blog symlink used for the subdirectory install.
 * maintenance Print the scheduled maintenance time and its closed report (read-only).
 * inspect   Print fixture counters; never secrets.
 * teardown  Delete the synthetic users and posts (audited at shutdown of this process).
 * restore   In a new process: restore files, options and StealthAudit tables, verify, delete fixture files.
 *
 * Synthetic passwords and the key exist only in artifacts/e2e (gitignored) and the
 * temporary wp-config block, and are removed by teardown.
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) ) {
	exit( 1 );
}
if ( 'local' !== wp_get_environment_type() ) {
	WP_CLI::error( 'Refusing to run outside the local wp-env test site.' );
}

global $wpdb;
$e2e_dir      = dirname( __DIR__, 2 ) . '/artifacts/e2e';
$e2e_snapshot = $e2e_dir . '/snapshot.json';
$e2e_state    = $e2e_dir . '/state.json';
$e2e_config   = ABSPATH . 'wp-config.php';
$e2e_marker   = array( '/* stealthaudit-e2e:begin */', '/* stealthaudit-e2e:end */' );
$e2e_action   = $args[0] ?? '';
$e2e_input    = isset( $args[1] ) ? json_decode( $args[1], true ) : array();
$e2e_table    = static fn ( string $kind ): string => $wpdb->prefix . 'stealthaudit_' . $kind;
// Shared by the CLI and web containers and outside the webroot, as the plugin requires.
$e2e_geo      = rtrim( (string) getenv( 'HOME' ), '/' ) . '/stealthaudit-e2e-geoip';

$e2e_read = static function ( string $path ): array {
	$data = is_readable( $path ) ? json_decode( (string) file_get_contents( $path ), true ) : null;
	if ( ! is_array( $data ) ) {
		WP_CLI::error( 'Fixture file missing: run setup first.' );
	}
	return $data;
};
$e2e_write = static function ( string $path, array $data ): void {
	if ( false === file_put_contents( $path, wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ) ) {
		WP_CLI::error( 'Cannot write fixture file.' );
	}
	chmod( $path, 0600 );
};

/** Rebuild wp-config.php from the original bytes plus the disposable block. */
$e2e_configure = static function ( array $snapshot, array $state, array $variant ) use ( $e2e_config, $e2e_marker ): void {
	$original = base64_decode( $snapshot['wp_config'], true );
	$home     = $variant['home'] ?? $state['home'];
	$values   = array(
		'authorized_user_ids' => $state['allowlist'],
		'endpoint'            => $variant['endpoint'] ?? '/stealthaudit',
		'key_base64'          => ( $variant['key'] ?? 'valid' ) === 'valid' ? $state['key'] : '',
		'key_id'              => $state['key_id'],
		'installation_id'     => $state['installation_id'],
		'trusted_proxies'     => array(),
		'limits'              => array(),
		'mmdb_path'           => $variant['mmdb'] ?? null,
		'mmdb_max_age_days'   => 3650,
	);
	$count    = 0;
	$updated  = preg_replace(
		"~define\\( 'WP_(SITEURL|HOME)', '[^']*' \\);~",
		"define( 'WP_$1', " . var_export( $home, true ) . ' );', // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Literal for a generated config file.
		$original,
		-1,
		$count
	);
	$block    = $e2e_marker[0] . "\ndefine( 'STEALTHAUDIT_CONFIG', " . var_export( $values, true ) . " );\n" . $e2e_marker[1] . "\n"; // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Literal for a generated config file.
	$anchor   = "/* That's all, stop editing!";
	if ( 2 !== $count || ! is_string( $updated ) || ! str_contains( $updated, $anchor ) ) {
		WP_CLI::error( 'Unexpected wp-config.php layout; nothing changed.' );
	}
	$updated = str_replace( $anchor, $block . "\n" . $anchor, $updated );
	if ( false === file_put_contents( $e2e_config, $updated ) ) {
		WP_CLI::error( 'Cannot write wp-config.php.' );
	}
};

/** Proofs and limiter rows created after the snapshot. */
$e2e_new_state = static function ( array $snapshot, string $prefix ) use ( $wpdb, $e2e_table ): array {
	$known = array_column( $snapshot['state'], 'state_key' );
	$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE state_key LIKE %s', $e2e_table( 'state' ), $wpdb->esc_like( $prefix ) . '%' ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	return array_values( array_filter( $rows, static fn ( array $row ): bool => ! in_array( $row['state_key'], $known, true ) ) );
};

switch ( $e2e_action ) {
	case 'setup':
		if ( is_file( $e2e_snapshot ) ) {
			WP_CLI::error( 'A previous run was not torn down; run teardown and restore first.' );
		}
		if ( defined( 'STEALTHAUDIT_CONFIG' ) || defined( 'STEALTHAUDIT_CONFIG_FILE' ) ) {
			WP_CLI::error( 'Site already has a StealthAudit configuration; refusing to touch it.' );
		}
		if ( ! is_dir( $e2e_dir ) && ! mkdir( $e2e_dir, 0700, true ) ) {
			WP_CLI::error( 'Cannot create artifacts/e2e.' );
		}
		$snapshot = array(
			'wp_config' => base64_encode( (string) file_get_contents( $e2e_config ) ),
			'htaccess'  => is_file( ABSPATH . '.htaccess' ) ? base64_encode( (string) file_get_contents( ABSPATH . '.htaccess' ) ) : null,
			'options'   => $wpdb->get_results( $wpdb->prepare( 'SELECT option_name, option_value, autoload FROM %i ORDER BY option_name', $wpdb->options ), ARRAY_A ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'users'     => array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT ID FROM %i ORDER BY ID', $wpdb->users ) ) ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'state'     => $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY state_key', $e2e_table( 'state' ) ), ARRAY_A ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'events'    => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(MAX(id),0) FROM %i', $e2e_table( 'events' ) ) ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'errors'    => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(MAX(id),0) FROM %i', $e2e_table( 'errors' ) ) ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'posts'     => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(MAX(ID),0) FROM %i', $wpdb->posts ) ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'symlink'   => file_exists( ABSPATH . 'blog' ),
		);
		if ( $snapshot['symlink'] ) {
			WP_CLI::error( 'A /blog path already exists; refusing to continue.' );
		}
		$e2e_write( $e2e_snapshot, $snapshot );
		$suffix = bin2hex( random_bytes( 4 ) );
		$users  = array();
		foreach ( array(
			'operator' => 'administrator',
			'second'   => 'subscriber',
			'outsider' => 'subscriber',
		) as $name => $role ) {
			$password = 'e2e-' . bin2hex( random_bytes( 12 ) );
			$login    = 'sa-e2e-' . $name . '-' . $suffix;
			$id       = wp_insert_user(
				array(
					'user_login' => $login,
					'user_pass'  => $password,
					'user_email' => $login . '@example.invalid',
					'role'       => $role,
				)
			);
			if ( is_wp_error( $id ) ) {
				WP_CLI::error( 'Cannot create synthetic user.' );
			}
			$users[ $name ] = array(
				'id'       => $id,
				'login'    => $login,
				'password' => $password,
			);
		}
		$state = array(
			'home'            => (string) ( $e2e_input['home'] ?? 'https://localhost:8443' ),
			'users'           => $users,
			'allowlist'       => array( $users['operator']['id'], $users['second']['id'] ),
			'key'             => base64_encode( random_bytes( 32 ) ),
			'key_id'          => 'e2e-' . bin2hex( random_bytes( 4 ) ),
			'installation_id' => 'e2e-' . bin2hex( random_bytes( 8 ) ),
		);
		$e2e_write( $e2e_state, $state );
		$e2e_configure( $snapshot, $state, array() );
		WP_CLI::success( 'Fixture ready.' );
		break;

	case 'config':
		$e2e_configure( $e2e_read( $e2e_snapshot ), $e2e_read( $e2e_state ), is_array( $e2e_input ) ? $e2e_input : array() );
		WP_CLI::success( 'Config block rewritten.' );
		break;

	case 'geoip':
		$file = $e2e_geo . '/GeoIP2-City-Test.mmdb';
		if ( 'on' !== ( $e2e_input['state'] ?? '' ) ) {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
			if ( is_dir( $e2e_geo ) ) {
				rmdir( $e2e_geo );
			}
			WP_CLI::success( 'GeoIP test copy removed.' );
			break;
		}
		$source = dirname( __DIR__, 2 ) . '/artifacts/geoip/GeoIP2-City-Test.mmdb';
		$root   = realpath( ABSPATH );
		if ( ! is_file( $source ) || '/stealthaudit-e2e-geoip' === $e2e_geo || false === $root || str_starts_with( $e2e_geo, $root . '/' ) ) {
			WP_CLI::error( 'GeoIP test data missing (node tools/geoip-test-data.mjs) or no safe directory outside the webroot.' );
		}
		if ( ! is_dir( $e2e_geo ) && ! mkdir( $e2e_geo, 0700 ) ) {
			WP_CLI::error( 'Cannot create the GeoIP test directory.' );
		}
		if ( ! copy( $source, $file ) ) {
			WP_CLI::error( 'Cannot copy the GeoIP test database.' );
		}
		$events = \StealthAudit\Bootstrap\Runtime::boot()->storage()->events;
		foreach ( array( '81.2.69.160', '2001:218::' ) as $ip ) {
			$events->append(
				array(
					'actor_id'    => null,
					'category'    => 'access',
					'action'      => 'login_failed',
					'result'      => 'failure',
					'origin'      => 'http',
					'object_type' => 'user',
					'object_id'   => null,
					'ip'          => $ip,
				),
				time()
			);
		}
		echo wp_json_encode( array( 'path' => $file ) ) . "
";
		break;

	case 'permalinks':
		$structure = (string) ( $e2e_input['structure'] ?? '' );
		update_option( 'permalink_structure', $structure );
		// Like saving Settings > Permalinks: rules regenerate on the next request without our hand.
		delete_option( 'rewrite_rules' );
		WP_CLI::success( 'Permalinks: ' . ( '' === $structure ? 'plain' : $structure ) );
		break;

	case 'age-proof':
		$now   = time();
		$aged  = 0;
		$store = new \StealthAudit\Storage\StateRepository( new \StealthAudit\Storage\Database( $wpdb ) );
		foreach ( $e2e_new_state( $e2e_read( $e2e_snapshot ), 'proof:' ) as $row ) {
			$current = $store->get( $row['state_key'], $now );
			if ( null === $current ) {
				continue;
			}
			$payload                  = $current['payload'];
			$payload['last_activity'] = $now - 1801;
			$aged                    += $store->compare_and_swap( $row['state_key'], $current['revision'], $payload, $now + 600, $now ) ? 1 : 0;
		}
		WP_CLI::success( 'Aged proofs: ' . $aged );
		break;

	case 'inject':
		$payload = '<img src=x onerror="document.title=\'xss\'"><script>document.title="xss"</script>';
		$storage = \StealthAudit\Bootstrap\Runtime::boot()->storage();
		$error   = $storage->errors->append(
			array(
				'severity' => 'warning',
				'message'  => 'e2e ' . $payload,
				'file'     => 'wp-content/mu-plugins/stealthaudit/tests/e2e/fixture.php',
				'line'     => __LINE__,
			),
			time()
		);
		// The repository only stores closed codes in events, so a raw row stands in for tampered data.
		$event = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$e2e_table( 'events' ),
			array(
				'record_version' => 1,
				'occurred_at'    => gmdate( 'Y-m-d H:i:s' ),
				'actor_id'       => null,
				'category'       => 'access',
				'action'         => 'login_failed',
				'result'         => 'failure',
				'origin'         => 'http',
				'object_type'    => 'user',
				'object_id'      => null,
				'ip'             => '192.0.2.1',
				'summary'        => wp_json_encode( array( 'reason' => $payload ) ),
				'logical_bytes'  => 200,
			)
		);
		if ( ! $error || 1 !== $event ) {
			WP_CLI::error( 'Injection failed.' );
		}
		WP_CLI::success( 'Injected one diagnostic and one raw event.' );
		break;

	case 'theme':
		$slug = (string) ( $e2e_input['slug'] ?? '' );
		if ( 'restore' === $slug ) {
			$snapshot = $e2e_read( $e2e_snapshot );
			$options  = array_column( $snapshot['options'], 'option_value', 'option_name' );
			update_option( 'template', $options['template'] );
			update_option( 'stylesheet', $options['stylesheet'] );
		} elseif ( wp_get_theme( $slug )->exists() ) {
			switch_theme( $slug );
		} else {
			WP_CLI::error( 'Unknown theme.' );
		}
		WP_CLI::success( 'Theme: ' . get_option( 'stylesheet' ) );
		break;

	case 'subdir':
		$link = ABSPATH . 'blog';
		if ( 'on' === ( $e2e_input['state'] ?? '' ) ) {
			if ( ! file_exists( $link ) && ! symlink( '.', $link ) ) {
				WP_CLI::error( 'Cannot create symlink.' );
			}
		} elseif ( is_link( $link ) ) {
			unlink( $link );
		}
		WP_CLI::success( 'Subdirectory: ' . ( is_link( $link ) ? 'on' : 'off' ) );
		break;

	case 'maintenance':
		// Read-only: the run itself goes through "wp cron event run", WP-Cron's own runner.
		$next   = wp_next_scheduled( \StealthAudit\Bootstrap\Scheduler::HOOK, array() );
		$report = \StealthAudit\Bootstrap\Runtime::boot()->storage()->maintenance->report( time() );
		echo wp_json_encode(
			array(
				'scheduled' => false === $next ? null : (int) $next,
				'state'     => $report['state'],
				'last_run'  => $report['last_run'],
			)
		) . "\n";
		break;

	case 'inspect':
		$snapshot = $e2e_read( $e2e_snapshot );
		$rules    = get_option( 'rewrite_rules' );
		echo wp_json_encode(
			array(
				'new_events'      => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE id>%d', $e2e_table( 'events' ), $snapshot['events'] ) ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				'new_errors'      => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE id>%d', $e2e_table( 'errors' ), $snapshot['errors'] ) ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				'new_state'       => array_map( static fn ( array $row ): string => strtok( $row['state_key'], ':' ), $e2e_new_state( $snapshot, '' ) ),
				'rules'           => is_array( $rules ) ? array_values( array_filter( array_keys( $rules ), static fn ( string $rule ): bool => str_contains( $rule, 'stealth' ) || str_contains( $rule, 'painel' ) ) ) : null,
				'mfa_actions'     => $wpdb->get_col( $wpdb->prepare( "SELECT action FROM %i WHERE id>%d AND action LIKE 'mfa\\_%%' ORDER BY id", $e2e_table( 'events' ), $snapshot['events'] ) ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				'secret_in_event' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE id>%d AND (summary LIKE '%%otpauth%%' OR summary LIKE '%%e2e-%%')", $e2e_table( 'events' ), $snapshot['events'] ) ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			)
		) . "\n";
		break;

	case 'teardown':
		if ( ! is_file( $e2e_snapshot ) ) {
			WP_CLI::success( 'Nothing to tear down.' );
			break;
		}
		$snapshot = $e2e_read( $e2e_snapshot );
		require_once ABSPATH . 'wp-admin/includes/user.php';
		foreach ( $wpdb->get_col( $wpdb->prepare( 'SELECT ID FROM %i ORDER BY ID', $wpdb->users ) ) as $id ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( ! in_array( (int) $id, $snapshot['users'], true ) ) {
				wp_delete_user( (int) $id );
			}
		}
		foreach ( $wpdb->get_col( $wpdb->prepare( 'SELECT ID FROM %i WHERE ID>%d', $wpdb->posts, $snapshot['posts'] ?? PHP_INT_MAX ) ) as $id ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			wp_delete_post( (int) $id, true );
		}
		// The audit collector writes these deletions at shutdown, so tables are restored by a
		// separate "restore" process afterwards.
		WP_CLI::success( 'Synthetic users and posts deleted; run the restore action next.' );
		break;

	case 'restore':
		if ( ! is_file( $e2e_snapshot ) ) {
			WP_CLI::success( 'Nothing to restore.' );
			break;
		}
		$snapshot = $e2e_read( $e2e_snapshot );
		if ( is_link( ABSPATH . 'blog' ) ) {
			unlink( ABSPATH . 'blog' );
		}
		if ( is_file( $e2e_geo . '/GeoIP2-City-Test.mmdb' ) ) {
			unlink( $e2e_geo . '/GeoIP2-City-Test.mmdb' );
		}
		if ( is_dir( $e2e_geo ) ) {
			rmdir( $e2e_geo );
		}
		file_put_contents( $e2e_config, base64_decode( $snapshot['wp_config'], true ) );
		if ( null !== $snapshot['htaccess'] ) {
			file_put_contents( ABSPATH . '.htaccess', base64_decode( $snapshot['htaccess'], true ) );
		} elseif ( is_file( ABSPATH . '.htaccess' ) ) {
			// Pretty permalinks made WordPress write a file that did not exist before the run.
			unlink( ABSPATH . '.htaccess' );
		}
		// Deleting the users above may itself be audited; drop every row added by this run.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id>%d', $e2e_table( 'events' ), $snapshot['events'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id>%d', $e2e_table( 'errors' ), $snapshot['errors'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $e2e_table( 'state' ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( $snapshot['state'] as $row ) {
			$wpdb->insert( $e2e_table( 'state' ), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		$before = array();
		foreach ( $snapshot['options'] as $row ) {
			$before[ $row['option_name'] ] = $row;
		}
		$after = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT option_name, option_value, autoload FROM %i', $wpdb->options ), ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$after[ $row['option_name'] ] = $row;
			if ( ! isset( $before[ $row['option_name'] ] ) ) {
				$wpdb->delete( $wpdb->options, array( 'option_name' => $row['option_name'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
		}
		foreach ( $before as $name => $row ) {
			if ( ! isset( $after[ $name ] ) ) {
				$wpdb->insert( $wpdb->options, $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			} elseif ( $after[ $name ] !== $row ) {
				$wpdb->update( $wpdb->options, $row, array( 'option_name' => $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
		}
		wp_cache_flush();
		$left = array(
			'config'   => base64_encode( (string) file_get_contents( $e2e_config ) ) !== $snapshot['wp_config'],
			'htaccess' => ( is_file( ABSPATH . '.htaccess' ) ? base64_encode( (string) file_get_contents( ABSPATH . '.htaccess' ) ) : null ) !== $snapshot['htaccess'],
			'options'  => $wpdb->get_results( $wpdb->prepare( 'SELECT option_name, option_value, autoload FROM %i ORDER BY option_name', $wpdb->options ), ARRAY_A ) != $snapshot['options'], // phpcs:ignore WordPress.DB.DirectDatabaseQuery, Universal.Operators.StrictComparisons -- Row order may differ.
			'users'    => array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT ID FROM %i ORDER BY ID', $wpdb->users ) ) ) !== $snapshot['users'], // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'state'    => $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY state_key', $e2e_table( 'state' ) ), ARRAY_A ) != $snapshot['state'], // phpcs:ignore WordPress.DB.DirectDatabaseQuery, Universal.Operators.StrictComparisons
			'records'  => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(MAX(id),0) FROM %i', $e2e_table( 'events' ) ) ) > $snapshot['events'] || (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(MAX(id),0) FROM %i', $e2e_table( 'errors' ) ) ) > $snapshot['errors'], // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'symlink'  => file_exists( ABSPATH . 'blog' ),
			'geoip'    => file_exists( $e2e_geo ),
		);
		if ( in_array( true, $left, true ) ) {
			WP_CLI::error( 'Restore incomplete: ' . implode( ', ', array_keys( array_filter( $left ) ) ) . '; snapshot kept in artifacts/e2e.' );
		}
		unlink( $e2e_snapshot );
		if ( is_file( $e2e_state ) ) {
			unlink( $e2e_state );
		}
		WP_CLI::success( 'Restored wp-config.php, .htaccess, options, users and StealthAudit tables.' );
		break;

	default:
		WP_CLI::error( 'Unknown action.' );
}
