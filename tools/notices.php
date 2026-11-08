<?php
// SPDX-License-Identifier: GPL-3.0-or-later
// Generate the inventory from the resolved lock and preserve production notices.
//
//   php tools/notices.php            rewrites THIRD-PARTY-NOTICES.md and the dev inventory
//
// tools/package.php includes this file and calls stealthaudit_third_party_notices() on its clean
// build, then refuses to package when the committed THIRD-PARTY-NOTICES.md differs.

/**
 * Notices for the production packages of a lock, with each package's license files from $vendor.
 *
 * @param array<string,mixed> $lock   Decoded composer.lock.
 * @param string              $vendor Vendor directory holding those packages.
 * @return string Markdown.
 */
function stealthaudit_third_party_notices( array $lock, string $vendor ): string {
	$notice = "# Third-party notices\n\nGenerated from composer.lock. StealthAudit original code is GPL-3.0-or-later.\nDependency copyrights and license terms below remain in force. No MMDB is bundled.\n\n";
	foreach ( $lock['packages'] as $package ) {
		$notice .= '## ' . $package['name'] . ' ' . $package['version'] . "\n\n";
		$notice .= 'License: ' . implode( ', ', $package['license'] ) . "\n\n";
		$notice .= 'Source: ' . $package['source']['url'] . ' @ ' . $package['source']['reference'] . "\n\n";
		$files = glob( $vendor . '/' . $package['name'] . '/*' );
		sort( $files );
		$found = false;
		foreach ( $files as $file ) {
			if ( is_file( $file ) && preg_match( '/^(license|licence|copying|notice)(\..*)?$/i', basename( $file ) ) ) {
				$notice .= '### ' . basename( $file ) . "\n\n```text\n" . trim( str_replace( "\r\n", "\n", file_get_contents( $file ) ) ) . "\n```\n\n";
				$found = true;
			}
		}
		if ( ! $found ) {
			throw new RuntimeException( 'License notice not found for ' . $package['name'] );
		}
	}
	return rtrim( $notice ) . "\n";
}

if ( 'cli' === PHP_SAPI && isset( $argv[0] ) && realpath( $argv[0] ) === __FILE__ ) {
	$root = dirname( __DIR__ );
	$lock = json_decode( file_get_contents( $root . '/composer.lock' ), true, 512, JSON_THROW_ON_ERROR );
	file_put_contents( $root . '/THIRD-PARTY-NOTICES.md', stealthaudit_third_party_notices( $lock, $root . '/vendor' ) );
	$dev = "# Development dependency inventory\n\nResolved composer.lock development packages; excluded from the distribution ZIP.\n\n| Package | Version | SPDX license |\n| --- | --- | --- |\n";
	foreach ( $lock['packages-dev'] as $package ) {
		$dev .= '| ' . $package['name'] . ' | ' . $package['version'] . ' | ' . implode( ', ', $package['license'] ) . " |\n";
	}
	file_put_contents( $root . '/docs/testing/development-dependencies.md', $dev );
}
