/**
 * ZG Smart Search - admin widget "vyloučené produkty".
 */
( function () {
	'use strict';

	if ( typeof window.ZGSS_ADMIN === 'undefined' ) {
		return;
	}

	var CFG = window.ZGSS_ADMIN;

	function debounce( fn, wait ) {
		var t;
		return function () {
			var args = arguments;
			clearTimeout( t );
			t = setTimeout( function () {
				fn.apply( null, args );
			}, wait );
		};
	}

	/**
	 * Widget pro výběr produktů (textové hledání + chipy) - generický,
	 * podporuje víc instancí na stránce (Vyloučit produkty, Ruční výběr
	 * doporučených…), rozlišených přes data-field na kontejneru.
	 */
	document.addEventListener( 'DOMContentLoaded', function () {
		function initProductPicker( container ) {
			var input       = container.querySelector( '.zgss-pp-input' );
			var suggestions = container.querySelector( '.zgss-pp-suggestions' );
			var chips       = container.querySelector( '.zgss-pp-chips' );
			var fieldName   = container.getAttribute( 'data-field' );

			if ( ! input || ! suggestions || ! chips || ! fieldName ) {
				return;
			}

			function existingIds() {
				return Array.prototype.map.call(
					chips.querySelectorAll( '.zgss-pp-chip' ),
					function ( el ) { return el.getAttribute( 'data-id' ); }
				);
			}

			function addChip( id, title ) {
				if ( existingIds().indexOf( String( id ) ) !== -1 ) {
					return;
				}
				var chip = document.createElement( 'span' );
				chip.className = 'zgss-pp-chip';
				chip.setAttribute( 'data-id', id );
				chip.innerHTML = title.replace( /</g, '&lt;' ) +
					' <input type="hidden" name="' + fieldName + '[]" value="' + id + '" />' +
					'<button type="button" class="zgss-pp-chip__remove" aria-label="Odebrat">&times;</button>';
				chips.appendChild( chip );
			}

			chips.addEventListener( 'click', function ( e ) {
				if ( e.target.classList.contains( 'zgss-pp-chip__remove' ) ) {
					e.target.closest( '.zgss-pp-chip' ).remove();
				}
			} );

			var doSearch = debounce( function ( term ) {
				if ( term.length < 2 ) {
					suggestions.hidden = true;
					suggestions.innerHTML = '';
					return;
				}

				var url = CFG.ajaxUrl + '?action=zgss_admin_search_products&nonce=' +
					encodeURIComponent( CFG.nonce ) + '&term=' + encodeURIComponent( term );

				fetch( url, { credentials: 'same-origin' } )
					.then( function ( res ) { return res.json(); } )
					.then( function ( json ) {
						if ( ! json || ! json.success ) {
							return;
						}
						var items = json.data || [];
						if ( ! items.length ) {
							suggestions.hidden = true;
							suggestions.innerHTML = '';
							return;
						}
						suggestions.innerHTML = items.map( function ( p ) {
							return '<button type="button" data-id="' + p.id + '" data-title="' +
								p.text.replace( /"/g, '&quot;' ) + '">' + p.text.replace( /</g, '&lt;' ) + '</button>';
						} ).join( '' );
						suggestions.hidden = false;
					} )
					.catch( function () {
						suggestions.hidden = true;
					} );
			}, 300 );

			input.addEventListener( 'input', function () {
				doSearch( input.value.trim() );
			} );

			suggestions.addEventListener( 'click', function ( e ) {
				var btn = e.target.closest( 'button[data-id]' );
				if ( ! btn ) {
					return;
				}
				addChip( btn.getAttribute( 'data-id' ), btn.getAttribute( 'data-title' ) );
				input.value = '';
				suggestions.hidden = true;
				suggestions.innerHTML = '';
			} );

			document.addEventListener( 'click', function ( e ) {
				if ( e.target !== input && ! suggestions.contains( e.target ) ) {
					suggestions.hidden = true;
				}
			} );
		}

		document.querySelectorAll( '.zgss-product-picker' ).forEach( initProductPicker );

		// Ruční výběr doporučených produktů se zobrazí jen v odpovídajícím režimu.
		var recModeRadios = document.querySelectorAll( '.zgss-rec-mode-radio' );
		var manualPicker  = document.querySelector( '.zgss-rec-manual-picker' );
		if ( recModeRadios.length && manualPicker ) {
			var toggleManualPicker = function () {
				var checked = document.querySelector( '.zgss-rec-mode-radio:checked' );
				manualPicker.style.display = ( checked && 'manual' === checked.value ) ? '' : 'none';
			};
			recModeRadios.forEach( function ( radio ) {
				radio.addEventListener( 'change', toggleManualPicker );
			} );
			toggleManualPicker();
		}
	} );

	/**
	 * Záložka "Elasticsearch" - přepínání polí podle typu autentizace,
	 * tlačítka test/reindex a živé grafy CPU/paměti/JVM heap.
	 */
	document.addEventListener( 'DOMContentLoaded', function () {
		var authSelect = document.getElementById( 'es_auth_type' );
		if ( authSelect ) {
			var basicFields = document.querySelector( '.zgss-es-auth-basic' );
			var apiKeyFields = document.querySelector( '.zgss-es-auth-apikey' );
			var toggleAuthFields = function () {
				if ( basicFields ) { basicFields.style.display = ( 'basic' === authSelect.value ) ? '' : 'none'; }
				if ( apiKeyFields ) { apiKeyFields.style.display = ( 'api_key' === authSelect.value ) ? '' : 'none'; }
			};
			authSelect.addEventListener( 'change', toggleAuthFields );
			toggleAuthFields();
		}

		var testBtn      = document.getElementById( 'zgss-es-test' );
		var reindexBtn   = document.getElementById( 'zgss-es-reindex' );
		var actionResult = document.getElementById( 'zgss-es-action-result' );

		function callAjax( action, onDone ) {
			var body = new URLSearchParams();
			body.set( 'action', action );
			body.set( 'nonce', CFG.nonce );

			fetch( CFG.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: body.toString(),
			} )
				.then( function ( res ) { return res.json(); } )
				.then( function ( json ) { onDone( json && json.success ? json.data : null, json ); } )
				.catch( function () { onDone( null, null ); } );
		}

		if ( testBtn && actionResult ) {
			testBtn.addEventListener( 'click', function () {
				testBtn.disabled = true;
				actionResult.textContent = 'Testuji připojení…';
				callAjax( 'zgss_es_test', function ( data ) {
					testBtn.disabled = false;
					if ( data && data.ok ) {
						actionResult.textContent = '✅ ' + data.message;
					} else {
						actionResult.textContent = '❌ ' + ( data && data.message ? data.message : 'Test se nezdařil.' );
					}
				} );
			} );
		}

		if ( reindexBtn && actionResult ) {
			reindexBtn.addEventListener( 'click', function () {
				reindexBtn.disabled = true;
				actionResult.textContent = 'Indexuji produkty, může to chvíli trvat…';
				callAjax( 'zgss_es_reindex', function ( data ) {
					reindexBtn.disabled = false;
					if ( data && data.ok ) {
						actionResult.textContent = '✅ ' + data.message;
					} else {
						actionResult.textContent = '❌ ' + ( data && data.message ? data.message : 'Reindexace se nezdařila.' );
					}
				} );
			} );
		}

		var metricsPanel = document.getElementById( 'zgss-es-metrics' );
		if ( ! metricsPanel ) {
			return;
		}

		var HISTORY_LEN = 30;
		var history = { cpu: [], mem: [], heap: [] };

		function drawSpark( canvas, values ) {
			if ( ! canvas || ! canvas.getContext ) {
				return;
			}
			var ctx = canvas.getContext( '2d' );
			var w = canvas.width;
			var h = canvas.height;
			ctx.clearRect( 0, 0, w, h );
			if ( values.length < 2 ) {
				return;
			}
			ctx.beginPath();
			ctx.strokeStyle = '#2271b1';
			ctx.lineWidth = 2;
			values.forEach( function ( v, i ) {
				var x = ( i / ( HISTORY_LEN - 1 ) ) * w;
				var y = h - ( Math.max( 0, Math.min( 100, v ) ) / 100 ) * h;
				if ( 0 === i ) { ctx.moveTo( x, y ); } else { ctx.lineTo( x, y ); }
			} );
			ctx.stroke();
		}

		function fillClass( v ) {
			if ( v >= 90 ) { return 'is-crit'; }
			if ( v >= 70 ) { return 'is-warn'; }
			return '';
		}

		function updateGauge( key, value ) {
			var fill = document.getElementById( 'zgss-es-' + key + '-fill' );
			var num  = document.getElementById( 'zgss-es-' + key + '-num' );
			if ( fill ) {
				fill.style.width = value + '%';
				fill.className = 'zgss-es-gauge__fill ' + fillClass( value );
			}
			if ( num ) {
				num.textContent = value + '%';
			}
			history[ key ].push( value );
			if ( history[ key ].length > HISTORY_LEN ) {
				history[ key ].shift();
			}
			drawSpark( document.getElementById( 'zgss-es-' + key + '-spark' ), history[ key ] );
		}

		function refreshStats() {
			callAjax( 'zgss_es_stats', function ( data ) {
				var nodeNameEl = document.getElementById( 'zgss-es-node-name' );
				if ( ! data || ! data.ok || ! data.nodes || ! data.nodes.length ) {
					if ( nodeNameEl ) {
						nodeNameEl.textContent = data && data.message ? data.message : 'Metriky nejsou k dispozici.';
					}
					return;
				}
				var node = data.nodes[ 0 ];
				updateGauge( 'cpu', node.cpu_percent || 0 );
				updateGauge( 'mem', node.mem_percent || 0 );
				updateGauge( 'heap', node.jvm_heap_percent || 0 );
				if ( nodeNameEl ) {
					nodeNameEl.textContent = 'Uzel: ' + node.name + ' · aktualizováno ' + ( data.time || '' );
				}
			} );
		}

		refreshStats();
		setInterval( refreshStats, 5000 );
	} );
	/**
	 * Záložka "Nastavení" - šipky nahoru/dolů pro řazení bloků (Desktop /
	 * Mobil a tablet). Přesunutí <li> v DOM stačí - skryté input[name]
	 * uvnitř se přesune s ním, takže se pořadí odešle správně při submitu.
	 */
	document.addEventListener( 'DOMContentLoaded', function () {
		document.querySelectorAll( '.zgss-order-list' ).forEach( function ( list ) {
			list.addEventListener( 'click', function ( e ) {
				var li = e.target.closest( 'li' );
				if ( ! li ) {
					return;
				}
				if ( e.target.closest( '.zgss-order-up' ) && li.previousElementSibling ) {
					list.insertBefore( li, li.previousElementSibling );
				} else if ( e.target.closest( '.zgss-order-down' ) && li.nextElementSibling ) {
					list.insertBefore( li.nextElementSibling, li );
				}
			} );
		} );
	} );

} )();
