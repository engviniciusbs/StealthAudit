<?php
// SPDX-License-Identifier: GPL-3.0-or-later
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Error-handler behavior is exercised in disposable PHP processes, never PHPUnit itself. */
final class DiagnosticsCollectorTest extends TestCase {
	private function child( string $body ): array {
		$autoload = var_export( dirname( __DIR__, 2 ) . '/vendor/autoload.php', true );
		$script = 'require ' . $autoload . '; error_reporting(E_ALL); ' . $body;
		exec( escapeshellarg( PHP_BINARY ) . ' -d display_errors=0 -d log_errors=0 -r ' . escapeshellarg( $script ) . ' 2>&1', $output, $status );
		return array( $status, implode( "\n", $output ) );
	}

	/** @dataProvider returns */
	public function test_previous_return_and_suppression_are_preserved( string $return ): void {
		[$exit, $output] = $this->child(
			'$calls=0; set_error_handler(function()use(&$calls){++$calls;return ' . $return . ';});'
			. '$c=new StealthAudit\\Diagnostics\\Collector(new StealthAudit\\Diagnostics\\Normalizer("/srv/"),function(){throw new RuntimeException("unavailable");}); $c->register(); $c->register();'
			. '$value=$c->handle(E_USER_WARNING,"synthetic warning","/srv/plugin.php",7);'
			. '@trigger_error("suppressed diagnostic",E_USER_WARNING);'
			. 'echo json_encode([$value,$calls,$c->health()]);'
		);
		self::assertSame( 0, $exit, $output );
		$decoded = json_decode( $output, true, 512, JSON_THROW_ON_ERROR );
		self::assertSame( json_decode( $return ), $decoded[0] );
		self::assertSame( 2, $decoded[1] );
		self::assertSame( 1, $decoded[2]['buffered'] );
	}

	public static function returns(): array {
		return array( array( 'true' ), array( 'false' ), array( 'null' ) );
	}

	public function test_previous_throw_propagates_and_buffer_is_bounded(): void {
		[$exit,$output] = $this->child(
			'set_error_handler(function(){throw new LogicException("unchanged");});'
			. '$c=new StealthAudit\\Diagnostics\\Collector(new StealthAudit\\Diagnostics\\Normalizer("/srv/"),function(){throw new RuntimeException("unavailable");},2);$c->register();'
			. '$caught=[]; for($i=0;$i<7;++$i){try{$c->handle(E_USER_WARNING,"synthetic","/srv/plugin.php",1);}catch(LogicException $e){$caught[]=$e->getMessage();}}'
			. '$before=$c->health();$c->flush();echo json_encode([$caught,$before,$c->health()]);'
		);
		self::assertSame( 0, $exit, $output );
		$data = json_decode( $output, true, 512, JSON_THROW_ON_ERROR );
		self::assertSame( array_fill( 0, 7, 'unchanged' ), $data[0] );
		self::assertSame( array( 'status' => 'limited', 'dropped' => 5, 'buffered' => 2 ), $data[1] );
		self::assertSame( 'storage_unavailable', $data[2]['status'] );
	}

	public function test_previous_handler_mask_is_not_broadened(): void {
		[$exit,$output] = $this->child(
			'set_error_handler(function(){throw new LogicException("notice only");},E_USER_NOTICE);'
			. '$c=new StealthAudit\\Diagnostics\\Collector(new StealthAudit\\Diagnostics\\Normalizer("/srv/"),function(){throw new RuntimeException("unavailable");});$c->register();'
			. 'trigger_error("warning keeps default",E_USER_WARNING);try{trigger_error("notice",E_USER_NOTICE);}catch(LogicException $e){echo $e->getMessage();}echo json_encode($c->health());'
		);
		self::assertSame( 0, $exit, $output );
		self::assertStringStartsWith( 'notice only', $output );
		self::assertStringContainsString( 'limited_previous_handler', $output );
		self::assertStringContainsString( '"buffered":0', $output );
	}

	public function test_unhandled_warning_uses_default_and_fatal_only_ends_child(): void {
		[$exit,$output] = $this->child(
			'$c=new StealthAudit\\Diagnostics\\Collector(new StealthAudit\\Diagnostics\\Normalizer("/srv/"),function(){throw new RuntimeException("unavailable");});$c->register();'
			. 'register_shutdown_function(function()use($c){echo json_encode($c->health());});'
			. 'trigger_error("synthetic warning",E_USER_WARNING);trigger_error("synthetic fatal",E_USER_ERROR);echo "unreachable";'
		);
		self::assertNotSame( 0, $exit );
		self::assertStringNotContainsString( 'unreachable', $output );
		self::assertSame( 'storage_unavailable', json_decode( $output, true, 512, JSON_THROW_ON_ERROR )['status'] );
	}

	public function test_active_capture_respects_suppression_and_reporting_mask(): void {
		[$exit,$output] = $this->child(
			'$mask=error_reporting();$c=new StealthAudit\\Diagnostics\\Collector(new StealthAudit\\Diagnostics\\Normalizer("/srv/"),function(){throw new RuntimeException("unavailable");});$c->register();'
			. '@trigger_error("suppressed",E_USER_WARNING);trigger_error("visible",E_USER_NOTICE);trigger_error("deprecated",E_USER_DEPRECATED);'
			. 'echo json_encode([$c->health(),error_reporting()===$mask]);'
		);
		self::assertSame( 0, $exit, $output );
		$data = json_decode( $output, true, 512, JSON_THROW_ON_ERROR );
		self::assertSame( 2, $data[0]['buffered'] );
		self::assertTrue( $data[1] );
	}

	public function test_collector_storage_warning_cannot_reenter_capture(): void {
		[$exit,$output] = $this->child(
			'$calls=0;$c=new StealthAudit\\Diagnostics\\Collector(new StealthAudit\\Diagnostics\\Normalizer("/srv/"),function()use(&$calls){++$calls;trigger_error("storage warning",E_USER_WARNING);throw new RuntimeException("unavailable");});$c->register();$c->flush();'
			. 'for($i=0;$i<25;++$i){trigger_error("application warning",E_USER_WARNING);} $c->flush();echo json_encode([$calls,$c->health()]);'
		);
		self::assertSame( 0, $exit, $output );
		$data = json_decode( $output, true, 512, JSON_THROW_ON_ERROR );
		self::assertSame( 1, $data[0] );
		self::assertSame( 'storage_unavailable', $data[1]['status'] );
		self::assertSame( 0, $data[1]['buffered'] );
		self::assertGreaterThan( 0, $data[1]['dropped'] );
	}
}
