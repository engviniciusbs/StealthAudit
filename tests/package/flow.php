<?php
// SPDX-License-Identifier: GPL-3.0-or-later
/**
 * Development only: read-mostly probes for the operator/update flow of tests/package/run.mjs,
 * run with WP-CLI in the clean artifact site. Never part of the ZIP; prints JSON, never secrets.
 *
 *   wp --path=/var/www/html eval-file /opt/stealthaudit-dev/tests/package/flow.php <action> ['{"user":N}']
 *
 * snapshot   Version, boot status, row counts/max ids, schema version, the user's factor row
 *            (revision + SHA-256 of the stored envelope, never the envelope), proof count,
 *            maintenance report and next cron run.
 * age-proofs Move the idle clock of every proof past 1,800 s (expiration test; one operator).
 * warning    Raise one E_USER_WARNING inside WordPress (collected as a diagnostic).
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) ) {
	exit( 1 );
}
global $wpdb;
$flow_input = isset( $args[1] ) ? json_decode( $args[1], true, 8, JSON_THROW_ON_ERROR ) : array();
$flow_user  = (int) ( $flow_input['user'] ?? 0 );
$flow_table = static fn ( string $kind ): string => $wpdb->prefix . 'stealthaudit_' . $kind;
$flow_value = static fn ( string $sql, ...$params ) => $wpdb->get_var( $wpdb->prepare( $sql, ...$params ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

switch ( $args[0] ?? '' ) {
	case 'snapshot':
		$factor = $wpdb->get_row( $wpdb->prepare( 'SELECT revision, payload FROM %i WHERE state_key = %s', $flow_table( 'state' ), 'factor:' . $flow_user ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$report = \StealthAudit\Bootstrap\Runtime::boot()->storage()->maintenance->report( time() );
		echo wp_json_encode(
			array(
				'version'     => get_mu_plugins()['stealthaudit.php']['Version'] ?? null,
				'boot'        => defined( 'STEALTHAUDIT_BOOT_STATUS' ) ? STEALTHAUDIT_BOOT_STATUS : null,
				'schema'      => get_option( 'stealthaudit_schema_version' ),
				'events'      => (int) $flow_value( 'SELECT COUNT(*) FROM %i', $flow_table( 'events' ) ),
				'events_max'  => (int) $flow_value( 'SELECT COALESCE(MAX(id),0) FROM %i', $flow_table( 'events' ) ),
				'errors'      => (int) $flow_value( 'SELECT COUNT(*) FROM %i', $flow_table( 'errors' ) ),
				'errors_max'  => (int) $flow_value( 'SELECT COALESCE(MAX(id),0) FROM %i', $flow_table( 'errors' ) ),
				'state_rows'  => (int) $flow_value( 'SELECT COUNT(*) FROM %i', $flow_table( 'state' ) ),
				'proofs'      => (int) $flow_value( "SELECT COUNT(*) FROM %i WHERE state_key LIKE 'proof:%%'", $flow_table( 'state' ) ),
				'factor'      => null === $factor ? null : array(
					'revision' => (int) $factor['revision'],
					'sha256'   => hash( 'sha256', $factor['payload'] ),
				),
				'maintenance' => array(
					'state'    => $report['state'],
					'last_run' => $report['last_run'],
				),
				'cron_next'   => wp_next_scheduled( \StealthAudit\Bootstrap\Scheduler::HOOK, array() ) ?: null,
				'old_events'  => (int) $flow_value( 'SELECT COUNT(*) FROM %i WHERE id <= %d', $flow_table( 'events' ), (int) ( $flow_input['upto_event'] ?? 0 ) ),
			)
		) . "\n";
		break;

	case 'age-proofs':
		$now   = time();
		$aged  = 0;
		$store = new \StealthAudit\Storage\StateRepository( new \StealthAudit\Storage\Database( $wpdb ) );
		foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT state_key FROM %i WHERE state_key LIKE 'proof:%%'", $flow_table( 'state' ) ) ) as $key ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$current = $store->get( $key, $now );
			// Proofs are keyed by WordPress session, not user; the flow has a single operator.
			if ( null === $current ) {
				continue;
			}
			$payload                  = $current['payload'];
			$payload['last_activity'] = $now - 1801;
			$aged                    += $store->compare_and_swap( $key, $current['revision'], $payload, $now + 600, $now ) ? 1 : 0;
		}
		echo wp_json_encode( array( 'aged' => $aged ) ) . "\n";
		break;

	case 'warning':
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error -- The diagnostic under test.
		trigger_error( 'stealthaudit package flow warning', E_USER_WARNING );
		echo wp_json_encode( array( 'raised' => 'E_USER_WARNING' ) ) . "\n";
		break;

	default:
		WP_CLI::error( 'Unknown action.' );
}
