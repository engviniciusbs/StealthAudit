<?php
// SPDX-License-Identifier: GPL-3.0-or-later
// Build the distribution ZIP from the lockfiles in a clean temporary directory (build tooling,
// never shipped). Started by `npm run package` (tools/package.mjs), which exports the committed
// tree with `git archive` and runs this file with PHP 8.3 + Composer in the wp-env CLI container:
//
//   php tools/package.php --archive=build/package-source.tar --commit=<sha> --dirty=0|1
//                         [--package-version=0.1.0-dev+marker] [--out=dist]
//
// Steps: validate the archive against the package layout -> composer validate/install --no-dev/
// audit/check-platform-reqs/licenses in a temporary copy -> prune vendor to runtime files ->
// PHP-Scoper (pinned PHAR, SHA-256 checked) prefixes vendor with StealthAuditVendor\ -> rewrite
// the product's vendor imports -> composer dump-autoload (classmap-authoritative, no platform
// check) and isolate Composer's runtime classes -> lint -> deterministic ZIP + SHA-256 + content
// list + build manifest. The workspace (and its dev vendor/) is never modified.
if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}
require __DIR__ . '/package-lib.php';
require __DIR__ . '/notices.php';

const STEALTHAUDIT_SCOPER_VERSION = '0.18.20';
const STEALTHAUDIT_SCOPER_SHA256  = '5e35e4a89ea53b2c70a19bfa8a856e4f9080aa12eb13a5bce650bb69d08eb122';
const STEALTHAUDIT_SCOPER_URL     = 'https://github.com/humbug/php-scoper/releases/download/0.18.20/php-scoper.phar';
// Licenses compatible with distributing the bundle under GPL-3.0-or-later.
const STEALTHAUDIT_LICENSES = array( 'MIT', 'BSD-2-Clause', 'BSD-3-Clause', 'Apache-2.0', 'ISC' );
// Fixed entry time (1980-02-01T00:00:00Z): the same commit always gives the same ZIP bytes.
const STEALTHAUDIT_ZIP_MTIME = 318211200;

// Shipped from the archive, by package path. Everything else the archive holds must be listed
// in $source_only (kept in the source archive, never shipped) or be export-ignored.
$shipped = array(
	'loader/stealthaudit.php'          => 'stealthaudit.php',
	'bootstrap.php'                    => 'stealthaudit/bootstrap.php',
	'src/'                             => 'stealthaudit/src/',
	'assets/'                          => 'stealthaudit/assets/',
	'templates/'                       => 'stealthaudit/templates/',
	'bin/security-operator.php'        => 'stealthaudit/bin/security-operator.php',
	'config/stealthaudit.example.php'  => 'stealthaudit/config/stealthaudit.example.php',
	'LICENSE'                          => 'stealthaudit/LICENSE',
	'THIRD-PARTY-NOTICES.md'           => 'stealthaudit/THIRD-PARTY-NOTICES.md',
);
$build_inputs = array( 'composer.json', 'composer.lock' );
$source_only  = array( 'README.md', 'docs/' );

$log = static function ( string $message ): void {
	fwrite( STDOUT, '[package] ' . $message . "\n" );
};

/**
 * Run a command without a shell; return stdout, throw with the output on failure.
 *
 * @param list<string>          $command Command and arguments.
 * @param string                $cwd     Working directory.
 * @param array<string,string>  $env     Extra environment.
 * @param bool                  $check   Throw on a non-zero exit.
 * @return array{0:int,1:string,2:string}
 */
function stealthaudit_run( array $command, string $cwd, array $env = array(), bool $check = true ): array {
	$process = proc_open( $command, array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes, $cwd, array_merge( getenv(), $env ) );
	if ( ! is_resource( $process ) ) {
		throw new RuntimeException( 'Cannot start ' . $command[0] );
	}
	$stdout = stream_get_contents( $pipes[1] );
	$stderr = stream_get_contents( $pipes[2] );
	fclose( $pipes[1] );
	fclose( $pipes[2] );
	$status = proc_close( $process );
	if ( $check && 0 !== $status ) {
		throw new RuntimeException( implode( ' ', $command ) . " failed ({$status}):\n" . trim( $stdout . "\n" . $stderr ) );
	}
	return array( $status, (string) $stdout, (string) $stderr );
}

