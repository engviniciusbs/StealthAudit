<?php
// SPDX-License-Identifier: GPL-3.0-or-later
/**
 * Development only: exercise the INSTALLED package (scoped vendor, regenerated autoloader) inside
 * the clean artifact site of tests/package/run.mjs. Never part of the ZIP.
 *
 *   wp --path=/var/www/html eval-file /opt/stealthaudit-dev/tests/package/smoke.php '{"mmdb":"/tmp/x.mmdb","conflict":"none|before|after"}'
 *
 * Proves: the classes come from wp-content/mu-plugins/stealthaudit (never from the repository
 * mounted for the harness); every library is the StealthAuditVendor\ copy; QR (BaconQrCode),
 * TOTP (OTPHP + the prefixed PSR-20 clock, RFC 6238 vector and an independent implementation),
 * the sodium cipher and GeoIP (GeoIp2 + MaxMind reader, official City test database) work; the
 * scoped trigger_deprecation() is reachable from OTPHP; and, with the coexistence fixture
 * installed, the other plugin's unprefixed, different versions load and work side by side.
 * Prints one JSON object; exits non-zero on the first failed check.
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) ) {
	exit( 1 );
}
$input    = isset( $args[0] ) ? json_decode( $args[0], true, 8, JSON_THROW_ON_ERROR ) : array();
$conflict = (string) ( $input['conflict'] ?? 'none' );
$result   = array( 'conflict' => $conflict );
$check    = static function ( bool $ok, string $what ) {
	if ( ! $ok ) {
		WP_CLI::error( 'package smoke: ' . $what );
	}
};
$package  = realpath( WP_CONTENT_DIR . '/mu-plugins/stealthaudit' );
$other    = realpath( WP_CONTENT_DIR . '/mu-plugins/sa-conflict' );
$inside   = static fn ( string $file, $dir ): bool => false !== $dir && str_starts_with( (string) realpath( $file ), $dir . '/' );
$origin   = static fn ( string $name ): string => (string) ( new ReflectionClass( $name ) )->getFileName();

// ---------- the installed package is what runs ----------
$check( defined( 'STEALTHAUDIT_BOOT_STATUS' ) && 'ready' === STEALTHAUDIT_BOOT_STATUS, 'boot status is ' . ( defined( 'STEALTHAUDIT_BOOT_STATUS' ) ? STEALTHAUDIT_BOOT_STATUS : 'undefined' ) );
$mu                = get_mu_plugins();
$result['version'] = $mu['stealthaudit.php']['Version'] ?? null;
$check( null !== $result['version'], 'loader not listed as must-use' );
foreach ( array( \StealthAudit\Bootstrap\Runtime::class, \StealthAudit\Security\TotpProvider::class, \StealthAudit\Geo\Geolocation::class ) as $class ) {
	$check( $inside( $origin( $class ), $package ), "{$class} not loaded from the installed package" );
}

// ---------- every library is the prefixed copy shipped in the package ----------
$prefixed = array( 'BaconQrCode\Writer', 'OTPHP\TOTP', 'GeoIp2\Database\Reader', 'MaxMind\Db\Reader', 'ParagonIE\ConstantTime\Base32', 'DASPRiD\Enum\AbstractEnum', 'Composer\CaBundle\CaBundle', 'Composer\Autoload\ClassLoader' );
foreach ( $prefixed as $class ) {
	$name = 'StealthAuditVendor\\' . $class;
	$check( class_exists( $name ), "{$name} missing" );
	$check( $inside( $origin( $name ), $package . '/vendor' ), "{$name} not from the package vendor" );
}
$check( interface_exists( 'StealthAuditVendor\Psr\Clock\ClockInterface' ) && $inside( $origin( 'StealthAuditVendor\Psr\Clock\ClockInterface' ), $package . '/vendor' ), 'prefixed PSR-20 clock interface' );
$check( function_exists( 'StealthAuditVendor\trigger_deprecation' ) && $inside( (string) ( new ReflectionFunction( 'StealthAuditVendor\trigger_deprecation' ) )->getFileName(), $package . '/vendor' ), 'scoped trigger_deprecation() not loaded (Composer "files" identifier collision?)' );
$result['prefixed_classes'] = count( $prefixed ) + 1;

// ---------- TOTP: RFC 6238 vector + independent implementation, through the prefixed clock ----------
$base32 = static function ( string $text ): string {
	$bits = '';
	foreach ( str_split( $text ) as $char ) {
		$bits .= str_pad( decbin( strpos( 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567', $char ) ), 5, '0', STR_PAD_LEFT );
	}
	return implode( '', array_map( static fn ( string $byte ): string => chr( bindec( $byte ) ), array_filter( str_split( $bits, 8 ), static fn ( string $b ): bool => 8 === strlen( $b ) ) ) );
};
$hotp   = static function ( string $key, int $step ): string {
	$hash   = hash_hmac( 'sha1', pack( 'J', $step ), $key, true );
	$offset = ord( $hash[19] ) & 0xf;
	return str_pad( (string) ( ( unpack( 'N', substr( $hash, $offset, 4 ) )[1] & 0x7fffffff ) % 1000000 ), 6, '0', STR_PAD_LEFT );
};
$clock  = new class() implements \StealthAuditVendor\Psr\Clock\ClockInterface {
	/** Fixed instant (RFC 6238 test time 59 s is passed explicitly where needed). */
	public function now(): DateTimeImmutable {
		return new DateTimeImmutable( '@1111111109' );
	}
};
$totp   = new \StealthAudit\Security\TotpProvider( $clock );
$check( 1 === $totp->matching_step( 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', '287082', 59 ), 'RFC 6238 SHA-1 vector at T=59 (287082)' );
$secret = $totp->create_secret();
$now    = time();
$check( 1 === preg_match( '/\A[A-Z2-7]{32}\z/', $secret ), 'generated secret is not 160-bit Base32' );
$check( intdiv( $now, 30 ) === $totp->matching_step( $secret, $hotp( $base32( $secret ), intdiv( $now, 30 ) ), $now ), 'independent TOTP code rejected' );
$check( null === $totp->matching_step( $secret, $hotp( $base32( $secret ), intdiv( $now, 30 ) + 5 ), $now ), 'code five steps ahead accepted' );
$result['totp'] = 'RFC 6238 vector + independent HOTP/SHA-1 code accepted, off-window code rejected';
// PSR-20 interop is with the PREFIXED interface only: another plugin's \Psr\Clock\ClockInterface
// is a different type to the package, by design.
if ( interface_exists( 'Psr\Clock\ClockInterface', false ) ) {
	$foreign = new class() implements \Psr\Clock\ClockInterface {
		/** Now. */
		public function now(): DateTimeImmutable {
			return new DateTimeImmutable();
		}
	};
	try {
		new \StealthAudit\Security\TotpProvider( $foreign );
		$check( false, 'an unprefixed PSR clock was accepted by the package' );
	} catch ( TypeError $expected ) {
		$result['foreign_clock'] = 'rejected (TypeError): the package only accepts StealthAuditVendor\Psr\Clock\ClockInterface';
	}
}
// OTPHP's deprecation path calls the scoped trigger_deprecation() (patched to a qualified call):
// with no clock it must raise E_USER_DEPRECATED, never "undefined function".
$deprecations = array();
set_error_handler(
	static function ( int $level, string $message ) use ( &$deprecations ): bool {
		if ( E_USER_DEPRECATED === $level ) {
			$deprecations[] = $message;
			return true;
		}
		return false;
	}
);
try {
	\StealthAuditVendor\OTPHP\TOTP::create( null, 30, 'sha1', 6, 0, null );
} finally {
	restore_error_handler();
}
$check( count( $deprecations ) >= 1 && str_contains( $deprecations[0], 'otphp' ), 'OTPHP deprecation did not reach the scoped trigger_deprecation()' );
$result['trigger_deprecation'] = 'reached StealthAuditVendor\trigger_deprecation()';

// ---------- QR ----------
$svg = $totp->qr( $secret, 'smoke-operator' );
$check( str_contains( $svg, '<svg' ) && str_contains( $svg, '</svg>' ) && ! preg_match( '/\b(?:href|src)=|url\(/i', $svg ) && $svg === $totp->qr( $secret, 'smoke-operator' ), 'QR SVG malformed, external reference or non-deterministic' );
$result['qr'] = strlen( $svg ) . ' byte SVG, no external references (decoded by tests/e2e through the panel)';

// ---------- sodium cipher ----------
$cipher   = new \StealthAudit\Security\SecretCipher( random_bytes( 32 ), 'smoke', 'smoke-installation' );
$envelope = $cipher->encrypt( $secret, 7 );
$check( $secret === $cipher->decrypt( $envelope, 7 ) && null === $cipher->decrypt( $envelope, 8 ) && null === ( new \StealthAudit\Security\SecretCipher( random_bytes( 32 ), 'smoke', 'smoke-installation' ) )->decrypt( $envelope, 7 ), 'cipher round trip / user binding / key binding' );
$check( ! str_contains( $envelope, $secret ), 'envelope holds the plaintext' );
$result['cipher'] = 'XChaCha20-Poly1305 round trip; wrong user and wrong key rejected';

// ---------- GeoIP ----------
$mmdb = (string) ( $input['mmdb'] ?? '' );
$geo  = ( new \StealthAudit\Geo\Geolocation( $mmdb, 3650, ABSPATH, static fn (): int => time() ) )->resolve( array( '81.2.69.160', '2001:218::', '10.0.0.1', 'not-an-ip' ) );
$check( 'available' === $geo['state'] && 'GB' === ( $geo['locations']['81.2.69.160']['country_code'] ?? null ) && 'JP' === ( $geo['locations']['2001:218::']['country_code'] ?? null ) && ! isset( $geo['locations']['10.0.0.1'] ), 'GeoIP lookup through the scoped reader: ' . wp_json_encode( $geo ) );
$result['geoip'] = array(
	'81.2.69.160' => $geo['locations']['81.2.69.160']['country_code'] . '/' . ( $geo['locations']['81.2.69.160']['city'] ?? '-' ),
	'2001:218::'  => $geo['locations']['2001:218::']['country_code'],
);

// ---------- isolation from the other plugin (or from nothing) ----------
$roots   = array( 'BaconQrCode\\', 'OTPHP\\', 'GeoIp2\\', 'MaxMind\\', 'ParagonIE\\', 'Psr\\Clock\\', 'DASPRiD\\', 'Composer\\CaBundle\\' );
$foreign = array();
foreach ( array_merge( get_declared_classes(), get_declared_interfaces(), get_declared_traits() ) as $declared ) {
	foreach ( $roots as $root ) {
		// Skip this script's own anonymous classes (Psr\Clock\ClockInterface@anonymous...).
		if ( str_starts_with( $declared, $root ) && ! str_contains( $declared, '@anonymous' ) ) {
			$foreign[ $declared ] = $origin( $declared );
		}
	}
}
if ( 'none' === $conflict ) {
	$check( array() === $foreign, 'unprefixed library classes were loaded: ' . implode( ', ', array_keys( $foreign ) ) );
	$check( ! function_exists( 'trigger_deprecation' ), 'a global trigger_deprecation() exists without the fixture' );
} else {
	$check( defined( 'SA_CONFLICT_LOADED' ) && false !== $other, 'coexistence fixture not loaded' );
	$check( array() !== $foreign, 'fixture classes not declared' );
	foreach ( $foreign as $declared => $file ) {
		$check( $inside( $file, $other ), "unprefixed {$declared} not from the fixture" );
	}
	// Different versions on each side, read from each side's own records.
	$theirs = array();
	foreach ( json_decode( (string) file_get_contents( $other . '/vendor/composer/installed.json' ), true )['packages'] as $info ) {
		$theirs[ $info['name'] ] = $info['version'];
	}
	preg_match_all( '/^## (\S+) (\S+)$/m', (string) file_get_contents( $package . '/THIRD-PARTY-NOTICES.md' ), $ours, PREG_SET_ORDER );
	$versions = array();
	foreach ( $ours as $match ) {
		if ( isset( $theirs[ $match[1] ] ) ) {
			$versions[ $match[1] ] = $match[2] . ( $match[2] === $theirs[ $match[1] ] ? ' (same version, separate copy)' : ' vs ' . $theirs[ $match[1] ] );
		}
	}
	$result['versions'] = $versions;
	$check( count( array_filter( $versions, static fn ( string $v ): bool => str_contains( $v, ' vs ' ) ) ) >= 5, 'fixture does not bundle different versions' );
	// Both copies work in the same process.
	$their_qr = ( new \BaconQrCode\Writer( new \BaconQrCode\Renderer\ImageRenderer( new \BaconQrCode\Renderer\RendererStyle\RendererStyle( 200, 4 ), new \BaconQrCode\Renderer\Image\SvgImageBackEnd() ) ) )->writeString( 'coexistence' );
	$their_otp = \OTPHP\TOTP::createFromSecret( 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ' )->at( 59 );
	$their_geo = ( new \GeoIp2\Database\Reader( $mmdb ) )->city( '81.2.69.160' )->country->isoCode;
	$check( str_contains( $their_qr, '<svg' ) && '287082' === $their_otp && 'GB' === $their_geo, 'the fixture\'s own libraries failed next to the package' );
	$check( 'available' === ( new \StealthAudit\Geo\Geolocation( $mmdb, 3650, ABSPATH, static fn (): int => time() ) )->resolve( array( '81.2.69.160' ) )['state'] && 1 === $totp->matching_step( 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', '287082', 59 ), 'package failed after the fixture used its libraries' );
	$result['fixture_libraries'] = 'unprefixed copies from mu-plugins/sa-conflict; QR, TOTP and GeoIP work on both sides in one process';
	$result['fixture_classes']   = count( $foreign );
}
// Composer: our loader is the prefixed class; another \Composer\Autoload\ClassLoader (WP-CLI's
// own phar, the fixture's) never comes from the package.
if ( class_exists( 'Composer\Autoload\ClassLoader', false ) ) {
	$check( ! $inside( $origin( 'Composer\Autoload\ClassLoader' ), $package ), 'global Composer ClassLoader comes from the package' );
	$result['global_composer_loader'] = str_starts_with( $origin( 'Composer\Autoload\ClassLoader' ), 'phar://' ) ? 'WP-CLI phar' : 'other plugin';
}

// ---------- nothing from the repository or another vendor tree ----------
$harness = '/opt/stealthaudit-dev/';
foreach ( get_included_files() as $file ) {
	if ( str_starts_with( $file, $harness ) ) {
		$check( __FILE__ === $file, "repository file included: {$file}" );
	} elseif ( str_contains( $file, '/vendor/' ) && ! str_starts_with( $file, 'phar://' ) ) {
		$check( $inside( $file, $package . '/vendor' ) || $inside( $file, $other ), "foreign vendor file included: {$file}" );
	}
}
$result['included_files'] = count( get_included_files() );
echo wp_json_encode( $result, JSON_UNESCAPED_SLASHES ) . "\n";
