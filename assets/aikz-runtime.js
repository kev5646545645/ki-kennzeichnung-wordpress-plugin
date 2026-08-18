/**
 * Setzt die im Editor gespeicherten Markierungen auf der Seite.
 * Nutzt dieselben CSS-Klassen wie die Mediathek-Kennzeichnung.
 */
( function () {
	'use strict';

	var cfg = window.aikzRuntime;
	if ( ! cfg || ! cfg.marks || ! cfg.marks.length ) {
		return;
	}

	function badge( text ) {
		var b = document.createElement( 'span' );
		b.className = 'aikz-badge';
		b.setAttribute( 'role', 'note' );

		if ( cfg.icon ) {
			b.innerHTML = cfg.icon;
		}

		var s = document.createElement( 'span' );
		s.className = 'aikz-badge__text';
		s.textContent = text;
		b.appendChild( s );

		return b;
	}

	function caption( text ) {
		var s = document.createElement( 'span' );
		s.className = 'aikz-caption';
		s.textContent = text;
		return s;
	}

	function applyBadge( host, mark ) {
		host.classList.add( 'aikz-host', 'aikz-mode-' + cfg.mode, 'aikz-pos-' + mark.position );

		// Absolut positionierte Badges brauchen einen Bezugsrahmen.
		var position = window.getComputedStyle( host ).position;
		if ( 'static' === position ) {
			host.style.position = 'relative';
		}

		host.appendChild( badge( mark.label ) );
	}

	function applyCaption( host, mark ) {
		var cap = caption( mark.label );
		if ( host.nextSibling ) {
			host.parentNode.insertBefore( cap, host.nextSibling );
		} else {
			host.parentNode.appendChild( cap );
		}
	}

	function apply() {
		// Erst auflösen, dann verändern: sonst verschieben eingefügte Elemente
		// die Selektoren der noch folgenden Markierungen.
		var resolved = [];

		cfg.marks.forEach( function ( mark ) {
			var el;
			try {
				el = document.querySelector( mark.selector );
			} catch ( e ) {
				return;
			}
			if ( ! el || el.getAttribute( 'data-aikz-marked' ) ) {
				return;
			}
			el.setAttribute( 'data-aikz-marked', '1' );
			resolved.push( { el: el, mark: mark } );
		} );

		resolved.forEach( function ( item ) {
			var el = item.el;
			var mark = item.mark;

			el.setAttribute( 'data-ai-generated', 'true' );

			if ( 'caption' === mark.display ) {
				applyCaption( el, mark );
				return;
			}

			// Bilder können keine Kindelemente aufnehmen – Badge kommt ans Elternelement.
			var host = 'IMG' === el.tagName ? el.parentElement : el;
			if ( ! host ) {
				return;
			}
			applyBadge( host, mark );
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', apply );
	} else {
		apply();
	}

	document.addEventListener( 'aikz:refresh', apply );
} )();
