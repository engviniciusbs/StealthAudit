<?php
/**
 * Authoritative, bounded security state. Never stores raw session tokens.
 *
 * @package StealthAudit
 */

declare(strict_types=1);
namespace StealthAudit\Storage;

/** One-row CAS; callers must revalidate after any failed comparison. */
final class StateRepository {
	/**
	 * Construct.
	 *
	 * @param Database $db SQL boundary.
	 * @param int      $capacity Maximum allocated rows.
	 */
	public function __construct( private readonly Database $db, private readonly int $capacity = 10000 ) {}

	/**
	 * Fetch fresh state. Expired entries are logically absent, never renewed here.
	 *
	 * @param string $key Typed identifier; session/IP components must be digests.
	 * @param int    $now UTC Unix time.
	 * @return array{revision:int,payload:array<string,mixed>,expires_at:?string}|null State.
	 * @throws StorageException On database or malformed state.
	 */
	public function get( string $key, int $now ): ?array {
		self::validate_key( $key );
		$rows = $this->db->rows( 'SELECT revision,payload,expires_at FROM %i WHERE state_key=%s AND (expires_at IS NULL OR expires_at>%s)', $this->db->table( 'state' ), $key, gmdate( 'Y-m-d H:i:s', $now ) );
		if ( array() === $rows ) {
			return null;
		}
		try {
			$payload = json_decode( (string) $rows[0]['payload'], true, 16, JSON_THROW_ON_ERROR );
		} catch ( \JsonException $failure ) {
			throw new StorageException( 'Invalid stored state.' );
		}
		if ( ! is_array( $payload ) || (int) $rows[0]['revision'] < 1 ) {
			throw new StorageException( 'Invalid stored state.' );
		}
		return array(
			'revision'   => (int) $rows[0]['revision'],
			'payload'    => $payload,
			'expires_at' => $rows[0]['expires_at'],
		);
	}

	/**
	 * Create immutable identity with a conservatively reserved auxiliary slot.
	 *
	 * @param string              $key Typed key.
	 * @param array<string,mixed> $payload Validated service state, encrypted secrets only.
	 * @param int|null            $expires UTC timestamp; required except for factor/budget.
	 * @return bool False includes duplicate, capacity or contention.
	 * @throws StorageException On invalid state or database error.
	 */
	public function create( string $key, array $payload, ?int $expires ): bool {
		self::validate_key( $key );
		$encoded = self::encode( $payload );
		$this->validate_expiry( $key, $expires );
		if ( str_starts_with( $key, 'budget:' ) ) {
			throw new StorageException( 'Reserved state identity.' );
		}
		for ( $attempt = 0; $attempt < 3; ++$attempt ) {
			$budget = $this->get( 'budget:state', time() );
			if ( null === $budget || ! isset( $budget['payload']['rows'] ) || ! is_int( $budget['payload']['rows'] ) || $budget['payload']['rows'] < 3 || $budget['payload']['rows'] >= $this->capacity ) {
				return false;
			}
			$next = $budget['payload'];
			++$next['rows'];
			if ( $this->compare_and_swap( 'budget:state', $budget['revision'], $next, null, time() ) ) {
				// Failed insertion may overcount. Only maintenance can release capacity.
				$revision = random_int( 1, PHP_INT_MAX >> 1 );
				if ( null === $expires ) {
					return 1 === $this->db->execute( 'INSERT IGNORE INTO %i (state_key,revision,payload,expires_at) VALUES (%s,%d,%s,NULL)', $this->db->table( 'state' ), $key, $revision, $encoded );
				}
				return 1 === $this->db->execute( 'INSERT IGNORE INTO %i (state_key,revision,payload,expires_at) VALUES (%s,%d,%s,%s)', $this->db->table( 'state' ), $key, $revision, $encoded, gmdate( 'Y-m-d H:i:s', $expires ) );
			}
		}
		return false;
	}

