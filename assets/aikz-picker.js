/**
 * Läuft innerhalb des Vorschau-iframes.
 * Hebt Elemente unter dem Mauszeiger hervor und meldet die Auswahl an den Editor.
 */
( function () {
	'use strict';

	var origin = ( window.aikzPicker && window.aikzPicker.origin ) || window.location.origin;
	var picking = false;
	var hoverBox = null;
	var markBoxes = [];
	var currentMarks = [];

	function send( type, payload ) {
		try {
			window.parent.postMessage( Object.assign( { aikz: true, type: type }, payload || {} ), origin );
		} catch ( e ) {}
	}

	function box( className ) {
		var el = document.createElement( 'div' );
		el.className = className;
		document.body.appendChild( el );
		return el;
	}

	function place( el, rect ) {
		el.style.top = ( rect.top + window.scrollY ) + 'px';
		el.style.left = ( rect.left + window.scrollX ) + 'px';
		el.style.width = rect.width + 'px';
		el.style.height = rect.height + 'px';
	}

	/* --------------------------------------------------------------- */
	/* Selektor erzeugen                                               */
	/* --------------------------------------------------------------- */

	function esc( value ) {
		return window.CSS && CSS.escape ? CSS.escape( value ) : value.replace( /([^\w-])/g, '\\$1' );
	}

	function isStableClass( name ) {
		// Zustands- und Hilfsklassen taugen nicht als Anker.
		return name &&
			! /^(aikz|is-|has-|current|active|hover|focus|open|animated|elementor-animation)/.test( name ) &&
			! /^[a-z]{1,2}-?\d+$/i.test( name );
	}

	function countable( node ) {
		// Eigene Overlays und die Admin-Bar dürfen die Zählung nicht verschieben,
		// sonst passt der Selektor für ausgeloggte Besucher nicht mehr.
		if ( 'wpadminbar' === node.id ) {
			return false;
		}
		if ( node.classList && ( node.classList.contains( 'aikz-picker-ui' ) || node.classList.contains( 'aikz-caption' ) || node.classList.contains( 'aikz-badge' ) ) ) {
			return false;
		}
		return true;
	}

	function part( el ) {
		var out = el.tagName.toLowerCase();
		var classes = Array.prototype.slice.call( el.classList ).filter( isStableClass ).slice( 0, 2 );

		if ( classes.length ) {
			out += '.' + classes.map( esc ).join( '.' );
		}

		var parent = el.parentElement;
		if ( parent ) {
			var siblings = Array.prototype.filter.call( parent.children, function ( c ) {
				return c.tagName === el.tagName && countable( c );
			} );
			if ( siblings.length > 1 ) {
				out += ':nth-of-type(' + ( siblings.indexOf( el ) + 1 ) + ')';
			}
		}

		return out;
	}

	function unique( selector ) {
		try {
			return document.querySelectorAll( selector ).length === 1;
		} catch ( e ) {
			return false;
		}
	}

	function cssPath( el ) {
		if ( el.id && unique( '#' + esc( el.id ) ) ) {
			return '#' + esc( el.id );
		}

		var parts = [];
		var node = el;

		while ( node && node.nodeType === 1 && node !== document.body ) {
			if ( node.id && unique( '#' + esc( node.id ) ) ) {
				parts.unshift( '#' + esc( node.id ) );
				break;
			}

			parts.unshift( part( node ) );

			var candidate = parts.join( ' > ' );
			if ( unique( candidate ) ) {
				return candidate;
			}

			node = node.parentElement;
		}

		return parts.join( ' > ' );
	}

	/* --------------------------------------------------------------- */
	/* Auswahl                                                         */
	/* --------------------------------------------------------------- */

	function target( e ) {
		var el = e.target;
		if ( ! el || el === document.body || el === document.documentElement ) {
			return null;
		}
		if ( el.closest && el.closest( '.aikz-picker-ui' ) ) {
			return null;
		}
		return el;
	}

	function onMove( e ) {
		if ( ! picking ) {
			return;
		}
		var el = target( e );
		if ( ! el ) {
			hoverBox.style.display = 'none';
			return;
		}
		hoverBox.style.display = 'block';
		place( hoverBox, el.getBoundingClientRect() );
	}

	function describe( el ) {
		var text = ( el.textContent || '' ).trim().replace( /\s+/g, ' ' ).slice( 0, 60 );
		if ( 'IMG' === el.tagName ) {
			text = el.getAttribute( 'alt' ) || el.getAttribute( 'src' ) || '';
			text = text.split( '/' ).pop().slice( 0, 60 );
		}
		return {
			tag: el.tagName.toLowerCase(),
			text: text,
			isImage: 'IMG' === el.tagName || !! el.querySelector( 'img' )
		};
	}

	function onClick( e ) {
		if ( ! picking ) {
			return;
		}
		var el = target( e );
		if ( ! el ) {
			return;
		}

		e.preventDefault();
		e.stopPropagation();

		var info = describe( el );
		send( 'picked', {
			selector: cssPath( el ),
			tag: info.tag,
			text: info.text,
			isImage: info.isImage
		} );

		setPicking( false );
	}

	function setPicking( on ) {
		picking = !! on;
		document.documentElement.classList.toggle( 'aikz-picking', picking );
		if ( ! picking && hoverBox ) {
			hoverBox.style.display = 'none';
		}
	}

	/* --------------------------------------------------------------- */
	/* Bestehende Markierungen anzeigen                                */
	/* --------------------------------------------------------------- */

	function drawMarks() {
		markBoxes.forEach( function ( b ) {
			b.remove();
		} );
		markBoxes = [];

		currentMarks.forEach( function ( mark ) {
			var el;
			try {
				el = document.querySelector( mark.selector );
			} catch ( err ) {
				el = null;
			}
			if ( ! el ) {
				return;
			}
			var b = box( 'aikz-picker-ui aikz-mark-box' );
			b.setAttribute( 'data-aikz-mark', mark.id );
			place( b, el.getBoundingClientRect() );
			markBoxes.push( b );
		} );
	}

	function resolveCount() {
		var missing = [];
		currentMarks.forEach( function ( mark ) {
			var found = false;
			try {
				found = !! document.querySelector( mark.selector );
			} catch ( err ) {}
			if ( ! found ) {
				missing.push( mark.id );
			}
		} );
		send( 'resolved', { missing: missing } );
	}

	/* --------------------------------------------------------------- */

	function init() {
		hoverBox = box( 'aikz-picker-ui aikz-hover-box' );
		hoverBox.style.display = 'none';

		document.addEventListener( 'mousemove', onMove, true );
		document.addEventListener( 'click', onClick, true );
		document.addEventListener( 'keydown', function ( e ) {
			if ( 'Escape' === e.key && picking ) {
				setPicking( false );
				send( 'cancelled' );
			}
		} );

		window.addEventListener( 'message', function ( e ) {
			if ( e.origin !== origin || ! e.data || ! e.data.aikz ) {
				return;
			}
			if ( 'pick' === e.data.type ) {
				setPicking( !! e.data.on );
			}
			if ( 'marks' === e.data.type ) {
				currentMarks = e.data.marks || [];
				drawMarks();
				resolveCount();
			}
			if ( 'highlight' === e.data.type ) {
				var el;
				try {
					el = document.querySelector( e.data.selector );
				} catch ( err ) {}
				if ( el ) {
					el.scrollIntoView( { behavior: 'smooth', block: 'center' } );
					hoverBox.style.display = 'block';
					place( hoverBox, el.getBoundingClientRect() );
					setTimeout( function () {
						if ( ! picking ) {
							hoverBox.style.display = 'none';
						}
					}, 1500 );
				}
			}
		} );

		window.addEventListener( 'scroll', drawMarks, { passive: true } );
		window.addEventListener( 'resize', drawMarks );

		send( 'ready', { url: window.location.href } );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
