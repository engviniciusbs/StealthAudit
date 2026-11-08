<?php
/**
 * Closed "try later" denial: the attempt window is full; no counters are exposed.
 *
 * @package StealthAudit
 */

declare(strict_types=1);
namespace StealthAudit\Security;

/** Still a denial (fails closed); only lets transport answer "try later" instead of 403. */
final class AttemptsExhausted extends \RuntimeException {
}
