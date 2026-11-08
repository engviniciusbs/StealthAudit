<?php
// SPDX-License-Identifier: GPL-3.0-or-later
declare(strict_types=1);

namespace StealthAudit\Tests\Unit;

use GeoIp2\Database\Reader;
use PHPUnit\Framework\TestCase;
use StealthAudit\Geo\Geolocation;

/**
 * Read-time GeoIP against MaxMind's official MMDB test files (synthetic data, Apache-2.0 or
 * MIT), fetched by tools/geoip-test-data.mjs into artifacts/geoip with a pinned commit and
 * SHA-256. Copies live in a temporary directory outside the repository, which plays the
 * webroot here. No real GeoLite/GeoIP database and no license key are involved.
 */
final class GeolocationTest extends TestCase {
	private const DAY = 86400;
	private string $source;
	private string $dir = '';
	private int $opens = 0;

	protected function setUp(): void {
		$this->source = dirname( __DIR__, 2 ) . '/artifacts/geoip';
		if ( ! is_file( $this->source . '/GeoIP2-City-Test.mmdb' ) ) {
			self::fail( 'GeoIP test data missing: run node tools/geoip-test-data.mjs (the npm test scripts do it).' );
		}
		$this->dir = sys_get_temp_dir() . '/sa-geo-' . bin2hex( random_bytes( 6 ) );
		self::assertTrue( mkdir( $this->dir, 0700 ) );
	}

	protected function tearDown(): void {
		if ( '' === $this->dir || ! is_dir( $this->dir ) ) {
			return; // setUp failed before creating the directory; report only that failure.
		}
		foreach ( glob( $this->dir . '/*' ) ?: array() as $file ) {
			unlink( $file );
		}
		rmdir( $this->dir );
	}

	public function test_city_database_estimates_ipv4_and_ipv6_with_nullable_fields(): void {
		$path = $this->copy( 'GeoIP2-City-Test.mmdb' );
		$out = $this->geo( $path )->resolve( array( '81.2.69.160', '2001:218::', '::81.2.69.160', '216.160.83.56' ) );
		self::assertSame( 'available', $out['state'] );
		self::assertSame( $this->built( $path ), $out['built'] );
		self::assertSame( array( 'country_code' => 'GB', 'country' => 'Reino Unido', 'region' => 'Inglaterra', 'city' => 'Londres' ), $out['locations']['81.2.69.160'] );
		// IPv6 with country only: region and city stay null instead of being invented.
		self::assertSame( array( 'country_code' => 'JP', 'country' => 'Japão', 'region' => null, 'city' => null ), $out['locations']['2001:218::'] );
		self::assertSame( 'Londres', $out['locations']['::81.2.69.160']['city'] );
		self::assertSame( array( 'country_code' => 'US', 'country' => 'EUA', 'region' => 'Washington', 'city' => 'Milton' ), $out['locations']['216.160.83.56'] );
		self::assertSame( 1, $this->opens );
	}

	public function test_country_database_returns_country_only(): void {
		$out = $this->geo( $this->copy( 'GeoIP2-Country-Test.mmdb' ) )->resolve( array( '89.160.20.112', '2a02:d300::' ) );
		self::assertSame( 'available', $out['state'] );
		self::assertSame( array( 'country_code' => 'SE', 'country' => 'Suécia', 'region' => null, 'city' => null ), $out['locations']['89.160.20.112'] );
		self::assertSame( 'UA', $out['locations']['2a02:d300::']['country_code'] );
	}

	public function test_address_without_result_is_absent_not_an_error(): void {
		$out = $this->geo( $this->copy( 'GeoIP2-City-Test.mmdb' ) )->resolve( array( '127.0.0.1', '2001:db8::1', '10.1.2.3', null, 'not-an-ip', '81.2.69.160' ) );
		self::assertSame( 'available', $out['state'] );
		self::assertSame( array( '81.2.69.160' ), array_keys( $out['locations'] ) );
	}

	public function test_absent_database_is_missing_without_opening(): void {
		$out = $this->geo( $this->dir . '/absent.mmdb' )->resolve( array( '81.2.69.160' ) );
		self::assertSame( array( 'state' => 'missing', 'built' => null, 'locations' => array() ), $out );
		self::assertSame( 0, $this->opens );
	}

	public function test_corrupt_databases_are_invalid_and_never_throw(): void {
		$random = $this->dir . '/random.mmdb';
		file_put_contents( $random, random_bytes( 4096 ) );
		$city = (string) file_get_contents( $this->source . '/GeoIP2-City-Test.mmdb' );
		$truncated = $this->dir . '/truncated.mmdb';
		file_put_contents( $truncated, substr( $city, 0, intdiv( strlen( $city ), 2 ) ) );
		$empty = $this->dir . '/empty.mmdb';
		touch( $empty );
		foreach ( array( $random, $truncated, $empty ) as $path ) {
			self::assertSame( array( 'state' => 'invalid', 'built' => null, 'locations' => array() ), $this->geo( $path )->resolve( array( '81.2.69.160' ) ), basename( $path ) );
		}
		// Valid metadata but a corrupt data section: the error surfaces during lookup, and no
		// partial result from earlier addresses is kept.
		$broken = $this->copy( 'GeoIP2-City-Test-Broken-Double-Format.mmdb' );
		$out = $this->geo( $broken )->resolve( array( '127.0.0.1', '81.2.69.160' ) );
		self::assertSame( array( 'state' => 'invalid', 'built' => null, 'locations' => array() ), $out );
		$opened = $this->geo( $broken )->resolve( array( '127.0.0.1' ) );
		self::assertSame( 'available', $opened['state'], 'No result is not corruption.' );
	}

