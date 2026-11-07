<?php
// SPDX-License-Identifier: GPL-3.0-or-later
declare(strict_types=1);

namespace StealthAudit\Tests\Unit;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use DateTimeImmutable;
use DOMDocument;
use DOMXPath;
use ParagonIE\ConstantTime\Base32;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use RuntimeException;
use StealthAudit\Security\TotpProvider;

final class TotpProviderTest extends TestCase {

	// Public RFC 6238 SHA-1 test key, never an operator credential.
	private const SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

	/** @dataProvider rfc_vectors */
	public function test_rfc6238_sha1_vectors_with_six_digit_truncation( int $timestamp, string $code ): void {
		self::assertSame( intdiv( $timestamp, 30 ), ( new TotpProvider() )->matching_step( self::SECRET, $code, $timestamp ) );
	}

	public static function rfc_vectors(): array {
		return array(
			array( 59, '287082' ),
			array( 1111111109, '081804' ),
			array( 1111111111, '050471' ),
			array( 1234567890, '005924' ),
			array( 2000000000, '279037' ),
			array( 20000000000, '353130' ),
		);
	}

	public function test_adjacent_steps_and_boundaries_are_exact_and_replay_is_callers_responsibility(): void {
		$provider = new TotpProvider();
		self::assertSame( 1, $provider->matching_step( self::SECRET, '287082', 0 ) );
		self::assertSame( 1, $provider->matching_step( self::SECRET, '287082', 29 ) );
		self::assertSame( 1, $provider->matching_step( self::SECRET, '287082', 30 ) );
		self::assertSame( 1, $provider->matching_step( self::SECRET, '287082', 59 ) );
		self::assertSame( 1, $provider->matching_step( self::SECRET, '287082', 60 ) );
		self::assertSame( 1, $provider->matching_step( self::SECRET, '287082', 89 ) );
		self::assertNull( $provider->matching_step( self::SECRET, '287082', 90 ) );
		self::assertSame( 0, $provider->matching_step( self::SECRET, '755224', 0 ) );
		self::assertSame( 1, $provider->matching_step( self::SECRET, '287082', 59 ) );
	}

	/** @dataProvider invalid_codes */
	public function test_invalid_code_fails_closed( string $code ): void {
		self::assertNull( ( new TotpProvider() )->matching_step( self::SECRET, $code, 59 ) );
	}

	public static function invalid_codes(): array {
		return array_map( static fn ( string $code ): array => array( $code ), array( '', '28708', '2870820', ' 287082', "287082\n", '２８７０８２', "28708\0", '+287082', '000000', str_repeat( '2', 10000 ) ) );
	}

	/** @dataProvider invalid_secrets */
	public function test_invalid_secret_fails_closed_and_qr_error_is_opaque( string $secret ): void {
		$provider = new TotpProvider();
		self::assertNull( $provider->matching_step( $secret, '287082', 59 ) );
		try {
			$provider->qr( $secret, 'RFC fixture' );
			self::fail( 'Invalid secret accepted.' );
		} catch ( RuntimeException $exception ) {
			self::assertSame( 'Authenticator setup unavailable.', $exception->getMessage() );
			self::assertNull( $exception->getPrevious() );
		}
	}

	public static function invalid_secrets(): array {
		return array_map( static fn ( string $secret ): array => array( $secret ), array( '', 'short', strtolower( self::SECRET ), self::SECRET . '=', self::SECRET . "\n", str_repeat( 'A', 33 ), str_repeat( 'A', 105 ), str_repeat( '0', 32 ), str_repeat( 'Ａ', 32 ), str_repeat( 'A', 31 ) . "\0" ) );
	}

	public function test_negative_timestamp_fails_closed_and_extreme_timestamp_does_not_overflow(): void {
		$provider = new TotpProvider();
		self::assertNull( $provider->matching_step( self::SECRET, '287082', -1 ) );
		self::assertNull( $provider->matching_step( self::SECRET, '287082', PHP_INT_MAX ) );
	}

