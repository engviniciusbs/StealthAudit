<?php
// SPDX-License-Identifier: GPL-3.0-or-later
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use StealthAudit\Http\Filters;

/** Server-side filter validation is strict and independent of any interface. */
final class HttpFiltersTest extends TestCase {
	public function test_defaults_and_normalization(): void {
		$query = Filters::events( array() );
		self::assertSame( 50, $query['limit'] );
		self::assertSame( array(), $query['filters'] );
		self::assertNull( $query['from'] );
		self::assertNull( $query['after'] );
		$query = Filters::events( array( 'user' => '7', 'category' => 'access', 'action' => 'mfa_access', 'result' => 'failure', 'ip' => '2001:DB8:0:0:0:0:0:1', 'limit' => '100', 'cursor' => '1700000000-42', 'from' => '2026-11-01T00:00:00Z', 'to' => '2026-11-01T03:00:00+03:00', 'rest_route' => '/stealthaudit/v1/events' ) );
		self::assertSame( array( 'actor_id' => 7, 'category' => 'access', 'action' => 'mfa_access', 'result' => 'failure', 'ip' => '2001:db8::1' ), $query['filters'] );
		self::assertSame( 100, $query['limit'] );
		self::assertSame( array( 1700000000, 42 ), $query['after'] );
		self::assertSame( gmmktime( 0, 0, 0, 11, 1, 2026 ), $query['from'] );
		self::assertSame( $query['from'], $query['to'] );
		self::assertArrayNotHasKey( 'cursor', $query['query'] );
		self::assertSame( '1700000000-42', Filters::cursor( $query['after'] ) );
		self::assertSame( '2026-11-01T00:00:00Z', Filters::format( $query['from'] ) );
		self::assertSame( array( 'severity' => 'fatal' ), Filters::errors( array( 'severity' => 'fatal' ) )['filters'] );
	}

	/** @dataProvider malformedEvents */
	public function test_malformed_audit_filters_are_rejected( array $params ): void {
		$this->expectException( InvalidArgumentException::class );
		Filters::events( $params );
	}

	public static function malformedEvents(): array {
		return array(
			'bad date'          => array( array( 'from' => '2026-02-30T00:00:00Z' ) ),
			'date only'         => array( array( 'from' => '2026-11-01' ) ),
			'bad hour'          => array( array( 'to' => '2026-11-01T24:00:00Z' ) ),
			'bad offset'        => array( array( 'to' => '2026-11-01T00:00:00+15:00' ) ),
			'pre epoch'         => array( array( 'from' => '1969-12-31T23:59:59Z' ) ),
			'inverted period'   => array( array( 'from' => '2026-11-02T00:00:00Z', 'to' => '2026-11-01T00:00:00Z' ) ),
			'oversize'          => array( array( 'action' => str_repeat( 'a', 65 ) ) ),
			'empty'             => array( array( 'category' => '' ) ),
			'array value'       => array( array( 'category' => array( 'access' ) ) ),
			'unknown key'       => array( array( 'orderby' => 'id' ) ),
			'nonce in url'      => array( array( '_wpnonce' => 'abc' ) ),
			'unknown category'  => array( array( 'category' => 'secrets' ) ),
			'unknown action'    => array( array( 'action' => 'login OR 1=1' ) ),
			'bad ip'            => array( array( 'ip' => '999.1.1.1' ) ),
			'ip with port'      => array( array( 'ip' => '192.0.2.1:80' ) ),
			'user zero'         => array( array( 'user' => '0' ) ),
			'user negative'     => array( array( 'user' => '-1' ) ),
			'user float'        => array( array( 'user' => '1.5' ) ),
			'limit over'        => array( array( 'limit' => '101' ) ),
			'limit zero'        => array( array( 'limit' => '0' ) ),
			'limit padded'      => array( array( 'limit' => '050' ) ),
			'cursor text'       => array( array( 'cursor' => 'abc' ) ),
			'cursor zero id'    => array( array( 'cursor' => '1700000000-0' ) ),
			'cursor sql'        => array( array( 'cursor' => '1-1 OR 1=1' ) ),
			'cursor huge time'  => array( array( 'cursor' => '999999999999-1' ) ),
			'too many params'   => array( array_fill_keys( range( 'a', 'q' ), 'x' ) ),
			'severity on audit' => array( array( 'severity' => 'fatal' ) ),
		);
	}

	public function test_malformed_error_filters_are_rejected(): void {
		foreach ( array( array( 'severity' => 'critical' ), array( 'category' => 'access' ), array( 'limit' => '1000' ) ) as $params ) {
			try {
				Filters::errors( $params );
				self::fail( 'Expected rejection.' );
			} catch ( InvalidArgumentException $failure ) {
				self::assertSame( 'Invalid filter.', $failure->getMessage() );
			}
		}
	}
}
