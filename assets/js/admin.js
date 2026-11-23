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

	// Prevent double submits while a connection is being made (it can take several seconds).
	document.querySelectorAll( '.wm-warp form' ).forEach( function ( form ) {
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
