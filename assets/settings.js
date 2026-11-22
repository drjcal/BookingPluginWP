/* Settings page: logo picker backed by the WordPress media library. Strings come from gzwSettings. */
jQuery( function ( $ ) {
	var frame;
	$( '#gzw-logo-select-button' ).on( 'click', function ( e ) {
		e.preventDefault();
		if ( frame ) {
			frame.open();
			return;
		}
		frame = wp.media( {
			title: gzwSettings.title,
			button: { text: gzwSettings.button },
			multiple: false,
			library: { type: 'image' }
		} );
		frame.on( 'select', function () {
			var attachment = frame.state().get( 'selection' ).first().toJSON();
			$( '#gzw-logo-id' ).val( attachment.id );
			$( '#gzw-logo-url' ).val( attachment.url );
			$( '#gzw-logo-preview' ).attr( 'src', attachment.url ).show();
			$( '#gzw-logo-remove-button' ).show();
		} );
		frame.open();
	} );
	$( '#gzw-logo-remove-button' ).on( 'click', function ( e ) {
		e.preventDefault();
		$( '#gzw-logo-id' ).val( '' );
		$( '#gzw-logo-url' ).val( '' );
		$( '#gzw-logo-preview' ).attr( 'src', '' ).hide();
		$( this ).hide();
	} );
} );
