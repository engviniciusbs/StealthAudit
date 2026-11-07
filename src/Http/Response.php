<?php
/**
 * Immutable panel page response, emitted only by the router.
 *
 * @package StealthAudit
 */

declare(strict_types=1);
namespace StealthAudit\Http;

/** Status, private headers and an already escaped body. */
final readonly class Response {
	/**
	 * Construct.
	 *
	 * @param int                  $status HTTP status.
	 * @param array<string,string> $headers Header names to values.
	 * @param string               $body Escaped HTML body.
	 */
	public function __construct(
		public int $status,
		public array $headers,
		public string $body,
	) {}
}
