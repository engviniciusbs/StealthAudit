<?php
// SPDX-License-Identifier: GPL-3.0-or-later
declare(strict_types=1);

namespace StealthAudit\Tests\Unit;

use PHPUnit\Framework\TestCase;
use StealthAudit\Diagnostics\Normalizer;

/** Bounded normalization tests; no claim of arbitrary-secret recognition. */
final class DiagnosticsNormalizerTest extends TestCase {

	public function test_plain_diagnostic_stays_useful_and_repeated_records_are_stable(): void {
		$normalizer = new Normalizer( '/srv/synthetic-site/' );
		$record = $normalizer->normalize( E_WARNING, 'Undefined fixture property', '/srv/synthetic-site/index.php', 42 );
		self::assertSame( 'Undefined fixture property', $record['message'] );
		self::assertSame( $record, $normalizer->normalize( E_WARNING, 'Undefined fixture property', '/srv/synthetic-site/index.php', 42 ) );
	}

	public function test_native_fatal_trace_arguments_are_removed_before_message_flattening(): void {
		$message = "Uncaught RuntimeException: Synthetic fatal failure in /srv/synthetic-site/example.php:42\n"
			. "Stack trace:\n"
			. "#0 /srv/synthetic-site/runner.php(12): fixture_operation('harmless-frame-argument')\n"
			. "#1 {main}\n  thrown";
		$record = ( new Normalizer( '/srv/synthetic-site/' ) )->normalize( E_ERROR, $message, '/srv/synthetic-site/example.php', 42 );
		self::assertStringContainsString( 'Synthetic fatal failure', $record['message'] );
		self::assertStringNotContainsString( 'harmless-frame-argument', $record['message'] );
		self::assertStringNotContainsString( 'fixture_operation', $record['message'] );
		self::assertStringNotContainsString( 'Stack trace:', $record['message'] );
		$this->assert_bounded_message( $record['message'] );
	}

	/** @dataProvider severities */
	public function test_php_levels_map_to_the_closed_storage_severity_set( int $level, string $expected ): void {
		$record = ( new Normalizer( '/srv/synthetic-site/' ) )->normalize( $level, 'Synthetic diagnostic', '/srv/synthetic-site/wp-includes/example.php', 12 );
		self::assertSame( $expected, $record['severity'] );
		self::assertSame( array( 'severity', 'message', 'file', 'line' ), array_keys( $record ) );
		self::assertSame( 'wp-includes/example.php', $record['file'] );
		self::assertSame( 12, $record['line'] );
	}

	public function test_normalizing_every_level_raises_no_diagnostic_of_its_own(): void {
		// Regression (PHP 8.4+): reading the deprecated E_STRICT constant emitted E_DEPRECATED
		// from inside the diagnostics path, which the collector would then record about itself.
		$raised = array();
		set_error_handler( static function ( int $level, string $message ) use ( &$raised ): bool {
			$raised[] = $level . ' ' . $message;
			return true;
		} );
		try {
			foreach ( self::severities() as [ $level ] ) {
				( new Normalizer( '/srv/synthetic-site/' ) )->normalize( $level, 'Synthetic diagnostic', '/srv/synthetic-site/index.php', 1 );
			}
		} finally {
			restore_error_handler();
		}
		self::assertSame( array(), $raised );
	}

	public static function severities(): array {
		return array(
			'warning' => array( E_WARNING, 'warning' ),
			'user warning' => array( E_USER_WARNING, 'warning' ),
			'core warning' => array( E_CORE_WARNING, 'warning' ),
			'compile warning' => array( E_COMPILE_WARNING, 'warning' ),
			'notice' => array( E_NOTICE, 'notice' ),
			'user notice' => array( E_USER_NOTICE, 'notice' ),
			'legacy strict (2048)' => array( 2048, 'notice' ),
			'deprecated' => array( E_DEPRECATED, 'deprecated' ),
			'user deprecated' => array( E_USER_DEPRECATED, 'deprecated' ),
			'fatal' => array( E_ERROR, 'fatal' ),
			'parse' => array( E_PARSE, 'fatal' ),
			'core fatal' => array( E_CORE_ERROR, 'fatal' ),
			'compile fatal' => array( E_COMPILE_ERROR, 'fatal' ),
			'user fatal' => array( E_USER_ERROR, 'fatal' ),
			'recoverable' => array( E_RECOVERABLE_ERROR, 'fatal' ),
			'unknown' => array( 0, 'error' ),
		);
	}

	/** @dataProvider sensitive_messages */
	public function test_known_sensitive_message_patterns_remove_synthetic_values( string $message, array $sentinels ): void {
		$record = ( new Normalizer( '/srv/synthetic-site/' ) )->normalize( E_WARNING, $message, '/srv/synthetic-site/index.php', 1 );
		foreach ( $sentinels as $sentinel ) {
			self::assertStringNotContainsString( $sentinel, $record['message'] );
		}
		$this->assert_bounded_message( $record['message'] );
	}

