<?php
/**
 * Opaque persistence failure; never includes SQL or stored values.
 *
 * @package StealthAudit
 */

declare(strict_types=1);
namespace StealthAudit\Storage;

/** Access callers must deny on this exception. */
final class StorageException extends \RuntimeException {}
