/**
 * WM Warp – admin page behaviour.
 *
 * Texts come translated from PHP in data attributes, so no JS translations are needed.
 */
( function () {
	'use strict';

	// Copy button: data-wm-warp-copy="<id of the field>", data-wm-warp-copied="<label after copying>".
	document.querySelectorAll( '[data-wm-warp-copy]' ).forEach( function ( button ) {
		var label = button.textContent;

		button.addEventListener( 'click', function () {
			var field = document.getElementById( button.getAttribute( 'data-wm-warp-copy' ) );

			if ( ! field ) {
				return;
			}

			var done = function () {
				button.textContent = button.getAttribute( 'data-wm-warp-copied' ) || label;
				window.setTimeout( function () {
					button.textContent = label;
				}, 2000 );
			};

			// Older way, for browsers or frames where the Clipboard API is not allowed.
			var fallback = function () {
				field.focus();
				field.select();

				try {
					if ( document.execCommand( 'copy' ) ) {
						done();
					}
				} catch ( error ) {
					// The text stays selected, the user can copy it by hand.
				}
			};

			if ( navigator.clipboard && window.isSecureContext ) {
				navigator.clipboard.writeText( field.value ).then( done, fallback );
			} else {
				fallback();
			}
		} );
	} );

	// Confirmation before submitting a form: data-wm-warp-confirm="<question>".
	document.querySelectorAll( 'form[data-wm-warp-confirm]' ).forEach( function ( form ) {
		form.addEventListener( 'submit', function ( event ) {
			if ( ! window.confirm( form.getAttribute( 'data-wm-warp-confirm' ) ) ) {
				event.preventDefault();
			}
		} );
	} );

	// IP address of the other server: hints about Cloudflare while the section is open.
	document.querySelectorAll( '[data-wm-warp-probe]' ).forEach( function ( section ) {
		var form = section.closest( 'form' );
		var key = form ? form.querySelector( '[name="key"]' ) : null;
		var ip = section.querySelector( '[name="ip"]' );
		var siteHint = section.querySelector( '[data-wm-warp-probe-site]' );
		var ipHint = section.querySelector( '[data-wm-warp-probe-ip]' );
		var timer = 0;
		var asked = '';

		if ( ! key || ! ip || ! siteHint || ! ipHint ) {
			return;
		}

		var probe = function () {
			var query = key.value.trim() + '\n' + ip.value.trim();

			if ( ! section.open || '' === key.value.trim() || query === asked ) {
				return;
			}

			asked = query;

			var body = new FormData();
			body.append( 'action', section.getAttribute( 'data-action' ) );
			body.append( 'nonce', section.getAttribute( 'data-nonce' ) );
			body.append( 'key', key.value.trim() );
			body.append( 'ip', ip.value.trim() );

			fetch( section.getAttribute( 'data-ajax' ), { method: 'POST', body: body, credentials: 'same-origin' } )
				.then( function ( response ) {
					return response.json();
				} )
				.then( function ( result ) {
					if ( result && result.success && query === asked ) {
						siteHint.hidden = ! result.data.site;
						ipHint.hidden = ! result.data.ip;
					}
				} )
				.catch( function () {
					// Hints only; connecting works without them.
				} );
		};

		var later = function () {
			window.clearTimeout( timer );
			timer = window.setTimeout( probe, 400 );
		};

		section.addEventListener( 'toggle', probe );
		key.addEventListener( 'change', probe );
		ip.addEventListener( 'input', later );
		probe();
	} );

	// Migration: the form starts it, then the page calls the server step by step until it ends.
	document.querySelectorAll( '[data-wm-warp-migration]' ).forEach( function ( root ) {
		var form = root.querySelector( '[data-wm-warp-migration-form]' );

		if ( ! form ) {
			return;
		}

		var ajaxUrl = root.getAttribute( 'data-ajax' );
		var nonce = root.getAttribute( 'data-nonce' );
		var running = false;
		var cancelling = false;
		var part = function ( name ) {
			return root.querySelector( '[data-wm-warp-' + name + ']' );
		};
		var show = function ( name ) {
			[ 'progress', 'result', 'final', 'failure' ].forEach( function ( other ) {
				part( other ).hidden = other !== name;
			} );
			form.hidden = true;
		};
		var formError = function ( message ) {
			var box = part( 'form-error' );
			box.querySelector( 'p' ).textContent = message;
			box.hidden = false;
			form.querySelectorAll( 'button[type="submit"]' ).forEach( function ( button ) {
				button.disabled = false;
				button.classList.remove( 'is-busy' );
			} );
		};

		var request = function ( command, data ) {
			var body = data || new FormData();
			body.append( 'action', 'wm_warp_migration' );
			body.append( 'command', command );
			body.append( 'nonce', nonce );

			return fetch( ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' } )
				.then( function ( response ) {
					return response.json().catch( function () {
						throw new Error( 'HTTP ' + response.status );
					} );
				} )
				.then( function ( json ) {
					if ( ! json || ! json.success ) {
						throw new Error( json && json.data && json.data.message ? json.data.message : 'Error' );
					}
					if ( json.data.nonce ) {
						nonce = json.data.nonce;
					}
					return json.data;
				} );
		};

		var render = function ( progress ) {
			part( 'phase' ).textContent = progress.phase;
			part( 'bar' ).value = progress.percent;
			part( 'percent' ).textContent = progress.percent + ' %';
			part( 'stats' ).textContent = progress.stats;
			document.title = progress.percent + ' % · ' + root.querySelector( 'h2' ).textContent.trim();
		};

		var finish = function ( progress ) {
			running = false;

			if ( 'done' === progress.status ) {
				part( 'summary' ).textContent = progress.summary;
				var warnings = part( 'warnings' );
				warnings.textContent = '';
				( progress.warnings || [] ).forEach( function ( text ) {
					var item = document.createElement( 'li' );
					item.textContent = text;
					warnings.appendChild( item );
				} );
				warnings.hidden = ! warnings.children.length;
				part( 'logged-out' ).hidden = false !== progress.logged_in;
				show( 'result' );
			} else if ( 'failed' === progress.status && progress.error ) {
				root.querySelector( '[data-wm-warp-error-message]' ).textContent = progress.error.message;
				root.querySelector( '[data-wm-warp-error-where]' ).textContent = progress.error.where;
				root.querySelector( '[data-wm-warp-error-details]' ).textContent = progress.error.details;
				show( 'failure' );
			} else {
				var text = root.querySelector( '[data-wm-warp-final-text]' );
				var key = 'rolled_back' === progress.status ? 'data-rolled-back' : ( 'cancelled' === progress.status ? 'data-cancelled' : 'data-confirmed' );
				text.textContent = text.getAttribute( key );
				show( 'final' );
			}
		};

		var step = function () {
			request( cancelling ? 'cancel' : 'step' ).then( function ( progress ) {
				render( progress );
				if ( 'running' === progress.status ) {
					step();
				} else {
					finish( progress );
				}
			} ).catch( function ( error ) {
				finish( { status: 'failed', error: { message: error.message, where: '', details: '' } } );
			} );
		};

		var rules = part( 'rules' );
		var template = root.querySelector( '[data-wm-warp-rule-template]' );

		part( 'add-rule' ).addEventListener( 'click', function () {
			rules.appendChild( template.content.cloneNode( true ) );
			rules.lastElementChild.querySelector( 'input' ).focus();
		} );

		rules.addEventListener( 'click', function ( event ) {
			var remove = event.target.closest( '[data-wm-warp-remove-rule]' );
			if ( remove ) {
				remove.closest( 'tr' ).remove();
			}
		} );

		// Sections with a checkbox in the legend: Database, Media, Themes, Plugins.
		form.querySelectorAll( '[data-wm-warp-group-toggle]' ).forEach( function ( toggle ) {
			var update = function () {
				toggle.closest( '[data-wm-warp-group]' ).querySelector( '[data-wm-warp-group-body]' ).hidden = ! toggle.checked;
				if ( 'database' === toggle.name ) {
					form.querySelectorAll( '[data-wm-warp-database-only]' ).forEach( function ( section ) {
						section.hidden = ! toggle.checked;
					} );
				}
			};
			toggle.addEventListener( 'change', update );
			update();
		} );

		form.querySelectorAll( 'input[name="uploads_mode"]' ).forEach( function ( radio ) {
			radio.addEventListener( 'change', function () {
				part( 'since' ).hidden = 'since' !== form.querySelector( 'input[name="uploads_mode"]:checked' ).value;
			} );
		} );

		// Plugins and themes: the list shows what the mode transfers; checkboxes only for selecting.
		form.querySelectorAll( '[data-wm-warp-items]' ).forEach( function ( box ) {
			var update = function () {
				var checked = box.querySelector( 'input[type="radio"]:checked' );
				var mode = checked ? checked.value : 'all';
				box.querySelectorAll( '.wm-warp__item' ).forEach( function ( row ) {
					row.hidden = 'active' === mode && '1' !== row.getAttribute( 'data-active' );
					row.querySelector( '[data-wm-warp-item-check]' ).hidden = 'selected' !== mode && 'except' !== mode;
				} );
			};
			box.querySelectorAll( 'input[type="radio"]' ).forEach( function ( radio ) {
				radio.addEventListener( 'change', update );
			} );
			update();
		} );

		form.querySelectorAll( 'input[name="mode"]' ).forEach( function ( radio ) {
			radio.addEventListener( 'change', function () {
				part( 'tables' ).hidden = 'selected' !== form.querySelector( 'input[name="mode"]:checked' ).value;
			} );
		} );

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			part( 'form-error' ).hidden = true;
			form.querySelectorAll( 'button[type="submit"]' ).forEach( function ( button ) {
				button.disabled = true;
				button.classList.add( 'is-busy' );
			} );

			var data = new FormData( form );
			// Flags of custom rows are sent as the indexes of the rows that have them.
			form.querySelectorAll( 'input[name="find[]"]' ).forEach( function ( input, index ) {
				var row = input.closest( 'tr' );
				[ 'case', 'regex' ].forEach( function ( flag ) {
					if ( row.querySelector( '[data-wm-warp-flag="' + flag + '"]' ).checked ) {
						data.append( flag + '[]', String( index ) );
					}
				} );
			} );

			request( 'start', data ).then( function ( progress ) {
				// The new migration confirmed the waiting one; its notice would now act on the new one.
				document.querySelectorAll( '.wm-warp__pending' ).forEach( function ( notice ) {
					notice.remove();
				} );
				running = true;
				render( progress );
				show( 'progress' );
				step();
			} ).catch( function ( error ) {
				formError( error.message );
			} );
		} );

		part( 'cancel' ).addEventListener( 'click', function () {
			cancelling = true;
			part( 'cancel' ).disabled = true;
		} );

		root.querySelectorAll( '[data-wm-warp-command]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				var question = button.getAttribute( 'data-confirm' );
				if ( question && ! window.confirm( question ) ) {
					return;
				}

				root.querySelectorAll( '[data-wm-warp-command]' ).forEach( function ( other ) {
					other.disabled = true;
				} );
				button.classList.add( 'is-busy' );

				request( button.getAttribute( 'data-wm-warp-command' ) ).then( finish ).catch( function ( error ) {
					finish( { status: 'failed', error: { message: error.message, where: '', details: '' } } );
				} );
			} );
		} );

		window.addEventListener( 'beforeunload', function ( event ) {
			if ( running ) {
				event.preventDefault();
				event.returnValue = root.getAttribute( 'data-leave' );
			}
		} );
	} );

	// Prevent double submits while a connection is being made (it can take several seconds).
	document.querySelectorAll( '.wm-warp form:not([data-wm-warp-migration-form])' ).forEach( function ( form ) {
		form.addEventListener( 'submit', function ( event ) {
			if ( event.defaultPrevented ) {
				return;
			}

			form.querySelectorAll( 'button[type="submit"]' ).forEach( function ( button ) {
				button.disabled = true;
				button.classList.add( 'is-busy' );
			} );
		} );
	} );
}() );