	public static function sensitive_messages(): array {
		return array(
			'password assignment' => array( 'Connection failed password=harmless-fixture-password', array( 'harmless-fixture-password' ) ),
			'passwd quoted' => array( 'Option passwd: "harmless fixture phrase"', array( 'harmless fixture phrase' ) ),
			'token assignment' => array( 'token=harmless-fixture-value', array( 'harmless-fixture-value' ) ),
			'API key assignment' => array( 'api_key: harmless-fixture-value', array( 'harmless-fixture-value' ) ),
			'secret assignment' => array( 'secret=harmless-fixture-value', array( 'harmless-fixture-value' ) ),
			'query authorization' => array( 'Request https://example.invalid/path?authorization=harmless-query-value&mode=fixture', array( 'harmless-query-value' ) ),
			'bearer authorization' => array( 'Authorization: Bearer harmless-bearer-value', array( 'harmless-bearer-value' ) ),
			'cookie header' => array( 'Cookie: fixture_session=harmless-cookie-value', array( 'harmless-cookie-value' ) ),
			'DSN userinfo' => array( 'Connection mysql://fixture-user:harmless-dsn-value@example.invalid/db failed', array( 'fixture-user', 'harmless-dsn-value' ) ),
			'Unix external path' => array( 'Failed at /private/synthetic-sentinel/component.php:42', array( '/private/synthetic-sentinel' ) ),
			'Windows external path' => array( 'Failed at C:\\private\\synthetic-sentinel\\component.php:42', array( 'C:\\private\\synthetic-sentinel' ) ),
			'site absolute path' => array( 'Failed at /srv/synthetic-site/wp-includes/example.php', array( '/srv/synthetic-site' ) ),
		);
	}

	/** @dataProvider diagnostic_messages */
	public function test_messages_remain_nonempty_valid_utf8_and_bounded( string $message ): void {
		$record = ( new Normalizer( '/srv/synthetic-site/' ) )->normalize( E_NOTICE, $message, '/srv/synthetic-site/index.php', 1 );
		$this->assert_bounded_message( $record['message'] );
		self::assertSame( 0, preg_match( '/[\x00-\x1f\x7f]/', $record['message'] ) );
	}

	public static function diagnostic_messages(): array {
		return array(
			'plain' => array( 'Synthetic diagnostic only' ),
			'empty' => array( '' ),
			'whitespace' => array( " \r\n\t " ),
			'control characters' => array( "Synthetic\x00value\x01\tline\r\nnext\x7f" ),
			'invalid UTF8' => array( "Synthetic\xC3\x28\xFFtext" ),
			'long ASCII' => array( str_repeat( 'a', 4096 ) ),
			'long unicode' => array( str_repeat( '診断😀é', 500 ) ),
			'unicode byte boundary' => array( str_repeat( 'a', 1023 ) . '😀' ),
		);
	}

	/** @dataProvider filenames */
	public function test_file_paths_are_safe_relative_or_external_fallback( string $root, string $file, string $expected ): void {
		$record = ( new Normalizer( $root ) )->normalize( E_WARNING, 'Synthetic diagnostic', $file, 1 );
		self::assertSame( $expected, $record['file'] );
		self::assertLessThanOrEqual( 255, strlen( $record['file'] ) );
		self::assertMatchesRegularExpression( '~^(?:[a-zA-Z0-9_.-]+/)*[a-zA-Z0-9_.-]*$~D', $record['file'] );
		self::assertStringNotContainsString( '..', $record['file'] );
	}

	public static function filenames(): array {
		return array(
			'root trailing slash' => array( '/srv/synthetic-site/', '/srv/synthetic-site/wp-content/example.php', 'wp-content/example.php' ),
			'root no slash' => array( '/srv/synthetic-site', '/srv/synthetic-site/index.php', 'index.php' ),
			'neighbor root' => array( '/srv/synthetic-site/', '/srv/synthetic-site-extra/private.php', 'external.php' ),
			'external Unix' => array( '/srv/synthetic-site/', '/private/synthetic-file.php', 'external.php' ),
			'external Windows' => array( '/srv/synthetic-site/', 'C:\\private\\synthetic-file.php', 'external.php' ),
			'Windows root' => array( 'C:\\synthetic-site\\', 'C:\\synthetic-site\\wp-content\\example.php', 'wp-content/example.php' ),
			'Windows neighbor root' => array( 'C:\\synthetic-site\\', 'C:\\synthetic-site-extra\\private.php', 'external.php' ),
			'parent traversal' => array( '/srv/synthetic-site/', '/srv/synthetic-site/../private.php', 'external.php' ),
			'invalid path bytes' => array( '/srv/synthetic-site/', "/srv/synthetic-site/bad\x00.php", 'external.php' ),
			'oversized relative name' => array( '/srv/synthetic-site/', '/srv/synthetic-site/' . str_repeat( 'a', 256 ) . '.php', 'external.php' ),
		);
	}

	/** @dataProvider lines */
	public function test_line_numbers_stay_in_storage_range( int $line ): void {
		$record = ( new Normalizer( '/srv/synthetic-site/' ) )->normalize( E_WARNING, 'Synthetic diagnostic', '/srv/synthetic-site/index.php', $line );
		self::assertIsInt( $record['line'] );
		self::assertGreaterThanOrEqual( 0, $record['line'] );
		self::assertLessThanOrEqual( 2147483647, $record['line'] );
	}

	public static function lines(): array {
		return array( array( -1 ), array( 0 ), array( 1 ), array( 2147483647 ), array( PHP_INT_MAX ) );
	}

	private function assert_bounded_message( string $message ): void {
		self::assertNotSame( '', $message );
		self::assertLessThanOrEqual( 1024, strlen( $message ) );
		self::assertSame( 1, preg_match( '//u', $message ) );
	}
}
