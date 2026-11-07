<?php
// SPDX-License-Identifier: GPL-3.0-or-later
declare(strict_types=1);

namespace StealthAudit\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use StealthAudit\Config\Configuration;
use StealthAudit\Bootstrap\Requirements;

final class ConfigurationTest extends TestCase {
	public function test_default_denies_even_administrator_id(): void {
		$config = Configuration::from_array( array() );
		self::assertFalse( $config->allows_user( 1 ) );
		self::assertFalse( $config->has_key() );
	}

	public function test_explicit_ids_and_valid_key(): void {
		$config = Configuration::from_array( array(
			'authorized_user_ids' => array( 7, 7, 12 ),
			'key_base64' => base64_encode( str_repeat( 'a', 32 ) ),
			'key_id' => 'test-only',
			'trusted_proxies' => array( '192.0.2.0/24', '2001:db8::/32' ),
			'limits' => array( 'events_per_request' => 2 ),
		) );
		self::assertTrue( $config->allows_user( 7 ) );
		self::assertFalse( $config->allows_user( 1 ) );
		self::assertTrue( $config->has_key() );
		self::assertSame( 2, $config->limits['events_per_request'] );
		self::assertSame( 100000, $config->limits['event_rows'] );
	}

	/** @dataProvider invalid_configuration */
	public function test_invalid_security_configuration_rejected( array $input ): void {
		$this->expectException( InvalidArgumentException::class );
		Configuration::from_array( $input );
	}

	public static function invalid_configuration(): array {
		return array(
			array( array( 'authorized_user_ids' => array( '1' ) ) ),
			array( array( 'authorized_user_ids' => array( 0 ) ) ),
			array( array( 'endpoint' => '//evil.test' ) ),
			array( array( 'endpoint' => '/ok?bypass=1' ) ),
			array( array( 'endpoint' => '/../wp-admin' ) ),
			array( array( 'key_base64' => 'not-base64!' ) ),
			array( array( 'key_base64' => base64_encode( 'short' ), 'key_id' => 'test' ) ),
			array( array( 'key_base64' => base64_encode( str_repeat( 'a', 32 ) ) ) ),
			array( array( 'trusted_proxies' => array( '0.0.0.0/33' ) ) ),
			array( array( 'limits' => array( 'events_per_request' => 0 ) ) ),
			array( array( 'limits' => array( 'unknown' => 1 ) ) ),
			array( array( 'mmdb_path' => 'https://remote.example/db' ) ),
		);
	}

	public function test_panel_extensions_do_not_disable_collection(): void {
		$requirements = new Requirements( array( 'mysqli', 'json' ) );
		self::assertSame( array(), $requirements->collection_missing() );
		self::assertSame( array( 'sodium', 'iconv', 'xmlwriter' ), $requirements->panel_missing() );
	}
}
