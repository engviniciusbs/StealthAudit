<?php
/**
 * Diagnostic persistence boundary. Message reduction belongs to the collector.
 *
 * @package StealthAudit
 */

declare(strict_types=1);
namespace StealthAudit\Storage;

/** Accepts reduced messages only; never accept raw request/error objects. */
final class ErrorRepository extends Records {
	/**
	 * Persist one bounded diagnostic occurrence; no synchronous aggregation scan.
	 *
	 * @param array<string,mixed> $record Reduced diagnostic fields from sanitizer.
	 * @param int                 $now UTC Unix timestamp.
	 */
	public function append( array $record, int $now ): bool {
		$reserved = false;
		$started  = $this->budget->begin();
		if ( null === $started ) {
			return false;
		}
		try {
			if ( $now < 1 || array_diff( array_keys( $record ), array( 'severity', 'message', 'file', 'line' ) ) ) {
				return false;
			}
			$severity = self::code( $record['severity'] ?? null, array( 'fatal', 'warning', 'notice', 'deprecated', 'error' ) );
			$message  = $record['message'] ?? null;
			$file     = $record['file'] ?? '';
			$line     = $record['line'] ?? 0;
			if ( ! is_string( $message ) || '' === $message || strlen( $message ) > 1024 || ! preg_match( '//u', $message ) || ! is_string( $file ) || strlen( $file ) > 255 || ! preg_match( '~^(?:[a-zA-Z0-9_.-]+/)*[a-zA-Z0-9_.-]*$~D', $file ) || str_contains( $file, '..' ) || ! is_int( $line ) || $line < 0 || $line > 2147483647 ) {
				return false;
			}
			// Defence in depth, not a substitute for phase-four message sanitization.
			if ( preg_match( '~(?:password|passwd|authorization|cookie|secret|token|api[_-]?key)\s*[:=]|(?:Bearer\s+)|-----BEGIN|://[^\s]+@~i', $message ) ) {
				$message = '[redacted diagnostic]';
			}
			$fingerprint = hash( 'sha256', $severity . "\0" . $message . "\0" . $file . "\0" . $line );
			$bytes       = strlen( $severity . $message . $file ) + 120;
			if ( ! $this->budget->reserve( 'errors', $bytes, $now ) ) {
				return false;
			}
			$reserved = true;
			return 1 === $this->db->execute( 'INSERT INTO %i (record_version,first_seen,last_seen,severity,message,file,line,fingerprint,occurrences,logical_bytes) VALUES (1,%s,%s,%s,%s,%s,%d,%s,1,%d)', $this->db->table( 'errors' ), gmdate( 'Y-m-d H:i:s', $now ), gmdate( 'Y-m-d H:i:s', $now ), $severity, $message, $file, $line, $fingerprint, $bytes );
		} catch ( \Throwable $failure ) {
			return false;
		} finally {
			if ( $reserved ) {
				$this->budget->complete( 'errors', $now );
			}
			$this->budget->finish( $started );
		}
	}

	/**
	 * Fetch records after authorization by the access layer.
	 *
	 * @param array<string,int|string> $filters Fixed equality filters.
	 * @param int                      $limit Page size.
	 * @param int                      $before_id Exclusive cursor.
	 * @param int                      $now UTC Unix timestamp.
	 * @return list<array<string,mixed>> Records.
	 */
	public function page( array $filters, int $limit, int $before_id, int $now ): array {
		return $this->read_page( 'errors', $filters, array( 'severity', 'fingerprint' ), $limit, $before_id, $now );
	}
}
