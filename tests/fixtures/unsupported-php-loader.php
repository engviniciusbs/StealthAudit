<?php
// SPDX-License-Identifier: GPL-3.0-or-later
// Run with PHP <8.3 to prove the loader never parses the newer core.
define( 'ABSPATH', '/tmp/' );
require dirname( __DIR__, 2 ) . '/loader/stealthaudit.php';
if ( 'php_unsupported' !== STEALTHAUDIT_BOOT_STATUS ) {
	exit( 1 );
}
echo "Unsupported PHP returns safely.\n";
