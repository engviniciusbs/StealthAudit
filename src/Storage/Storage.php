<?php
/**
 * Request-scoped storage composition; no route or collector is registered here.
 *
 * @package StealthAudit
 */

declare(strict_types=1);
namespace StealthAudit\Storage;

use StealthAudit\Config\Configuration;

/** Shares one work budget across repositories. */
final class Storage {
	/**
	 * Bounded maintenance primitives.
	 *
	 * @var Maintenance
	 */
	public readonly Maintenance $maintenance;
	/**
	 * Authoritative CAS state.
	 *
	 * @var StateRepository
	 */
	public readonly StateRepository $state;
	/**
	 * Structured audit persistence.
	 *
	 * @var EventRepository
	 */
	public readonly EventRepository $events;
	/**
	 * Reduced diagnostic persistence.
	 *
	 * @var ErrorRepository
	 */
	public readonly ErrorRepository $errors;
	/**
	 * Versioned schema manager.
	 *
	 * @var Schema
	 */
	public readonly Schema $schema;
	/**
	 * Whether lifecycle migration succeeded.
	 *
	 * @var bool
	 */
	public bool $ready = false;

	/**
	 * Compose without running migrations.
	 *
	 * @param \wpdb         $connection WordPress connection.
	 * @param Configuration $configuration Resource limits.
	 */
	public function __construct( \wpdb $connection, Configuration $configuration ) {
		$db                = new Database( $connection );
		$this->schema      = new Schema( $db );
		$this->state       = new StateRepository( $db, $configuration->limits['state_rows'] );
		$this->maintenance = new Maintenance( $db, $this->state, $configuration->limits['state_rows'] );
		$budget            = new Budget( $this->state, $configuration );
		$this->events      = new EventRepository( $db, $budget );
		$this->errors      = new ErrorRepository( $db, $budget );
	}
}
