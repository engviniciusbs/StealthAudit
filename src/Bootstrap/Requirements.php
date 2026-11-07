<?php
/**
 * Independent capabilities; QR/security failure does not disable collection.
 *
 * @license GPL-3.0-or-later
 * @package StealthAudit
 */

declare(strict_types=1);

namespace StealthAudit\Bootstrap;

/** Separate capability checks for collection and the future panel. */
final readonly class Requirements {
	/**
	 * Construct an extension inventory.
	 *
	 * @param list<string> $extensions Available extensions (injectable for tests).
	 */
	public function __construct( private array $extensions ) {}

	/** Detect current runtime extensions. */
	public static function detect(): self {
		return new self( get_loaded_extensions() );
	}

	/**
	 * Find unavailable collection extensions.
	 *
	 * @return list<string> Missing collection requirements.
	 */
	public function collection_missing(): array {
		return array_values( array_diff( array( 'mysqli', 'json' ), $this->extensions ) );
	}

	/**
	 * Find unavailable panel extensions.
	 *
	 * @return list<string> Missing panel/security/QR requirements.
	 */
	public function panel_missing(): array {
		return array_values( array_diff( array( 'sodium', 'iconv', 'xmlwriter' ), array_map( 'strtolower', $this->extensions ) ) );
	}
}