	public function test_generation_is_160_bits_unique_and_canonical_without_output(): void {
		$provider = new TotpProvider();
		$secrets = array();
		ob_start();
		try {
			for ( $i = 0; $i < 16; ++$i ) {
				$secret = $provider->create_secret();
				self::assertMatchesRegularExpression( '/\A[A-Z2-7]{32}\z/', $secret );
				self::assertSame( 20, strlen( Base32::decodeUpper( $secret ) ) );
				$secrets[] = $secret;
			}
			self::assertSame( '', ob_get_contents() );
		} finally {
			ob_end_clean();
		}
		self::assertCount( 16, array_unique( $secrets ) );
	}

	public function test_explicit_timestamp_is_used_instead_of_clock(): void {
		$clock = new class() implements ClockInterface {
			public function now(): DateTimeImmutable {
				throw new RuntimeException( 'Verification must use the supplied timestamp.' );
			}
		};
		self::assertSame( 1, ( new TotpProvider( $clock ) )->matching_step( self::SECRET, '287082', 59 ) );
	}

	public function test_qr_encodes_expected_private_uri_with_custom_utf8_label_and_quiet_zone(): void {
		$label = 'Operador São & <QA>@example.test';
		$svg = ( new TotpProvider() )->qr( self::SECRET, $label );
		$uri = 'otpauth://totp/' . rawurlencode( 'StealthAudit:' . $label ) . '?issuer=StealthAudit&secret=' . self::SECRET;
		$writer = new Writer( new ImageRenderer( new RendererStyle( 320, 4 ), new SvgImageBackEnd() ) );
		self::assertSame( $writer->writeString( $uri, 'UTF-8' ), $svg );
		$document = new DOMDocument();
		self::assertTrue( $document->loadXML( $svg, LIBXML_NONET ) );
		$xpath = new DOMXPath( $document );
		$xpath->registerNamespace( 's', 'http://www.w3.org/2000/svg' );
		self::assertSame( '320', $document->documentElement->getAttribute( 'width' ) );
		self::assertSame( 1, $xpath->query( '//s:g[@transform="translate(4,4)"]' )->length );
		self::assertSame( 0, $xpath->query( '//*[local-name()="script" or local-name()="image" or local-name()="foreignObject"] | //@href | //@*[starts-with(local-name(), "on")]' )->length );
		self::assertStringNotContainsString( '<!DOCTYPE', $svg );
		self::assertStringNotContainsString( self::SECRET, $svg );
		self::assertStringNotContainsString( '<QA>', $svg );
		self::assertStringNotContainsString( 'otpauth:', $svg );
		self::assertNotSame( $svg, ( new TotpProvider() )->qr( self::SECRET, 'Different account' ) );
	}

	public function test_long_canonical_secret_is_supported(): void {
		$svg = ( new TotpProvider() )->qr( Base32::encodeUpperUnpadded( str_repeat( 'a', 64 ) ), 'Bounded test' );
		self::assertStringContainsString( '<svg', $svg );
	}

	public function test_missing_xmlwriter_fails_closed_with_opaque_error(): void {
		$script = 'if (class_exists("XMLWriter")) { exit(77); } require '
			. var_export( dirname( __DIR__, 2 ) . '/vendor/autoload.php', true )
			. '; try { (new \\StealthAudit\\Security\\TotpProvider())->qr("' . self::SECRET . '", "RFC fixture"); exit(1); }'
			. ' catch (\\RuntimeException $e) { echo $e->getMessage(); exit($e->getPrevious() === null ? 0 : 2); }';
		$process = proc_open( array( PHP_BINARY, '-n', '-r', $script ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		self::assertIsResource( $process );
		$output = stream_get_contents( $pipes[1] );
		$errors = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$exit = proc_close( $process );
		if ( 77 === $exit ) {
			self::markTestSkipped( 'XMLWriter is statically compiled in this PHP binary.' );
		}
		self::assertSame( 0, $exit, $errors );
		self::assertSame( 'Authenticator setup unavailable.', $output );
		self::assertSame( '', $errors );
	}

	/** @dataProvider invalid_labels */
	public function test_invalid_label_throws_only_opaque_error( string $label ): void {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Authenticator setup unavailable.' );
		( new TotpProvider() )->qr( self::SECRET, $label );
	}

	public static function invalid_labels(): array {
		return array_map( static fn ( string $label ): array => array( $label ), array( '', '  ', "test\nlabel", "test\0label", 'issuer:account', 'issuer%3Aaccount', str_repeat( 'a', 129 ), "invalid\xff", "invisible\u{200B}" ) );
	}
}
