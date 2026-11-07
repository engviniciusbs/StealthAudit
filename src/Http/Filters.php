<?php
/**
 * Strict server-side query filter validation; no WordPress dependency.
 *
 * @package StealthAudit
 */

declare(strict_types=1);
namespace StealthAudit\Http;

use StealthAudit\Storage\ErrorRepository;
use StealthAudit\Storage\EventRepository;

/** Rejects malformed or unknown input instead of coercing it. */
final class Filters {
	public const DEFAULT_LIMIT = 50;
	public const MAX_LIMIT     = 100;
	/**
	 * Parameters WordPress transport may add; ignored, never interpreted.
	 */
	private const TRANSPORT = array( 'rest_route', '_locale' );

	/**
	 * Validate audit filters: period, user, category, action, result and IP.
	 *
	 * @param array<mixed> $params Raw query parameters.
	 * @return array{filters:array<string,int|string>,from:?int,to:?int,after:?array{0:int,1:int},limit:int,query:array<string,string>} Normalized query.
	 * @throws \InvalidArgumentException On malformed input.
	 */
	public static function events( array $params ): array {
		$query = self::common( $params, array( 'user', 'category', 'action', 'result', 'ip' ) );
		$out   = array();
		if ( isset( $query['user'] ) ) {
			if ( ! preg_match( '/^[1-9][0-9]{0,17}$/D', $query['user'] ) ) {
				throw new \InvalidArgumentException( 'Invalid filter.' );
			}
			$out['actor_id'] = (int) $query['user'];
		}
		foreach ( array(
			'category' => EventRepository::CATEGORIES,
			'action'   => EventRepository::ACTIONS,
			'result'   => EventRepository::RESULTS,
		) as $field => $allowed ) {
			if ( isset( $query[ $field ] ) ) {
				$out[ $field ] = self::closed( $query[ $field ], $allowed );
			}
		}
		if ( isset( $query['ip'] ) ) {
			$packed = false === filter_var( $query['ip'], FILTER_VALIDATE_IP ) ? false : inet_pton( $query['ip'] );
			$ip     = false === $packed ? false : inet_ntop( $packed );
			if ( false === $ip ) {
				throw new \InvalidArgumentException( 'Invalid filter.' );
			}
			$out['ip'] = $ip;
		}
		return self::window( $query, $out );
	}

	/**
	 * Validate diagnostic filters: period and severity.
	 *
	 * @param array<mixed> $params Raw query parameters.
	 * @return array{filters:array<string,int|string>,from:?int,to:?int,after:?array{0:int,1:int},limit:int,query:array<string,string>} Normalized query.
	 * @throws \InvalidArgumentException On malformed input.
	 */
	public static function errors( array $params ): array {
		$query = self::common( $params, array( 'severity' ) );
		$out   = array();
		if ( isset( $query['severity'] ) ) {
			$out['severity'] = self::closed( $query['severity'], ErrorRepository::SEVERITIES );
		}
		return self::window( $query, $out );
	}

	/**
	 * Encode an opaque-but-inspectable (time,id) cursor.
	 *
	 * @param array{0:int,1:int}|null $after Cursor parts.
	 */
	public static function cursor( ?array $after ): ?string {
		return null === $after ? null : $after[0] . '-' . $after[1];
	}

	/**
	 * Format a UTC timestamp exactly as accepted by the period filters.
	 *
	 * @param int $time UTC timestamp.
	 */
	public static function format( int $time ): string {
		return gmdate( 'Y-m-d\TH:i:s\Z', $time );
	}

	/**
	 * Select string scalars of bounded size; reject unknown keys.
	 *
	 * @param array<mixed> $params Raw parameters.
	 * @param list<string> $specific View-specific keys.
	 * @return array<string,string> Bounded values.
	 * @throws \InvalidArgumentException On unknown, non-string or oversized input.
	 */
	private static function common( array $params, array $specific ): array {
		$allowed = array_merge( array( 'from', 'to', 'limit', 'cursor' ), $specific );
		$query   = array();
		if ( count( $params ) > 16 ) {
			throw new \InvalidArgumentException( 'Invalid filter.' );
		}
		foreach ( $params as $key => $value ) {
			if ( in_array( $key, self::TRANSPORT, true ) ) {
				continue;
			}
			if ( ! is_string( $key ) || ! in_array( $key, $allowed, true ) || ! is_string( $value ) || '' === $value || strlen( $value ) > 64 ) {
				throw new \InvalidArgumentException( 'Invalid filter.' );
			}
			$query[ $key ] = $value;
		}
		return $query;
	}

