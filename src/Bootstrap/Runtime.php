<?php
/**
 * Phase-one capability state. No routes or collectors are enabled yet.
 *
 * @license GPL-3.0-or-later
 * @package StealthAudit
 */

declare(strict_types=1);

namespace StealthAudit\Bootstrap;

use StealthAudit\Config\Configuration;
use StealthAudit\Config\ConfigurationLoader;
use Throwable;

/** Idempotent runtime state; capabilities do not grant authentication. */
final class Runtime {
	/**
	 * Singleton for this PHP request.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Keep the boot result without exposing exception details.
	 *
	 * @param Requirements  $requirements Detected capabilities.
	 * @param Configuration $configuration Validated configuration or deny defaults.
	 * @param bool          $configuration_valid Whether the operator input was valid.
	 */
	private function __construct(
		public readonly Requirements $requirements,
		public readonly Configuration $configuration,
		public readonly bool $configuration_valid,
	) {}

	/** Boot once without registering collectors or access routes. */
	public static function boot(): self {
		if ( isset( self::$instance ) ) {
			return self::$instance;
		}
		$valid = true;
		try {
			$configuration = ( new ConfigurationLoader() )->load();
		} catch ( Throwable $failure ) {
			$configuration = Configuration::from_array( array() );
			$valid         = false;
		}
		self::$instance = new self( Requirements::detect(), $configuration, $valid );
		return self::$instance;
	}

	/** Whether collection prerequisites exist (collectors are added later). */
	public function collection_available(): bool {
		return array() === $this->requirements->collection_missing();
	}

	/** Whether security prerequisites exist; never an access authorization. */
	public function panel_configured(): bool {
		return $this->configuration_valid && array() === $this->requirements->panel_missing() && $this->configuration->has_key() && array() !== $this->configuration->authorized_user_ids;
	}
}