	/**
	 * Reclaim an expired physical row in one conditional write, without a delete gap.
	 *
	 * @param string              $key Ephemeral identity.
	 * @param array<string,mixed> $payload Replacement payload.
	 * @param int                 $expires Future expiry.
	 * @param int                 $now Logical current time.
	 * @throws StorageException On invalid state or SQL failure.
	 */
	public function replace_expired( string $key, array $payload, int $expires, int $now ): bool {
		self::validate_key( $key );
		$this->validate_expiry( $key, $expires );
		if ( ! preg_match( '/^(proof|limit):/', $key ) || $expires <= $now ) {
			throw new StorageException( 'Invalid expired replacement.' );
		}
		return 1 === $this->db->execute( 'UPDATE %i SET payload=%s,revision=%d,expires_at=%s WHERE state_key=%s AND expires_at IS NOT NULL AND expires_at<=%s', $this->db->table( 'state' ), self::encode( $payload ), random_int( 1, PHP_INT_MAX >> 1 ), gmdate( 'Y-m-d H:i:s', $expires ), $key, gmdate( 'Y-m-d H:i:s', $now ) );
	}

	/**
	 * Atomically replace exactly one current revision; no security retry here.
	 *
	 * Factor ciphertext, recovery hashes and generation belong in this same payload.
	 * Never split factor changes across rows. Proof readers must compare generation.
	 *
	 * @param string              $key Typed key.
	 * @param int                 $revision Expected revision.
	 * @param array<string,mixed> $payload Complete service-owned replacement.
	 * @param int|null            $expires New validity.
	 * @param int                 $now UTC timestamp; expired state cannot be revived.
	 * @return bool Exactly one affected row is success.
	 * @throws StorageException On invalid state or database error.
	 */
	public function compare_and_swap( string $key, int $revision, array $payload, ?int $expires, int $now ): bool {
		self::validate_key( $key );
		$this->validate_expiry( $key, $expires );
		if ( $revision < 1 || ( null !== $expires && $expires <= $now ) ) {
			throw new StorageException( 'Invalid state revision or expiry.' );
		}
		$encoded    = self::encode( $payload );
		$expiry_sql = null === $expires ? 'NULL' : '%s';
		$args       = array( $this->db->table( 'state' ), $encoded );
		if ( null !== $expires ) {
			$args[] = gmdate( 'Y-m-d H:i:s', $expires );
		}
		array_push( $args, $key, $revision, gmdate( 'Y-m-d H:i:s', $now ) );
		return 1 === $this->db->execute( 'UPDATE %i SET payload=%s,revision=revision+1,expires_at=' . $expiry_sql . ' WHERE state_key=%s AND revision=%d AND (expires_at IS NULL OR expires_at>%s)', ...$args );
	}

	/**
	 * Limit key cardinality and forbid raw tokens/addresses in keys.
	 *
	 * @param string $key Typed identifier.
	 * @throws StorageException On unknown/arbitrary identity.
	 */
	private static function validate_key( string $key ): void {
		if ( ! preg_match( '/^(?:factor:[1-9][0-9]{0,18}|(?:proof|limit|lease):[a-f0-9]{64}|budget:(?:events|errors|state))$/D', $key ) ) {
			throw new StorageException( 'Invalid state identity.' );
		}
	}

	/**
	 * Require expiry on ephemeral namespaces.
	 *
	 * @param string   $key State key.
	 * @param int|null $expires UTC expiry.
	 * @throws StorageException When ephemeral state has no TTL.
	 */
	private function validate_expiry( string $key, ?int $expires ): void {
		if ( null === $expires && ! str_starts_with( $key, 'factor:' ) && ! str_starts_with( $key, 'budget:' ) ) {
			throw new StorageException( 'State expiry required.' );
		}
		if ( null !== $expires && ( $expires < 1 || $expires > 253402300799 ) ) {
			throw new StorageException( 'Invalid state expiry.' );
		}
	}

	/**
	 * Encode bounded state without WordPress slashing or serialization.
	 *
	 * @param array<string,mixed> $payload Service-owned data.
	 * @throws StorageException When invalid/oversized.
	 */
	private static function encode( array $payload ): string {
		try {
			$value = json_encode( $payload, JSON_THROW_ON_ERROR, 16 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Strict JSON must fail closed, never repair invalid state bytes.
		} catch ( \JsonException $failure ) {
			throw new StorageException( 'Invalid state payload.' );
		}
		if ( strlen( $value ) > 8192 ) {
			throw new StorageException( 'State payload too large.' );
		}
		return $value;
	}
}
