<?php
// SPDX-License-Identifier: GPL-3.0-or-later
declare(strict_types=1);

namespace StealthAudit\Tests\Unit;

use PHPUnit\Framework\TestCase;
use StealthAudit\Audit\Context;

final class AuditContextTest extends TestCase {

	public function test_direct_client_cannot_forge_a_forwarded_identity(): void {
		$server = array(
			'REMOTE_ADDR' => '198.51.100.20',
			'HTTP_X_FORWARDED_FOR' => '203.0.113.99',
			'HTTP_FORWARDED' => 'for=203.0.113.99',
			'HTTP_X_REAL_IP' => '203.0.113.99',
			'HTTP_CF_CONNECTING_IP' => '203.0.113.99',
		);
		self::assertSame( '198.51.100.20', ( new Context() )->ip( $server ) );
		self::assertSame( '198.51.100.20', ( new Context( array( '192.0.2.0/24' ) ) )->ip( $server ) );
	}

	public function test_alternative_forwarding_headers_are_not_trusted_even_from_proxy(): void {
		self::assertSame( '192.0.2.1', ( new Context( array( '192.0.2.1' ) ) )->ip( array(
			'REMOTE_ADDR' => '192.0.2.1',
			'HTTP_FORWARDED' => 'for=203.0.113.99',
			'HTTP_X_REAL_IP' => '203.0.113.99',
			'HTTP_CF_CONNECTING_IP' => '203.0.113.99',
		) ) );
	}

	/** @dataProvider invalid_peers */
	public function test_invalid_or_missing_peer_never_uses_a_header( array $server ): void {
		$server['HTTP_X_FORWARDED_FOR'] = '203.0.113.99';
		self::assertNull( ( new Context( array( '0.0.0.0/0', '::/0' ) ) )->ip( $server ) );
	}

	public static function invalid_peers(): array {
		return array(
			'missing' => array( array() ),
			'null' => array( array( 'REMOTE_ADDR' => null ) ),
			'array' => array( array( 'REMOTE_ADDR' => array( '192.0.2.1' ) ) ),
			'port' => array( array( 'REMOTE_ADDR' => '192.0.2.1:443' ) ),
			'zone' => array( array( 'REMOTE_ADDR' => 'fe80::1%eth0' ) ),
			'whitespace' => array( array( 'REMOTE_ADDR' => ' 192.0.2.1 ' ) ),
			'invalid' => array( array( 'REMOTE_ADDR' => '999.0.0.1' ) ),
		);
	}

	public function test_walk_stops_at_first_untrusted_hop_from_the_right(): void {
		$context = new Context( array( '192.0.2.0/24', '2001:db8:abcd::/48' ) );
		self::assertSame( '198.51.100.8', $context->ip( array(
			'REMOTE_ADDR' => '192.0.2.10',
			'HTTP_X_FORWARDED_FOR' => '203.0.113.99, 198.51.100.8, 2001:db8:abcd::2, 192.0.2.11',
		) ) );
	}

	public function test_all_trusted_chain_returns_leftmost_address(): void {
		self::assertSame( '192.0.2.4', ( new Context( array( '192.0.2.0/24' ) ) )->ip( array(
			'REMOTE_ADDR' => '192.0.2.1',
			'HTTP_X_FORWARDED_FOR' => '192.0.2.4, 192.0.2.3',
		) ) );
	}

	/** @dataProvider trust_boundaries */
	public function test_cidr_and_exact_address_trust_boundaries( array $proxies, string $peer, string $expected ): void {
		self::assertSame( $expected, ( new Context( $proxies ) )->ip( array(
			'REMOTE_ADDR' => $peer,
			'HTTP_X_FORWARDED_FOR' => '203.0.113.42',
		) ) );
	}

