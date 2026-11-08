/*
 * StealthAudit panel: small local script, no build step, no third-party code.
 * Secrets (password, TOTP, recovery codes, QR) live only in memory and the DOM of this page:
 * never in the URL, localStorage, sessionStorage, cookies or console output.
 * Log text is always inserted with textContent, never as HTML.
 */
( function () {
	'use strict';

	var root = document.querySelector( '[data-sa-root]' );
	if ( ! root ) {
		return;
	}
	document.documentElement.classList.add( 'js' );

	var i18n = { messages: {}, labels: {} };
	try {
		i18n = JSON.parse( document.getElementById( 'stealthaudit-i18n' ).textContent );
	} catch ( failure ) {
		// Fallback keeps the page usable with technical codes.
	}
	var M = i18n.messages || {};
	var L = i18n.labels || {};
	var cfg = {
		rest: root.getAttribute( 'data-rest' ) || '',
		nonce: root.getAttribute( 'data-nonce' ) || '',
		restNonce: root.getAttribute( 'data-rest-nonce' ) || '',
		status: root.getAttribute( 'data-status' ) || '',
		view: 'errors' === root.getAttribute( 'data-view' ) ? 'errors' : 'audit'
	};
	var live = root.querySelector( '[data-sa-live]' );

	function text( key, fallback ) {
		return 'string' === typeof M[ key ] ? M[ key ] : ( fallback || key );
	}

	function count( key, value ) {
		return text( key ).replace( '%d', String( value ) );
	}

	function label( group, code ) {
		var map = L[ group ] || {};
		return 'string' === typeof map[ code ] ? map[ code ] : String( code );
	}

	function announce( message ) {
		if ( ! live ) {
			return;
		}
		live.textContent = '';
		window.setTimeout( function () {
			live.textContent = message;
		}, 40 );
	}

	function el( tag, className, content ) {
		var node = document.createElement( tag );
		if ( className ) {
			node.className = className;
		}
		if ( null !== content && undefined !== content ) {
			node.textContent = String( content );
		}
		return node;
	}

	/* Transport: same-origin JSON with both nonces; neither is a second factor. */
	function endpoint( route, params ) {
		var url = new URL( cfg.rest + route, window.location.href );
		Object.keys( params || {} ).forEach( function ( key ) {
			var value = params[ key ];
			if ( 'string' === typeof value && '' !== value ) {
				url.searchParams.set( key, value );
			}
		} );
		return url.toString();
	}

	function call( method, route, body, params ) {
		var headers = {
			Accept: 'application/json',
			'X-WP-Nonce': cfg.restNonce,
			'X-StealthAudit-Nonce': cfg.nonce
		};
		var init = { method: method, headers: headers, credentials: 'same-origin', cache: 'no-store', redirect: 'error', referrerPolicy: 'no-referrer' };
		if ( 'POST' === method ) {
			headers[ 'Content-Type' ] = 'application/json';
			init.body = JSON.stringify( body || {} );
		}
		return window.fetch( endpoint( route, params ), init ).then( function ( response ) {
			return response.json().catch( function () {
				return null;
			} ).then( function ( data ) {
				return classify( response.status, data );
			} );
		}, function () {
			return { kind: 'network' };
		} );
	}

	function classify( status, data ) {
		var code = data && 'string' === typeof data.code ? data.code : '';
		if ( status >= 200 && status < 300 ) {
			return { kind: 'ok', data: data || {} };
		}
		if ( 401 === status && 'stealthaudit_proof_required' === code ) {
			return { kind: 'proof', panel: data && data.data ? data.data.panel : '' };
		}
		if ( 401 === status ) {
			return { kind: 'session' };
		}
		if ( 429 === status ) {
			return { kind: 'try_later' };
		}
		if ( 503 === status ) {
			return { kind: 'unavailable' };
		}
		if ( 'rest_cookie_invalid_nonce' === code ) {
			return { kind: 'stale' };
		}
		if ( 400 === status || 413 === status ) {
			return { kind: 'invalid' };
		}
		return { kind: 'denied' };
	}

	function reloadPanel() {
		window.location.reload();
	}

	/* Screens */
	function show( name, variant ) {
		var target = null;
		root.querySelectorAll( '[data-sa-screen]' ).forEach( function ( screen ) {
			var match = screen.getAttribute( 'data-sa-screen' ) === name;
			screen.hidden = ! match;
			if ( match ) {
				target = screen;
			}
		} );
		if ( ! target ) {
			return;
		}
		target.querySelectorAll( '[data-sa-variant]' ).forEach( function ( part ) {
			part.hidden = part.getAttribute( 'data-sa-variant' ) !== ( variant || 'first' );
		} );
		var heading = target.querySelector( 'h1' );
		if ( heading && 'expired' === variant ) {
			heading.textContent = text( 'expired_heading' );
		}
		if ( heading ) {
			heading.focus();
		}
	}

	/* Leaving the workspace drops every record from the DOM: nothing stays cached on screen. */
	function leaveWorkspace() {
		var rows = root.querySelector( '[data-sa-rows]' );
		if ( rows ) {
			rows.replaceChildren();
		}
		[ '[data-sa-capture]', '[data-sa-pager]', '[data-sa-table]', '[data-sa-message]' ].forEach( function ( selector ) {
			var node = root.querySelector( selector );
			if ( node ) {
				node.hidden = true;
			}
		} );
		document.querySelectorAll( '[data-sa-chrome]' ).forEach( function ( node ) {
			node.hidden = true;
		} );
	}

	/* Forms */
	function helpFor( input ) {
		var id = input.id ? input.id + '-help' : '';
		return id ? document.getElementById( id ) : null;
	}

	function setInvalid( input, message ) {
		var help = helpFor( input );
		input.setAttribute( 'aria-invalid', 'true' );
		if ( help ) {
			help.textContent = message;
			help.setAttribute( 'data-tone', 'error' );
		}
	}

	function clearState( form ) {
		form.querySelectorAll( '[aria-invalid]' ).forEach( function ( input ) {
			input.removeAttribute( 'aria-invalid' );
		} );
		form.querySelectorAll( '[data-sa-help], [data-sa-form-status]' ).forEach( function ( help ) {
			help.textContent = '';
			help.removeAttribute( 'data-tone' );
		} );
	}

	function formStatus( form, message, tone ) {
		var status = form.querySelector( '[data-sa-form-status]' );
		if ( status ) {
			status.textContent = message;
			status.setAttribute( 'data-tone', tone || 'error' );
		} else {
			announce( message );
		}
	}

	function busy( form, on ) {
		var button = form.querySelector( 'button[type="submit"]' );
		var fieldset = form.querySelector( 'fieldset' );
		if ( button ) {
			if ( on ) {
				button.style.minWidth = button.offsetWidth + 'px';
				button.setAttribute( 'data-label', button.textContent );
				button.textContent = text( 'busy' );
				button.setAttribute( 'aria-busy', 'true' );
			} else {
				button.textContent = button.getAttribute( 'data-label' ) || button.textContent;
				button.removeAttribute( 'aria-busy' );
				button.style.minWidth = '';
			}
		}
		if ( fieldset ) {
			fieldset.disabled = on;
		}
	}

	function readFields( form ) {
		var body = {};
		var first = null;
		form.querySelectorAll( 'input[name]' ).forEach( function ( input ) {
			var value = input.value;
			var problem = '';
			if ( 'checkbox' === input.type ) {
				return;
			}
			if ( 'code' === input.name ) {
				value = value.replace( /\s+/g, '' );
				problem = /^[0-9]{6}$/.test( value ) ? '' : text( 'code_invalid' );
			} else if ( 'recovery_code' === input.name ) {
				value = value.replace( /[\s-]+/g, '' ).toLowerCase();
				problem = /^[a-f0-9]{32}$/.test( value ) ? '' : text( 'recovery_invalid' );
			} else if ( 'password' === input.name && '' === value ) {
				problem = text( 'password_empty' );
			}
			if ( problem ) {
				setInvalid( input, problem );
				first = first || input;
			}
			body[ input.name ] = value;
		} );
		return { body: body, first: first };
	}

	function wipeSecrets( form ) {
		form.querySelectorAll( 'input[type="password"], input[name="code"], input[name="recovery_code"]' ).forEach( function ( input ) {
			input.value = '';
		} );
	}

	function showQr( svg ) {
		var box = root.querySelector( '[data-sa-qr]' );
		if ( ! box || 'string' !== typeof svg || -1 === svg.indexOf( '<svg' ) ) {
			return false;
		}
		/* An SVG loaded as an image cannot run script or fetch anything. */
		var image = new window.Image();
		image.alt = text( 'qr_alt' );
		image.width = 256;
		image.height = 256;
		image.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent( svg );
		box.replaceChildren( image );
		box.hidden = false;
		return true;
	}

	function showCodes( codes ) {
		var list = root.querySelector( '[data-sa-codes]' );
		if ( ! list || ! Array.isArray( codes ) ) {
			return false;
		}
		list.replaceChildren();
		codes.forEach( function ( code ) {
			var item = el( 'li' );
			item.appendChild( el( 'code', '', code ) );
			list.appendChild( item );
		} );
		return true;
	}

	function failure( form, result, action ) {
		if ( 'session' === result.kind ) {
			leaveWorkspace();
			show( 'session' );
			return;
		}
		if ( 'enrollment/begin' === action && 'denied' === result.kind ) {
			formStatus( form, text( 'qr_failed' ) );
			return;
		}
		formStatus( form, text( result.kind in { try_later: 1, unavailable: 1, network: 1, stale: 1 } ? result.kind : 'denied' ) );
	}

	function submit( event ) {
		var form = event.currentTarget;
		var action = form.getAttribute( 'data-sa-action' );
		event.preventDefault();
		clearState( form );
		if ( 'codes-ack' === action ) {
			var box = form.querySelector( 'input[type="checkbox"]' );
			if ( box && ! box.checked ) {
				setInvalid( box, text( 'ack_required' ) );
				box.focus();
				return;
			}
			reloadPanel();
			return;
		}
		var fields = readFields( form );
		if ( fields.first ) {
			fields.first.focus();
			return;
		}
		busy( form, true );
		call( 'POST', action, fields.body ).then( function ( result ) {
			wipeSecrets( form );
			busy( form, false );
			if ( 'ok' !== result.kind ) {
				failure( form, result, action );
				return;
			}
			var data = result.data;
			if ( 'enrollment/begin' === action || 'recover' === action || 'replace-factor' === action ) {
				if ( ! showQr( data.qr ) ) {
					formStatus( form, text( 'qr_failed' ) );
					return;
				}
				leaveWorkspace();
				show( 'setup' );
				announce( text( 'qr_ready' ) );
				return;
			}
			if ( 'enrollment/confirm' === action ) {
				if ( showCodes( data.recovery_codes ) ) {
					show( 'codes' );
					announce( text( 'codes_ready' ) );
				} else {
					reloadPanel();
				}
				return;
			}
			if ( 'revoke' === action ) {
				announce( text( 'signed_out' ) );
			}
			reloadPanel();
		} );
	}

	document.querySelectorAll( 'form[data-sa-action]' ).forEach( function ( form ) {
		form.addEventListener( 'submit', submit );
	} );
	document.querySelectorAll( 'fieldset[data-sa-js]' ).forEach( function ( fieldset ) {
		fieldset.disabled = false;
	} );

	/* Filters: blank fields are left out of the URL; the server validates everything again. */
	var filters = root.querySelector( '[data-sa-filters]' );
	if ( filters ) {
		filters.addEventListener( 'submit', function () {
			filters.querySelectorAll( 'input[name], select[name]' ).forEach( function ( field ) {
				if ( '' === field.value || ( 'limit' === field.name && '50' === field.value ) ) {
					field.disabled = true;
				}
			} );
			window.setTimeout( function () {
				filters.querySelectorAll( '[name]' ).forEach( function ( field ) {
					field.disabled = false;
				} );
			}, 0 );
		} );
	}

	/* Records: loading, empty, error and full; capture state sits beside the list. */
	var records = root.querySelector( '[data-sa-records]' );
	if ( 'authorized' !== cfg.status || ! records ) {
		return;
	}
	var table = records.querySelector( '[data-sa-table]' );
	var rows = records.querySelector( '[data-sa-rows]' );
	var message = records.querySelector( '[data-sa-message]' );
	var capture = root.querySelector( '[data-sa-capture]' );
	var pager = root.querySelector( '[data-sa-pager]' );
	var columns = table ? table.querySelectorAll( 'thead th' ).length : 1;
	var base = pager ? pager.querySelector( '[data-sa-first]' ).getAttribute( 'href' ) : window.location.href;

	function query() {
		var params = {};
		try {
			params = JSON.parse( root.getAttribute( 'data-filters' ) || '{}' ) || {};
		} catch ( failure ) {
			params = {};
		}
		var cursor = root.getAttribute( 'data-cursor' );
		if ( cursor ) {
			params.cursor = cursor;
		}
		return params;
	}

	function pageUrl( cursor ) {
		var url = new URL( base, window.location.href );
		var params = query();
		delete params.cursor;
		Object.keys( params ).forEach( function ( key ) {
			url.searchParams.set( key, params[ key ] );
		} );
		if ( cursor ) {
			url.searchParams.set( 'cursor', cursor );
		}
		return url.toString();
	}

	function setState( state ) {
		records.setAttribute( 'data-state', state );
		records.setAttribute( 'aria-busy', 'loading' === state ? 'true' : 'false' );
		table.hidden = ! ( 'loading' === state || 'full' === state );
		message.hidden = ! ( 'empty' === state || 'error' === state );
		if ( 'full' !== state ) {
			pager.hidden = true;
		}
	}

	function loading() {
		setState( 'loading' );
		rows.replaceChildren();
		for ( var r = 0; r < 5; r++ ) {
			var tr = el( 'tr' );
			tr.setAttribute( 'aria-hidden', 'true' );
			for ( var c = 0; c < columns; c++ ) {
				var td = el( 'td' );
				td.appendChild( el( 'span', 'skel' ) );
				tr.appendChild( td );
			}
			rows.appendChild( tr );
		}
		announce( text( 'loading' ) );
	}

	function say( title, body, extra, actions ) {
		message.querySelector( '[data-sa-message-title]' ).textContent = title;
		message.querySelector( '[data-sa-message-text]' ).textContent = body;
		var more = message.querySelector( '[data-sa-message-extra]' );
		more.textContent = extra || '';
		more.hidden = ! extra;
		var slot = message.querySelector( '[data-sa-message-actions]' );
		slot.replaceChildren();
		( actions || [] ).forEach( function ( node ) {
			slot.appendChild( node );
		} );
	}

	function clearLink() {
		var link = el( 'a', 'btn', text( 'clear' ) );
		link.href = base;
		return link;
	}

	function retryButton() {
		var button = el( 'button', 'btn btn--primary', text( 'retry' ) );
		button.type = 'button';
		button.addEventListener( 'click', load );
		return button;
	}

	/* "limited" is cumulative until maintenance, so it is worded as recorded losses, not a live state. */
	function captureLine( data ) {
		var state = data && data.capture ? data.capture : {};
		var kind = 'errors' === cfg.view ? 'errors' : 'events';
		var own = state[ kind ] || { state: 'unavailable', dropped: null };
		var complete = 'available' === own.state;
		capture.replaceChildren();
		var line = el( 'p' );
		line.appendChild( el( 'strong', '', text( 'errors' === kind ? 'capture_errors' : 'capture_events' ) ) );
		var detail = ' ' + label( 'capture', own.state );
		if ( 'number' === typeof own.dropped && own.dropped > 0 ) {
			detail += ' (' + count( 'dropped', own.dropped ) + ')';
		}
		line.appendChild( document.createTextNode( detail ) );
		capture.appendChild( line );
		if ( 'errors' === kind ) {
			var collector = el( 'p' );
			collector.appendChild( el( 'strong', '', text( 'collector' ) ) );
			collector.appendChild( document.createTextNode( ' ' + label( 'diagnostics', state.diagnostics || 'disabled' ) ) );
			capture.appendChild( collector );
			complete = complete && 'available' === state.diagnostics;
		}
		capture.hidden = false;
		return complete;
	}

	function cell( tr, value, className ) {
		var td = el( 'td', className || '', value );
		tr.appendChild( td );
		return td;
	}

	function tagCell( tr, word, strong, ruled ) {
		var td = el( 'td', 'nowrap' );
		td.appendChild( el( 'span', 'tag' + ( strong ? ' tag--strong' : ( ruled ? ' tag--rule' : '' ) ), word ) );
		tr.appendChild( td );
	}

	function summary( value ) {
		if ( ! value || 'object' !== typeof value ) {
			return '';
		}
		return Object.keys( value ).map( function ( key ) {
			var item = value[ key ];
			return key + ': ' + ( 'object' === typeof item ? JSON.stringify( item ) : String( item ) );
		} ).join( '; ' );
	}

	function eventRow( item ) {
		var tr = el( 'tr' );
		cell( tr, item.time, 'nowrap' );
		tagCell( tr, label( 'result', item.result ), 'failure' === item.result, 'unknown' === item.result );
		cell( tr, item.action, 'nowrap' );
		cell( tr, label( 'category', item.category ), 'nowrap' );
		if ( null === item.actor_id ) {
			cell( tr, text( 'none' ), 'nowrap muted' );
		} else {
			cell( tr, count( 'user_prefix', item.actor_id ), 'nowrap' );
		}
		cell( tr, item.object_type + ( null === item.object_id ? '' : ' #' + item.object_id ), 'nowrap' );
		cell( tr, null === item.ip ? text( 'none' ) : item.ip, null === item.ip ? 'nowrap muted' : 'nowrap' );
		cell( tr, label( 'origin', item.origin ), 'nowrap' );
		cell( tr, summary( item.summary ), 'wrap' );
		return tr;
	}

	function errorRow( item ) {
		var tr = el( 'tr' );
		cell( tr, item.first_seen, 'nowrap' );
		tagCell( tr, label( 'severity', item.severity ), 'fatal' === item.severity || 'error' === item.severity, 'warning' === item.severity );
		cell( tr, item.message, 'wrap' );
		cell( tr, item.file + ':' + item.line, 'wrap' );
		cell( tr, count( 'occurrences', item.occurrences ), 'nowrap' );
		cell( tr, item.last_seen, 'nowrap' );
		return tr;
	}

	function full( data ) {
		var complete = captureLine( data );
		var items = Array.isArray( data.items ) ? data.items : [];
		rows.replaceChildren();
		if ( 0 === items.length ) {
			setState( 'empty' );
			say( text( 'empty_title' ), text( 'empty_text' ), complete ? '' : text( 'empty_partial' ), [ clearLink() ] );
			announce( text( 'empty_title' ) );
			return;
		}
		items.forEach( function ( item ) {
			rows.appendChild( 'errors' === cfg.view ? errorRow( item ) : eventRow( item ) );
		} );
		setState( 'full' );
		var first = pager.querySelector( '[data-sa-first]' );
		var next = pager.querySelector( '[data-sa-next]' );
		first.hidden = ! root.getAttribute( 'data-cursor' );
		first.href = pageUrl( '' );
		next.hidden = ! data.next_cursor;
		if ( data.next_cursor ) {
			next.href = pageUrl( String( data.next_cursor ) );
		}
		pager.hidden = first.hidden && next.hidden;
		announce( count( 'loaded', items.length ) + ( data.next_cursor ? ' ' + text( 'more' ) : '' ) );
	}

	function load() {
		loading();
		call( 'GET', 'errors' === cfg.view ? 'errors' : 'events', null, query() ).then( function ( result ) {
			if ( 'ok' === result.kind ) {
				full( result.data );
				return;
			}
			if ( 'session' === result.kind ) {
				leaveWorkspace();
				show( 'session' );
				return;
			}
			if ( 'proof' === result.kind ) {
				leaveWorkspace();
				if ( 'challenge_required' === result.panel ) {
					show( 'challenge', 'expired' );
				} else {
					reloadPanel();
				}
				return;
			}
			setState( 'error' );
			if ( 'invalid' === result.kind ) {
				say( text( 'filters_title' ), text( 'filters_text' ), '', [ clearLink() ] );
			} else {
				say( text( 'error_title' ), text( 'try_later' === result.kind || 'unavailable' === result.kind || 'network' === result.kind || 'stale' === result.kind ? result.kind : 'error_text' ), '', [ retryButton() ] );
			}
			announce( text( 'error_title' ) );
		} );
	}

	load();
}() );
