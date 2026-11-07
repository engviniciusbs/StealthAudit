<?php
/**
 * Failure-contained collector boundary.
 *
 * @package StealthAudit
 */

declare(strict_types=1);
namespace StealthAudit\Audit;

use StealthAudit\Storage\EventRepository;

/** Never lets storage failure change the originating WordPress action. */
final class Recorder {
	/**
	 * Reentrant writes are ignored.
	 *
	 * @var bool
	 */
	private bool $writing = false;

	/**
	 * Select validated collector inputs.
	 *
	 * @param \Closure():EventRepository $repository Ready repository resolver.
	 * @param Context                    $context Minimal request context.
	 */
	public function __construct( private readonly \Closure $repository, private readonly Context $context ) {}

	/**
	 * Capture context before deferred deduplication or an identity switch.
	 *
	 * @return array<string,int|string|null> Event-time envelope.
	 */
	public function capture(): array {
		return $this->context->current() + array( 'now' => time() );
	}

	/**
	 * Select validated collector inputs.
	 *
	 * @param string                             $category Closed category.
	 * @param string                             $action Closed action.
	 * @param string                             $type Object type.
	 * @param int|null                           $id Confirmed object ID.
	 * @param string                             $result Confirmed/unknown outcome.
	 * @param array<string,int|string>           $summary Allowlisted summary.
	 * @param int|null                           $actor Confirmed actor override.
	 * @param array<string,int|string|null>|null $envelope Previously captured context.
	 */
	public function emit( string $category, string $action, string $type, ?int $id = null, string $result = 'success', array $summary = array(), ?int $actor = null, ?array $envelope = null ): void {
		if ( $this->writing ) {
			return;
		}
		$this->writing = true;
		try {
			$record = $envelope ?? $this->capture();
			$now    = (int) $record['now'];
			unset( $record['now'] );
			if ( null !== $actor ) {
				$record['actor_id'] = $actor > 0 ? $actor : null;
			}
			$record += array(
				'category'    => $category,
				'action'      => $action,
				'object_type' => $type,
				'object_id'   => $id,
				'result'      => $result,
				'summary'     => $summary,
			);
			( $this->repository )()->append( $record, $now );
		} catch ( \Throwable $failure ) {
			// Collection is best effort; never expose database/request details.
			return;
		} finally {
			$this->writing = false;
		}
	}
}
