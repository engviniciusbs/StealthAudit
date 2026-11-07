<?php
/**
 * Core bootstrap; loader checks the minimum PHP version first.
 *
 * @license GPL-3.0-or-later
 * @package StealthAudit
 */

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

require_once __DIR__ . '/vendor/autoload.php';
\StealthAudit\Bootstrap\Runtime::boot();
