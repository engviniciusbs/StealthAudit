<?php
// SPDX-License-Identifier: GPL-3.0-or-later
declare(strict_types=1);

use StealthAudit\Bootstrap\Runtime;

final class BootstrapTest extends WP_UnitTestCase {
	public function test_mu_loaded_without_activation_and_default_denies(): void {
		self::assertSame( 'ready', STEALTHAUDIT_BOOT_STATUS );
		self::assertSame( 1, count( array_filter( get_included_files(), static fn( $file ) => str_ends_with( $file, '/mu-plugins/stealthaudit.php' ) ) ) );
		self::assertSame( array(), get_option( 'active_plugins', array() ) );
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user );
		self::assertFalse( Runtime::boot()->configuration->allows_user( $user ) );
		self::assertFalse( Runtime::boot()->panel_configured() );
		self::assertTrue( Runtime::boot()->collection_available() );
	}

	public function test_no_global_login_or_authentication_hooks(): void {
		self::assertFalse( has_filter( 'authenticate', array( Runtime::class, 'boot' ) ) );
		self::assertSame( Runtime::boot(), Runtime::boot() );
	}
}
