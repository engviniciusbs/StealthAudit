<?php
// SPDX-License-Identifier: GPL-3.0-or-later
declare(strict_types=1);

namespace StealthAudit\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class LoaderTest extends TestCase {
	public function test_runtime_version_matches_the_loader_header_and_package_json(): void {
		$root = dirname( __DIR__, 2 );
		self::assertMatchesRegularExpression( '/^ \* Version: ' . preg_quote( \StealthAudit\Bootstrap\Runtime::VERSION, '/' ) . '$/m', (string) file_get_contents( $root . '/loader/stealthaudit.php' ) );
		self::assertSame( \StealthAudit\Bootstrap\Runtime::VERSION, json_decode( (string) file_get_contents( $root . '/package.json' ), true )['version'] );
	}

	public function test_direct_include_is_silent(): void {
		$loader = dirname( __DIR__, 2 ) . '/loader/stealthaudit.php';
		exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $loader ) . ' 2>&1', $output, $status );
		self::assertSame( 0, $status );
		self::assertSame( array(), $output );
	}

	public function test_missing_vendor_returns_safely_and_duplicate_load_is_idempotent(): void {
		$loader = dirname( __DIR__, 2 ) . '/loader/stealthaudit.php';
		$script = "define('ABSPATH', '/tmp/'); require " . var_export( $loader, true ) . '; require ' . var_export( $loader, true ) . '; echo STEALTHAUDIT_BOOT_STATUS;';
		exec( escapeshellarg( PHP_BINARY ) . ' -r ' . escapeshellarg( $script ) . ' 2>&1', $output, $status );
		self::assertSame( 0, $status );
		self::assertSame( array( 'vendor_missing' ), $output );
	}

	/** @dataProvider broken_installation */
	public function test_partial_installation_is_silent_and_restores_handler( ?string $bootstrap, string $expected ): void {
		$directory = sys_get_temp_dir() . '/stealthaudit-loader-' . bin2hex( random_bytes( 8 ) );
		mkdir( $directory . '/stealthaudit/vendor', 0700, true );
		// Read the bytes rather than copy_file_range across a Docker Desktop bind
		// and tmpfs: that optimization can append NUL bytes after a host edit.
		file_put_contents( $directory . '/stealthaudit.php', file_get_contents( dirname( __DIR__, 2 ) . '/loader/stealthaudit.php' ) );
		file_put_contents( $directory . '/stealthaudit/vendor/autoload.php', '<?php' );
		if ( null !== $bootstrap ) {
			file_put_contents( $directory . '/stealthaudit/bootstrap.php', $bootstrap );
		}
		$script = "define('ABSPATH', '/tmp/'); set_error_handler(static function(){echo 'restored';}); require " . var_export( $directory . '/stealthaudit.php', true ) . "; echo STEALTHAUDIT_BOOT_STATUS; trigger_error('test');";
		try {
			exec( escapeshellarg( PHP_BINARY ) . ' -d display_errors=1 -r ' . escapeshellarg( $script ) . ' 2>&1', $output, $status );
			self::assertSame( 0, $status, implode( "\n", $output ) );
			self::assertSame( array( $expected . 'restored' ), $output );
		} finally {
			if ( null !== $bootstrap ) {
				unlink( $directory . '/stealthaudit/bootstrap.php' );
			}
			unlink( $directory . '/stealthaudit/vendor/autoload.php' );
			unlink( $directory . '/stealthaudit.php' );
			rmdir( $directory . '/stealthaudit/vendor' );
			rmdir( $directory . '/stealthaudit' );
			rmdir( $directory );
		}
	}

	public static function broken_installation(): array {
		return array(
			array( null, 'bootstrap_missing' ),
			array( "<?php trigger_error('private-path-and-key');", 'bootstrap_error' ),
			array( "<?php throw new RuntimeException('private-secret');", 'bootstrap_error' ),
		);
	}
}
