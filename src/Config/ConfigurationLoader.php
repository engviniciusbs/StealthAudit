<?php
/**
 * Configuration source explicitly selected by the operator in wp-config or env.
 *
 * @license GPL-3.0-or-later
 * @package StealthAudit
 */

declare(strict_types=1);

namespace StealthAudit\Config;

use RuntimeException;

/** Read only an operator-selected configuration source. */
final class ConfigurationLoader {
	/**
	 * Load configuration without reading request or WordPress options.
	 *
	 * @throws RuntimeException When the selected source is unavailable or unsafe.
	 */
	public function load(): Configuration {
		if ( defined( 'STEALTHAUDIT_CONFIG' ) ) {
			$values = constant( 'STEALTHAUDIT_CONFIG' );
		} else {
			$path = defined( 'STEALTHAUDIT_CONFIG_FILE' ) ? constant( 'STEALTHAUDIT_CONFIG_FILE' ) : getenv( 'STEALTHAUDIT_CONFIG_FILE' );
			if ( false === $path || '' === $path ) {
				return Configuration::from_array( array() );
			}
			if ( ! is_string( $path ) || ! str_starts_with( $path, '/' ) || ! is_readable( $path ) ) {
				throw new RuntimeException( 'Configuration unavailable.' );
			}
			$resolved = realpath( $path );
			$webroot  = realpath( ABSPATH );
			if ( false === $resolved || false === $webroot || $resolved === $webroot || str_starts_with( $resolved, rtrim( $webroot, '/' ) . '/' ) ) {
				throw new RuntimeException( 'Configuration must be outside the webroot.' );
			}
			$values = ( static function ( string $file ): mixed {
				return require $file;
			} )( $resolved );
		}
		if ( ! is_array( $values ) ) {
			throw new RuntimeException( 'Configuration must return an array.' );
		}
		return Configuration::from_array( $values );
	}
}
