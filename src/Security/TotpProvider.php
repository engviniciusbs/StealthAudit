<?php
/**
 * Local authenticator codes and provisioning images.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * @package StealthAudit
 */

declare(strict_types=1);

namespace StealthAudit\Security;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use DateTimeImmutable;
use InvalidArgumentException;
use OTPHP\TOTP;
use ParagonIE\ConstantTime\Base32;
use Psr\Clock\ClockInterface;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/** Generates the fixed SHA-1/six-digit/30-second panel profile. */
final class TotpProvider {

	/**
	 * Explicit clock required by OTPHP.
	 *
	 * @var ClockInterface
	 */
	private ClockInterface $clock;

	/**
	 * Supply a clock without relying on OTPHP's deprecated implicit clock.
	 *
	 * @param ClockInterface|null $clock Optional clock for deterministic callers.
	 */
	public function __construct( ?ClockInterface $clock = null ) {
		$this->clock = $clock ?? new class() implements ClockInterface {
			/** Get the current instant. */
			public function now(): DateTimeImmutable {
				return new DateTimeImmutable( 'now' );
			}
		};
	}

	/**
	 * Generate a fresh 160-bit secret. The caller must protect this value.
	 *
	 * @return string Unpadded canonical Base32 secret.
	 * @throws RuntimeException When secure generation fails.
	 */
	public function create_secret(): string {
		try {
			return TOTP::generate( $this->clock, 20 )->getSecret();
		} catch ( Throwable ) {
			throw new RuntimeException( 'Authenticator setup unavailable.' );
		}
	}

	/**
	 * Return the accepted step within one adjacent step of the supplied instant.
	 *
	 * Replay protection belongs to the caller's atomic persistent state update.
	 *
	 * @param string $secret Canonical Base32 secret.
	 * @param string $code Six ASCII digits, preserving leading zeroes.
	 * @param int    $now Unix timestamp in seconds.
	 * @return int|null Accepted step or null on any invalid input/failure.
	 */
	public function matching_step( #[SensitiveParameter] string $secret, #[SensitiveParameter] string $code, int $now ): ?int {
		if ( $now < 0 || strlen( $code ) !== 6 || 1 !== preg_match( '/\A[0-9]{6}\z/D', $code ) ) {
			return null;
		}
		try {
			$totp = $this->profile( $secret );
			$step = intdiv( $now, 30 );
			foreach ( array( $step, $step - 1, $step + 1 ) as $candidate ) {
				if ( $candidate >= 0 && $candidate <= intdiv( PHP_INT_MAX, 30 ) && $totp->verify( $code, $candidate * 30 ) ) {
					return $candidate;
				}
			}
		} catch ( Throwable ) {
			return null;
		}
		return null;
	}

	/**
	 * Render provisioning entirely in memory, including a four-module quiet zone.
	 *
	 * The returned SVG encodes the secret and must receive private/no-store delivery.
	 *
	 * @param string $secret Canonical Base32 secret.
	 * @param string $label Account label, valid UTF-8 and at most 128 bytes.
	 * @return string Private SVG image, with no remote asset references.
	 * @throws RuntimeException When input or required extensions are unavailable.
	 */
	public function qr( #[SensitiveParameter] string $secret, string $label ): string {
		try {
			$this->validate_label( $label );
			$totp   = $this->profile( $secret )->withIssuer( 'StealthAudit' )->withLabel( $label );
			$writer = new Writer( new ImageRenderer( new RendererStyle( 320, 4 ), new SvgImageBackEnd() ) );
			return $writer->writeString( $totp->getProvisioningUri(), 'UTF-8' );
		} catch ( Throwable ) {
			throw new RuntimeException( 'Authenticator setup unavailable.' );
		}
	}

	/**
	 * Reject malformed or ambiguous provisioning labels.
	 *
	 * @param string $label Account label.
	 * @throws InvalidArgumentException When the label cannot be provisioned safely.
	 */
	private function validate_label( string $label ): void {
		if ( strlen( $label ) > 128 || '' === trim( $label ) || 1 !== preg_match( '/\A[^\p{C}:]+\z/u', $label ) || false !== stripos( $label, '%3a' ) ) {
			throw new InvalidArgumentException( 'Invalid authenticator label.' );
		}
	}

	/**
	 * Validate canonical encoding before handing the secret to OTPHP.
	 *
	 * @param string $secret Bounded Base32 secret (160 to 512 bits).
	 * @return TOTP Fixed interoperable profile.
	 * @throws InvalidArgumentException When the secret is malformed.
	 */
	private function profile( #[SensitiveParameter] string $secret ): TOTP {
		if ( strlen( $secret ) < 32 || strlen( $secret ) > 104 || 1 !== preg_match( '/\A[A-Z2-7]+\z/D', $secret ) ) {
			throw new InvalidArgumentException( 'Invalid authenticator configuration.' );
		}
		$decoded = Base32::decodeUpper( $secret );
		if ( strlen( $decoded ) < 20 || strlen( $decoded ) > 64 || ! hash_equals( Base32::encodeUpperUnpadded( $decoded ), $secret ) ) {
			throw new InvalidArgumentException( 'Invalid authenticator configuration.' );
		}
		return TOTP::createFromSecret( $secret, $this->clock )->withPeriod( 30 )->withDigest( 'sha1' )->withDigits( 6 );
	}
}