	/**
	 * Period, cursor and page size shared by both views.
	 *
	 * @param array<string,string>     $query Bounded input.
	 * @param array<string,int|string> $filters Equality filters.
	 * @return array{filters:array<string,int|string>,from:?int,to:?int,after:?array{0:int,1:int},limit:int,query:array<string,string>} Normalized query.
	 * @throws \InvalidArgumentException On malformed input.
	 */
	private static function window( array $query, array $filters ): array {
		$from  = isset( $query['from'] ) ? self::time( $query['from'] ) : null;
		$to    = isset( $query['to'] ) ? self::time( $query['to'] ) : null;
		$limit = self::DEFAULT_LIMIT;
		if ( null !== $from && null !== $to && $from > $to ) {
			throw new \InvalidArgumentException( 'Invalid filter.' );
		}
		if ( isset( $query['limit'] ) ) {
			if ( ! preg_match( '/^[1-9][0-9]{0,2}$/D', $query['limit'] ) || (int) $query['limit'] > self::MAX_LIMIT ) {
				throw new \InvalidArgumentException( 'Invalid filter.' );
			}
			$limit = (int) $query['limit'];
		}
		$after = null;
		if ( isset( $query['cursor'] ) ) {
			if ( ! preg_match( '/^(0|[1-9][0-9]{0,11})-([1-9][0-9]{0,17})$/D', $query['cursor'], $parts ) || (int) $parts[1] > 253402300799 ) {
				throw new \InvalidArgumentException( 'Invalid filter.' );
			}
			$after = array( (int) $parts[1], (int) $parts[2] );
		}
		unset( $query['cursor'] );
		return array(
			'filters' => $filters,
			'from'    => $from,
			'to'      => $to,
			'after'   => $after,
			'limit'   => $limit,
			'query'   => $query,
		);
	}

	/**
	 * Closed code lists only.
	 *
	 * @param string       $value Candidate.
	 * @param list<string> $allowed Allowed values.
	 * @throws \InvalidArgumentException On unknown code.
	 */
	private static function closed( string $value, array $allowed ): string {
		if ( ! in_array( $value, $allowed, true ) ) {
			throw new \InvalidArgumentException( 'Invalid filter.' );
		}
		return $value;
	}

	/**
	 * Parse RFC 3339 seconds with Z or numeric offset; impossible dates are rejected.
	 *
	 * @param string $value Candidate.
	 * @throws \InvalidArgumentException On malformed time.
	 */
	private static function time( string $value ): int {
		if ( ! preg_match( '/^([0-9]{4})-([0-9]{2})-([0-9]{2})T([0-9]{2}):([0-9]{2}):([0-9]{2})(Z|([+-])([0-9]{2}):([0-9]{2}))$/D', $value, $m ) ) {
			throw new \InvalidArgumentException( 'Invalid filter.' );
		}
		$year = (int) $m[1];
		if ( $year < 1970 || ! checkdate( (int) $m[2], (int) $m[3], $year ) || (int) $m[4] > 23 || (int) $m[5] > 59 || (int) $m[6] > 59 ) {
			throw new \InvalidArgumentException( 'Invalid filter.' );
		}
		$offset = 0;
		if ( 'Z' !== $m[7] ) {
			if ( (int) $m[9] > 14 || (int) $m[10] > 59 ) {
				throw new \InvalidArgumentException( 'Invalid filter.' );
			}
			$offset = ( '-' === $m[8] ? -1 : 1 ) * ( (int) $m[9] * 3600 + (int) $m[10] * 60 );
		}
		$time = gmmktime( (int) $m[4], (int) $m[5], (int) $m[6], (int) $m[2], (int) $m[3], $year ) - $offset;
		if ( $time < 0 || $time > 253402300799 ) {
			throw new \InvalidArgumentException( 'Invalid filter.' );
		}
		return $time;
	}
}
