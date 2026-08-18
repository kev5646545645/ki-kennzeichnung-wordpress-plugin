/**
 * Editor-Shell: Toolbar, Viewport-Umschaltung, Markierungsliste.
 * Die Seite selbst läuft in einem iframe, damit die Breite frei wählbar ist.
 */
( function () {
	'use strict';

	var cfg = window.aikzEditor;
	if ( ! cfg ) {
		return;
	}

	var t = cfg.i18n;
	var origin = window.location.origin;
	var marks = ( cfg.marks || [] ).slice();
	var dirty = false;
	var picking = false;
	var frame = null;
	var pending = null;

	var ICONS = {
		desktop: '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M2 3h16a1 1 0 0 1 1 1v9a1 1 0 0 1-1 1h-6v2h3v1H5v-1h3v-2H2a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1zm0 1v9h16V4H2z"/></svg>',
		tablet: '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M5 1h10a1 1 0 0 1 1 1v16a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V2a1 1 0 0 1 1-1zm0 1v16h10V2H5zm5 14a1 1 0 1 1 0 2 1 1 0 0 1 0-2z"/></svg>',
		mobile: '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M6 1h8a1 1 0 0 1 1 1v16a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V2a1 1 0 0 1 1-1zm0 1v16h8V2H6zm4 14a1 1 0 1 1 0 2 1 1 0 0 1 0-2z"/></svg>'
	};

	var VIEWPORTS = {
		desktop: { width: 0, label: t.desktop },
		tablet: { width: 834, label: t.tablet },
		mobile: { width: 390, label: t.mobile }
	};
	var viewport = 'desktop';

	/* --------------------------------------------------------------- */
	/* Aufbau                                                          */
	/* --------------------------------------------------------------- */

	function el( tag, attrs, children ) {
		var node = document.createElement( tag );
		Object.keys( attrs || {} ).forEach( function ( key ) {
			if ( 'class' === key ) {
				node.className = attrs[ key ];
			} else if ( 'text' === key ) {
				node.textContent = attrs[ key ];
			} else if ( 0 === key.indexOf( 'on' ) ) {
				node.addEventListener( key.slice( 2 ).toLowerCase(), attrs[ key ] );
			} else {
				node.setAttribute( key, attrs[ key ] );
			}
		} );
		( children || [] ).forEach( function ( child ) {
			if ( child ) {
				node.appendChild( child );
			}
		} );
		return node;
	}

	var refs = {};

	function build() {
		document.documentElement.classList.add( 'aikz-editing' );

		var viewportButtons = Object.keys( VIEWPORTS ).map( function ( key ) {
			var v = VIEWPORTS[ key ];
			var icon = el( 'span', { class: 'aikz-vp-icon' } );
			icon.innerHTML = ICONS[ key ];

			return el( 'button', {
				type: 'button',
				class: 'aikz-vp' + ( key === viewport ? ' is-active' : '' ),
				'data-vp': key,
				title: v.label + ( v.width ? ' (' + v.width + 'px)' : '' ),
				onclick: function () {
					setViewport( key );
				}
			}, [ icon, el( 'span', { class: 'aikz-vp-label', text: v.label } ) ] );
		} );

		refs.viewportBar = el( 'div', { class: 'aikz-vp-bar' }, viewportButtons );

		refs.pickBtn = el( 'button', {
			type: 'button',
			class: 'aikz-btn aikz-btn-primary',
			text: t.pick,
			onclick: togglePicking
		} );

		refs.saveBtn = el( 'button', {
			type: 'button',
			class: 'aikz-btn',
			text: t.save,
			onclick: save
		} );

		refs.status = el( 'span', { class: 'aikz-status' } );

		var bar = el( 'header', { class: 'aikz-bar' }, [
			el( 'span', { class: 'aikz-brand', text: t.title } ),
			refs.viewportBar,
			el( 'div', { class: 'aikz-bar-actions' }, [
				refs.status,
				refs.pickBtn,
				refs.saveBtn,
				el( 'a', { class: 'aikz-btn aikz-btn-ghost', href: cfg.exitUrl, text: t.exit } )
			] )
		] );

		refs.list = el( 'div', { class: 'aikz-list' } );
		refs.form = el( 'div', { class: 'aikz-form', hidden: 'hidden' } );

		var panel = el( 'aside', { class: 'aikz-panel' }, [
			el( 'h2', { class: 'aikz-panel-title', text: t.marks } ),
			refs.list,
			refs.form
		] );

		frame = el( 'iframe', {
			class: 'aikz-frame',
			src: cfg.frameUrl,
			title: t.title
		} );

		refs.stage = el( 'div', { class: 'aikz-stage' }, [ el( 'div', { class: 'aikz-frame-wrap' }, [ frame ] ) ] );

		var root = el( 'div', { class: 'aikz-editor' }, [
			bar,
			el( 'div', { class: 'aikz-body' }, [ refs.stage, panel ] )
		] );

		document.body.appendChild( root );

		frame.addEventListener( 'load', function () {
			pushMarks();
		} );

		renderList();
		setViewport( viewport );
	}

	/* --------------------------------------------------------------- */
	/* Viewport                                                        */
	/* --------------------------------------------------------------- */

	function setViewport( key ) {
		viewport = key;
		var v = VIEWPORTS[ key ];
		var wrap = refs.stage.querySelector( '.aikz-frame-wrap' );

		if ( v.width ) {
			wrap.style.width = v.width + 'px';
			wrap.style.maxWidth = '100%';
			wrap.classList.add( 'is-device' );
		} else {
			wrap.style.width = '100%';
			wrap.classList.remove( 'is-device' );
		}

		refs.viewportBar.querySelectorAll( '.aikz-vp' ).forEach( function ( btn ) {
			btn.classList.toggle( 'is-active', btn.getAttribute( 'data-vp' ) === key );
		} );
	}

	/* --------------------------------------------------------------- */
	/* Kommunikation mit dem iframe                                    */
	/* --------------------------------------------------------------- */

	function toFrame( type, payload ) {
		if ( ! frame || ! frame.contentWindow ) {
			return;
		}
		frame.contentWindow.postMessage( Object.assign( { aikz: true, type: type }, payload || {} ), origin );
	}

	function pushMarks() {
		toFrame( 'marks', { marks: marks } );
	}

	function togglePicking() {
		picking = ! picking;
		refs.pickBtn.textContent = picking ? t.picking : t.pick;
		refs.pickBtn.classList.toggle( 'is-active', picking );
		toFrame( 'pick', { on: picking } );
	}

	window.addEventListener( 'message', function ( e ) {
		if ( e.origin !== origin || ! e.data || ! e.data.aikz ) {
			return;
		}

		if ( 'ready' === e.data.type ) {
			pushMarks();
		}

		if ( 'cancelled' === e.data.type ) {
			picking = false;
			refs.pickBtn.textContent = t.pick;
			refs.pickBtn.classList.remove( 'is-active' );
		}

		if ( 'picked' === e.data.type ) {
			picking = false;
			refs.pickBtn.textContent = t.pick;
			refs.pickBtn.classList.remove( 'is-active' );
			openForm( e.data );
		}

		if ( 'resolved' === e.data.type ) {
			var missing = e.data.missing || [];
			refs.list.querySelectorAll( '[data-mark-id]' ).forEach( function ( row ) {
				row.classList.toggle( 'is-missing', missing.indexOf( row.getAttribute( 'data-mark-id' ) ) > -1 );
			} );
		}
	} );

	/* --------------------------------------------------------------- */
	/* Formular für eine neue Markierung                               */
	/* --------------------------------------------------------------- */

	function openForm( data ) {
		pending = data;
		refs.form.hidden = false;
		refs.form.innerHTML = '';

		var preview = el( 'p', { class: 'aikz-form-target' }, [
			el( 'code', { text: '<' + data.tag + '>' } ),
			el( 'span', { text: data.text ? ' ' + data.text : '' } )
		] );

		var labelInput = el( 'input', {
			type: 'text',
			class: 'aikz-input',
			value: cfg.defaults.label
		} );

		var displaySelect = el( 'select', { class: 'aikz-input' }, [
			el( 'option', { value: 'badge', text: t.badge } ),
			el( 'option', { value: 'caption', text: t.caption } )
		] );
		displaySelect.value = data.isImage ? 'badge' : 'caption';

		var positionSelect = el( 'select', { class: 'aikz-input' }, [
			el( 'option', { value: 'top-left', text: t.topLeft } ),
			el( 'option', { value: 'top-right', text: t.topRight } ),
			el( 'option', { value: 'bottom-left', text: t.bottomLeft } ),
			el( 'option', { value: 'bottom-right', text: t.bottomRight } )
		] );
		positionSelect.value = cfg.defaults.position;

		refs.form.appendChild( preview );
		refs.form.appendChild( el( 'label', { class: 'aikz-field' }, [ el( 'span', { text: t.label } ), labelInput ] ) );
		refs.form.appendChild( el( 'label', { class: 'aikz-field' }, [ el( 'span', { text: t.display } ), displaySelect ] ) );
		refs.form.appendChild( el( 'label', { class: 'aikz-field' }, [ el( 'span', { text: t.position } ), positionSelect ] ) );

		refs.form.appendChild( el( 'div', { class: 'aikz-form-actions' }, [
			el( 'button', {
				type: 'button',
				class: 'aikz-btn aikz-btn-primary',
				text: t.add,
				onclick: function () {
					marks.push( {
						id: 'm' + Date.now().toString( 36 ),
						selector: pending.selector,
						label: labelInput.value || cfg.defaults.label,
						display: displaySelect.value,
						position: positionSelect.value
					} );
					closeForm();
					markDirty();
					renderList();
					pushMarks();
				}
			} ),
			el( 'button', { type: 'button', class: 'aikz-btn aikz-btn-ghost', text: t.cancel, onclick: closeForm } )
		] ) );
	}

	function closeForm() {
		pending = null;
		refs.form.hidden = true;
		refs.form.innerHTML = '';
	}

	/* --------------------------------------------------------------- */
	/* Liste                                                           */
	/* --------------------------------------------------------------- */

	function renderList() {
		refs.list.innerHTML = '';

		if ( ! marks.length ) {
			refs.list.appendChild( el( 'p', { class: 'aikz-empty', text: t.empty } ) );
			return;
		}

		marks.forEach( function ( mark, index ) {
			var row = el( 'div', { class: 'aikz-row', 'data-mark-id': mark.id }, [
				el( 'div', { class: 'aikz-row-main', onclick: function () {
					toFrame( 'highlight', { selector: mark.selector } );
				} }, [
					el( 'strong', { text: mark.label } ),
					el( 'code', { class: 'aikz-row-sel', text: mark.selector } ),
					el( 'span', { class: 'aikz-row-meta', text: 'badge' === mark.display ? t.badge : t.caption } )
				] ),
				el( 'button', {
					type: 'button',
					class: 'aikz-row-remove',
					title: t.remove,
					text: '×',
					onclick: function () {
						marks.splice( index, 1 );
						markDirty();
						renderList();
						pushMarks();
					}
				} )
			] );
			refs.list.appendChild( row );
		} );
	}

	/* --------------------------------------------------------------- */
	/* Speichern                                                       */
	/* --------------------------------------------------------------- */

	function markDirty() {
		dirty = true;
		refs.status.textContent = t.unsaved;
		refs.status.className = 'aikz-status is-dirty';
	}

	function save() {
		refs.saveBtn.disabled = true;

		fetch( cfg.restUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': cfg.nonce
			},
			body: JSON.stringify( { context: cfg.context, marks: marks } )
		} )
			.then( function ( res ) {
				if ( ! res.ok ) {
					throw new Error( res.status );
				}
				return res.json();
			} )
			.then( function ( data ) {
				marks = data.marks || marks;
				dirty = false;
				refs.status.textContent = t.saved;
				refs.status.className = 'aikz-status is-ok';
				renderList();
				frame.contentWindow.location.reload();
			} )
			.catch( function () {
				refs.status.textContent = t.saveError;
				refs.status.className = 'aikz-status is-error';
			} )
			.finally( function () {
				refs.saveBtn.disabled = false;
			} );
	}

	window.addEventListener( 'beforeunload', function ( e ) {
		if ( dirty ) {
			e.preventDefault();
			e.returnValue = '';
		}
	} );

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', build );
	} else {
		build();
	}
} )();
