<?php
/**
 * Structured audit records; no free-form request/content summaries.
 *
 * @package StealthAudit
 */

declare(strict_types=1);
namespace StealthAudit\Storage;

/** Collection failures return false without affecting the originating action. */
final class EventRepository extends Records {
	public const CATEGORIES = array( 'access', 'users', 'admin', 'content' );
	public const ACTIONS    = array( 'login', 'login_failed', 'logout', 'created', 'updated', 'deleted', 'role_changed', 'capability_changed', 'activated', 'deactivated', 'installed', 'upgraded', 'theme_switched', 'option_changed', 'trashed', 'restored', 'mfa_password', 'mfa_enrollment', 'mfa_enrolled', 'mfa_access', 'mfa_recovery', 'mfa_replacement', 'mfa_revoked' );
	public const RESULTS    = array( 'success', 'failure', 'unknown' );

	/**
	 * Insert an allowlisted record, reserving conservative logical bytes first.
	 *
	 * @param array<string,mixed> $record Trusted adapter's structured record.
	 * @param int                 $now UTC timestamp from the collector's clock.
	 */
	public function append( array $record, int $now ): bool {
		$reserved = false;
		$started  = $this->budget->begin();
		if ( null === $started ) {
			return false;
		}
		try {
			if ( $now < 1 || array_diff( array_keys( $record ), array( 'actor_id', 'category', 'action', 'result', 'origin', 'object_type', 'object_id', 'ip', 'summary' ) ) ) {
				return false;
			}
			$category = self::code( $record['category'] ?? null, self::CATEGORIES );
			$action   = self::code( $record['action'] ?? null, self::ACTIONS );
			$result   = self::code( $record['result'] ?? null, self::RESULTS );
			$origin   = self::code( $record['origin'] ?? null, array( 'http', 'admin', 'rest', 'cron', 'cli', 'unknown' ) );
			$type     = self::code( $record['object_type'] ?? 'none', array( 'none', 'user', 'plugin', 'theme', 'core', 'option', 'post', 'page', 'attachment' ) );
			$actor    = isset( $record['actor_id'] ) ? self::identity( $record['actor_id'] ) : null;
			$object   = isset( $record['object_id'] ) ? self::identity( $record['object_id'] ) : null;
			$ip       = $record['ip'] ?? null;
			if ( null !== $ip ) {
				if ( ! is_string( $ip ) || false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return false;
				}
				$ip = inet_ntop( inet_pton( $ip ) );
			}
			$summary = $record['summary'] ?? array();
			if ( ! is_array( $summary ) || array_diff( array_keys( $summary ), array( 'count', 'reason', 'reference', 'stage' ) ) ) {
				return false;
			}
			if ( isset( $summary['count'] ) ) {
				self::identity( $summary['count'] );
			}
			if ( array_key_exists( 'reference', $summary ) && ! \StealthAudit\Audit\Reference::valid( $type, $summary['reference'] ) ) {
				return false;
			}
			if ( array_key_exists( 'stage', $summary ) ) {
				if ( 'admin' !== $category || ! in_array( $action, array( 'installed', 'upgraded' ), true ) ) {
					return false;
				}
				self::code( $summary['stage'], array( 'download', 'package', 'completion', 'automatic' ) );
			}
			if ( isset( $summary['reason'] ) ) {
				self::code( $summary['reason'], array( 'invalid_username', 'incorrect_password', 'empty_username', 'empty_password', 'authentication_failed', 'not_confirmed' ) );
			}
			$json  = json_encode( $summary, JSON_THROW_ON_ERROR ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Reject invalid bytes rather than silently repairing stored records.
			$bytes = strlen( $json ) + strlen( $category . $action . $result . $origin . $type . ( $ip ?? '' ) ) + 80;
			if ( ! $this->budget->reserve( 'events', $bytes, $now ) ) {
				return false;
			}
			$reserved = true;
			// Nullable identities/IP use literal NULL, never sentinel user identities.
			$sql  = 'INSERT INTO %i (record_version,occurred_at,actor_id,category,action,result,origin,object_type,object_id,ip,summary,logical_bytes) VALUES (1,%s,' . ( null === $actor ? 'NULL' : '%d' ) . ',%s,%s,%s,%s,%s,' . ( null === $object ? 'NULL' : '%d' ) . ',' . ( null === $ip ? 'NULL' : '%s' ) . ',%s,%d)';
			$args = array( $this->db->table( 'events' ), gmdate( 'Y-m-d H:i:s', $now ) );
			if ( null !== $actor ) {
				$args[] = $actor;
			}
			array_push( $args, $category, $action, $result, $origin, $type );
			if ( null !== $object ) {
				$args[] = $object;
			}
			if ( null !== $ip ) {
				$args[] = $ip;
			}
			array_push( $args, $json, $bytes );
			return 1 === $this->db->execute( $sql, ...$args );
		} catch ( \Throwable $failure ) {
			return false;
		} finally {
			if ( $reserved ) {
				$this->budget->complete( 'events', $now );
			}
			$this->budget->finish( $started );
		}
	}

	/**
	 * Fetch records after authorization by the access layer.
	 *
	 * @param array<string,int|string> $filters Fixed equality filters.
	 * @param int                      $limit Page size.
	 * @param int                      $before_id Exclusive cursor.
	 * @param int                      $now UTC Unix timestamp.
	 * @return list<array<string,mixed>> Records.
	 */
	public function page( array $filters, int $limit, int $before_id, int $now ): array {
		return $this->read_page( 'events', $filters, array( 'category', 'actor_id', 'ip', 'action', 'result' ), $limit, $before_id, $now );
	}

	/**
	 * Fetch a period-bounded keyset window after authorization by the access layer.
	 *
	 * @param array<string,int|string> $filters Fixed equality filters.
	 * @param int|null                 $from Inclusive UTC lower bound.
	 * @param int|null                 $to Inclusive UTC upper bound.
	 * @param array{0:int,1:int}|null  $after Exclusive (time,id) cursor.
	 * @param int                      $limit Page size 1..100.
	 * @param int                      $now UTC Unix timestamp.
	 * @return array{rows:list<array<string,mixed>>,next:array{0:int,1:int}|null} Window.
	 */
	public function window( array $filters, ?int $from, ?int $to, ?array $after, int $limit, int $now ): array {
		return $this->read_window( 'events', $filters, array( 'category', 'actor_id', 'ip', 'action', 'result' ), $from, $to, $after, $limit, $now );
	}
}
