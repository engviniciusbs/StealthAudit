<?php
// SPDX-License-Identifier: GPL-3.0-or-later
declare(strict_types=1);

namespace StealthAudit\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use OTPHP\TOTP;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

final class DependencyContractTest extends TestCase {
	public function test_pinned_otphp_accepts_psr_clock_and_rfc_vector(): void {
		$clock = new class implements ClockInterface {
			public function now(): DateTimeImmutable {
				return ( new DateTimeImmutable( '@59' ) )->setTimezone( new DateTimeZone( 'UTC' ) );
			}
		};
		$totp = TOTP::createFromSecret( 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', $clock )->withDigits( 8 );
		self::assertSame( '94287082', $totp->now() );
		self::assertTrue( $totp->verify( '94287082' ) );
	}
}
