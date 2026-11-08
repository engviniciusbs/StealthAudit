<?php
// SPDX-License-Identifier: GPL-3.0-or-later
// Verify a distribution ZIP built by tools/package.php (build tooling, never shipped).
//
//   npm run verify-package                       newest dist/stealthaudit-*.zip
//   npm run verify-package -- dist/stealthaudit-0.1.0-dev.zip [--against-source]
//
// Checks: SHA-256 file and content list match the ZIP; entry names, types and permissions;
// required layout; nothing outside the allowed top level; excluded paths (tests, tools,
// node_modules, package*.json, composer manifests, .env, MMDB, keys, fixtures, artifacts...);
// secret-looking content; every vendor PHP file under StealthAuditVendor\ and nothing global
// besides Composer's ComposerAutoloaderInitStealthAudit; product code references vendor code
// only through the prefix and never prefixes anything else (WordPress symbols untouched);
// classmap targets exist; "files" identifiers isolated; php -l on every PHP file; notices
// cover every bundled package. --against-source also compares every non-vendor file with the
// working tree (after the same import rewrite), for a build made from a clean checkout.
if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}
require __DIR__ . '/package-lib.php';

$repo     = dirname( __DIR__ );
$args     = array_values( array_filter( array_slice( $argv, 1 ), static fn ( string $a ): bool => ! str_starts_with( $a, '--' ) ) );
$compare  = in_array( '--against-source', $argv, true );
$failures = array();
$fail     = static function ( string $message ) use ( &$failures ): void {
	$failures[] = $message;
};
$temp     = null;
try {
	$zip_path = $args[0] ?? null;
	if ( null === $zip_path ) {
		$candidates = glob( $repo . '/dist/stealthaudit-*.zip' );
		usort( $candidates, static fn ( string $a, string $b ): int => filemtime( $b ) <=> filemtime( $a ) );
		$zip_path = $candidates[0] ?? null;
	} elseif ( ! str_starts_with( $zip_path, '/' ) ) {
		$zip_path = $repo . '/' . $zip_path;
	}
	if ( null === $zip_path || ! is_file( $zip_path ) || ! preg_match( '/^stealthaudit-(.+)\.zip$/', basename( $zip_path ), $match ) ) {
		throw new RuntimeException( 'No stealthaudit-<version>.zip found (run npm run package first).' );
	}
	$version = $match[1];
	$base    = substr( $zip_path, 0, -4 );
	$sha     = hash_file( 'sha256', $zip_path );
	echo '[verify] ' . basename( $zip_path ) . " sha256 {$sha}\n";

	// ---------- checksum file and content list ----------
	$sum = is_file( $zip_path . '.sha256' ) ? trim( (string) file_get_contents( $zip_path . '.sha256' ) ) : '';
	if ( $sum !== $sha . '  ' . basename( $zip_path ) ) {
		$fail( 'SHA-256 file missing or different from the ZIP.' );
	}
	$listed = array();
	foreach ( is_file( $base . '.contents.txt' ) ? file( $base . '.contents.txt', FILE_IGNORE_NEW_LINES ) : array() as $line ) {
		if ( preg_match( '/^([0-9a-f]{64})  (\d+)  (\S+)$/', $line, $m ) ) {
			$listed[ $m[3] ] = array( $m[1], (int) $m[2] );
		} else {
			$fail( 'Malformed content list line: ' . $line );
		}
	}
	if ( array() === $listed ) {
		$fail( 'Content list missing.' );
	}

	// ---------- entries ----------
	$zip = new ZipArchive();
	if ( true !== $zip->open( $zip_path, ZipArchive::RDONLY | ZipArchive::CHECKCONS ) ) {
		throw new RuntimeException( 'ZIP does not open or fails its consistency check.' );
	}
	$temp = sys_get_temp_dir() . '/stealthaudit-verify-' . bin2hex( random_bytes( 6 ) );
	mkdir( $temp, 0700 );
	$files = array();
	for ( $i = 0; $i < $zip->numFiles; $i++ ) {
		$stat = $zip->statIndex( $i );
		$name = $stat['name'];
		$zip->getExternalAttributesIndex( $i, $os, $attributes );
		$mode = ( $attributes >> 16 ) & 0177777;
		if ( ! str_starts_with( $name, 'mu-plugins/' ) || str_contains( $name, '\\' ) || str_contains( $name, '..' ) || str_contains( $name, '//' ) || preg_match( '/[^A-Za-z0-9._\/+-]/', $name ) ) {
			$fail( "Entry name not allowed: {$name}" );
			continue;
		}
		if ( str_ends_with( $name, '/' ) ) {
			if ( 040755 !== $mode ) {
				$fail( sprintf( 'Directory %s has mode %o.', $name, $mode ) );
			}
			continue;
		}
		if ( 0100644 !== $mode ) {
			$fail( sprintf( 'File %s has mode %o (symlink or executable?).', $name, $mode ) );
		}
		$bytes = $zip->getFromIndex( $i );
		if ( false === $bytes ) {
			$fail( "Cannot read {$name}." );
			continue;
		}
		$files[ $name ] = $bytes;
		if ( ! isset( $listed[ $name ] ) || $listed[ $name ][0] !== hash( 'sha256', $bytes ) || $listed[ $name ][1] !== strlen( $bytes ) ) {
			$fail( "Content list does not match {$name}." );
		}
		if ( ! is_dir( dirname( $temp . '/' . $name ) ) ) {
			mkdir( dirname( $temp . '/' . $name ), 0700, true );
		}
		file_put_contents( $temp . '/' . $name, $bytes );
	}
	$zip->close();
	foreach ( array_diff( array_keys( $listed ), array_keys( $files ) ) as $missing ) {
		$fail( "Content list names {$missing}, absent from the ZIP." );
	}
	ksort( $files, SORT_STRING );
	$core = 'mu-plugins/stealthaudit/';

	// ---------- layout ----------
	$required = array( 'mu-plugins/stealthaudit.php', 'bootstrap.php', 'src/Bootstrap/Runtime.php', 'templates/panel.php', 'assets/panel.js', 'assets/panel.css', 'assets/base.css', 'assets/tokens.css', 'bin/security-operator.php', 'config/stealthaudit.example.php', 'LICENSE', 'THIRD-PARTY-NOTICES.md', 'vendor/autoload.php', 'vendor/composer/autoload_real.php', 'vendor/composer/autoload_static.php', 'vendor/composer/ClassLoader.php', 'vendor/composer/LICENSE' );
	foreach ( $required as $file ) {
		$name = str_starts_with( $file, 'mu-plugins/' ) ? $file : $core . $file;
		if ( ! isset( $files[ $name ] ) ) {
			$fail( "Required file missing: {$name}" );
		}
	}
	$top = array( 'bootstrap.php', 'src', 'assets', 'templates', 'bin', 'config', 'vendor', 'LICENSE', 'THIRD-PARTY-NOTICES.md', 'languages' );
	foreach ( array_keys( $files ) as $name ) {
		if ( 'mu-plugins/stealthaudit.php' === $name ) {
			continue;
		}
		if ( ! str_starts_with( $name, $core ) || ! in_array( explode( '/', substr( $name, strlen( $core ) ) )[0], $top, true ) ) {
			$fail( "Outside the allowed layout: {$name}" );
		}
	}

	// ---------- exclusions (paths) ----------
	$forbidden = array(
		'#(^|/)(tests?|fixtures?|benchmarks?|artifacts|tools|docs|node_modules|\.git|\.github|\.hallmark|\.superpowers|\.phpunit\.cache|\.phpstan-cache)/#i' => 'development directory',
		'#(^|/)(package(-lock)?\.json|composer\.(json|lock)|installed\.json|phpunit[^/]*|phpcs[^/]*|phpstan[^/]*|\.wp-env[^/]*|\.env(\.[^/]*)?|\.gitattributes|\.gitignore|Dockerfile[^/]*|Makefile)$#i' => 'development or manifest file',
		'#\.(mmdb|key|p12|pfx|crt|der|csr|log|sql|sqlite|bak|orig|swp|tar|gz|zip|phar|sh|bat|ps1|phpt|dist|neon|toml|lock|mjs|cjs|ts|map)$#i' => 'excluded file type',
		'#^mu-plugins/stealthaudit/config/(?!stealthaudit\.example\.php$)#' => 'real configuration',
		'#^mu-plugins/stealthaudit/vendor/bin/#' => 'vendor binaries',
		'#/vendor/[^/]+/[^/]+/(ext|docs?|examples?|dev-bin|\.github)/#' => 'vendor development files',
	);
	foreach ( array_keys( $files ) as $name ) {
		foreach ( $forbidden as $pattern => $why ) {
			if ( preg_match( $pattern, $name ) ) {
				$fail( "Excluded ({$why}): {$name}" );
			}
		}
		if ( str_ends_with( $name, '.pem' ) && 'mu-plugins/stealthaudit/vendor/composer/ca-bundle/res/cacert.pem' !== $name ) {
			$fail( "PEM file outside the public CA bundle: {$name}" );
		}
	}

	// ---------- content: nothing secret-looking ----------
	foreach ( $files as $name => $bytes ) {
		if ( str_ends_with( $name, '.png' ) || str_ends_with( $name, '.woff2' ) ) {
			continue;
		}
		$product = ! str_contains( $name, '/vendor/' );
		$checks  = array(
			'/-----BEGIN [A-Z0-9 ]*PRIVATE KEY-----/' => 'private key',
			'#otpauth://[^\s"\']*secret=[A-Z2-7]{8,}#i' => 'provisioning URI with a secret',
			'/AKIA[0-9A-Z]{16}/'                      => 'cloud access key',
			'/[A-Za-z]:\\\\Users\\\\|\/home\/[a-z][a-z0-9_-]*\/|\/Users\/[A-Za-z]/' => 'home directory path',
			'/\be2e-[0-9a-f]{24}\b/'                   => 'synthetic test password',
		);
		if ( $product ) {
			$checks['/[A-Za-z0-9+\/]{43}=/']                         = 'base64 32-byte value';
			$checks["/'key_base64'\s*=>\s*'[^']+'/"]                  = 'configured key';
			$checks["/'authorized_user_ids'\s*=>\s*array\(\s*[0-9]/"] = 'configured operator';
			$checks['/sa-e2e-|stealthaudit-e2e|example\.invalid/']    = 'test fixture marker';
		}
		if ( str_ends_with( $name, 'cacert.pem' ) ) {
			// Public CA certificates only.
			if ( preg_match( '/-----BEGIN (?!CERTIFICATE-----)/', $bytes ) ) {
				$fail( 'CA bundle holds something other than certificates.' );
			}
			continue;
		}
		foreach ( $checks as $pattern => $why ) {
			if ( preg_match( $pattern, $bytes ) ) {
				$fail( "Secret-looking content ({$why}) in {$name}" );
			}
		}
	}

	// ---------- PHP structure ----------
	$vendor_namespaces = array();
	$php               = array_filter( array_keys( $files ), static fn ( string $n ): bool => str_ends_with( $n, '.php' ) );
	foreach ( $php as $name ) {
		if ( ! str_starts_with( $name, $core . 'vendor/' ) ) {
			continue;
		}
		$tokens    = PhpToken::tokenize( $files[ $name ] );
		$namespace = null;
		$depth     = 0;
		foreach ( $tokens as $i => $token ) {
			if ( '{' === $token->text || $token->is( array( T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ) ) ) {
				++$depth;
			} elseif ( '}' === $token->text ) {
				--$depth;
			} elseif ( $token->is( T_NAMESPACE ) && isset( $tokens[ $i + 2 ] ) && $tokens[ $i + 2 ]->is( array( T_NAME_QUALIFIED, T_STRING ) ) ) {
				$namespace = $tokens[ $i + 2 ]->text;
				if ( ! str_starts_with( $namespace . '\\', STEALTHAUDIT_PACKAGE_PREFIX . '\\' ) ) {
					$fail( "Unprefixed namespace {$namespace} in {$name}" );
				} else {
					$vendor_namespaces[ substr( $namespace, strlen( STEALTHAUDIT_PACKAGE_PREFIX ) + 1 ) ] = true;
				}
			} elseif ( null === $namespace && 0 === $depth && $token->is( array( T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM, T_FUNCTION ) ) && isset( $tokens[ $i + 2 ] ) && $tokens[ $i + 2 ]->is( T_STRING ) && ! $tokens[ $i - 1 ]->is( T_DOUBLE_COLON ) ) {
				$declared = $tokens[ $i + 2 ]->text;
				if ( ! ( $core . 'vendor/composer/autoload_real.php' === $name && 'ComposerAutoloaderInitStealthAudit' === $declared ) ) {
					$fail( "Global symbol {$declared} declared in {$name}" );
				}
			}
		}
	}
	// Functions the scoped vendor declares under the prefix (StealthAuditVendor\trigger_deprecation).
	foreach ( $php as $name ) {
		if ( str_starts_with( $name, $core . 'vendor/' ) && preg_match_all( '/^namespace ' . STEALTHAUDIT_PACKAGE_PREFIX . ';.*?function (\w+)\s*\(/sm', $files[ $name ], $m ) ) {
			foreach ( $m[1] as $function ) {
				$vendor_namespaces[ $function ] = true;
			}
		}
	}
	unset( $vendor_namespaces[''] );
	$roots = array_keys( $vendor_namespaces );
	if ( array() === $roots ) {
		$fail( 'No prefixed vendor namespace found.' );
	}
	// Product code AND vendor code: PHP-Scoper skips symbols it thinks are internal (extension stubs).
	foreach ( $php as $name ) {
		$scan = stealthaudit_package_prefix_source( $files[ $name ], $roots, true, str_starts_with( $name, $core . 'vendor/' ) );
		foreach ( $scan['problems'] as $problem ) {
			$fail( "{$name}: {$problem}" );
		}
	}
	// Composer runtime: classmap only our namespaces, every target present, "files" identifiers isolated.
	$static = $files[ $core . 'vendor/composer/autoload_static.php' ] ?? '';
	if ( preg_match_all( "/^\s*'((?:[^'\\\\]|\\\\.)+)' => __DIR__ \. '([^']*)' \. '([^']+)',$/m", $static, $entries, PREG_SET_ORDER ) ) {
		foreach ( $entries as $entry ) {
			$class = str_replace( '\\\\', '\\', $entry[1] );
			$parts = array();
			foreach ( explode( '/', $core . 'vendor/composer/' . $entry[2] . $entry[3] ) as $part ) {
				if ( '..' === $part ) {
					array_pop( $parts );
				} elseif ( '' !== $part && '.' !== $part ) {
					$parts[] = $part;
				}
			}
			$path = implode( '/', $parts );
			if ( preg_match( '/^[0-9a-f]{32}$/', $class ) ) {
				// "files" entry: identifier must be the package-unique one.
				if ( preg_match( '#/vendor/([^/]+/[^/]+)/(.+)$#', $path, $p ) && stealthaudit_package_files_identifier( $p[1], $p[2] ) !== $class ) {
					$fail( "Composer files identifier for {$p[1]} is not isolated." );
				}
			} elseif ( ! str_starts_with( $class, 'StealthAudit\\' ) && ! str_starts_with( $class, STEALTHAUDIT_PACKAGE_PREFIX . '\\' ) ) {
				$fail( "Classmap entry outside our namespaces: {$class}" );
			}
			if ( ! isset( $files[ $path ] ) && ! is_dir( $temp . '/' . $path ) ) {
				$fail( "Autoload target missing: {$class} -> {$path}" );
			}
		}
	} else {
		$fail( 'Cannot read autoload_static.php.' );
	}
	foreach ( $files as $name => $bytes ) {
		if ( str_starts_with( $name, $core . 'vendor/composer/' ) && str_ends_with( $name, '.php' ) && ! str_contains( substr( $name, strlen( $core . 'vendor/composer/' ) ), '/' ) ) {
			$left = str_replace( array( STEALTHAUDIT_PACKAGE_PREFIX . '\\\\Composer', STEALTHAUDIT_PACKAGE_PREFIX . '\\Composer' ), '', $bytes );
			if ( preg_match( '/Composer\\\\{1,2}(?:Autoload|InstalledVersions)\b|^namespace Composer\b/m', $left ) ) {
				$fail( "Unprefixed Composer runtime reference in {$name}" );
			}
		}
	}

	// ---------- php -l ----------
	foreach ( $php as $name ) {
		exec( escapeshellarg( PHP_BINARY ) . ' -n -l ' . escapeshellarg( $temp . '/' . $name ) . ' 2>&1', $output, $status );
		if ( 0 !== $status ) {
			$fail( "php -l failed: {$name}" );
		}
	}

	// ---------- version, license, notices ----------
	$loader_version = stealthaudit_package_loader_version( $files['mu-plugins/stealthaudit.php'] ?? '' );
	if ( $loader_version !== $version ) {
		$fail( "Loader Version ({$loader_version}) differs from the file name ({$version})." );
	}
	$license = $files[ $core . 'LICENSE' ] ?? '';
	if ( ! str_contains( $license, 'GNU GENERAL PUBLIC LICENSE' ) || ! str_contains( $license, 'Version 3, 29 June 2007' ) ) {
		$fail( 'LICENSE is not the GPL-3.0 text.' );
	}
	$notices = $files[ $core . 'THIRD-PARTY-NOTICES.md' ] ?? '';
	$bundled = array();
	foreach ( array_keys( $files ) as $name ) {
		if ( preg_match( '#^mu-plugins/stealthaudit/vendor/([^/]+/[^/]+)/#', $name, $m ) && 'composer/' !== substr( $m[1], 0, 9 ) || preg_match( '#^mu-plugins/stealthaudit/vendor/(composer/ca-bundle)/#', $name, $m ) ) {
			$bundled[ $m[1] ] = true;
		}
	}
	foreach ( array_keys( $bundled ) as $package ) {
		if ( ! preg_match( '/^## ' . preg_quote( $package, '/' ) . ' /m', $notices ) ) {
			$fail( "THIRD-PARTY-NOTICES.md has no section for bundled {$package}." );
		}
		if ( ! array_filter( array_keys( $files ), static fn ( string $n ): bool => (bool) preg_match( '#^mu-plugins/stealthaudit/vendor/' . preg_quote( $package, '#' ) . '/(license|licence|copying|notice)[^/]*$#i', $n ) ) ) {
			$fail( "Bundled {$package} ships without its license file." );
		}
	}

	// ---------- optional: same bytes as the working tree ----------
	if ( $compare ) {
		$map = array(
			'mu-plugins/stealthaudit.php' => 'loader/stealthaudit.php',
		);
		foreach ( array_keys( $files ) as $name ) {
			if ( str_starts_with( $name, $core ) && ! str_starts_with( $name, $core . 'vendor/' ) ) {
				$map[ $name ] = substr( $name, strlen( $core ) );
			}
		}
		foreach ( $map as $name => $source ) {
			$expected = is_file( $repo . '/' . $source ) ? (string) file_get_contents( $repo . '/' . $source ) : null;
			if ( null !== $expected && str_starts_with( $source, 'src/' ) && str_ends_with( $source, '.php' ) ) {
				$expected = stealthaudit_package_prefix_source( $expected, $roots )['code'];
			}
			if ( null === $expected || $expected !== $files[ $name ] ) {
				$fail( "Differs from the working tree: {$name} ({$source})" );
			}
		}
		foreach ( array( 'src', 'assets', 'templates' ) as $dir ) {
			foreach ( stealthaudit_package_files( $repo . '/' . $dir ) as $file ) {
				if ( ! isset( $files[ $core . $dir . '/' . $file ] ) ) {
					$fail( "Working-tree file not packaged: {$dir}/{$file}" );
				}
			}
		}
	}

	$counts = array( 'files' => count( $files ), 'php' => count( $php ), 'vendor_roots' => count( $roots ), 'bytes' => filesize( $zip_path ) );
	echo '[verify] ' . json_encode( $counts ) . "\n";
} catch ( Throwable $failure ) {
	$fail( $failure->getMessage() );
} finally {
	if ( null !== $temp ) {
		stealthaudit_package_remove( $temp );
	}
}
if ( array() !== $failures ) {
	foreach ( $failures as $failure ) {
		fwrite( STDERR, '[verify] FAIL ' . $failure . "\n" );
	}
	fwrite( STDERR, '[verify] ' . count( $failures ) . " problem(s).\n" );
	exit( 1 );
}
echo "[verify] OK\n";
