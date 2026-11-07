<?php
// SPDX-License-Identifier: GPL-3.0-or-later
declare(strict_types=1);

namespace StealthAudit\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use StealthAudit\Security\SecretCipher;

final class SecretCipherTest extends TestCase {
	private function cipher(): SecretCipher {
		return new SecretCipher( str_repeat( 'K', 32 ), 'test-key', 'installation-test' );
	}

	public function test_random_nonces_round_trip_binary_and_boundary_secrets(): void {
		foreach ( array( 'JBSWY3DPEHPK3PXP', "\0\xffbinary", str_repeat( 'x', 256 ) ) as $secret ) {
			$one = $this->cipher()->encrypt( $secret, 7 );
			$two = $this->cipher()->encrypt( $secret, 7 );
			self::assertNotSame( $one, $two );
			self::assertSame( $secret, $this->cipher()->decrypt( $one, 7 ) );
			self::assertSame( $secret, $this->cipher()->decrypt( $two, 7 ) );
			self::assertStringNotContainsString( $secret, $one );
		}
	}

	public function test_key_identity_installation_and_user_are_bound(): void {
		$envelope = $this->cipher()->encrypt( 'JBSWY3DPEHPK3PXP', 7 );
		self::assertNull( ( new SecretCipher( str_repeat( 'L', 32 ), 'test-key', 'installation-test' ) )->decrypt( $envelope, 7 ) );
		self::assertNull( ( new SecretCipher( str_repeat( 'K', 32 ), 'other-key', 'installation-test' ) )->decrypt( $envelope, 7 ) );
		self::assertNull( ( new SecretCipher( str_repeat( 'K', 32 ), 'test-key', 'other-installation' ) )->decrypt( $envelope, 7 ) );
		self::assertNull( $this->cipher()->decrypt( $envelope, 8 ) );
		self::assertNull( $this->cipher()->decrypt( $envelope, 0 ) );
	}

	public function test_every_envelope_field_rejects_tampering(): void {
		$original = json_decode( $this->cipher()->encrypt( 'factor-secret', 7 ), true, 4, JSON_THROW_ON_ERROR );
		foreach ( array( 'v' => 2, 'kid' => 'foreign', 'nonce' => base64_encode( str_repeat( 'a', 24 ) ), 'ciphertext' => base64_encode( str_repeat( 'b', 32 ) ) ) as $field => $value ) {
			$data = $original;
			$data[$field] = $value;
			self::assertNull( $this->cipher()->decrypt( json_encode( $data, JSON_THROW_ON_ERROR ), 7 ) );
		}
		foreach ( array_keys( $original ) as $field ) {
			$data = $original;
			unset( $data[$field] );
			self::assertNull( $this->cipher()->decrypt( json_encode( $data, JSON_THROW_ON_ERROR ), 7 ) );
		}
		$original['extra'] = true;
		self::assertNull( $this->cipher()->decrypt( json_encode( $original, JSON_THROW_ON_ERROR ), 7 ) );
	}

	public function test_malformed_and_unbounded_envelopes_fail_closed(): void {
		foreach ( array( '', '{', 'null', '[]', 'true', str_repeat( 'x', 2049 ), '{"v":1,"kid":"test-key","nonce":[],"ciphertext":"abc"}' ) as $input ) {
			self::assertNull( $this->cipher()->decrypt( $input, 7 ) );
		}
		$original = json_decode( $this->cipher()->encrypt( 'secret', 7 ), true, 4, JSON_THROW_ON_ERROR );
		foreach ( array( '!', '', 'YQ==', str_repeat( 'A', 400 ), "\n" . $original['nonce'], rtrim( $original['ciphertext'], '=' ) . '!' ) as $bad ) {
			foreach ( array( 'nonce', 'ciphertext' ) as $field ) {
				$data = $original;
				$data[$field] = $bad;
				self::assertNull( $this->cipher()->decrypt( json_encode( $data, JSON_THROW_ON_ERROR ), 7 ) );
			}
		}
	}

	public function test_configuration_and_inputs_are_bounded(): void {
		foreach ( array( array( 'short', 'key', 'site' ), array( str_repeat( 'K', 32 ), '', 'site' ), array( str_repeat( 'K', 32 ), 'key', '' ), array( str_repeat( 'K', 32 ), 'key', 'https://example.test' ), array( str_repeat( 'K', 32 ), 'key', str_repeat( 'a', 129 ) ) ) as $args ) {
			try {
				new SecretCipher( ...$args );
				self::fail( 'Expected invalid configuration.' );
			} catch ( InvalidArgumentException $error ) {
				self::assertSame( 'Invalid cipher configuration.', $error->getMessage() );
			}
		}
		foreach ( array( array( '', 7 ), array( str_repeat( 's', 257 ), 7 ), array( 'secret', 0 ) ) as $args ) {
			try {
				$this->cipher()->encrypt( ...$args );
				self::fail( 'Expected invalid cipher input.' );
			} catch ( InvalidArgumentException $error ) {
				self::assertSame( 'Invalid cipher input.', $error->getMessage() );
			}
		}
	}

	public function test_debug_and_serialization_do_not_disclose_key(): void {
		ob_start();
		var_dump( $this->cipher() );
		$debug = ob_get_clean();
		self::assertStringNotContainsString( str_repeat( 'K', 32 ), $debug );
		self::assertStringContainsString( '[redacted]', $debug );
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Cipher serialization is prohibited.' );
		serialize( $this->cipher() );
	}
}