	public function test_age_policy_marks_an_old_database_outdated(): void {
		$path = $this->copy( 'GeoIP2-City-Test.mmdb' );
		$built = $this->built( $path );
		$edge = $this->geo( $path, 30, $built + 30 * self::DAY )->resolve( array( '81.2.69.160' ) );
		self::assertSame( 'available', $edge['state'] );
		self::assertCount( 1, $edge['locations'] );
		$old = $this->geo( $path, 30, $built + 30 * self::DAY + 1 )->resolve( array( '81.2.69.160' ) );
		self::assertSame( array( 'state' => 'outdated', 'built' => $built, 'locations' => array() ), $old );
		self::assertSame( 'available', $this->geo( $path, 3650, $built + 31 * self::DAY )->resolve( array() )['state'] );
		// A build far in the future means the metadata (or the clock) cannot be trusted.
		self::assertSame( 'invalid', $this->geo( $path, 30, $built - 2 * self::DAY )->resolve( array( '81.2.69.160' ) )['state'] );
	}

	public function test_wrong_database_type_is_unsupported(): void {
		$path = $this->copy( 'GeoIP2-Anonymous-IP-Test.mmdb' );
		self::assertSame( array( 'state' => 'unsupported', 'built' => $this->built( $path ), 'locations' => array() ), $this->geo( $path )->resolve( array( '81.2.69.160' ) ) );
	}

	public function test_database_inside_the_webroot_is_refused(): void {
		$geo = new Geolocation( $this->copy( 'GeoIP2-City-Test.mmdb' ), 3650, $this->dir, static fn (): int => time(), $this->counter() );
		self::assertSame( array( 'state' => 'misplaced', 'built' => null, 'locations' => array() ), $geo->resolve( array( '81.2.69.160' ) ) );
		self::assertSame( 0, $this->opens );
	}

	public function test_reader_opens_once_per_query_for_unique_addresses_capped_at_100(): void {
		$geo = $this->geo( $this->copy( 'GeoIP2-City-Test.mmdb' ) );
		// 99 unique private addresses (no result), then London repeated, then Linköping as the
		// 101st unique address. Duplicates and malformed values do not use the quota.
		$page = array();
		for ( $i = 1; $i <= 99; ++$i ) {
			$page[] = '10.0.0.' . $i;
			$page[] = null;
			$page[] = '10.0.0.' . $i;
		}
		$page = array_merge( $page, array_fill( 0, 50, '81.2.69.160' ), array( 'x', '89.160.20.112', '216.160.83.56' ) );
		$out = $geo->resolve( $page );
		self::assertSame( 'available', $out['state'] );
		self::assertSame( array( '81.2.69.160' ), array_keys( $out['locations'] ), 'The 101st unique address is not resolved.' );
		self::assertSame( 1, $this->opens );
		$geo->resolve( array( '89.160.20.112' ) );
		self::assertSame( 2, $this->opens, 'One opening per query, never cached across queries.' );
		self::assertSame( 100, Geolocation::MAX_ADDRESSES );
	}

	public function test_resolver_has_no_network_or_storage_path(): void {
		$code = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Geo/Geolocation.php' );
		foreach ( array( 'wp_remote', 'curl_', 'fsockopen', 'stream_socket', 'file_get_contents', 'WebService', 'http', '$wpdb', 'update_option', 'set_transient', 'wp_cache_set', 'Storage' ) as $forbidden ) {
			self::assertStringNotContainsStringIgnoringCase( $forbidden, $code, $forbidden );
		}
	}

	private function geo( string $path, int $max_age_days = 3650, ?int $now = null ): Geolocation {
		return new Geolocation( $path, $max_age_days, dirname( __DIR__, 2 ), static fn (): int => $now ?? time(), $this->counter() );
	}

	private function counter(): \Closure {
		return function ( string $file, array $locales ): Reader {
			++$this->opens;
			return new Reader( $file, $locales );
		};
	}

	private function copy( string $name ): string {
		self::assertTrue( copy( $this->source . '/' . $name, $this->dir . '/' . $name ) );
		return $this->dir . '/' . $name;
	}

	private function built( string $path ): int {
		$reader = new Reader( $path );
		try {
			return $reader->metadata()->buildEpoch;
		} finally {
			$reader->close();
		}
	}
}
