<?php
/**
 * Local panel stylesheets and script, served by the router with private headers.
 *
 * @package StealthAudit
 */

declare(strict_types=1);
namespace StealthAudit\Http;

use StealthAudit\Config\Configuration;
use StealthAudit\Security\WordPressSession;

/** Closed file list; only an allowlisted operator with a live session receives them. */
final class Assets {
	public const FILES = array(
		'tokens.css' => 'text/css; charset=utf-8',
		'base.css'   => 'text/css; charset=utf-8',
		'panel.css'  => 'text/css; charset=utf-8',
		'panel.js'   => 'text/javascript; charset=utf-8',
	);

	/**
	 * Construct.
	 *
	 * @param Configuration    $configuration Operator allowlist.
	 * @param string           $directory Absolute assets directory.
	 * @param WordPressSession $sessions Signed-cookie validator.
	 */
	public function __construct(
		private readonly Configuration $configuration,
		private readonly string $directory,
		private readonly WordPressSession $sessions = new WordPressSession(),
	) {}

	/**
	 * Serve one listed file; everything else is a bodiless 404 that reveals nothing.
	 *
	 * @param string       $method HTTP method.
	 * @param array<mixed> $query Raw query parameters.
	 */
	public function respond( string $method, array $query ): Response {
		$missing = new Response( 404, Headers::private() + array( 'Content-Type' => 'text/plain; charset=utf-8' ), '' );
		if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
			return new Response( 405, Headers::private() + array( 'Allow' => 'GET, HEAD' ), '' );
		}
		$file = $query['file'] ?? null;
		if ( ! is_string( $file ) || ! isset( self::FILES[ $file ] ) || ! is_ssl() ) {
			return $missing;
		}
		$user = get_current_user_id();
		if ( $user < 1 || ! $this->configuration->allows_user( $user ) ) {
			return $missing;
		}
		try {
			$this->sessions->current( $this->configuration );
		} catch ( \Throwable $failure ) {
			return $missing;
		}
		$path = $this->directory . '/' . $file;
		$body = is_readable( $path ) ? file_get_contents( $path ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Fixed local file from a closed list.
		if ( false === $body ) {
			return $missing;
		}
		return new Response(
			200,
			Headers::private() + array(
				'Content-Type'                 => self::FILES[ $file ],
				'Cross-Origin-Resource-Policy' => 'same-origin',
			),
			$body
		);
	}
}
