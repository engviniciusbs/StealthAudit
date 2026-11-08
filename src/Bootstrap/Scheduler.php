<?php
/**
 * WP-Cron wiring for bounded maintenance; traffic-driven, server cron optional.
 *
 * @license GPL-3.0-or-later
 * @package StealthAudit
 */

declare(strict_types=1);

namespace StealthAudit\Bootstrap;

use StealthAudit\Storage\Storage;

/** Never blocks a page: scheduling failures and maintenance failures are swallowed. */
final class Scheduler {
	public const HOOK       = 'stealthaudit_maintenance';
	public const RECURRENCE = 'hourly';
	/** Stable, empty arguments: wp_next_scheduled() must find the same event every time. */
	public const ARGS = array();

	/**
	 * Construct.
	 *
	 * @param \Closure():Storage $storage Ready storage or throws.
	 * @param \Closure():int     $clock UTC clock.
	 */
	public function __construct( private readonly \Closure $storage, private readonly \Closure $clock ) {}

	/** The cron runner needs the handler on every request; scheduling waits for init. */
	public function register(): void {
		add_action( self::HOOK, array( $this, 'run' ) );
		add_action( 'init', array( $this, 'init' ) );
	}

	/** The init action; returns nothing. */
	public function init(): void {
		$this->schedule();
	}

	/**
	 * Schedule once; an existing (even overdue) event is kept, never duplicated.
	 *
	 * @return bool Whether an event is scheduled after this call.
	 */
	public function schedule(): bool {
		try {
			( $this->storage )();
		} catch ( \Throwable $failure ) {
			return false;
		}
		if ( false !== wp_next_scheduled( self::HOOK, self::ARGS ) ) {
			return true;
		}
		$result = wp_schedule_event( ( $this->clock )(), self::RECURRENCE, self::HOOK, self::ARGS, true );
		// A WP_Error (duplicate, invalid schedule, a pre_schedule_event veto, option write
		// failure) only means "try again on the next request".
		return true === $result;
	}

	/** Cron handler: one bounded pass; failures are recorded in the status row, not shown. */
	public function run(): void {
		try {
			( $this->storage )()->maintenance->run( ( $this->clock )() );
		} catch ( \Throwable $failure ) {
			return;
		}
	}
}
