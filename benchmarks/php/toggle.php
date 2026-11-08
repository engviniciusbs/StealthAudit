<?php
// SPDX-License-Identifier: GPL-3.0-or-later
/**
 * Benchmark environment only (benchmarks/run.mjs copies it to mu-plugins/0-stealthaudit-bench.php
 * in the disposable tag=bench wp-env). Never shipped and never installed anywhere else.
 *
 * It loads before mu-plugins/stealthaudit.php (alphabetical order) and selects, per request:
 *   mode "off": defines STEALTHAUDIT_BOOT_STATUS, so the real loader returns at its first check
 *               (the baseline "without loader"; the same file is still read in both modes);
 *   mode "on":  defines STEALTHAUDIT_CONFIG and boots a copy of the plugin that lives in the
 *               container's own disk (/opt/stealthaudit-bench, outside the webroot), not in the Windows 9p
 *               bind mount, so latency of the host share is not measured as plugin cost.
 *
 * The configuration is JSON (read on every request, so a mode switch is immediate, unlike an
 * opcache-cached PHP file), outside the webroot.
 * STEALTHAUDIT_BENCH_PROFILE=loop (WP-CLI only) merges the "loop" limits, which let one CLI
 * process repeat an action thousands of times; HTTP requests always use "limits".
 *
 * @package StealthAudit
 */

if ( ! defined( 'ABSPATH' ) ) {
	return;
}
$stealthaudit_bench_dir    = '/opt/stealthaudit-bench';
$stealthaudit_bench_config = is_readable( $stealthaudit_bench_dir . '/config.json' ) ? json_decode( (string) file_get_contents( $stealthaudit_bench_dir . '/config.json' ), true ) : null;
if ( ! is_array( $stealthaudit_bench_config ) || 'on' !== ( $stealthaudit_bench_config['mode'] ?? 'off' ) ) {
	define( 'STEALTHAUDIT_BOOT_STATUS', 'benchmark_off' );
	unset( $stealthaudit_bench_dir, $stealthaudit_bench_config );
	return;
}
$stealthaudit_bench_values = $stealthaudit_bench_config['config'];
if ( 'loop' === getenv( 'STEALTHAUDIT_BENCH_PROFILE' ) ) {
	$stealthaudit_bench_values['limits'] = array_replace( $stealthaudit_bench_values['limits'], $stealthaudit_bench_config['loop'] );
}
define( 'STEALTHAUDIT_CONFIG', $stealthaudit_bench_values );
require $stealthaudit_bench_dir . '/stealthaudit.php';
unset( $stealthaudit_bench_dir, $stealthaudit_bench_config, $stealthaudit_bench_values );
