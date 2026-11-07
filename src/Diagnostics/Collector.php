<?php
/**
 * Transparent PHP error-handler chain and bounded best-effort shutdown capture.
 *
 * @package StealthAudit
 */

declare(strict_types=1);
namespace StealthAudit\Diagnostics;

// phpcs:disable WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting, WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_error_reporting -- Reading the current mask is required to respect suppression; no ini value is changed.

use StealthAudit\Storage\ErrorRepository;

/** Never changes reporting, display, exception handling or HTTP behavior. */
final class Collector {
	/**
	 * Previous handler; its return and exceptions remain authoritative.
	 *
	 * @var callable|null
	 */
	private $previous = null;
	/**
	 * Whether registration occurred.
	 *
	 * @var bool
	 */
	private bool $registered = false;
	/**
	 * PHP does not expose the previous handler mask, so chaining cannot be proven safe.
	 *
	 * @var bool
	 */
	private bool $previous_handler_present = false;
	/**
	 * Collection recursion guard.
	 *
	 * @var bool
	 */
	private bool $busy = false;
	/**
	 * Whether the repository is ready for immediate writes.
	 *
	 * @var bool
	 */
	private bool $ready = false;
	/**
	 * Accepted occurrence count, including buffered records.
	 *
	 * @var int
	 */
	private int $accepted = 0;
	/**
	 * Bounded local dropped count.
	 *
	 * @var int
	 */
	private int $dropped = 0;
	/**
	 * Coarse request-scoped health, containing no diagnostics.
	 *
	 * @var string
	 */
	private string $status = 'waiting_storage';
	/**
	 * Sticky request circuit breaker, independent of later dropped occurrences.
	 *
	 * @var bool
	 */
	private bool $storage_failed = false;
	/**
	 * Bounded reduced buffer.
	 *
	 * @var list<array{record:array{severity:string,message:string,file:string,line:int},now:int}>
	 */
	private array $buffer = array();
	/**
	 * Last fatal accepted by handle(), to avoid shutdown duplication.
	 *
	 * @var string|null
	 */
	private ?string $handled_fatal = null;

	/**
	 * Construct the request collector.
	 *
	 * @param Normalizer                 $normalizer Privacy reduction.
	 * @param \Closure():ErrorRepository $repository Ready repository resolver.
	 * @param int                        $limit Per-request occurrence bound (clamped 1..20).
	 */
	public function __construct( private readonly Normalizer $normalizer, private readonly \Closure $repository, private readonly int $limit = 5 ) {}

