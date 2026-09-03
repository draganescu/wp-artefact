/* global wpArtifactsEdit */
( function () {
	'use strict';

	function initPreview( preview ) {
		var frame = preview.querySelector( '.wp-artifacts-preview__frame' );
		var buttons = preview.querySelectorAll( '[data-viewport]' );

		if ( ! frame ) {
			return;
		}

		buttons.forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				buttons.forEach( function ( other ) {
					other.classList.toggle( 'is-active', other === button );
				} );
				preview.classList.toggle( 'is-mobile', button.dataset.viewport === 'mobile' );
			} );
		} );

		var reload = preview.querySelector( '[data-reload]' );
		if ( reload ) {
			reload.addEventListener( 'click', function () {
				var src = preview.dataset.src || frame.src;
				frame.src = src + ( src.indexOf( '?' ) === -1 ? '?' : '&' ) + 'cb=' + Date.now();
			} );
		}
	}

	function initCopy( field ) {
		field.addEventListener( 'click', function () {
			field.select();

			if ( ! navigator.clipboard ) {
				return;
			}

			navigator.clipboard.writeText( field.value ).then( function () {
				var previous = field.getAttribute( 'title' );
				field.setAttribute( 'title', wpArtifactsEdit.copied );
				window.setTimeout( function () {
					field.setAttribute( 'title', previous || '' );
				}, 1500 );
			} );
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		document.querySelectorAll( '.wp-artifacts-preview' ).forEach( initPreview );
		document.querySelectorAll( '.wp-artifacts-copyable' ).forEach( initCopy );
	} );
}() );
