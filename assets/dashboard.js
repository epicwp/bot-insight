/**
 * Bot Insight dashboard (Tools > Bot Insight).
 *
 * Plain ES2017, no build step. Renders the read-only REST report into #bot-insight-app.
 * All data goes into the page through textContent / attributes only.
 *
 * @package BotInsight
 * @license GPL-2.0-or-later
 */
( function () {
	'use strict';

	const root = document.getElementById( 'bot-insight-app' );
	if ( ! root || ! window.wp || ! window.wp.apiFetch || ! window.wp.i18n ) {
		return;
	}

	const apiFetch = window.wp.apiFetch;
	const __ = window.wp.i18n.__;
	const _n = window.wp.i18n._n;
	const sprintf = window.wp.i18n.sprintf;
	const config = window.botInsightDashboard || {};
	const restPath = typeof config.restPath === 'string' ? config.restPath : '/bot-insight/v1';

	const SVG_NS = 'http://www.w3.org/2000/svg';
	const STORAGE_KEY = 'bot_insight_period';
	const PERIODS = [ '24h', '7d', '30d' ];
	const DEFAULT_PERIOD = '7d';
	const VISITOR_ROWS = 15;
	const LOCALE = normaliseLocale( document.documentElement.lang );

	const CLASSES = [
		'people',
		'search',
		'ai_search',
		'ai_assistant',
		'ai_training',
		'seo',
		'social',
		'monitoring',
		'feed',
		'library',
		'unknown_bot',
		'suspected',
	];

	const CLASS_LABELS = {
		people: __( 'People', 'bot-insight' ),
		search: __( 'Search engines', 'bot-insight' ),
		ai_search: __( 'AI search', 'bot-insight' ),
		ai_assistant: __( 'AI assistants', 'bot-insight' ),
		ai_training: __( 'AI training crawlers', 'bot-insight' ),
		seo: __( 'SEO tools', 'bot-insight' ),
		social: __( 'Social previews', 'bot-insight' ),
		monitoring: __( 'Monitoring', 'bot-insight' ),
		feed: __( 'Feed readers', 'bot-insight' ),
		library: __( 'Script libraries', 'bot-insight' ),
		unknown_bot: __( 'Unknown bots', 'bot-insight' ),
		suspected: __( 'Suspected automated', 'bot-insight' ),
	};

	const SIGNAL_LABELS = {
		no_js: __( 'No JavaScript', 'bot-insight' ),
		no_sec_fetch: __( 'No Sec-Fetch browser headers', 'bot-insight' ),
		no_accept_lang: __( 'No Accept-Language header', 'bot-insight' ),
		get_add_to_cart: __( 'Add-to-cart opened with a GET request', 'bot-insight' ),
		ua_outdated: __( 'Outdated browser version', 'bot-insight' ),
	};

	const PERIOD_LABELS = {
		'24h': __( 'Last 24 hours', 'bot-insight' ),
		'7d': __( 'Last 7 days', 'bot-insight' ),
		'30d': __( 'Last 30 days', 'bot-insight' ),
	};

	const state = {
		period: readPeriod(),
		request: 0,
		resizeTimer: 0,
		observer: null,
		exportNotice: null,
	};

	/* ------------------------------------------------------------------ helpers */

	function normaliseLocale( lang ) {
		const tag = String( lang || '' ).replace( '_', '-' );
		try {
			return Intl.NumberFormat.supportedLocalesOf( [ tag ] ).length ? tag : undefined;
		} catch ( e ) {
			return undefined;
		}
	}

	function readPeriod() {
		try {
			const stored = window.localStorage.getItem( STORAGE_KEY );
			return PERIODS.indexOf( stored ) >= 0 ? stored : DEFAULT_PERIOD;
		} catch ( e ) {
			return DEFAULT_PERIOD;
		}
	}

	function storePeriod( period ) {
		try {
			window.localStorage.setItem( STORAGE_KEY, period );
		} catch ( e ) {
			// Storage blocked: the choice simply is not remembered.
		}
	}

	/**
	 * Create an element. `attrs.text` sets textContent, `attrs.className` the class; every other
	 * key becomes an attribute. Children may be nodes or strings (added as text).
	 */
	function el( tag, attrs, children ) {
		const node = document.createElement( tag );
		applyAttrs( node, attrs );
		append( node, children );
		return node;
	}

	function svg( tag, attrs, children ) {
		const node = document.createElementNS( SVG_NS, tag );
		applyAttrs( node, attrs );
		append( node, children );
		return node;
	}

	function applyAttrs( node, attrs ) {
		Object.keys( attrs || {} ).forEach( function ( key ) {
			const value = attrs[ key ];
			if ( value === null || value === undefined || value === false ) {
				return;
			}
			if ( key === 'text' ) {
				node.textContent = String( value );
			} else if ( key === 'className' ) {
				node.setAttribute( 'class', value );
			} else {
				node.setAttribute( key, value === true ? '' : String( value ) );
			}
		} );
	}

	function append( node, children ) {
		( Array.isArray( children ) ? children : [ children ] ).forEach( function ( child ) {
			if ( child === null || child === undefined || child === false ) {
				return;
			}
			node.appendChild( typeof child === 'string' ? document.createTextNode( child ) : child );
		} );
	}

	function clear( node ) {
		while ( node.firstChild ) {
			node.removeChild( node.firstChild );
		}
	}

	function numberFormat( options ) {
		try {
			return new Intl.NumberFormat( LOCALE, options );
		} catch ( e ) {
			return new Intl.NumberFormat( undefined, options );
		}
	}

	const intFormat = numberFormat( { maximumFractionDigits: 0 } );

	function fmtInt( value ) {
		return intFormat.format( Number( value ) || 0 );
	}

	function hourDigits( v ) {
		if ( v >= 100 ) {
			return 0;
		}
		return v >= 10 ? 1 : 2;
	}

	function fmtHours( value ) {
		const v = Number( value ) || 0;
		return numberFormat( { maximumFractionDigits: hourDigits( v ) } ).format( v );
	}

	/** The value as fmtHours() shows it, as a number: the key for _n(). */
	function shownHours( value ) {
		const v = Number( value ) || 0;
		const f = Math.pow( 10, hourDigits( v ) );
		return Math.round( v * f ) / f;
	}

	function fmtPct( share ) {
		const v = Number( share ) || 0;
		const digits = v > 0 && v < 0.01 ? 1 : 0;
		return numberFormat( { style: 'percent', maximumFractionDigits: digits } ).format( v );
	}

	function fmtMs( us ) {
		return numberFormat( { minimumFractionDigits: 2, maximumFractionDigits: 2 } ).format( ( Number( us ) || 0 ) / 1000 );
	}

	/** 'Y-m-d' → short local date label; the date itself is already in the site's timezone. */
	function fmtDay( day ) {
		const parts = String( day ).split( '-' ).map( Number );
		if ( parts.length !== 3 || parts.some( isNaN ) ) {
			return String( day );
		}
		const date = new Date( Date.UTC( parts[ 0 ], parts[ 1 ] - 1, parts[ 2 ] ) );
		try {
			return new Intl.DateTimeFormat( LOCALE, { month: 'short', day: 'numeric', timeZone: 'UTC' } ).format( date );
		} catch ( e ) {
			return String( day );
		}
	}

	function countryName( code ) {
		if ( ! code || code === '??' ) {
			return __( 'Unknown', 'bot-insight' );
		}
		try {
			if ( Intl.DisplayNames ) {
				const name = new Intl.DisplayNames( LOCALE ? [ LOCALE ] : undefined, { type: 'region' } ).of( code );
				if ( name && name !== code ) {
					return name;
				}
			}
		} catch ( e ) {
			// Fall through to the code itself.
		}
		return code;
	}

	function classLabel( cls ) {
		return CLASS_LABELS[ cls ] || cls;
	}

	function classKey( cls ) {
		return CLASSES.indexOf( cls ) >= 0 ? cls : 'other';
	}

	function swatch( cls ) {
		return el( 'span', { className: 'bi-swatch bi-c-' + classKey( cls ), 'aria-hidden': 'true' } );
	}

	function card( heading, extraClass ) {
		const section = el( 'section', { className: 'bi-card' + ( extraClass ? ' ' + extraClass : '' ) } );
		if ( heading ) {
			section.appendChild( el( 'h2', { className: 'bi-card__title', text: heading } ) );
		}
		return section;
	}

	function scroller( table ) {
		return el( 'div', { className: 'bi-scroll', tabindex: '0', role: 'region', 'aria-label': table.getAttribute( 'aria-label' ) || '' }, table );
	}

	/**
	 * A data table. `columns`: [{label, numeric}]; `rows`: arrays of cell content (string or node).
	 */
	function dataTable( label, columns, rows ) {
		const table = el( 'table', { className: 'widefat striped bi-table', 'aria-label': label } );
		const headRow = el( 'tr' );
		columns.forEach( function ( col ) {
			headRow.appendChild( el( 'th', { scope: 'col', className: col.numeric ? 'bi-num' : null, text: col.label } ) );
		} );
		table.appendChild( el( 'thead', null, headRow ) );
		const body = el( 'tbody' );
		rows.forEach( function ( cells ) {
			const tr = el( 'tr' );
			cells.forEach( function ( cell, i ) {
				const tag = i === 0 ? 'th' : 'td';
				const td = el( tag, { scope: i === 0 ? 'row' : null, className: columns[ i ] && columns[ i ].numeric ? 'bi-num' : null }, cell );
				tr.appendChild( td );
			} );
			body.appendChild( tr );
		} );
		table.appendChild( body );
		return table;
	}

	/* ------------------------------------------------------------------ layout */

	const select = el( 'select', { id: 'bot-insight-period' } );
	PERIODS.forEach( function ( key ) {
		select.appendChild( el( 'option', { value: key, text: PERIOD_LABELS[ key ] } ) );
	} );
	select.value = state.period;

	const exportButton = el( 'button', { type: 'button', className: 'button', text: __( 'Export data (JSON)', 'bot-insight' ) } );
	const status = el( 'div', { className: 'bi-status', role: 'status', 'aria-live': 'polite' } );
	const content = el( 'div', { className: 'bi-content' } );

	const toolbar = el( 'div', { className: 'bi-toolbar' }, [
		el( 'span', { className: 'bi-pill', text: __( 'Measuring only, nothing is blocked', 'bot-insight' ) } ),
		el( 'div', { className: 'bi-toolbar__controls' }, [
			el( 'label', { for: 'bot-insight-period', text: __( 'Period', 'bot-insight' ) } ),
			select,
			exportButton,
		] ),
	] );

	clear( root );
	append( root, [ toolbar, status, content ] );

	select.addEventListener( 'change', function () {
		state.period = PERIODS.indexOf( select.value ) >= 0 ? select.value : DEFAULT_PERIOD;
		storePeriod( state.period );
		load();
	} );
	exportButton.addEventListener( 'click', exportData );

	/* ------------------------------------------------------------------ data */

	function load() {
		const token = ++state.request;
		if ( state.observer ) {
			state.observer.disconnect();
			state.observer = null;
		}
		content.setAttribute( 'aria-busy', 'true' );
		clear( content );
		content.appendChild( skeleton() );

		apiFetch( { path: restPath + '/report?period=' + encodeURIComponent( state.period ) } )
			.then( function ( data ) {
				if ( token !== state.request ) {
					return;
				}
				content.removeAttribute( 'aria-busy' );
				clear( content );
				renderReport( data || {} );
			} )
			.catch( function ( error ) {
				if ( token !== state.request ) {
					return;
				}
				content.removeAttribute( 'aria-busy' );
				clear( content );
				content.appendChild( errorNotice( __( 'The report could not be loaded.', 'bot-insight' ), error, load ) );
			} );
	}

	function skeleton() {
		const wrap = el( 'div', { className: 'bi-skeleton' }, [
			el( 'p', { className: 'bi-skeleton__text', text: __( 'Loading…', 'bot-insight' ) } ),
		] );
		const grid = el( 'div', { className: 'bi-kpis', 'aria-hidden': 'true' } );
		for ( let i = 0; i < 4; i++ ) {
			grid.appendChild( el( 'div', { className: 'bi-card bi-skeleton__block' } ) );
		}
		wrap.appendChild( grid );
		wrap.appendChild( el( 'div', { className: 'bi-card bi-skeleton__block bi-skeleton__block--tall', 'aria-hidden': 'true' } ) );
		return wrap;
	}

	function errorMessage( error ) {
		if ( error && typeof error.message === 'string' && error.message !== '' ) {
			return error.message;
		}
		if ( error && typeof error.status === 'number' ) {
			/* translators: %d: HTTP status code */
			return sprintf( __( 'The server answered with status %d.', 'bot-insight' ), error.status );
		}
		return '';
	}

	function errorNotice( heading, error, retry ) {
		const detail = errorMessage( error );
		const button = el( 'button', { type: 'button', className: 'button', text: __( 'Try again', 'bot-insight' ) } );
		button.addEventListener( 'click', retry );
		return el( 'div', { className: 'notice notice-error inline bi-notice' }, [
			el( 'p', null, [ el( 'strong', { text: heading } ), detail ? ' ' + detail : '' ] ),
			el( 'p', null, button ),
		] );
	}

	function exportData() {
		const period = state.period;
		if ( state.exportNotice ) {
			state.exportNotice.remove();
			state.exportNotice = null;
		}
		exportButton.disabled = true;
		status.textContent = __( 'Preparing export…', 'bot-insight' );

		apiFetch( { path: restPath + '/export?period=' + encodeURIComponent( period ), parse: false } )
			.then( function ( response ) {
				const disposition = response.headers.get( 'Content-Disposition' ) || '';
				// Plain `filename=` only; `filename*=` (RFC 5987) is ignored.
				const match = /(?:^|;)\s*filename="?([^";]+)"?/i.exec( disposition );
				const safe = match ? match[ 1 ].trim().replace( /[^\w.-]/g, '_' ) : '';
				const filename = safe && /[^._]/.test( safe ) ? safe : 'bot-insight-' + period + '.json';
				return response.blob().then( function ( blob ) {
					const url = URL.createObjectURL( blob );
					const link = el( 'a', { href: url, download: filename, className: 'screen-reader-text' } );
					document.body.appendChild( link );
					link.click();
					document.body.removeChild( link );
					window.setTimeout( function () {
						URL.revokeObjectURL( url );
					}, 1000 );
					/* translators: %s: file name */
					status.textContent = sprintf( __( 'Downloaded %s.', 'bot-insight' ), filename );
				} );
			} )
			.catch( function ( error ) {
				status.textContent = '';
				const finish = function ( detail ) {
					state.exportNotice = errorNotice( __( 'The export could not be created.', 'bot-insight' ), detail, exportData );
					root.insertBefore( state.exportNotice, content );
				};
				// With parse:false a failed request rejects with the raw Response.
				if ( error && typeof error.json === 'function' ) {
					error.json().then( finish, function () {
						finish( error );
					} );
				} else {
					finish( error );
				}
			} )
			.then( function () {
				exportButton.disabled = false;
			} );
	}

	/* ------------------------------------------------------------------ report */

	function renderReport( data ) {
		const totals = data.totals || {};
		const health = data.health || {};
		const hasData = ( Number( totals.requests ) || 0 ) > 0;

		const healthNotice = renderHealth( health );
		if ( healthNotice ) {
			content.appendChild( healthNotice );
		}
		content.appendChild( renderBanner( totals, hasData ) );

		if ( hasData ) {
			content.appendChild( renderKpis( totals ) );
			content.appendChild( renderChart( data.per_day || [] ) );
			content.appendChild(
				el( 'div', { className: 'bi-columns' }, [
					el( 'div', { className: 'bi-columns__main' }, renderVisitors( data.visitors || [] ) ),
					el( 'div', { className: 'bi-columns__side' }, [
						renderParams( data.params || [] ),
						renderCountries( data.countries || [] ),
					] ),
				] )
			);
			content.appendChild( renderSignals( data.signals || [], data.signal_breakdown || {} ) );
		}
		content.appendChild( renderFooter( data.overhead || {} ) );
	}

	function renderHealth( health ) {
		const lines = [];
		if ( health.buffer_writable === false ) {
			lines.push( __( 'Bot Insight cannot write to its buffer directory, so new requests are not being measured.', 'bot-insight' ) );
		}
		if ( typeof health.last_error === 'string' && health.last_error !== '' ) {
			/* translators: %s: error message */
			lines.push( sprintf( __( 'The last processing run reported an error: %s', 'bot-insight' ), health.last_error ) );
		}
		if ( ! lines.length ) {
			return null;
		}
		return el(
			'div',
			{ className: 'notice notice-warning inline bi-notice' },
			lines.map( function ( line ) {
				return el( 'p', { text: line } );
			} )
		);
	}

	function bannerSentence( share ) {
		const pct = fmtPct( share );
		switch ( state.period ) {
			case '24h':
				/* translators: %s: percentage, e.g. 82% */
				return sprintf( __( "Bots used %s of this site's PHP time in the last 24 hours.", 'bot-insight' ), pct );
			case '30d':
				/* translators: %s: percentage, e.g. 82% */
				return sprintf( __( "Bots used %s of this site's PHP time in the last 30 days.", 'bot-insight' ), pct );
			default:
				/* translators: %s: percentage, e.g. 82% */
				return sprintf( __( "Bots used %s of this site's PHP time in the last 7 days.", 'bot-insight' ), pct );
		}
	}

	function renderBanner( totals, hasData ) {
		if ( ! hasData ) {
			return el( 'div', { className: 'bi-banner bi-banner--collecting' }, [
				el( 'p', { className: 'bi-banner__lead', text: __( 'Collecting data — the first numbers appear within about 10 minutes.', 'bot-insight' ) } ),
			] );
		}
		return el( 'div', { className: 'bi-banner' }, [
			el( 'p', { className: 'bi-banner__lead', text: bannerSentence( totals.bot_share ) } ),
			el( 'p', {
				className: 'bi-banner__sub',
				text: sprintf(
					/* translators: 1: bot PHP hours, 2: total PHP hours */
					_n( 'That is %1$s of %2$s PHP hour.', 'That is %1$s of %2$s PHP hours.', shownHours( totals.php_hours ), 'bot-insight' ),
					fmtHours( totals.bot_php_hours ),
					fmtHours( totals.php_hours )
				),
			} ),
		] );
	}

	function kpi( label, value, sub, help ) {
		const labelNode = el( 'span', { className: 'bi-kpi__label' }, label );
		if ( help ) {
			const id = 'bi-help-' + Math.random().toString( 36 ).slice( 2 );
			const icon = el( 'span', { className: 'bi-help', tabindex: '0', role: 'img', 'aria-label': __( 'What is this?', 'bot-insight' ), 'aria-describedby': id, text: '?' } );
			const tip = el( 'span', { id: id, role: 'tooltip', className: 'bi-tooltip', text: help } );
			// Escape hides the tooltip without moving focus.
			icon.addEventListener( 'keydown', function ( event ) {
				if ( event.key === 'Escape' ) {
					tip.classList.add( 'bi-tooltip--dismissed' );
				}
			} );
			icon.addEventListener( 'blur', function () {
				tip.classList.remove( 'bi-tooltip--dismissed' );
			} );
			labelNode.appendChild( icon );
			labelNode.appendChild( tip );
		}
		return el( 'div', { className: 'bi-card bi-kpi' }, [
			labelNode,
			el( 'span', { className: 'bi-kpi__value', text: value } ),
			sub ? el( 'span', { className: 'bi-kpi__sub', text: sub } ) : null,
		] );
	}

	function renderKpis( totals ) {
		const clientDays = Number( totals.unique_client_days ) || 0;
		return el( 'div', { className: 'bi-kpis' }, [
			kpi(
				__( 'PHP hours', 'bot-insight' ),
				fmtHours( totals.php_hours ),
				/* translators: %s: number of requests */
				sprintf( _n( '%s request reached PHP', '%s requests reached PHP', Number( totals.requests ) || 0, 'bot-insight' ), fmtInt( totals.requests ) )
			),
			kpi(
				__( 'Bot share of PHP time', 'bot-insight' ),
				fmtPct( totals.bot_share ),
				sprintf(
					/* translators: %s: PHP hours */
					_n( '%s PHP hour used by bots', '%s PHP hours used by bots', shownHours( totals.bot_php_hours ), 'bot-insight' ),
					fmtHours( totals.bot_php_hours )
				)
			),
			kpi(
				__( 'Verified search requests', 'bot-insight' ),
				fmtInt( totals.verified_search_requests ),
				__( 'Search crawlers confirmed as genuine', 'bot-insight' )
			),
			kpi(
				__( 'Unique client-days', 'bot-insight' ),
				clientDays > 0 ? fmtInt( clientDays ) : '—',
				clientDays > 0 ? __( 'Counted over whole days (UTC)', 'bot-insight' ) : __( 'No whole day (UTC) in this period yet', 'bot-insight' ),
				__( 'A client-day is one network address seen on one day. An address seen on three days counts three times. Addresses are never stored in full.', 'bot-insight' )
			),
		] );
	}

	/* ------------------------------------------------------------------ chart */

	function classesIn( perDay ) {
		const seen = {};
		perDay.forEach( function ( day ) {
			Object.keys( day.by_class || {} ).forEach( function ( cls ) {
				seen[ cls ] = true;
			} );
		} );
		const known = CLASSES.filter( function ( cls ) {
			return seen[ cls ];
		} );
		const unknown = Object.keys( seen ).filter( function ( cls ) {
			return CLASSES.indexOf( cls ) < 0;
		} );
		return known.concat( unknown );
	}

	function dayTotal( day ) {
		return Object.keys( day.by_class || {} ).reduce( function ( sum, cls ) {
			return sum + ( Number( day.by_class[ cls ] ) || 0 );
		}, 0 );
	}

	function niceStep( raw ) {
		if ( raw <= 0 ) {
			return 1;
		}
		const power = Math.pow( 10, Math.floor( Math.log10( raw ) ) );
		const unit = raw / power;
		let nice = 10;
		if ( unit <= 1 ) {
			nice = 1;
		} else if ( unit <= 2 ) {
			nice = 2;
		} else if ( unit <= 5 ) {
			nice = 5;
		}
		return nice * power;
	}

	function renderChart( perDay ) {
		const section = card( __( 'PHP hours per day, by visitor type', 'bot-insight' ), 'bi-chart' );
		const classes = classesIn( perDay );

		const legend = el(
			'ul',
			{ className: 'bi-legend', 'aria-label': __( 'Legend', 'bot-insight' ) },
			classes.map( function ( cls ) {
				return el( 'li', null, [ swatch( cls ), classLabel( cls ) ] );
			} )
		);

		const figure = el( 'div', { className: 'bi-chart__figure' } );
		const tableWrap = el( 'div', { className: 'bi-chart__table', hidden: true } );
		tableWrap.appendChild( scroller( chartTable( perDay, classes ) ) );

		const toggle = el( 'button', { type: 'button', className: 'button-link bi-chart__toggle', 'aria-expanded': 'false', text: __( 'Show as table', 'bot-insight' ) } );
		toggle.addEventListener( 'click', function () {
			const open = tableWrap.hidden;
			tableWrap.hidden = ! open;
			toggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
			toggle.textContent = open ? __( 'Hide table', 'bot-insight' ) : __( 'Show as table', 'bot-insight' );
		} );

		append( section, [ el( 'div', { className: 'bi-chart__head' }, [ legend, toggle ] ), figure, tableWrap ] );

		const draw = function () {
			clear( figure );
			figure.appendChild( chartSvg( perDay, classes, figure.clientWidth || 640 ) );
		};
		draw();
		watchWidth( figure, draw );
		return section;
	}

	function watchWidth( node, draw ) {
		let width = 0;
		const redraw = function () {
			if ( ! node.isConnected ) {
				return;
			}
			const w = node.clientWidth;
			if ( w && Math.abs( w - width ) > 8 ) {
				width = w;
				draw();
			}
		};
		// The node is attached after this returns; draw at the real width once it is.
		window.requestAnimationFrame( redraw );
		if ( typeof window.ResizeObserver === 'function' ) {
			state.observer = new window.ResizeObserver( function () {
				window.clearTimeout( state.resizeTimer );
				state.resizeTimer = window.setTimeout( redraw, 100 );
			} );
			state.observer.observe( node );
		}
	}

	function chartSvg( perDay, classes, width ) {
		const W = Math.max( 280, Math.round( width ) );
		const H = 260;
		const pad = { top: 22, right: 8, bottom: 26, left: 48 };
		const plotW = W - pad.left - pad.right;
		const plotH = H - pad.top - pad.bottom;
		const totals = perDay.map( dayTotal );
		const max = Math.max.apply( null, totals.concat( [ 0 ] ) );
		const step = niceStep( max / 4 );
		const top = max > 0 ? Math.ceil( max / step ) * step : 1;
		const n = Math.max( 1, perDay.length );
		const slot = plotW / n;
		const barW = Math.max( 2, Math.min( 56, slot * 0.68 ) );
		const labelEvery = Math.max( 1, Math.ceil( n / Math.max( 1, Math.floor( plotW / 56 ) ) ) );
		const showTotals = slot >= 40;
		const y = function ( v ) {
			return pad.top + plotH - ( v / top ) * plotH;
		};

		const titleId = 'bi-chart-title-' + state.request;
		const descId = 'bi-chart-desc-' + state.request;
		const peak = totals.length ? totals.indexOf( max ) : -1;
		const node = svg( 'svg', {
			className: 'bi-chart__svg',
			width: W,
			height: H,
			viewBox: '0 0 ' + W + ' ' + H,
			role: 'img',
			'aria-labelledby': titleId + ' ' + descId,
		} );
		node.appendChild( svg( 'title', { id: titleId, text: __( 'PHP hours per day, by visitor type', 'bot-insight' ) } ) );
		node.appendChild(
			svg( 'desc', {
				id: descId,
				text:
					peak >= 0 && max > 0
						? [
								sprintf(
									/* translators: %s: number of days */
									_n( 'Stacked bars for %s day.', 'Stacked bars for %s days.', perDay.length, 'bot-insight' ),
									fmtInt( perDay.length )
								),
								sprintf(
									/* translators: 1: date, 2: PHP hours */
									_n( 'The busiest day was %1$s with %2$s PHP hour.', 'The busiest day was %1$s with %2$s PHP hours.', shownHours( max ), 'bot-insight' ),
									fmtDay( perDay[ peak ].day ),
									fmtHours( max )
								),
								__( 'Use "Show as table" for all values.', 'bot-insight' ),
						  ].join( ' ' )
						: __( 'No PHP time was recorded in this period.', 'bot-insight' ),
			} )
		);

		const grid = svg( 'g', { className: 'bi-chart__grid', 'aria-hidden': 'true' } );
		for ( let v = 0; v <= top + step / 2; v += step ) {
			const gy = Math.round( y( v ) ) + 0.5;
			grid.appendChild( svg( 'line', { x1: pad.left, x2: W - pad.right, y1: gy, y2: gy } ) );
			grid.appendChild( svg( 'text', { x: pad.left - 6, y: gy + 4, 'text-anchor': 'end', text: fmtHours( v ) + ' h' } ) );
		}
		node.appendChild( grid );

		const bars = svg( 'g', { className: 'bi-chart__bars' } );
		perDay.forEach( function ( day, i ) {
			const x = pad.left + slot * i + ( slot - barW ) / 2;
			const label = fmtDay( day.day );
			const group = svg( 'g' );
			let acc = 0;
			classes.forEach( function ( cls ) {
				const value = Number( ( day.by_class || {} )[ cls ] ) || 0;
				if ( value <= 0 ) {
					return;
				}
				const y0 = y( acc );
				const y1 = y( acc + value );
				acc += value;
				const rect = svg( 'rect', {
					className: 'bi-c-' + classKey( cls ),
					x: x.toFixed( 2 ),
					y: y1.toFixed( 2 ),
					width: barW.toFixed( 2 ),
					height: Math.max( 0.5, y0 - y1 ).toFixed( 2 ),
				} );
				rect.appendChild(
					svg( 'title', {
						text: sprintf(
							/* translators: 1: date, 2: visitor type, 3: PHP hours */
							_n( '%1$s · %2$s: %3$s PHP hour', '%1$s · %2$s: %3$s PHP hours', shownHours( value ), 'bot-insight' ),
							label,
							classLabel( cls ),
							fmtHours( value )
						),
					} )
				);
				group.appendChild( rect );
			} );
			if ( showTotals && totals[ i ] > 0 ) {
				group.appendChild(
					svg( 'text', { className: 'bi-chart__total', x: ( x + barW / 2 ).toFixed( 2 ), y: ( y( totals[ i ] ) - 6 ).toFixed( 2 ), 'text-anchor': 'middle', 'aria-hidden': 'true', text: fmtHours( totals[ i ] ) } )
				);
			}
			if ( i % labelEvery === 0 ) {
				group.appendChild(
					svg( 'text', { className: 'bi-chart__day', x: ( x + barW / 2 ).toFixed( 2 ), y: H - 8, 'text-anchor': 'middle', 'aria-hidden': 'true', text: label } )
				);
			}
			bars.appendChild( group );
		} );
		node.appendChild( bars );
		node.appendChild( svg( 'line', { className: 'bi-chart__axis', x1: pad.left, x2: W - pad.right, y1: y( 0 ) + 0.5, y2: y( 0 ) + 0.5, 'aria-hidden': 'true' } ) );
		return node;
	}

	function chartTable( perDay, classes ) {
		const columns = [ { label: __( 'Day', 'bot-insight' ) } ]
			.concat(
				classes.map( function ( cls ) {
					return { label: classLabel( cls ), numeric: true };
				} )
			)
			.concat( [ { label: __( 'Total', 'bot-insight' ), numeric: true } ] );
		const rows = perDay.map( function ( day ) {
			return [ fmtDay( day.day ) ]
				.concat(
					classes.map( function ( cls ) {
						return fmtHours( ( day.by_class || {} )[ cls ] || 0 );
					} )
				)
				.concat( [ fmtHours( dayTotal( day ) ) ] );
		} );
		return dataTable( __( 'PHP hours per day, by visitor type', 'bot-insight' ), columns, rows );
	}

	/* ------------------------------------------------------------------ tables */

	function badge( text, modifier, title ) {
		return el( 'span', { className: 'bi-badge bi-badge--' + modifier, title: title || null, text: text } );
	}

	function verifiedCell( verified ) {
		const v = verified || {};
		const parts = [
			[ 'yes', __( 'Yes', 'bot-insight' ) ],
			[ 'no', __( 'No', 'bot-insight' ) ],
			[ 'unchecked', __( 'Unchecked', 'bot-insight' ) ],
		].filter( function ( part ) {
			return ( Number( v[ part[ 0 ] ] ) || 0 ) > 0;
		} );
		if ( ! parts.length ) {
			return el( 'span', { className: 'bi-muted', title: __( 'Not applicable: no bot identity was claimed.', 'bot-insight' ), text: '—' } );
		}
		if ( parts.length === 1 ) {
			return el( 'span', { className: 'bi-verified bi-verified--' + parts[ 0 ][ 0 ], text: parts[ 0 ][ 1 ] } );
		}
		return el(
			'span',
			{ className: 'bi-verified' },
			parts.map( function ( part, i ) {
				return el( 'span', { className: 'bi-verified--' + part[ 0 ], text: ( i ? ' · ' : '' ) + part[ 1 ] + ' ' + fmtInt( v[ part[ 0 ] ] ) } );
			} )
		);
	}

	function renderVisitors( visitors ) {
		const section = card( __( 'Who is visiting', 'bot-insight' ), 'bi-visitors' );
		if ( ! visitors.length ) {
			section.appendChild( el( 'p', { className: 'bi-muted', text: __( 'No visitors were recorded in this period.', 'bot-insight' ) } ) );
			return section;
		}
		const columns = [
			{ label: __( 'Visitor', 'bot-insight' ) },
			{ label: __( 'Type', 'bot-insight' ) },
			{ label: __( 'Requests', 'bot-insight' ), numeric: true },
			{ label: __( 'PHP hours', 'bot-insight' ), numeric: true },
			{ label: __( 'Verified', 'bot-insight' ) },
			{ label: __( 'Findability', 'bot-insight' ) },
		];
		const rows = visitors.map( function ( row ) {
			const name = el( 'span', { className: 'bi-visitor' }, [ String( row.bot || '' ) ] );
			if ( row.impostor ) {
				name.appendChild(
					badge( __( 'Failed verification', 'bot-insight' ), 'warn', __( 'These requests claimed to be this bot, but their address does not belong to it.', 'bot-insight' ) )
				);
			}
			return [
				name,
				el( 'span', { className: 'bi-type' }, [ swatch( row.class ), classLabel( row.class ) ] ),
				fmtInt( row.requests ),
				fmtHours( row.php_hours ),
				verifiedCell( row.verified ),
				row.findability
					? badge( __( 'Search or AI findability', 'bot-insight' ), 'good', __( 'This crawler feeds search results or AI answers that can send visitors to this site.', 'bot-insight' ) )
					: '',
			];
		} );
		const table = dataTable( __( 'Who is visiting', 'bot-insight' ), columns, rows );
		section.appendChild( scroller( table ) );

		if ( visitors.length > VISITOR_ROWS ) {
			const extra = Array.prototype.slice.call( table.tBodies[ 0 ].rows, VISITOR_ROWS );
			const toggle = el( 'button', { type: 'button', className: 'button-link bi-more', 'aria-expanded': 'false' } );
			const set = function ( open ) {
				extra.forEach( function ( tr ) {
					tr.hidden = ! open;
				} );
				toggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
				toggle.textContent = open
					? __( 'Show fewer', 'bot-insight' )
					: sprintf(
							/* translators: %s: number of rows */
							_n( 'Show all %s row', 'Show all %s rows', visitors.length, 'bot-insight' ),
							fmtInt( visitors.length )
					  );
			};
			toggle.addEventListener( 'click', function () {
				set( toggle.getAttribute( 'aria-expanded' ) !== 'true' );
			} );
			set( false );
			section.appendChild( toggle );
		}
		return section;
	}

	/** Parameter names in <code>, with line-break opportunities after the commas only. */
	function paramCode( label ) {
		const code = el( 'code' );
		label.split( ',' ).forEach( function ( name, i ) {
			if ( i ) {
				code.appendChild( document.createTextNode( ',' ) );
				code.appendChild( el( 'wbr' ) );
			}
			code.appendChild( document.createTextNode( name ) );
		} );
		return code;
	}

	function renderParams( params ) {
		const section = card( __( 'Query parameters reaching PHP', 'bot-insight' ), 'bi-params' );
		section.appendChild( el( 'p', { className: 'bi-card__intro', text: __( 'Parameter names only; values are never stored.', 'bot-insight' ) } ) );
		if ( ! params.length ) {
			section.appendChild( el( 'p', { className: 'bi-muted', text: __( 'No requests in this period.', 'bot-insight' ) } ) );
			return section;
		}
		const rows = params.map( function ( row ) {
			const label = String( row.params || '' );
			// "(none)" and "(other combinations)" are labels, not parameter names.
			const isLabel = /^\(.*\)$/.test( label );
			return [ isLabel ? el( 'span', { className: 'bi-muted', text: label } ) : paramCode( label ), fmtInt( row.requests ), fmtHours( row.php_hours ), fmtPct( row.bot_share ) ];
		} );
		section.appendChild(
			scroller(
				dataTable(
					__( 'Query parameters reaching PHP', 'bot-insight' ),
					[
						{ label: __( 'Parameters', 'bot-insight' ) },
						{ label: __( 'Requests', 'bot-insight' ), numeric: true },
						{ label: __( 'PHP hours', 'bot-insight' ), numeric: true },
						{ label: __( 'Bot share', 'bot-insight' ), numeric: true },
					],
					rows
				)
			)
		);
		return section;
	}

	function renderCountries( countries ) {
		const section = card( __( 'Where unknown bots come from', 'bot-insight' ), 'bi-countries' );
		section.appendChild( el( 'p', { className: 'bi-card__intro', text: __( 'Unknown bots, suspected automated clients and script libraries.', 'bot-insight' ) } ) );
		if ( ! countries.length ) {
			section.appendChild( el( 'p', { className: 'bi-muted', text: __( 'No unknown bots were recorded in this period.', 'bot-insight' ) } ) );
			return section;
		}
		const rows = countries.map( function ( row ) {
			const code = String( row.country || '' );
			const name = countryName( code );
			return [ name === code || code === '??' ? name : name + ' (' + code + ')', fmtInt( row.requests ), fmtHours( row.php_hours ) ];
		} );
		section.appendChild(
			scroller(
				dataTable(
					__( 'Where unknown bots come from', 'bot-insight' ),
					[
						{ label: __( 'Country', 'bot-insight' ) },
						{ label: __( 'Requests', 'bot-insight' ), numeric: true },
						{ label: __( 'PHP hours', 'bot-insight' ), numeric: true },
					],
					rows
				)
			)
		);
		return section;
	}

	function renderSignals( signals, breakdown ) {
		const section = card( __( 'Observations', 'bot-insight' ), 'bi-signals' );
		if ( signals.length ) {
			section.appendChild(
				el(
					'ul',
					{ className: 'bi-signal-list' },
					signals.map( function ( signal ) {
						const notice = signal.severity === 'notice';
						return el( 'li', { className: 'bi-signal bi-signal--' + ( notice ? 'notice' : 'info' ) }, [
							el( 'span', { className: 'bi-signal__level', text: notice ? __( 'Notice', 'bot-insight' ) : __( 'Info', 'bot-insight' ) } ),
							el( 'span', { className: 'bi-signal__title', text: String( signal.title || '' ) } ),
						] );
					} )
				)
			);
		} else {
			section.appendChild( el( 'p', { className: 'bi-muted', text: __( 'Nothing notable was found in this period.', 'bot-insight' ) } ) );
		}

		section.appendChild( el( 'h3', { className: 'bi-card__subtitle', text: __( 'Suspected automated: signals that fired', 'bot-insight' ) } ) );
		const slugs = Object.keys( SIGNAL_LABELS ).concat(
			Object.keys( breakdown ).filter( function ( slug ) {
				return ! SIGNAL_LABELS[ slug ];
			} )
		);
		const fired = slugs.filter( function ( slug ) {
			return ( Number( breakdown[ slug ] ) || 0 ) > 0;
		} );
		if ( ! fired.length ) {
			section.appendChild( el( 'p', { className: 'bi-muted', text: __( 'No suspected automated requests were sampled in this period.', 'bot-insight' ) } ) );
			return section;
		}
		section.appendChild(
			el(
				'ul',
				{ className: 'bi-breakdown' },
				fired.map( function ( slug ) {
					return el( 'li', null, [ el( 'span', { text: SIGNAL_LABELS[ slug ] || slug } ), el( 'span', { className: 'bi-num', text: fmtInt( breakdown[ slug ] ) } ) ] );
				} )
			)
		);
		section.appendChild( el( 'p', { className: 'bi-card__intro', text: __( 'Estimated requests, from sampled requests. One request can fire several signals.', 'bot-insight' ) } ) );
		return section;
	}

	function renderFooter( overhead ) {
		const us = Number( overhead.p95_us ) || 0;
		const days = Number( overhead.days ) || 0;
		const measured =
			us > 0 && days > 0
				? sprintf(
						/* translators: 1: milliseconds, 2: number of days */
						_n( 'Measured overhead: %1$s ms per request (p95, last %2$s day).', 'Measured overhead: %1$s ms per request (p95, last %2$s days).', days, 'bot-insight' ),
						fmtMs( us ),
						fmtInt( days )
				  )
				: __( 'Measured overhead: not measured yet.', 'bot-insight' );
		return el( 'div', { className: 'bi-footer' }, [
			el( 'p', { text: __( 'Bot Insight stores totals per hour. It never stores full IP addresses or query values, and all data stays on this site.', 'bot-insight' ) + ' ' + measured } ),
			el( 'p', { text: __( 'Requests answered by a page cache or CDN never reach PHP and are not counted.', 'bot-insight' ) } ),
			config.version
				? el( 'p', {
						className: 'bi-muted',
						/* translators: %s: plugin version */
						text: sprintf( __( 'Bot Insight %s', 'bot-insight' ), String( config.version ) ),
				  } )
				: null,
		] );
	}

	load();
} )();
