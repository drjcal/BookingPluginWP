( function () {
	'use strict';

	function init( form ) {
		var status = form.querySelector( '[data-gzw-status]' );

		function clearErrors() {
			form.querySelectorAll( '[data-gzw-error]' ).forEach( function ( el ) {
				el.textContent = '';
			} );
			form.querySelectorAll( '[aria-invalid]' ).forEach( function ( el ) {
				el.removeAttribute( 'aria-invalid' );
				var original = el.getAttribute( 'data-gzw-describedby' );
				if ( original ) {
					el.setAttribute( 'aria-describedby', original );
				} else {
					el.removeAttribute( 'aria-describedby' );
				}
			} );
		}

		function showErrors( errors ) {
			var first = null;
			Object.keys( errors ).forEach( function ( name ) {
				var input = form.elements[ name ];
				var slot = form.querySelector( '[data-gzw-error="' + name + '"]' );
				if ( ! input || ! slot ) {
					return;
				}
				slot.textContent = errors[ name ];
				input.setAttribute( 'aria-invalid', 'true' );
				if ( input.hasAttribute( 'aria-describedby' ) ) {
					input.setAttribute( 'data-gzw-describedby', input.getAttribute( 'aria-describedby' ) );
				}
				input.setAttribute( 'aria-describedby', slot.id + ( input.getAttribute( 'data-gzw-describedby' ) ? ' ' + input.getAttribute( 'data-gzw-describedby' ) : '' ) );
				first = first || input;
			} );
			if ( first ) {
				first.focus();
			}
		}

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			clearErrors();
			status.textContent = '';

			fetch( window.gzwBooking.endpoint, {
				method: 'POST',
				body: new FormData( form ),
				headers: { Accept: 'application/json' },
			} )
				.then( function ( response ) {
					return response.json().then( function ( data ) {
						return { ok: response.ok, data: data };
					} );
				} )
				.then( function ( result ) {
					status.textContent = result.data.message || '';
					if ( result.ok ) {
						form.reset();
						status.focus();
					} else if ( result.data.errors ) {
						showErrors( result.data.errors ); // Input stays untouched.
					}
				} )
				.catch( function () {
					status.textContent = 'Er ging iets mis. Probeer het later opnieuw.';
				} );
		} );
	}

	document.querySelectorAll( '[data-gzw-form]' ).forEach( init );
} )();
