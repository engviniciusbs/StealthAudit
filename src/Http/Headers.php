<?php
/**
 * Private, non-cacheable response headers shared by panel, API and QR.
 *
 * @package StealthAudit
 */

declare(strict_types=1);
namespace StealthAudit\Http;

/** One definition so no response type can forget a cache directive. */
final class Headers {
	/**
	 * Headers for every StealthAudit response, including errors and redirects.
	 *
	 * @return array<string,string> Header names to values.
	 */
	public static function private(): array {
		return array(
			'Cache-Control'          => 'no-store, no-cache, must-revalidate, max-age=0, private',
			'CDN-Cache-Control'      => 'no-store',
			'Surrogate-Control'      => 'no-store',
			'Pragma'                 => 'no-cache',
			'Expires'                => 'Wed, 11 Jan 1984 05:00:00 GMT',
			'Vary'                   => 'Cookie',
			'X-Content-Type-Options' => 'nosniff',
			'Referrer-Policy'        => 'no-referrer',
			'X-Robots-Tag'           => 'noindex, nofollow, noarchive',
		);
	}

	/**
	 * Panel HTML headers: local resources only, never framed.
	 *
	 * @return array<string,string> Header names to values.
	 */
	public static function page(): array {
		return self::private() + array(
			'Content-Type'            => 'text/html; charset=utf-8',
			'X-Frame-Options'         => 'DENY',
			'Content-Security-Policy' => "default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'",
		);
	}
}
