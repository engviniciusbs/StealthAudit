<?php
// SPDX-License-Identifier: GPL-3.0-or-later
// Development only: tools/verify-package.php must REJECT tampered copies of a good package.
// Each case changes one thing, rewrites the checksum and content list to match (so only the
// targeted rule can fire) and expects a failure naming that rule. Work files: build/package-tamper/.
//
//   php tests/package/verify-negative.php dist/stealthaudit-<version>.zip
if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}
require dirname( __DIR__, 2 ) . '/tools/package-lib.php';
$repo = dirname( __DIR__, 2 );
$good = $repo . '/' . ( $argv[1] ?? '' );
if ( ! preg_match( '/^stealthaudit-(.+)\.zip$/', basename( $good ), $match ) || ! is_file( $good ) ) {
	fwrite( STDERR, "usage: php tests/package/verify-negative.php dist/stealthaudit-<version>.zip\n" );
	exit( 2 );
}
$name  = basename( $good );
$core  = 'mu-plugins/stealthaudit/';
$edit  = static fn ( string $file, callable $change ): Closure => static function ( ZipArchive $zip ) use ( $file, $change ): void {
	$zip->addFromString( $file, $change( (string) $zip->getFromName( $file ) ) );
};
$cases = array(
	'test directory'               => array( static fn ( ZipArchive $z ) => $z->addFromString( $core . 'tests/x.php', "<?php\n" ), 'Outside the allowed layout' ),
	'real configuration with key'  => array( static fn ( ZipArchive $z ) => $z->addFromString( $core . 'config/stealthaudit.php', "<?php return array( 'key_base64' => 'q83vEjRWeJA=' );\n" ), 'real configuration' ),
	'MMDB'                         => array( static fn ( ZipArchive $z ) => $z->addFromString( $core . 'assets/GeoLite2-City.mmdb', 'x' ), 'excluded file type' ),
	'private key'                  => array( static fn ( ZipArchive $z ) => $z->addFromString( $core . 'assets/x.css', "-----BEGIN PRIVATE KEY-----\n" ), 'private key' ),
	'node_modules'                 => array( static fn ( ZipArchive $z ) => $z->addFromString( $core . 'assets/node_modules/x.js', '' ), 'development directory' ),
	'package.json'                 => array( static fn ( ZipArchive $z ) => $z->addFromString( $core . 'package.json', '{}' ), 'Outside the allowed layout' ),
	'unprefixed import in src'     => array( $edit( $core . 'src/Geo/Geolocation.php', static fn ( string $c ): string => str_replace( 'use StealthAuditVendor\GeoIp2\Database\Reader;', 'use GeoIp2\Database\Reader;', $c ) ), 'is not prefixed' ),
	'prefixed WordPress class'     => array( $edit( $core . 'src/Http/Api.php', static fn ( string $c ): string => str_replace( '\WP_Error', '\StealthAuditVendor\WP_Error', $c ) ), 'is prefixed but is not a vendor symbol' ),
	'unprefixed vendor namespace'  => array( $edit( $core . 'vendor/psr/clock/src/ClockInterface.php', static fn ( string $c ): string => str_replace( 'namespace StealthAuditVendor\Psr\Clock;', 'namespace Psr\Clock;', $c ) ), 'Unprefixed namespace' ),
	// The PHP-Scoper miss found in phase 8c: MaxMind\Db\Reader is in its extension stubs.
	'unprefixed import in vendor'  => array( $edit( $core . 'vendor/geoip2/geoip2/src/Database/Reader.php', static fn ( string $c ): string => str_replace( 'use StealthAuditVendor\MaxMind\Db\Reader as DbReader;', 'use MaxMind\Db\Reader as DbReader;', $c ) ), 'is not prefixed' ),
	'shared Composer ClassLoader'  => array( $edit( $core . 'vendor/composer/ClassLoader.php', static fn ( string $c ): string => str_replace( 'namespace StealthAuditVendor\Composer\Autoload;', 'namespace Composer\Autoload;', $c ) ), 'Unprefixed' ),
	'modification notice removed'  => array( $edit( $core . 'vendor/geoip2/geoip2/src/Database/Reader.php', static fn ( string $c ): string => str_replace( STEALTHAUDIT_PACKAGE_NOTICE . "\n", '', $c ) ), 'Modification notice missing' ),
	'Composer runtime in product'  => array( $edit( $core . 'src/Bootstrap/Runtime.php', static fn ( string $c ): string => str_replace( 'use Throwable;', "use Throwable;\nuse Composer\\Autoload\\ClassLoader;", $c ) ), "names Composer's runtime" ),
	'Runtime::VERSION mismatch'    => array( $edit( $core . 'src/Bootstrap/Runtime.php', static fn ( string $c ): string => preg_replace( "/public const VERSION = '[^']+';/", "public const VERSION = '9.9.9';", $c ) ), 'Runtime::VERSION' ),
	'license file removed'         => array( static fn ( ZipArchive $z ) => $z->deleteName( $core . 'vendor/psr/clock/LICENSE' ), 'without its license' ),
	'PHP syntax error'             => array( $edit( $core . 'templates/panel.php', static fn ( string $c ): string => $c . "\n<?php if ( {\n" ), 'php -l failed' ),
);
$dir = $repo . '/build/package-tamper';
if ( ! is_dir( $dir ) ) {
	mkdir( $dir, 0700, true );
}
$caught = 0;
foreach ( $cases as $label => list( $mutate, $expect ) ) {
	$zip_path = $dir . '/' . $name;
	copy( $good, $zip_path );
	$zip = new ZipArchive();
	$zip->open( $zip_path );
	$mutate( $zip );
	$zip->close();
	$zip->open( $zip_path );
	$files = array();
	for ( $i = 0; $i < $zip->numFiles; $i++ ) {
		$entry = (string) $zip->getNameIndex( $i );
		if ( ! str_ends_with( $entry, '/' ) ) {
			$files[] = $entry;
			// Entries added here get default attributes; give them the package's own.
			$zip->setExternalAttributesName( $entry, ZipArchive::OPSYS_UNIX, 0100644 << 16 );
		}
	}
	$zip->close();
	$zip->open( $zip_path );
	sort( $files, SORT_STRING );
	$list = '';
	foreach ( $files as $entry ) {
		$bytes = (string) $zip->getFromName( $entry );
		$list .= hash( 'sha256', $bytes ) . '  ' . strlen( $bytes ) . '  ' . $entry . "\n";
	}
	$zip->close();
	file_put_contents( substr( $zip_path, 0, -4 ) . '.contents.txt', $list );
	file_put_contents( $zip_path . '.sha256', hash_file( 'sha256', $zip_path ) . '  ' . $name . "\n" );
	$output = array();
	exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $repo . '/tools/verify-package.php' ) . ' ' . escapeshellarg( 'build/package-tamper/' . $name ) . ' 2>&1', $output, $status );
	$text = implode( "\n", $output );
	$hit  = 0 !== $status && str_contains( $text, $expect );
	$caught += $hit ? 1 : 0;
	echo ( $hit ? 'rejected' : 'NOT REJECTED' ) . " - {$label}: " . ( preg_match( '/FAIL (.*)/', $text, $m ) ? $m[1] : 'no failure reported' ) . "\n";
}
foreach ( glob( $dir . '/*' ) as $file ) {
	unlink( $file );
}
rmdir( $dir );
echo "{$caught}/" . count( $cases ) . " tampered packages rejected\n";
exit( count( $cases ) === $caught ? 0 : 1 );
