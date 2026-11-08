<?php
/**
 * Optional read-time location estimate from an operator-supplied local MMDB.
 *
 * @license GPL-3.0-or-later
 * @package StealthAudit
 */

declare(strict_types=1);

namespace StealthAudit\Geo;

use GeoIp2\Database\Reader;
use GeoIp2\Exception\AddressNotFoundException;
use GeoIp2\Model\City;

/**
 * Resolve the addresses shown on one page, at query time only.
 *
 * Opens the database at most once per call, never on the write path, never over the
 * network, and never stores what it finds: the estimate reflects the current database,
 * not where the address was when the event happened. Any failure yields a closed state
 * and no locations; it never throws to the caller.
 */
final class Geolocation {
	/** Most unique addresses resolved per query (one page holds at most 100 rows). */
	public const MAX_ADDRESSES = 100;

	/** Names in Portuguese when the database has them, otherwise English. */
	public const LOCALES = array( 'pt-BR', 'en' );

	/** Closed database states; every one but "available" means no location is shown. */
	public const STATES = array( 'available', 'missing', 'misplaced', 'invalid', 'unsupported', 'outdated' );

	/** Accepted build-time skew into the future before metadata is treated as invalid. */
	private const FUTURE_SKEW = 86400;

	/**
	 * Construct.
	 *
	 * @param string                                      $path Absolute MMDB path from configuration.
	 * @param int                                         $max_age_days Oldest accepted build, in days.
	 * @param string                                      $webroot Public document root the file must stay outside of.
	 * @param \Closure():int                              $clock UTC clock.
	 * @param (\Closure(string,list<string>):Reader)|null $open Reader factory (tests count openings).
	 */
	public function __construct(
		private readonly string $path,
		private readonly int $max_age_days,
		private readonly string $webroot,
		private readonly \Closure $clock,
		private readonly ?\Closure $open = null,
	) {}

	/**
	 * Estimate locations for the valid, unique addresses of one page, capped at MAX_ADDRESSES.
	 *
	 * @param list<mixed> $addresses Displayed IPs, in page order; null and malformed are skipped.
	 * @return array{state:string,built:?int,locations:array<string,array{country_code:?string,country:?string,region:?string,city:?string}>} Addresses without a result are absent.
	 */
	public function resolve( array $addresses ): array {
		$unique = array();
		foreach ( $addresses as $address ) {
			if ( count( $unique ) >= self::MAX_ADDRESSES ) {
				break;
			}
			if ( is_string( $address ) && false !== filter_var( $address, FILTER_VALIDATE_IP ) ) {
				$unique[ $address ] = true;
			}
		}
		$reader = null;
		try {
			$file = realpath( $this->path );
			if ( false === $file || ! is_file( $file ) || ! is_readable( $file ) ) {
				return self::closed( 'missing' );
			}
			$root = realpath( $this->webroot );
			if ( false === $root || $file === $root || str_starts_with( $file, rtrim( $root, '/' ) . '/' ) ) {
				// A database under the webroot may be downloadable by anyone: refuse to use it.
				return self::closed( 'misplaced' );
			}
			$reader = null === $this->open ? new Reader( $file, self::LOCALES ) : ( $this->open )( $file, self::LOCALES );
			// Metadata fields come straight from the file, unvalidated by the library.
			$metadata = get_object_vars( $reader->metadata() );
			$type     = $metadata['databaseType'] ?? null;
			$built    = $metadata['buildEpoch'] ?? null;
			$now      = ( $this->clock )();
			if ( ! is_string( $type ) || ! is_int( $built ) || $built > $now + self::FUTURE_SKEW ) {
				return self::closed( 'invalid' );
			}
			$city = str_contains( $type, 'City' );
			if ( ! $city && ! str_contains( $type, 'Country' ) ) {
				return self::closed( 'unsupported', $built );
			}
			if ( $now - $built > $this->max_age_days * 86400 ) {
				return self::closed( 'outdated', $built );
			}
			$locations = array();
			foreach ( array_keys( $unique ) as $address ) {
				$found = self::lookup( $reader, $city, (string) $address );
				if ( null !== $found ) {
					$locations[ (string) $address ] = $found;
				}
			}
			return array(
				'state'     => 'available',
				'built'     => $built,
				'locations' => $locations,
			);
		} catch ( \Throwable $failure ) {
			// Corrupt or unreadable database, including errors found during a lookup.
			return self::closed( 'invalid' );
		} finally {
			try {
				$reader?->close();
			} catch ( \Throwable $ignored ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Closing is best effort.
			}
		}
	}

	/**
	 * One address; "no result" and per-address argument errors are not database failures.
	 *
	 * @param Reader $reader Open database.
	 * @param bool   $city City (true) or Country database.
	 * @param string $address Valid IP.
	 * @return array{country_code:?string,country:?string,region:?string,city:?string}|null Fields may be null.
	 */
	private static function lookup( Reader $reader, bool $city, string $address ): ?array {
		try {
			$model = $city ? $reader->city( $address ) : $reader->country( $address );
		} catch ( AddressNotFoundException $failure ) {
			return null;
		} catch ( \InvalidArgumentException $failure ) { // @phpstan-ignore catch.neverThrown (MaxMind\Db\Reader throws it, undeclared by GeoIp2.)
			// For example an IPv6 address in an IPv4-only database.
			return null;
		}
		$found = array(
			'country_code' => $model->country->isoCode,
			'country'      => $model->country->name,
			'region'       => $model instanceof City ? $model->mostSpecificSubdivision->name : null, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Library property.
			'city'         => $model instanceof City ? $model->city->name : null,
		);
		return array( null, null, null, null ) === array_values( $found ) ? null : $found;
	}

	/**
	 * No locations, with the reason.
	 *
	 * @param string   $state Closed state.
	 * @param int|null $built Build time when the metadata was readable.
	 * @return array{state:string,built:?int,locations:array<string,array{country_code:?string,country:?string,region:?string,city:?string}>} Result.
	 */
	private static function closed( string $state, ?int $built = null ): array {
		return array(
			'state'     => $state,
			'built'     => $built,
			'locations' => array(),
		);
	}
}
