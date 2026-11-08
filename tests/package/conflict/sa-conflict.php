<?php
/**
 * Plugin Name: StealthAudit coexistence fixture (test only)
 * Description: Another plugin bundling OTHER versions of StealthAudit's libraries, unprefixed, with its own Composer autoloader.
 *
 * Development only (tests/package/run.mjs). Installed as wp-content/mu-plugins/0-sa-conflict.php
 * (loads BEFORE stealthaudit.php) or zz-sa-conflict.php (AFTER), with its vendor tree in
 * wp-content/mu-plugins/sa-conflict/. It eagerly loads every library class, as a plugin using
 * them on each request would, so StealthAudit must work with these versions already declared.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	return;
}
require __DIR__ . '/sa-conflict/vendor/autoload.php';
foreach ( array( 'BaconQrCode\Writer', 'BaconQrCode\Renderer\ImageRenderer', 'BaconQrCode\Renderer\Image\SvgImageBackEnd', 'BaconQrCode\Renderer\RendererStyle\RendererStyle', 'OTPHP\TOTP', 'GeoIp2\Database\Reader', 'MaxMind\Db\Reader', 'ParagonIE\ConstantTime\Base32', 'DASPRiD\Enum\AbstractEnum', 'Composer\CaBundle\CaBundle' ) as $sa_conflict_class ) {
	class_exists( $sa_conflict_class );
}
interface_exists( 'Psr\Clock\ClockInterface' );
define( 'SA_CONFLICT_LOADED', function_exists( 'trigger_deprecation' ) ? 'with-trigger_deprecation' : 'without-trigger_deprecation' );
unset( $sa_conflict_class );
