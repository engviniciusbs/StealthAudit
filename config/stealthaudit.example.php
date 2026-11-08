<?php
/**
 * Copy outside the public webroot and point STEALTHAUDIT_CONFIG_FILE at it in
 * wp-config.php (or environment). Never commit the resulting operator file.
 *
 * @license GPL-3.0-or-later
 * @package StealthAudit
 */

return array(
	'authorized_user_ids' => array(), // Explicit IDs; empty denies everyone.
	'endpoint'            => '/stealthaudit',
	'key_base64'          => '', // Operator supplies a random 32-byte key, base64 encoded.
	'key_id'              => '', // Nonsecret identifier for the supplied key.
	'installation_id'     => '', // Stable unique installation ID; preserve across URL/key changes.
	'trusted_proxies'     => array(), // Exact IP or CIDR; empty ignores forwarding headers.
	'limits'              => array(), // See Configuration::DEFAULT_LIMITS.
	'mmdb_path'           => null, // Optional absolute path to a City/Country MMDB outside the webroot; no download performed.
	'mmdb_max_age_days'   => 30, // A database built longer ago than this is not used: location shows as unavailable.
);
