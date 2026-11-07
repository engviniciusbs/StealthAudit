<?php
// SPDX-License-Identifier: GPL-3.0-or-later
$root = dirname( __DIR__ );
$paths = array( 'src', 'loader', 'config', 'tests', 'tools' );
$files = array( $root . '/bootstrap.php' );
foreach ( $paths as $path ) {
	foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/' . $path ) ) as $file ) {
		if ( 'php' === $file->getExtension() ) {
			$files[] = $file->getPathname();
		}
	}
}
foreach ( $files as $file ) {
	passthru( escapeshellarg( PHP_BINARY ) . ' -l ' . escapeshellarg( $file ), $status );
	if ( 0 !== $status ) {
		exit( $status );
	}
}
