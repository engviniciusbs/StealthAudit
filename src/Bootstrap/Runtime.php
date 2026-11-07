<?php
/**
 * Capability state, storage lifecycle and request-scoped collection.
 *
 * @license GPL-3.0-or-later
 * @package StealthAudit
 */

declare(strict_types=1);

namespace StealthAudit\Bootstrap;

use StealthAudit\Config\Configuration;
use StealthAudit\Config\ConfigurationLoader;
use Throwable;
use StealthAudit\Storage\Storage;
use StealthAudit\Storage\StorageException;
use StealthAudit\Audit\Collectors;
use StealthAudit\Audit\Context;
use StealthAudit\Audit\Recorder;
use StealthAudit\Audit\Upgrades;

/** Idempotent runtime state; capabilities do not grant authentication. */
final class Runtime {
	/**
	 * Singleton for this PHP request.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;
	/**
	 * Request-scoped storage, initialized after plugins load.
	 *
	 * @var Storage|null
	 */
	private ?Storage $storage = null;
	/**
	 * Prevent duplicate hook registration if lifecycle initialization is repeated.
	 *
	 * @var bool
	 */
	private bool $audit_initialized = false;

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

	/** Boot once; collection starts after plugins load, without access routes. */
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
		add_action( 'plugins_loaded', array( self::$instance, 'initialize_storage' ), 5 );
		add_action( 'plugins_loaded', array( self::$instance, 'initialize_audit' ), 10 );
		return self::$instance;
	}

	/** Register collection independently of panel credentials; pluggables are now loaded. */
	public function initialize_audit(): void {
		if ( $this->audit_initialized || ! $this->collection_available() ) {
			return;
		}
		$this->audit_initialized = true;
		$recorder                = new Recorder( fn () => $this->storage()->events, new Context( $this->configuration->trusted_proxies ) );
		( new Collectors( $recorder ) )->register();
		( new Upgrades( $recorder ) )->register();
	}

	/** Initialize schema once at the WordPress lifecycle boundary, never per event. */
	public function initialize_storage(): void {
		global $wpdb;
		if ( null !== $this->storage || ! $this->collection_available() || ! $wpdb instanceof \wpdb ) {
			return;
		}
		$this->storage        = new Storage( $wpdb, $this->configuration );
		$this->storage->ready = $this->storage->schema->ensure();
	}

	/**
	 * Return ready storage; security callers must deny on any failure.
	 *
	 * @throws StorageException When migration or database prerequisites failed.
	 */
	public function storage(): Storage {
		if ( null === $this->storage || ! $this->storage->ready ) {
			throw new StorageException( 'Storage unavailable.' );
		}
		return $this->storage;
	}

	/** Whether independent collection prerequisites exist. */
	public function collection_available(): bool {
		return array() === $this->requirements->collection_missing();
	}

	/** Whether security prerequisites exist; never an access authorization. */
	public function panel_configured(): bool {
		return $this->configuration_valid && array() === $this->requirements->panel_missing() && $this->configuration->has_key() && array() !== $this->configuration->authorized_user_ids;
	}
}