	/** Register after the loader's temporary error-handler guard has unwound. */
	public function register(): void {
		if ( $this->registered ) {
			return;
		}
		$this->registered = true;
		$this->previous   = set_error_handler( array( $this, 'handle' ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Transparent collector, retaining the previous chain.
		if ( null !== $this->previous ) {
			// PHP returns the callback, not its mask. Restore the exact native registration
			// rather than invoke a NOTICE-only application handler for a WARNING.
			restore_error_handler();
			$this->previous_handler_present = true;
		}
		register_shutdown_function( array( $this, 'shutdown' ) );
	}

	/**
	 * Preserve the previous handler's exact return, including null, or PHP default false.
	 *
	 * @param int    $severity PHP error level.
	 * @param string $message PHP error message.
	 * @param string $file PHP filename.
	 * @param int    $line PHP line.
	 * @return mixed Previous handler's exact return.
	 */
	public function handle( int $severity, string $message, string $file, int $line ): mixed {
		if ( ! $this->busy && ( error_reporting() & $severity ) !== 0 ) {
			$this->capture( $severity, $message, $file, $line );
			if ( in_array( $severity, array( E_USER_ERROR, E_RECOVERABLE_ERROR ), true ) ) {
				$this->handled_fatal = hash( 'sha256', $severity . $message . $file . $line );
			}
		}
		// Do not catch application handlers: their throws must propagate unchanged.
		return null !== $this->previous ? ( $this->previous )( $severity, $message, $file, $line ) : false;
	}

	/** Mark the repository available and drain at most the bounded buffer. */
	public function flush(): void {
		if ( $this->busy ) {
			return;
		}
		if ( $this->storage_failed ) {
			$this->buffer = array();
			return;
		}
		$this->ready = true;
		$this->busy  = true;
		try {
			( $this->repository )();
		} catch ( \Throwable $failure ) {
			$this->status         = 'storage_unavailable';
			$this->storage_failed = true;
			$this->buffer         = array();
			return;
		} finally {
			$this->busy = false;
		}
		$pending      = $this->buffer;
		$this->buffer = array();
		foreach ( $pending as $item ) {
			$this->write( $item['record'], $item['now'] );
		}
		if ( 'waiting_storage' === $this->status ) {
			$this->status = 'available';
		}
	}

	/** Capture only eligible last fatals; WordPress and host handlers remain installed. */
	public function shutdown(): void {
		$error = error_get_last();
		if ( null !== $error && in_array( $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR ), true ) && ( error_reporting() & $error['type'] ) !== 0 && $this->handled_fatal !== hash( 'sha256', $error['type'] . $error['message'] . $error['file'] . $error['line'] ) ) {
			$this->capture( $error['type'], $error['message'], $error['file'], $error['line'] );
		}
		$this->flush();
	}

	/**
	 * Request health only; absence of records does not prove absence of errors.
	 *
	 * @return array{status:string,dropped:int,buffered:int} Coarse health.
	 */
	public function health(): array {
		return array(
			'status'   => $this->storage_failed ? 'storage_unavailable' : ( 'available' === $this->status && $this->dropped > 0 ? 'limited' : ( $this->previous_handler_present && in_array( $this->status, array( 'available', 'waiting_storage' ), true ) ? 'limited_previous_handler' : $this->status ) ),
			'dropped'  => $this->dropped,
			'buffered' => count( $this->buffer ),
		);
	}

	/**
	 * Reduce and buffer or write, containing only failures of our own collector.
	 *
	 * @param int    $severity PHP level.
	 * @param string $message Message.
	 * @param string $file File.
	 * @param int    $line Line.
	 */
	private function capture( int $severity, string $message, string $file, int $line ): void {
		if ( $this->busy ) {
			return;
		}
		$this->accepted = min( 21, $this->accepted + 1 );
		if ( $this->accepted > max( 1, min( 20, $this->limit ) ) ) {
			$this->dropped = min( 2147483647, $this->dropped + 1 );
			$this->status  = 'limited';
			return;
		}
		$this->busy = true;
		try {
			$record = $this->normalizer->normalize( $severity, $message, $file, $line );
		} catch ( \Throwable $failure ) {
			$this->status = 'limited';
			return;
		} finally {
			$this->busy = false;
		}
		if ( $this->ready ) {
			$this->write( $record, time() );
		} else {
			$this->buffer[] = array(
				'record' => $record,
				'now'    => time(),
			);
		}
	}

	/**
	 * Make one bounded write, without retrying an unavailable backend.
	 *
	 * @param array{severity:string,message:string,file:string,line:int} $record Reduced record.
	 * @param int                                                        $now Occurrence time.
	 */
	private function write( array $record, int $now ): void {
		if ( $this->busy || $this->storage_failed ) {
			return;
		}
		$this->busy = true;
		try {
			$repository = ( $this->repository )();
			if ( ! $repository->append( $record, $now ) ) {
				$this->dropped = min( 2147483647, $this->dropped + 1 );
			}
			$this->status         = $repository->status();
			$this->storage_failed = 'storage_unavailable' === $this->status;
		} catch ( \Throwable $failure ) {
			$this->status         = 'storage_unavailable';
			$this->storage_failed = true;
		} finally {
			$this->busy = false;
		}
	}
}
