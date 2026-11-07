<?php
// SPDX-License-Identifier: GPL-3.0-or-later
// Generate the inventory from the resolved lock and preserve production notices.
$root = dirname( __DIR__ );
$lock = json_decode( file_get_contents( $root . '/composer.lock' ), true, 512, JSON_THROW_ON_ERROR );
$notice = "# Third-party notices\n\nGenerated from composer.lock. StealthAudit original code is GPL-3.0-or-later.\nDependency copyrights and license terms below remain in force. No MMDB is bundled.\n\n";
foreach ( $lock['packages'] as $package ) {
	$notice .= '## ' . $package['name'] . ' ' . $package['version'] . "\n\n";
	$notice .= 'License: ' . implode( ', ', $package['license'] ) . "\n\n";
	$notice .= 'Source: ' . $package['source']['url'] . ' @ ' . $package['source']['reference'] . "\n\n";
	$files = glob( $root . '/vendor/' . $package['name'] . '/*' );
	$found = false;
	foreach ( $files as $file ) {
		if ( is_file( $file ) && preg_match( '/^(license|licence|copying|notice)(\..*)?$/i', basename( $file ) ) ) {
			$notice .= '### ' . basename( $file ) . "\n\n```text\n" . trim( file_get_contents( $file ) ) . "\n```\n\n";
			$found = true;
		}
	}
	if ( ! $found ) {
		throw new RuntimeException( 'License notice not found for ' . $package['name'] );
	}
}
file_put_contents( $root . '/THIRD-PARTY-NOTICES.md', $notice );
$dev = "# Development dependency inventory\n\nResolved composer.lock development packages; excluded from the distribution ZIP.\n\n| Package | Version | SPDX license |\n| --- | --- | --- |\n";
foreach ( $lock['packages-dev'] as $package ) {
	$dev .= '| ' . $package['name'] . ' | ' . $package['version'] . ' | ' . implode( ', ', $package['license'] ) . " |\n";
}
file_put_contents( $root . '/docs/testing/development-dependencies.md', $dev );
