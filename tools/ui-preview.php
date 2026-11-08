<?php
// SPDX-License-Identifier: GPL-3.0-or-later
/**
 * Development only: render every panel state with clearly fake fixtures for Hallmark review.
 *
 * Run inside the test environment:
 *   node tools/wp-env.cjs run cli --env-cwd=wp-content/mu-plugins/stealthaudit wp eval-file tools/ui-preview.php
 * Writes artifacts/ui-preview/*.html (gitignored). No user, option, key, factor or real QR is
 * created; the "QR" in the pending preview is a placeholder SVG and REST calls are answered
 * by a local fetch stub, so captures never contain operator secrets.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

$root = dirname( __DIR__ );
$out  = $root . '/artifacts/ui-preview';
if ( ! is_dir( $out ) && ! mkdir( $out, 0775, true ) ) {
	exit( 1 );
}
foreach ( array( 'tokens.css', 'base.css', 'panel.css', 'panel.js' ) as $file ) {
	copy( $root . '/assets/' . $file, $out . '/' . $file );
}

// Fake REST answers, keyed by the scenario attribute of the stub script tag.
$stub = <<<'JS'
/* Preview fixtures only: synthetic rows, no real site data, no secrets. */
( function () {
	var scenario = document.currentScript.getAttribute( 'data-scenario' );
	var capture = { diagnostics: 'available', events: { state: 'available', dropped: 0 }, errors: { state: 'available', dropped: 0 } };
	var events = [
		{ id: 9, time: '2026-11-07T21:14:03Z', actor_id: 2, category: 'access', action: 'login_failed', result: 'failure', origin: 'http', object_type: 'user', object_id: 2, ip: '192.0.2.44', summary: { reason: 'fixture' } },
		{ id: 8, time: '2026-11-07T21:02:51Z', actor_id: 1, category: 'admin', action: 'activated', result: 'success', origin: 'admin', object_type: 'plugin', object_id: null, ip: '2001:db8::17', summary: { plugin: 'example-fixture/example-fixture.php' } },
		{ id: 7, time: '2026-11-07T20:40:12Z', actor_id: 1, category: 'users', action: 'role_changed', result: 'success', origin: 'admin', object_type: 'user', object_id: 5, ip: '192.0.2.10', summary: { from: 'subscriber', to: 'editor' } },
		{ id: 6, time: '2026-11-07T19:58:30Z', actor_id: null, category: 'content', action: 'updated', result: 'unknown', origin: 'cron', object_type: 'post', object_id: 120, ip: null, summary: {} },
		{ id: 5, time: '2026-11-07T19:12:07Z', actor_id: 3, category: 'access', action: 'mfa_access', result: 'success', origin: 'rest', object_type: 'user', object_id: 3, ip: '198.51.100.23', summary: { note: '<script>alert(1)</script> stays text' } }
	];
	var errors = [];
	var answers = {
		full: [ 200, { items: events, next_cursor: '1762542727-5', limit: 50, timezone: 'UTC', capture: capture } ],
		empty: [ 200, { items: errors, next_cursor: null, limit: 50, timezone: 'UTC', capture: { diagnostics: 'limited_previous_handler', events: { state: 'available', dropped: 0 }, errors: { state: 'limited', dropped: 3 } } } ],
		error: [ 503, { code: 'stealthaudit_unavailable', message: 'Service unavailable.', data: { status: 503 } } ],
		expired: [ 401, { code: 'stealthaudit_proof_required', message: 'Authentication required.', data: { status: 401, panel: 'challenge_required' } } ],
		session: [ 401, { code: 'stealthaudit_unauthenticated', message: 'Authentication required.', data: { status: 401 } } ],
		loading: null
	};
	window.fetch = function () {
		var answer = answers[ scenario ];
		if ( ! answer ) {
			return new Promise( function () {} );
		}
		return Promise.resolve( new Response( JSON.stringify( answer[ 1 ] ), { status: answer[ 0 ], headers: { 'Content-Type': 'application/json' } } ) );
	};
}() );
JS;
file_put_contents( $out . '/fixtures.js', $stub );

$base   = array(
	'panel'      => 'https://example.test/?stealthaudit=panel',
	'rest'       => 'https://example.test/?rest_route=/stealthaudit/v1/',
	'nonce'      => 'preview-nonce',
	'rest_nonce' => 'preview-rest-nonce',
	'login'      => 'https://example.test/wp-login.php?reauth=1',
	'query'      => array(),
	'view'       => 'audit',
	'filters'    => array(),
	'cursor'     => null,
	'invalid'    => false,
	'tabs'       => array(
		'audit'  => 'https://example.test/?stealthaudit=panel&view=audit',
		'errors' => 'https://example.test/?stealthaudit=panel&view=errors',
	),
	'action'     => 'https://example.test/',
	'hidden'     => array( 'stealthaudit' => 'panel' ),
	'assets'     => array(
		'tokens.css' => 'tokens.css',
		'base.css'   => 'base.css',
		'panel.css'  => 'panel.css',
		'panel.js'   => 'panel.js',
	),
	'site'       => 'Fixture site',
	'host'       => 'example.test',
);
$states = array(
	'password'  => array( 'status' => 'password_required' ),
	'enroll'    => array( 'status' => 'enrollment_required' ),
	'pending'   => array( 'status' => 'enrollment_pending' ),
	'challenge' => array(
		'status' => 'challenge_required',
		'query'  => array( 'view' => 'audit' ),
	),
	'locked'    => array( 'status' => 'recovery_locked' ),
	'full'      => array( 'status' => 'authorized' ),
	'loading'   => array( 'status' => 'authorized' ),
	'empty'     => array(
		'status'  => 'authorized',
		'view'    => 'errors',
		'filters' => array( 'severity' => 'fatal' ),
	),
	'error'     => array( 'status' => 'authorized' ),
	'expired'   => array( 'status' => 'authorized' ),
	'session'   => array( 'status' => 'authorized' ),
);
foreach ( $states as $name => $overrides ) {
	ob_start();
	( static function ( string $stealthaudit_template, array $stealthaudit_view ): void {
		require $stealthaudit_template;
	} )( $root . '/templates/panel.php', $overrides + $base );
	$html = (string) ob_get_clean();
	// esc_url() adds a scheme to bare relative names; previews load the copies beside them.
	$html = str_replace( array( 'http://tokens.css', 'http://base.css', 'http://panel.css', 'http://panel.js' ), array( 'tokens.css', 'base.css', 'panel.css', 'panel.js' ), $html );
	$html = str_replace( '<script src="panel.js"', '<script src="fixtures.js" data-scenario="' . $name . '" defer></script>' . "\n" . '<script src="panel.js"', $html );
	if ( 'pending' === $name ) {
		// Placeholder pattern, not a QR code and not derived from any secret.
		$placeholder = rawurlencode( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 8 8"><rect width="8" height="8" fill="#fff"/><path d="M1 1h2v2H1zM5 1h2v2H5zM1 5h2v2H1zM4 4h1v1H4z"/></svg>' );
		$html        = str_replace( '<div class="qr" data-sa-qr hidden></div>', '<div class="qr" data-sa-qr><img src="data:image/svg+xml,' . $placeholder . '" width="256" height="256" alt="Placeholder do QR (fixture, sem segredo)"></div>', $html );
		// As after a successful (re)display: the password form is hidden while a QR is shown.
		$html = str_replace( '<div data-sa-redisplay>', '<div data-sa-redisplay hidden>', $html );
	}
	file_put_contents( $out . '/' . $name . '.html', $html );
}
echo 'Wrote ' . count( $states ) . " previews to artifacts/ui-preview\n";
