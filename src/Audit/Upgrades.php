<?php
/**
 * Conservative per-item updater observations for WordPress 7.1.4.
 *
 * @package StealthAudit
 */

declare(strict_types=1);
namespace StealthAudit\Audit;

/** Completion is not success: bulk result is local, not an instance result map. */
final class Upgrades {
	/**
	 * Package-result confirmations awaiting completion.
	 *
	 * @var array<string,bool>
	 */
	private array $confirmed = array();
	/**
	 * Installed references resolved from the corresponding package.
	 *
	 * @var array<int,string>
	 */
	private array $installed = array();
	/**
	 * Bounded pending downloads.
	 *
	 * @var array<int,array{upgrader:object,type:string,action:string,reference:?string,envelope:array<string,int|string|null>}>
	 */
	private array $pending = array();

	/**
	 * Select validated collector inputs.
	 *
	 * @param Recorder $recorder Failure-contained writer.
	 */
	public function __construct( private readonly Recorder $recorder ) {}

	/** Register observers without changing upgrader return values. */
	public function register(): void {
		add_filter( 'upgrader_pre_download', array( $this, 'attempt' ), PHP_INT_MAX, 4 );
		add_filter( 'upgrader_install_package_result', array( $this, 'package_result' ), PHP_INT_MAX, 2 );
		add_action( 'upgrader_process_complete', array( $this, 'complete' ), 10, 2 );
		add_action( 'automatic_updates_complete', array( $this, 'automatic' ), 10, 1 );
		add_action( 'shutdown', array( $this, 'flush' ), 10, 0 );
	}

	/** Remove this observer instance. */
	public function unregister(): void {
		remove_filter( 'upgrader_pre_download', array( $this, 'attempt' ), PHP_INT_MAX );
		remove_filter( 'upgrader_install_package_result', array( $this, 'package_result' ), PHP_INT_MAX );
		remove_action( 'upgrader_process_complete', array( $this, 'complete' ), 10 );
		remove_action( 'automatic_updates_complete', array( $this, 'automatic' ), 10 );
		remove_action( 'shutdown', array( $this, 'flush' ), 10 );
	}

	/**
	 * Observe an attempt; discard the package URL/path immediately.
	 *
	 * @param mixed               $reply Existing filter response.
	 * @param mixed               $package Ignored, may contain credentials.
	 * @param object              $upgrader WordPress upgrader instance.
	 * @param array<string,mixed> $extra Hook metadata.
	 * @return mixed Identical response.
	 */
	public function attempt( mixed $reply, mixed $package, object $upgrader, array $extra ): mixed {
		$type = $extra['type'] ?? ( $upgrader instanceof \Plugin_Upgrader ? 'plugin' : ( $upgrader instanceof \Theme_Upgrader ? 'theme' : ( $upgrader instanceof \Core_Upgrader ? 'core' : '' ) ) );
		if ( in_array( $type, array( 'plugin', 'theme', 'core' ), true ) && count( $this->pending ) < 100 ) {
			$reference       = $extra[ $type ] ?? ( 'core' === $type ? Reference::CORE : null );
			$this->pending[] = array(
				'upgrader'  => $upgrader,
				'type'      => $type,
				'action'    => 'install' === ( $extra['action'] ?? '' ) ? 'installed' : 'upgraded',
				'reference' => Reference::valid( $type, $reference ) ? $reference : null,
				'envelope'  => $this->recorder->capture(),
			);
			if ( is_wp_error( $reply ) ) {
				$action    = 'install' === ( $extra['action'] ?? '' ) ? 'installed' : 'upgraded';
				$reference = Reference::valid( $type, $reference ) ? $reference : null;
				$this->write( $type, $action, $reference, 'failure', null, 'download' );
				if ( count( $this->confirmed ) < 100 ) {
					$this->confirmed[ $type . ':' . $action . ':' . ( $reference ?? '' ) ] = true;
				}
				array_pop( $this->pending );
			}
		}
		return $reply;
	}

