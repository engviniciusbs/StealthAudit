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
	 * Coarse result without database exception details.
	 *
	 * @var string
	 */
	private string $status = 'available';

	/** Last append health; never equate a rejected append with an empty log. */
	public function status(): string {
		return $this->status;
	}
	/**
	 * Persist one bounded diagnostic occurrence; no synchronous aggregation scan.
	 *
	 * @param array<string,mixed> $record Reduced diagnostic fields from sanitizer.
	 * @param int                 $now UTC Unix timestamp.
	 */
	public function append( array $record, int $now ): bool {
		$this->status = 'limited';
		$reserved     = false;
		$started      = $this->budget->begin();
		if ( null === $started ) {
			$this->status = $this->budget->storage_failed() ? 'storage_unavailable' : 'limited';
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
				$this->status = $this->budget->storage_failed() ? 'storage_unavailable' : 'limited';
				return false;
			}
			$reserved = true;
			// Fixed five-minute UTC buckets cannot extend the original message's retention.
			// Every occurrence reserves full capacity conservatively, including updates.
			$window = intdiv( $now, 300 ) * 300;
			$rows   = $this->db->rows( 'SELECT id FROM %i WHERE fingerprint=%s AND first_seen>=%s AND first_seen<%s ORDER BY id DESC LIMIT 1', $this->db->table( 'errors' ), $fingerprint, gmdate( 'Y-m-d H:i:s', $window ), gmdate( 'Y-m-d H:i:s', $window + 300 ) );
			if ( array() !== $rows ) {
				$written = 1 === $this->db->execute( 'UPDATE %i SET occurrences=LEAST(occurrences+1,2147483647),first_seen=LEAST(first_seen,%s),last_seen=GREATEST(last_seen,%s) WHERE id=%d AND fingerprint=%s AND first_seen>=%s AND first_seen<%s AND occurrences<2147483647', $this->db->table( 'errors' ), gmdate( 'Y-m-d H:i:s', $now ), gmdate( 'Y-m-d H:i:s', $now ), (int) $rows[0]['id'], $fingerprint, gmdate( 'Y-m-d H:i:s', $window ), gmdate( 'Y-m-d H:i:s', $window + 300 ) );
			} else {
				$written = 1 === $this->db->execute( 'INSERT INTO %i (record_version,first_seen,last_seen,severity,message,file,line,fingerprint,occurrences,logical_bytes) VALUES (1,%s,%s,%s,%s,%s,%d,%s,1,%d)', $this->db->table( 'errors' ), gmdate( 'Y-m-d H:i:s', $now ), gmdate( 'Y-m-d H:i:s', $now ), $severity, $message, $file, $line, $fingerprint, $bytes );
			}
			$this->status = $written ? 'available' : 'limited';
			return $written;
		} catch ( \Throwable $failure ) {
			$this->status = 'storage_unavailable';
			return false;
		} finally {
			if ( $reserved ) {
				$this->budget->complete( 'errors', $now );
			}
			$this->budget->finish( $started );
			if ( $this->budget->storage_failed() ) {
				$this->status = 'storage_unavailable';
			}
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
