<?php
/**
 * Installation-bound encryption for panel factor secrets.
 *
 * @license GPL-3.0-or-later
 * @package StealthAudit
 */

declare(strict_types=1);

namespace StealthAudit\Security;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** A bounded, versioned Sodium AEAD envelope with external key material. */
final readonly class SecretCipher {

	/**
	 * Validate external encryption context.
	 *
	 * @param string $key Raw installation key, exactly 32 bytes.
	 * @param string $key_id Operator-owned key identifier.
	 * @param string $installation_id Stable external identity, never a URL or path.
	 * @throws InvalidArgumentException When configuration is malformed.
	 * @throws RuntimeException When Sodium is unavailable.
	 */
	public function __construct(
		#[\SensitiveParameter] private string $key,
		private string $key_id,
		private string $installation_id,
	) {
		if ( 32 !== strlen( $key ) || ! preg_match( '/^[a-zA-Z0-9_-]{1,64}$/D', $key_id ) || ! preg_match( '/^[a-zA-Z0-9_-]{1,128}$/D', $installation_id ) ) {
			throw new InvalidArgumentException( 'Invalid cipher configuration.' );
		}
		if ( ! function_exists( 'sodium_crypto_aead_xchacha20poly1305_ietf_encrypt' ) || ! function_exists( 'sodium_crypto_aead_xchacha20poly1305_ietf_decrypt' ) ) {
			throw new RuntimeException( 'Cipher unavailable.' );
		}
	}

	/**
	 * Encrypt one bounded secret using a fresh random nonce.
	 *
	 * @param string $secret Factor secret.
	 * @param int    $user_id WordPress identity.
	 * @return string JSON envelope containing only public context and ciphertext.
	 * @throws InvalidArgumentException When input is malformed.
	 * @throws RuntimeException When encryption fails.
	 */
	public function encrypt( #[\SensitiveParameter] string $secret, int $user_id ): string {
		if ( $user_id < 1 || '' === $secret || strlen( $secret ) > 256 ) {
			throw new InvalidArgumentException( 'Invalid cipher input.' );
		}
		try {
			$nonce      = random_bytes( SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES );
			$ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt( $secret, $this->aad( $user_id ), $nonce, $this->key );
			return json_encode( // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Strict cryptographic serialization must fail instead of repairing input bytes.
				array(
					'v'          => 1,
					'kid'        => $this->key_id,
					'nonce'      => base64_encode( $nonce ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- JSON-safe nonce encoding.
					'ciphertext' => base64_encode( $ciphertext ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- JSON-safe authenticated ciphertext encoding.
				),
				JSON_THROW_ON_ERROR
			);
		} catch ( Throwable ) {
			throw new RuntimeException( 'Cipher operation failed.' );
		}
	}

	/**
	 * Authenticate and decrypt; reject every malformed or foreign envelope.
	 *
	 * @param string $envelope Untrusted persisted envelope.
	 * @param int    $user_id Expected WordPress identity.
	 * @return string|null Secret, or null when authentication fails.
	 */
	public function decrypt( string $envelope, int $user_id ): ?string {
		if ( $user_id < 1 || strlen( $envelope ) > 2048 ) {
			return null;
		}
		try {
			$data = json_decode( $envelope, true, 4, JSON_THROW_ON_ERROR );
			if ( ! is_array( $data ) || count( $data ) !== 4 || ( $data['v'] ?? null ) !== 1 || ( $data['kid'] ?? null ) !== $this->key_id || ! isset( $data['nonce'], $data['ciphertext'] ) || ! is_string( $data['nonce'] ) || ! is_string( $data['ciphertext'] ) ) {
				return null;
			}
			$nonce      = self::decode( $data['nonce'] );
			$ciphertext = self::decode( $data['ciphertext'] );
			if ( null === $nonce || null === $ciphertext || strlen( $nonce ) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES || strlen( $ciphertext ) < 17 || strlen( $ciphertext ) > 272 ) {
				return null;
			}
			$secret = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt( $ciphertext, $this->aad( $user_id ), $nonce, $this->key );
			return false === $secret ? null : $secret;
		} catch ( Throwable ) {
			return null;
		}
	}

	/**
	 * Hide the raw key from diagnostic dumps.
	 *
	 * @return array<string,string>
	 */
	public function __debugInfo(): array {
		return array( 'key' => '[redacted]' );
	}

	/**
	 * Prevent accidental key persistence through object serialization.
	 *
	 * @return array<never,never>
	 * @throws RuntimeException Always; external key material is not serializable.
	 */
	public function __serialize(): array {
		return throw new RuntimeException( 'Cipher serialization is prohibited.' );
	}

	/**
	 * Bind version, key, installation and user without ambiguous concatenation.
	 *
	 * @param int $user_id Expected identity.
	 * @return string Authenticated context.
	 */
	private function aad( int $user_id ): string {
		return json_encode( array( 'stealthaudit-factor', 1, $this->key_id, $this->installation_id, $user_id ), JSON_THROW_ON_ERROR ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Authenticated context requires exact bytes without WordPress normalization.
	}

	/**
	 * Decode only canonical standard Base64.
	 *
	 * @param string $encoded Encoded binary field.
	 * @return string|null Decoded bytes, or null for malformed encoding.
	 */
	private static function decode( string $encoded ): ?string {
		$decoded = base64_decode( $encoded, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decode bounded encrypted fields, never executable code.
		return false !== $decoded && base64_encode( $decoded ) === $encoded ? $decoded : null; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Enforce canonical Base64 encoding.
	}
}