	/**
	 * WP_Upgrader::run emits this only after install_package, not download errors.
	 *
	 * @param mixed               $result Original array/WP_Error.
	 * @param array<string,mixed> $extra Per-item metadata, often only plugin/theme in bulk.
	 * @return mixed Exactly the same value/object.
	 */
	public function package_result( mixed $result, array $extra ): mixed {
		$type = $extra['type'] ?? ( isset( $extra['plugin'] ) ? 'plugin' : ( isset( $extra['theme'] ) ? 'theme' : '' ) );
		if ( ! in_array( $type, array( 'plugin', 'theme' ), true ) ) {
			return $result;
		}
		$action    = 'install' === ( $extra['action'] ?? '' ) ? 'installed' : 'upgraded';
		$reference = $extra[ $type ] ?? ( is_array( $result ) ? ( $result['destination_name'] ?? null ) : null );
		if ( ! Reference::valid( $type, $reference ) ) {
			$reference = null;
		}
		$outcome = is_wp_error( $result ) || false === $result ? 'failure' : ( is_array( $result ) && ! empty( $result['destination'] ) ? 'success' : 'unknown' );
		$this->write( $type, $action, $reference, $outcome, null, 'package' );
		if ( count( $this->confirmed ) < 100 ) {
			$this->confirmed[ $type . ':' . $action . ':' . ( $reference ?? '' ) ] = true;
		}
		// Clear the matching attempt, including anonymous installation now identified.
		foreach ( $this->pending as $index => $attempt ) {
			if ( $attempt['type'] === $type && $attempt['action'] === $action && ( null === $attempt['reference'] || $attempt['reference'] === $reference ) ) {
				if ( 'installed' === $action && null !== $reference && count( $this->installed ) < 100 ) {
					$this->installed[ spl_object_id( $attempt['upgrader'] ) ] = $reference;
				}
				unset( $this->pending[ $index ] );
				break;
			}
		}
		return $result;
	}

	/**
	 * Read item proofs, never apply the final item's instance result to a bulk list.
	 * Source: wordpress-develop/7.1.4/src/wp-admin/includes/class-plugin-upgrader.php::bulk_upgrade.
	 *
	 * @param object              $upgrader Upgrader instance.
	 * @param array<string,mixed> $extra Completion metadata.
	 */
	public function complete( object $upgrader, array $extra ): void {
		$type = $extra['type'] ?? '';
		if ( ! in_array( $type, array( 'plugin', 'theme', 'core' ), true ) ) {
			return;
		}
		$action = 'install' === ( $extra['action'] ?? '' ) ? 'installed' : 'upgraded';
		$items  = $extra[ $type . 's' ] ?? array( $extra[ $type ] ?? ( 'core' === $type ? Reference::CORE : ( $this->installed[ spl_object_id( $upgrader ) ] ?? null ) ) );
		unset( $this->installed[ spl_object_id( $upgrader ) ] );
		if ( ! is_array( $items ) ) {
			return;
		}
		foreach ( array_slice( $items, 0, 100 ) as $reference ) {
			$reference = Reference::valid( $type, $reference ) ? $reference : null;
			$key       = $type . ':' . $action . ':' . ( $reference ?? '' );
			if ( isset( $this->confirmed[ $key ] ) ) {
				unset( $this->confirmed[ $key ] );
				continue;
			}
			// Core_Upgrader::upgrade holds $result locally; even process_complete can follow rollback.
			$this->write( $type, $action, $reference, 'unknown' );
		}
		foreach ( $this->pending as $index => $attempt ) {
			if ( $attempt['upgrader'] === $upgrader ) {
				unset( $this->pending[ $index ] );
			}
		}
	}

	/**
	 * Automatic core updater exports its actual return value separately.
	 *
	 * @param array<string,mixed> $results Automatic updater results.
	 */
	public function automatic( array $results ): void {
		$core = $results['core'] ?? array();
		if ( ! is_array( $core ) ) {
			return;
		}
		foreach ( array_slice( $core, 0, 10 ) as $item ) {
			if ( ! is_object( $item ) || ! isset( $item->result ) ) {
				continue;
			}
			$result = $item->result;
			$this->write( 'core', 'upgraded', Reference::CORE, is_wp_error( $result ) || false === $result ? 'failure' : ( is_string( $result ) && preg_match( '/^\d+\.\d+(?:\.\d+)?$/D', $result ) ? 'success' : 'unknown' ), null, 'automatic' );
		}
	}

	/** Early failures may never reach complete; unresolved observations stay unknown. */
	public function flush(): void {
		$pending       = $this->pending;
		$this->pending = array();
		foreach ( $pending as $attempt ) {
			// Instance result may belong to an earlier run; it cannot prove this attempt failed.
			$this->write( $attempt['type'], $attempt['action'], $attempt['reference'], 'unknown', $attempt['envelope'], 'download' );
		}
	}

	/**
	 * Select validated collector inputs.
	 *
	 * @param string                             $type Object type.
	 * @param string                             $action Closed action.
	 * @param string|null                        $reference Validated identity.
	 * @param string                             $outcome Confirmed or unknown outcome.
	 * @param array<string,int|string|null>|null $envelope Event-time context for deferred attempts.
	 * @param string                             $stage Observation stage, not inferred final success.
	 */
	private function write( string $type, string $action, ?string $reference, string $outcome, ?array $envelope = null, string $stage = 'completion' ): void {
		$summary          = null === $reference ? array() : array( 'reference' => $reference );
		$summary['stage'] = $stage;
		if ( 'unknown' === $outcome ) {
			$summary['reason'] = 'not_confirmed';
		}
		$this->recorder->emit( 'admin', $action, $type, null, $outcome, $summary, null, $envelope );
	}
}