/**
 * Copy a file or a directory tree.
 *
 * @param string $from Source.
 * @param string $to   Destination.
 */
function stealthaudit_copy( string $from, string $to ): void {
	if ( is_file( $from ) ) {
		if ( ! is_dir( dirname( $to ) ) ) {
			mkdir( dirname( $to ), 0755, true );
		}
		if ( ! copy( $from, $to ) ) {
			throw new RuntimeException( "Cannot copy {$from}" );
		}
		return;
	}
	foreach ( stealthaudit_package_files( $from ) as $file ) {
		stealthaudit_copy( $from . '/' . $file, $to . '/' . $file );
	}
}

$options = getopt( '', array( 'archive:', 'commit:', 'dirty:', 'package-version:', 'out:' ) );
$repo    = dirname( __DIR__ );
$temp    = null;
try {
	$archive = (string) ( $options['archive'] ?? '' );
	$commit  = (string) ( $options['commit'] ?? '' );
	$dirty   = '1' === (string) ( $options['dirty'] ?? '' );
	$out_dir = $repo . '/' . trim( (string) ( $options['out'] ?? 'dist' ), '/' );
	if ( ! is_file( $repo . '/' . $archive ) || ! preg_match( '/^[0-9a-f]{40}$/D', $commit ) || ! preg_match( '#^[A-Za-z0-9._/-]+$#D', (string) ( $options['out'] ?? 'dist' ) ) || str_contains( (string) ( $options['out'] ?? '' ), '..' ) ) {
		throw new RuntimeException( 'Usage: npm run package (see tools/package.mjs); archive, commit or output directory invalid.' );
	}

	$temp = sys_get_temp_dir() . '/stealthaudit-package-' . bin2hex( random_bytes( 6 ) );
	if ( ! mkdir( $temp, 0700 ) ) {
		throw new RuntimeException( 'Cannot create the temporary build directory.' );
	}
	$tree = $temp . '/tree';
	( new PharData( $repo . '/' . $archive ) )->extractTo( $tree );
	$log( 'source: git archive of ' . substr( $commit, 0, 12 ) . ( $dirty ? ' + uncommitted tracked changes (dirty)' : '' ) );

	// ---------- the archive must be exactly: shipped sources + build inputs + source-only docs ----------
	$unexpected = array();
	foreach ( stealthaudit_package_files( $tree ) as $file ) {
		$known = in_array( $file, $build_inputs, true );
		foreach ( array_merge( array_keys( $shipped ), $source_only ) as $entry ) {
			$known = $known || $file === $entry || ( str_ends_with( $entry, '/' ) && str_starts_with( $file, $entry ) );
		}
		if ( ! $known ) {
			$unexpected[] = $file;
		}
	}
	if ( array() !== $unexpected ) {
		throw new RuntimeException( "The git archive holds files that are neither packaged nor export-ignored (.gitattributes):\n  " . implode( "\n  ", $unexpected ) );
	}
	foreach ( array_merge( array_keys( $shipped ), $build_inputs ) as $entry ) {
		if ( ! file_exists( $tree . '/' . rtrim( $entry, '/' ) ) ) {
			throw new RuntimeException( "Archive is missing {$entry}." );
		}
	}

	// ---------- version ----------
	$loader         = (string) file_get_contents( $tree . '/loader/stealthaudit.php' );
	$source_version = stealthaudit_package_loader_version( $loader );
	$npm_version    = json_decode( (string) file_get_contents( $repo . '/package.json' ), true, 16, JSON_THROW_ON_ERROR )['version'] ?? null;
	if ( null === $source_version || $source_version !== $npm_version ) {
		throw new RuntimeException( "Loader version ({$source_version}) and package.json version ({$npm_version}) differ." );
	}
	$version = (string) ( $options['package-version'] ?? $source_version );
	if ( ! preg_match( '/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/D', $version ) ) {
		throw new RuntimeException( "Invalid version {$version}." );
	}
	if ( $version !== $source_version ) {
		// Test-only marker (package update test): the loader header carries it into the install.
		$loader = preg_replace( '/^ \* Version: \S+$/m', ' * Version: ' . $version, $loader, 1 );
		file_put_contents( $tree . '/loader/stealthaudit.php', $loader );
	}
	$log( "version {$version}" );

	// ---------- Composer: validate, install production deps from the lock, audit ----------
	$build = $temp . '/build';
	mkdir( $build, 0700 );
	foreach ( $build_inputs as $file ) {
		copy( $tree . '/' . $file, $build . '/' . $file );
	}
	$composer_env = array(
		'COMPOSER_ROOT_VERSION' => preg_replace( '/\+.*$/', '', $version ),
		'COMPOSER_NO_INTERACTION' => '1',
		'COMPOSER_ALLOW_SUPERUSER' => '0',
	);
	$composer = static fn ( array $args, string $cwd = '' ): array => stealthaudit_run( array_merge( array( 'composer' ), $args, array( '--no-interaction', '--no-ansi' ) ), '' === $cwd ? $build : $cwd, $composer_env );
	$tools    = array(
		'php'      => PHP_VERSION,
		'composer' => trim( preg_replace( '/^Composer version (\S+).*$/s', '$1', $composer( array( '--version' ) )[1] ) ),
	);
	$checks   = array();
	$composer( array( 'validate', '--strict' ) );
	$checks['composer validate --strict'] = 'valid';
	// Build-copy only: unique autoloader class names, no echoing platform check, authoritative classmap.
	$composer( array( 'config', 'autoloader-suffix', 'StealthAudit' ) );
	$composer( array( 'config', 'platform-check', 'false' ) );
	$composer( array( 'config', 'classmap-authoritative', 'true' ) );
	$composer( array( 'install', '--no-dev', '--prefer-dist', '--no-scripts', '--no-plugins', '--no-autoloader', '--no-progress' ) );
	$audit = json_decode( $composer( array( 'audit', '--locked', '--no-dev', '--format=json' ) )[1], true, 64, JSON_THROW_ON_ERROR );
	if ( array() !== ( $audit['advisories'] ?? array() ) || array() !== ( $audit['abandoned'] ?? array() ) ) {
		throw new RuntimeException( 'composer audit reported advisories or abandoned packages.' );
	}
	$checks['composer audit --locked --no-dev'] = 'no advisories, no abandoned packages';
	$platform = json_decode( $composer( array( 'check-platform-reqs', '--lock', '--no-dev', '--format=json' ) )[1], true, 16, JSON_THROW_ON_ERROR );
	$checks['composer check-platform-reqs --lock --no-dev (build host)'] = array_column( $platform, 'status', 'name' );
	$licenses = json_decode( $composer( array( 'licenses', '--no-dev', '--format=json' ) )[1], true, 16, JSON_THROW_ON_ERROR );
	$inventory = array();
	foreach ( $licenses['dependencies'] as $name => $info ) {
		if ( array() === $info['license'] || array() !== array_diff( $info['license'], STEALTHAUDIT_LICENSES ) ) {
			throw new RuntimeException( "License of {$name} (" . implode( ', ', $info['license'] ) . ') is not in the allowlist.' );
		}
		$inventory[ $name ] = $info['version'] . ' ' . implode( ' OR ', $info['license'] );
	}
	$checks['composer licenses --no-dev'] = $inventory;
	$log( 'composer: validate --strict, install --no-dev, audit, check-platform-reqs, licenses OK (' . count( $inventory ) . ' packages)' );

	// ---------- notices from the lock must match the committed file ----------
	$lock    = json_decode( (string) file_get_contents( $build . '/composer.lock' ), true, 512, JSON_THROW_ON_ERROR );
	$notices = stealthaudit_third_party_notices( $lock, $build . '/vendor' );
	if ( (string) file_get_contents( $tree . '/THIRD-PARTY-NOTICES.md' ) !== $notices ) {
		throw new RuntimeException( 'THIRD-PARTY-NOTICES.md is stale: run php tools/notices.php and commit the result.' );
	}
	if ( ! str_starts_with( ltrim( (string) file_get_contents( $tree . '/LICENSE' ) ), 'GNU GENERAL PUBLIC LICENSE' ) || ! str_contains( (string) file_get_contents( $tree . '/LICENSE' ), 'Version 3, 29 June 2007' ) ) {
		throw new RuntimeException( 'LICENSE is not the GPL-3.0 text.' );
	}

	// ---------- prune vendor to what runs: autoload roots + license files ----------
	$installed = json_decode( (string) file_get_contents( $build . '/vendor/composer/installed.json' ), true, 512, JSON_THROW_ON_ERROR );
	$roots     = array();
	// Runtime data outside the autoload roots (composer/ca-bundle's CA list, read by its own code).
	$extra = array( 'composer/ca-bundle' => array( 'res/' ) );
	foreach ( $installed['packages'] as $package ) {
		$dir  = realpath( $build . '/vendor/composer/' . $package['install-path'] );
		$keep = $extra[ $package['name'] ] ?? array();
		foreach ( $package['autoload'] ?? array() as $type => $entries ) {
			if ( ! in_array( $type, array( 'psr-4', 'files' ), true ) ) {
				throw new RuntimeException( "{$package['name']}: autoload type {$type} is not handled by the scoped build." );
			}
			foreach ( $entries as $namespace => $paths ) {
				foreach ( (array) $paths as $path ) {
					$path = trim( (string) $path, '/' );
					if ( '' === $path || '.' === $path ) {
						throw new RuntimeException( "{$package['name']}: autoload root is the whole package." );
					}
					$keep[] = 'files' === $type ? $path : $path . '/';
					if ( 'psr-4' === $type ) {
						$roots[] = rtrim( (string) $namespace, '\\' );
					}
				}
			}
		}
		foreach ( stealthaudit_package_files( $dir ) as $file ) {
			$wanted = (bool) preg_match( '/^(license|licence|copying|notice)(\..*)?$/i', $file );
			foreach ( $keep as $entry ) {
				$wanted = $wanted || $file === $entry || ( str_ends_with( $entry, '/' ) && str_starts_with( $file, $entry ) );
			}
			if ( ! $wanted ) {
				unlink( $dir . '/' . $file );
			}
		}
	}
	// Global functions the scoped vendor declares (symfony/deprecation-contracts).
	foreach ( $installed['packages'] as $package ) {
		foreach ( $package['autoload']['files'] ?? array() as $path ) {
			$tokens = PhpToken::tokenize( (string) file_get_contents( realpath( $build . '/vendor/composer/' . $package['install-path'] ) . '/' . $path ) );
			foreach ( $tokens as $i => $token ) {
				if ( $token->is( T_FUNCTION ) && isset( $tokens[ $i + 2 ] ) && $tokens[ $i + 2 ]->is( T_STRING ) ) {
					$roots[] = $tokens[ $i + 2 ]->text;
				}
			}
		}
	}
	$roots = array_values( array_unique( array_filter( $roots ) ) );
	sort( $roots );
	$log( 'vendor roots: ' . implode( ', ', $roots ) );

	// ---------- PHP-Scoper (isolated: pinned PHAR outside the product's dependencies) ----------
	$phar = $repo . '/build/tools/php-scoper-' . STEALTHAUDIT_SCOPER_VERSION . '.phar';
	if ( ! is_file( $phar ) ) {
		if ( ! is_dir( dirname( $phar ) ) ) {
			mkdir( dirname( $phar ), 0755, true );
		}
		$log( 'downloading PHP-Scoper ' . STEALTHAUDIT_SCOPER_VERSION );
		$bytes = file_get_contents( STEALTHAUDIT_SCOPER_URL, false, stream_context_create( array( 'http' => array( 'timeout' => 60 ) ) ) );
		if ( false === $bytes || ! hash_equals( STEALTHAUDIT_SCOPER_SHA256, hash( 'sha256', $bytes ) ) ) {
			throw new RuntimeException( 'PHP-Scoper download failed or its SHA-256 does not match.' );
		}
		file_put_contents( $phar . '.tmp', $bytes );
		rename( $phar . '.tmp', $phar );
	}
	if ( ! hash_equals( STEALTHAUDIT_SCOPER_SHA256, hash_file( 'sha256', $phar ) ) ) {
		throw new RuntimeException( "{$phar}: SHA-256 mismatch; delete it to download the pinned release again." );
	}
	$scoped = $temp . '/scoped';
	stealthaudit_run( array( PHP_BINARY, $phar, 'add-prefix', '--config=' . __DIR__ . '/scoper.inc.php', '--output-dir=' . $scoped, '--working-dir=' . $build, '--force', '--no-interaction', '--no-ansi' ), $build, array( 'STEALTHAUDIT_BUILD_DIR' => $build, 'STEALTHAUDIT_SCOPER_ROOTS' => json_encode( $roots ) ) );
	$tools['php-scoper'] = STEALTHAUDIT_SCOPER_VERSION . ' (sha256 ' . STEALTHAUDIT_SCOPER_SHA256 . ')';
	// Scoper writes outputs relative to the common base of its finders (the vendor directory).
	$scoped_vendor = is_dir( $scoped . '/vendor' ) ? $scoped . '/vendor' : $scoped;
	if ( ! is_file( $scoped_vendor . '/composer/installed.json' ) || is_file( $scoped_vendor . '/scoper-autoload.php' ) && false === unlink( $scoped_vendor . '/scoper-autoload.php' ) ) {
		throw new RuntimeException( 'Unexpected PHP-Scoper output layout.' );
	}
	// Nothing in the scoped vendor may still name a shipped vendor namespace without the prefix.
	foreach ( stealthaudit_package_files( $scoped_vendor ) as $file ) {
		if ( str_ends_with( $file, '.php' ) && ( ! str_starts_with( $file, 'composer/' ) || str_starts_with( $file, 'composer/ca-bundle/' ) ) ) {
			$scan = stealthaudit_package_prefix_source( (string) file_get_contents( $scoped_vendor . '/' . $file ), $roots, true, true );
			if ( array() !== $scan['problems'] ) {
				throw new RuntimeException( "vendor/{$file}: " . implode( '; ', $scan['problems'] ) );
			}
		}
	}
	$log( 'PHP-Scoper ' . STEALTHAUDIT_SCOPER_VERSION . ': vendor prefixed with ' . STEALTHAUDIT_PACKAGE_PREFIX . '\\ (extension-stub symbols included)' );

	// ---------- assemble mu-plugins/ ----------
	$package = $temp . '/package/mu-plugins';
	foreach ( $shipped as $from => $to ) {
		stealthaudit_copy( $tree . '/' . rtrim( $from, '/' ), $package . '/' . rtrim( $to, '/' ) );
	}
	stealthaudit_copy( $scoped_vendor, $package . '/stealthaudit/vendor' );
	$rewritten = 0;
	foreach ( array( 'stealthaudit.php', 'stealthaudit/bootstrap.php', 'stealthaudit/bin/security-operator.php', 'stealthaudit/config/stealthaudit.example.php' ) as $file ) {
		$result = stealthaudit_package_prefix_source( (string) file_get_contents( $package . '/' . $file ), $roots );
		if ( array() !== $result['problems'] || 0 !== $result['rewritten'] ) {
			throw new RuntimeException( "{$file}: unexpected vendor reference: " . implode( '; ', $result['problems'] ) );
		}
	}
	foreach ( array( 'src', 'templates' ) as $dir ) {
		foreach ( stealthaudit_package_files( $package . '/stealthaudit/' . $dir ) as $file ) {
			if ( ! str_ends_with( $file, '.php' ) ) {
				continue;
			}
			$path   = $package . '/stealthaudit/' . $dir . '/' . $file;
			$result = stealthaudit_package_prefix_source( (string) file_get_contents( $path ), $roots );
			if ( array() !== $result['problems'] ) {
				throw new RuntimeException( "{$dir}/{$file}: " . implode( '; ', $result['problems'] ) );
			}
			if ( $result['rewritten'] > 0 ) {
				file_put_contents( $path, $result['code'] );
				$rewritten += $result['rewritten'];
			}
		}
	}
	$log( "product imports rewritten: {$rewritten}" );

	// ---------- autoloader: regenerate, then isolate Composer's own runtime classes ----------
	$core = $package . '/stealthaudit';
	copy( $build . '/composer.json', $core . '/composer.json' );
	$composer( array( 'dump-autoload', '--no-dev', '--classmap-authoritative', '--no-scripts', '--no-plugins' ), $core );
	unlink( $core . '/composer.json' );
	unlink( $core . '/vendor/composer/installed.json' );
	if ( is_file( $core . '/vendor/composer/platform_check.php' ) ) {
		throw new RuntimeException( 'Composer generated a platform check; it would echo into public responses.' );
	}
	// Composer\Autoload\ClassLoader and Composer\InstalledVersions are global classes shared with
	// every other Composer autoloader in the process (first loaded wins). Move ours under the prefix.
	$runtime_files = array( 'autoload.php', 'composer/autoload_real.php', 'composer/autoload_static.php', 'composer/autoload_classmap.php', 'composer/autoload_files.php', 'composer/autoload_psr4.php', 'composer/autoload_namespaces.php', 'composer/ClassLoader.php', 'composer/InstalledVersions.php', 'composer/installed.php' );
	$moved         = 0;
	foreach ( $runtime_files as $file ) {
		$path = $core . '/vendor/' . $file;
		if ( ! is_file( $path ) ) {
			continue;
		}
		$code = (string) file_get_contents( $path );
		$code = preg_replace_callback(
			'/(?<![A-Za-z0-9_\\\\])(\\\\{0,2})Composer(\\\\{1,2})(Autoload|InstalledVersions)\b/',
			static function ( array $m ) use ( &$moved ): string {
				++$moved;
				return $m[1] . STEALTHAUDIT_PACKAGE_PREFIX . $m[2] . 'Composer' . $m[2] . $m[3];
			},
			$code
		);
		$code = preg_replace_callback(
			'/^namespace Composer(;|\\\\Autoload;)/m',
			static function ( array $m ) use ( &$moved ): string {
				++$moved;
				return 'namespace ' . STEALTHAUDIT_PACKAGE_PREFIX . '\\Composer' . $m[1];
			},
			$code
		);
		file_put_contents( $path, $code );
	}
	// dump-autoload maps InstalledVersions without writing it (only the installer does); nothing
	// here uses it, so drop the dangling classmap entry instead of shipping a missing file.
	if ( ! is_file( $core . '/vendor/composer/InstalledVersions.php' ) ) {
		foreach ( array( 'composer/autoload_static.php', 'composer/autoload_classmap.php' ) as $file ) {
			$code    = (string) file_get_contents( $core . '/vendor/' . $file );
			$updated = preg_replace( "/^\s*'" . STEALTHAUDIT_PACKAGE_PREFIX . "\\\\\\\\Composer\\\\\\\\InstalledVersions' => [^\n]+\n/m", '', $code, -1, $count );
			if ( 1 !== $count ) {
				throw new RuntimeException( "{$file}: InstalledVersions classmap entry not found exactly once." );
			}
			file_put_contents( $core . '/vendor/' . $file, $updated );
		}
	}
	// Per-package "files" identifiers, unique to this package (see stealthaudit_package_files_identifier()).
	$identifiers = 0;
	foreach ( $installed['packages'] as $info ) {
		foreach ( $info['autoload']['files'] ?? array() as $path ) {
			$standard = hash( 'md5', $info['name'] . ':' . $path );
			foreach ( array( 'composer/autoload_static.php', 'composer/autoload_files.php' ) as $file ) {
				$code  = (string) file_get_contents( $core . '/vendor/' . $file );
				$count = substr_count( $code, "'" . $standard . "'" );
				if ( 1 !== $count ) {
					throw new RuntimeException( "{$file}: files identifier for {$info['name']}:{$path} not found exactly once." );
				}
				file_put_contents( $core . '/vendor/' . $file, str_replace( "'" . $standard . "'", "'" . stealthaudit_package_files_identifier( $info['name'], $path ) . "'", $code ) );
				++$identifiers;
			}
		}
	}
	foreach ( glob( $core . '/vendor/composer/*.php' ) as $path ) {
		$left = str_replace( array( STEALTHAUDIT_PACKAGE_PREFIX . '\\\\Composer', STEALTHAUDIT_PACKAGE_PREFIX . '\\Composer' ), '', (string) file_get_contents( $path ) );
		if ( preg_match( '/Composer\\\\{1,2}(?:Autoload|InstalledVersions)\b|^namespace Composer\b/m', $left ) ) {
			throw new RuntimeException( 'vendor/composer/' . basename( $path ) . ': unprefixed Composer runtime reference left.' );
		}
	}
	$log( "autoloader regenerated; Composer runtime references moved: {$moved}; files identifiers: {$identifiers}" );

	// ---------- lint every shipped PHP file with the build PHP ----------
	$php_files = 0;
	foreach ( stealthaudit_package_files( $package ) as $file ) {
		if ( str_ends_with( $file, '.php' ) ) {
			stealthaudit_run( array( PHP_BINARY, '-n', '-l', $package . '/' . $file ), $package );
			++$php_files;
		}
	}
	$log( "php -l: {$php_files} files" );

	// ---------- deterministic ZIP, checksum, content list, manifest ----------
	if ( ! is_dir( $out_dir ) ) {
		mkdir( $out_dir, 0755, true );
	}
	$name    = 'stealthaudit-' . $version;
	$zip_tmp = $temp . '/' . $name . '.zip';
	$zip     = new ZipArchive();
	if ( true !== $zip->open( $zip_tmp, ZipArchive::CREATE | ZipArchive::EXCL ) ) {
		throw new RuntimeException( 'Cannot create the ZIP.' );
	}
	$files       = stealthaudit_package_files( $temp . '/package' );
	$directories = array();
	foreach ( $files as $file ) {
		for ( $dir = dirname( $file ); '.' !== $dir; $dir = dirname( $dir ) ) {
			$directories[ $dir . '/' ] = true;
		}
	}
	$entries = array_merge( array_keys( $directories ), $files );
	sort( $entries, SORT_STRING );
	$contents = '';
	foreach ( $entries as $entry ) {
		if ( str_ends_with( $entry, '/' ) ) {
			$zip->addEmptyDir( rtrim( $entry, '/' ) );
			$zip->setExternalAttributesName( $entry, ZipArchive::OPSYS_UNIX, ( 040755 ) << 16 );
		} else {
			$zip->addFile( $temp . '/package/' . $entry, $entry );
			$zip->setCompressionName( $entry, ZipArchive::CM_DEFLATE, 9 );
			$zip->setExternalAttributesName( $entry, ZipArchive::OPSYS_UNIX, ( 0100644 ) << 16 );
			$contents .= hash_file( 'sha256', $temp . '/package/' . $entry ) . '  ' . filesize( $temp . '/package/' . $entry ) . '  ' . $entry . "\n";
		}
		$zip->setMtimeName( $entry, STEALTHAUDIT_ZIP_MTIME );
	}
	if ( ! $zip->close() ) {
		throw new RuntimeException( 'Cannot write the ZIP.' );
	}
	$sha = hash_file( 'sha256', $zip_tmp );
	foreach ( array( '.zip', '.zip.sha256', '.contents.txt', '.build.json' ) as $suffix ) {
		if ( is_file( $out_dir . '/' . $name . $suffix ) ) {
			unlink( $out_dir . '/' . $name . $suffix );
		}
	}
	copy( $zip_tmp, $out_dir . '/' . $name . '.zip' );
	file_put_contents( $out_dir . '/' . $name . '.zip.sha256', $sha . '  ' . $name . ".zip\n" );
	file_put_contents( $out_dir . '/' . $name . '.contents.txt', $contents );
	file_put_contents(
		$out_dir . '/' . $name . '.build.json',
		json_encode(
			array(
				'package'      => $name . '.zip',
				'sha256'       => $sha,
				'version'      => $version,
				'commit'       => $commit,
				'dirty'        => $dirty,
				'files'        => count( $files ),
				'php_files'    => $php_files,
				'bytes'        => filesize( $zip_tmp ),
				'prefix'       => STEALTHAUDIT_PACKAGE_PREFIX,
				'vendor_roots' => $roots,
				'lock_hash'    => $lock['content-hash'],
				'tools'        => $tools,
				'checks'       => $checks,
				'zip_mtime'    => gmdate( 'c', STEALTHAUDIT_ZIP_MTIME ),
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		) . "\n"
	);
	$log( "{$name}.zip: " . count( $files ) . ' files, ' . filesize( $zip_tmp ) . " bytes\n[package] sha256 {$sha}" . ( $dirty ? "\n[package] WARNING: built from uncommitted changes; not a release artifact." : '' ) );
} catch ( Throwable $failure ) {
	fwrite( STDERR, '[package] FAILED: ' . $failure->getMessage() . "\n" );
	$status = 1;
} finally {
	if ( null !== $temp ) {
		stealthaudit_package_remove( $temp );
	}
}
exit( $status ?? 0 );
