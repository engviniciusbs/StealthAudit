<?php
/**
 * Bounded reduction of observable PHP diagnostics.
 *
 * @package StealthAudit
 */

declare(strict_types=1);
namespace StealthAudit\Diagnostics;

/** Pattern masking reduces known shapes; arbitrary prose can still contain secrets. */
final class Normalizer {

	/**
	 * Construct without inspecting the filesystem.
	 *
	 * @param string $root WordPress absolute root.
	 */
	public function __construct( private readonly string $root ) {}

	/**
	 * Reduce a diagnostic before buffering it; never retain a trace.
	 *
	 * @param int    $severity PHP error level.
	 * @param string $message PHP message.
	 * @param string $file Originating file.
	 * @param int    $line Originating line.
	 * @return array{severity:string,message:string,file:string,line:int} Reduced record.
	 */
	public function normalize( int $severity, string $message, string $file, int $line ): array {
		// Limit regex work before masking. Invalid UTF-8 becomes a fixed safe message.
		$message = substr( $message, 0, 8192 );
		// Native uncaught-error messages can contain argument-bearing stack frames.
		// Drop that suffix before masking or flattening, never buffer a trace.
		$message = preg_split( '/\r?\n(?:Stack trace:|#\d+\s)/', $message, 2 )[0];
		if ( ! preg_match( '//u', $message ) ) {
			$message = '[invalid or truncated UTF-8 diagnostic]';
		}
		if ( str_contains( $message, '-----BEGIN' ) ) {
			$message = '[redacted diagnostic]';
		}
		$message = preg_replace( '~(?:Authorization|Set-Cookie|Cookie)\s*:\s*[^\r\n]+~i', '[redacted]', $message ) ?? '[redacted diagnostic]';
		$message = preg_replace( '~(?:password|passwd|authorization|cookie|secret|token|api[_-]?key)\s*[:=]\s*(?:Bearer\s+[^\s,;]+|"[^"]*"|\'[^\']*\'|[^\s,;]+)|Bearer\s+[^\s,;]+|-----BEGIN[^\r\n]*|[a-z][a-z0-9+.-]*://[^\s]+@~i', '[redacted]', $message ) ?? '[redacted diagnostic]';
		$message = preg_replace( '~(?:[A-Za-z]:[\\\\/]|/)(?:[^\s<>"\'()]+[\\\\/])*[^\s<>"\'():,;]*~', '[path]', $message ) ?? '[redacted diagnostic]';
		$message = preg_replace( '/[\x00-\x1F\x7F]/', ' ', $message ) ?? '[redacted diagnostic]';
		$message = substr( $message, 0, 1024 );
		while ( ! preg_match( '//u', $message ) ) {
			$message = substr( $message, 0, -1 );
		}
		$path = str_replace( '\\', '/', $file );
		$root = rtrim( str_replace( '\\', '/', $this->root ), '/' ) . '/';
		$path = str_starts_with( $path, $root ) ? substr( $path, strlen( $root ) ) : 'external.php';
		if ( strlen( $path ) > 255 || str_contains( $path, '..' ) || ! preg_match( '~^(?:[a-zA-Z0-9_.-]+/)*[a-zA-Z0-9_.-]+$~D', $path ) ) {
			$path = 'external.php';
		}
		$code = match ( $severity ) {
			E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR => 'fatal',
			E_WARNING, E_CORE_WARNING, E_COMPILE_WARNING, E_USER_WARNING => 'warning',
			E_NOTICE, E_USER_NOTICE, E_STRICT => 'notice',
			E_DEPRECATED, E_USER_DEPRECATED => 'deprecated',
			default => 'error',
		};
		return array(
			'severity' => $code,
			'message'  => '' === trim( $message ) ? '[empty diagnostic]' : $message,
			'file'     => $path,
			'line'     => max( 0, min( 2147483647, $line ) ),
		);
	}
}