	public static function trust_boundaries(): array {
		return array(
			'exact IPv4' => array( array( '192.0.2.1' ), '192.0.2.1', '203.0.113.42' ),
			'IPv4 neighbor' => array( array( '192.0.2.1' ), '192.0.2.2', '192.0.2.2' ),
			'IPv4 partial byte inside' => array( array( '192.0.2.128/25' ), '192.0.2.255', '203.0.113.42' ),
			'IPv4 partial byte outside' => array( array( '192.0.2.128/25' ), '192.0.2.127', '192.0.2.127' ),
			'IPv4 zero prefix' => array( array( '0.0.0.0/0' ), '198.51.100.2', '203.0.113.42' ),
			'exact IPv6 canonicalized' => array( array( '2001:db8::1' ), '2001:0DB8:0000:0000:0000:0000:0000:0001', '203.0.113.42' ),
			'IPv6 partial byte inside' => array( array( '2001:db8:8000::/33' ), '2001:db8:ffff::1', '203.0.113.42' ),
			'IPv6 partial byte outside' => array( array( '2001:db8:8000::/33' ), '2001:db8:7fff::1', '2001:db8:7fff::1' ),
			'IPv6 zero prefix' => array( array( '::/0' ), '2001:db8::1', '203.0.113.42' ),
			'IPv4 trust does not cover mapped IPv6' => array( array( '192.0.2.0/24' ), '::ffff:192.0.2.1', '::ffff:192.0.2.1' ),
			'IPv6 trust does not cover IPv4' => array( array( '::/0' ), '192.0.2.1', '192.0.2.1' ),
		);
	}

	public function test_forwarded_ipv6_is_canonicalized_and_whitespace_trimmed(): void {
		self::assertSame( '2001:db8::7', ( new Context( array( '192.0.2.1' ) ) )->ip( array(
			'REMOTE_ADDR' => '192.0.2.1',
			'HTTP_X_FORWARDED_FOR' => " \t2001:0DB8:0000:0000:0000:0000:0000:0007 \t",
		) ) );
		self::assertSame( '2001:db8::7', ( new Context() )->ip( array( 'REMOTE_ADDR' => '2001:0DB8:0:0:0:0:0:7' ) ) );
	}

	/** @dataProvider invalid_headers */
	public function test_bad_forwarding_data_falls_back_to_direct_peer( mixed $header ): void {
		self::assertSame( '192.0.2.1', ( new Context( array( '192.0.2.1' ) ) )->ip( array(
			'REMOTE_ADDR' => '192.0.2.1',
			'HTTP_X_FORWARDED_FOR' => $header,
		) ) );
	}

	public static function invalid_headers(): array {
		return array(
			'missing' => array( null ),
			'array' => array( array( '203.0.113.1' ) ),
			'empty' => array( '' ),
			'empty element' => array( '203.0.113.1,,192.0.2.2' ),
			'unknown' => array( 'unknown' ),
			'port' => array( '203.0.113.1:80' ),
			'bracketed IPv6' => array( '[2001:db8::1]' ),
			'zone' => array( 'fe80::1%eth0' ),
			'line injection' => array( "203.0.113.1\r\nX-Foo: injected" ),
			'malformed leftmost behind untrusted hop' => array( 'spoofed, 198.51.100.8' ),
			'over byte limit' => array( '203.0.113.1' . str_repeat( ' ', 2038 ) ),
			'over hop limit' => array( implode( ',', array_fill( 0, 33, '203.0.113.1' ) ) ),
		);
	}

	public function test_header_limits_accept_the_boundary(): void {
		$context = new Context( array( '192.0.2.1' ) );
		self::assertSame( '203.0.113.1', $context->ip( array(
			'REMOTE_ADDR' => '192.0.2.1',
			'HTTP_X_FORWARDED_FOR' => '203.0.113.1' . str_repeat( ' ', 2037 ),
		) ) );
		self::assertSame( '203.0.113.1', $context->ip( array(
			'REMOTE_ADDR' => '192.0.2.1',
			'HTTP_X_FORWARDED_FOR' => implode( ',', array_fill( 0, 32, '203.0.113.1' ) ),
		) ) );
	}

	/** @dataProvider execution_modes */
	public function test_origin_precedence( bool $cli, bool $cron, bool $rest, bool $admin, string $expected ): void {
		self::assertSame( $expected, Context::origin( $cli, $cron, $rest, $admin ) );
	}

	public static function execution_modes(): array {
		return array(
			'CLI overrides every web mode' => array( true, true, true, true, 'cli' ),
			'cron overrides REST and admin' => array( false, true, true, true, 'cron' ),
			'REST overrides admin' => array( false, false, true, true, 'rest' ),
			'admin request' => array( false, false, false, true, 'admin' ),
			'ordinary HTTP request' => array( false, false, false, false, 'http' ),
		);
	}
}
