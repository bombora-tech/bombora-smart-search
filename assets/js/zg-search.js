/**
 * ZG Smart Search - frontend logika.
 * Vanilla JS bez závislostí, funguje s libovolným počtem instancí na stránce.
 */
( function () {
	'use strict';

	if ( typeof window.ZGSS === 'undefined' ) {
		return;
	}

	var CFG = window.ZGSS;

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

	function escapeHtml( str ) {
		if ( ! str ) {
			return '';
		}
		return String( str )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' );
	}

	// --- Zvýraznění shodné části názvu (podobně jako Magrano: světle zelené
	// pozadí, tmavě zelený text), včetně tolerance na překlepy a diakritiku.

	function stripDiacritics( str ) {
		if ( ! str ) {
			return '';
		}
		var s = String( str );
		if ( s.normalize ) {
			s = s.normalize( 'NFD' ).replace( /[\u0300-\u036f]/g, '' );
		}
		return s.toLowerCase();
	}

	function levenshtein( a, b ) {
		if ( a === b ) {
			return 0;
		}
		var al = a.length;
		var bl = b.length;
		if ( 0 === al ) {
			return bl;
		}
		if ( 0 === bl ) {
			return al;
		}
		var prev = [];
		for ( var j = 0; j <= bl; j++ ) {
			prev[ j ] = j;
		}
		for ( var i = 1; i <= al; i++ ) {
			var cur = [ i ];
			for ( var k = 1; k <= bl; k++ ) {
				var cost = a.charAt( i - 1 ) === b.charAt( k - 1 ) ? 0 : 1;
				cur[ k ] = Math.min( prev[ k ] + 1, cur[ k - 1 ] + 1, prev[ k - 1 ] + cost );
			}
			prev = cur;
		}
		return prev[ bl ];
	}

	/**
	 * Vrátí pole [start, end) rozsahů v původním titulku, které odpovídají
	 * některému slovu z dotazu (přesná shoda, prefix, nebo tolerance na
	 * překlep u delších slov). Diakritika se ignoruje, délka řetězce se
	 * stripDiacritics zachovává 1:1, takže indexy sedí i na původní text.
	 */
	function matchRanges( title, rawQuery ) {
		var strippedTitle = stripDiacritics( title );
		var qWords = stripDiacritics( rawQuery ).split( /\s+/ ).filter( function ( w ) { return w.length > 1; } );
		var ranges = [];

		qWords.forEach( function ( qw ) {
			var idx = strippedTitle.indexOf( qw );
			if ( idx !== -1 ) {
				ranges.push( [ idx, idx + qw.length ] );
				return;
			}

			// Fuzzy fallback: porovnej dotaz s jednotlivými slovy titulku.
			var wordRe = /[a-z0-9]+/g;
			var m;
			var bestDist = Infinity;
			var bestRange = null;
			while ( ( m = wordRe.exec( strippedTitle ) ) !== null ) {
				var fw = m[ 0 ];
				var len = Math.max( qw.length, fw.length );
				if ( len < 4 ) {
					continue;
				}
				var threshold = len <= 6 ? 1 : ( len <= 10 ? 2 : 3 );
				var dist = levenshtein( qw, fw );
				if ( dist <= threshold && dist < bestDist ) {
					bestDist = dist;
					bestRange = [ m.index, m.index + fw.length ];
				}
			}
			if ( bestRange ) {
				ranges.push( bestRange );
			}
		} );

		// Seřadit a sloučit případné překryvy.
		ranges.sort( function ( a, b ) { return a[ 0 ] - b[ 0 ]; } );
		var merged = [];
		ranges.forEach( function ( r ) {
			var last = merged[ merged.length - 1 ];
			if ( last && r[ 0 ] <= last[ 1 ] ) {
				last[ 1 ] = Math.max( last[ 1 ], r[ 1 ] );
			} else {
				merged.push( r );
			}
		} );
		return merged;
	}

	function highlightTitle( title, rawQuery ) {
		if ( ! rawQuery ) {
			return escapeHtml( title );
		}
		var ranges = matchRanges( title, rawQuery );
		if ( ! ranges.length ) {
			return escapeHtml( title );
		}
		var out = '';
		var pos = 0;
		ranges.forEach( function ( r ) {
			out += escapeHtml( title.slice( pos, r[ 0 ] ) );
			out += '<mark class="zgss-hl">' + escapeHtml( title.slice( r[ 0 ], r[ 1 ] ) ) + '</mark>';
			pos = r[ 1 ];
		} );
		out += escapeHtml( title.slice( pos ) );
		return out;
	}

	function productCard( p, rawQuery ) {
		var badges = '';
		if ( CFG.showBestseller && p.is_bestseller ) {
			badges += '<span class="zgss-card__label zgss-card__label--bestseller">' + escapeHtml( CFG.bestsellerLabel ) + '</span>';
		}
		if ( p.on_sale && p.sale_percent > 0 ) {
			badges += '<span class="zgss-card__label zgss-card__label--sale">-' + p.sale_percent + '%</span>';
		}
		var stock;
		if ( 'onbackorder' === p.stock_status ) {
			stock = '<span class="zgss-card__stock zgss-card__stock--backorder">' + escapeHtml( CFG.i18n.onBackorder || 'Předobjednávka' ) + '</span>';
		} else if ( p.in_stock ) {
			stock = '<span class="zgss-card__stock zgss-card__stock--in">' + escapeHtml( CFG.i18n.inStock ) + '</span>';
		} else {
			stock = '<span class="zgss-card__stock zgss-card__stock--out">' + escapeHtml( CFG.i18n.outOfStock ) + '</span>';
		}

		return (
			'<a class="zgss-card" href="' + escapeHtml( p.permalink ) + '">' +
				( badges ? '<span class="zgss-card__badges">' + badges + '</span>' : '' ) +
				'<span class="zgss-card__img"><img src="' + escapeHtml( p.image ) + '" alt="' + escapeHtml( p.title ) + '" loading="lazy" /></span>' +
				'<span class="zgss-card__title">' + highlightTitle( p.title, rawQuery || '' ) + '</span>' +
				'<span class="zgss-card__meta">' + stock + '<span class="zgss-card__price">' + p.price_html + '</span></span>' +
			'</a>'
		);
	}

	function chip( term ) {
		return '<button type="button" class="zgss-chip" data-term="' + escapeHtml( term ) + '">' + escapeHtml( term ) + '</button>';
	}

	function categoryRow( cat ) {
		return '<a class="zgss-cat" href="' + escapeHtml( cat.url ) + '">' + escapeHtml( cat.name ) + '<span class="zgss-cat__count">' + cat.count + '</span></a>';
	}

	/**
	 * Vykreslí jednu klikací "chip" hodnotu vlastnosti (např. "Červené").
	 * Aktivní (zvolený) stav se řídí `activeSlugs` (Set-like pole slugů).
	 */
	function attrChip( taxonomy, term, activeSlugs ) {
		var isActive = activeSlugs.indexOf( term.slug ) !== -1;
		return '<button type="button" class="zgss-chip zgss-chip--attr' + ( isActive ? ' is-active' : '' ) + '" data-tax="' +
			escapeHtml( taxonomy ) + '" data-term="' + escapeHtml( term.slug ) + '">' +
			escapeHtml( term.name ) + '<span class="zgss-cat__count">' + term.count + '</span></button>';
	}

	/**
	 * Sestaví jeden HTML blok pro danou skupinu filtrů (jednu vlastnost -
	 * např. "Barva"). Volá se zvlášť pro každou vlastnost, aby šlo každou
	 * zařadit samostatně podle admin nastaveného pořadí (sidebar_order /
	 * mobile_order) - ne jako jeden spojený "balík" filtrů.
	 */
	function attrGroupBlock( group, activeFilters ) {
		var active = ( activeFilters && activeFilters[ group.taxonomy ] ) || [];
		return '<div class="zgss-col zgss-block--attrs" data-tax="' + escapeHtml( group.taxonomy ) + '">' +
			'<div class="zgss-block-title zgss-block-title--sub">' + escapeHtml( group.label ) + '</div>' +
			'<div class="zgss-chips zgss-chips--attr">' +
				group.terms.map( function ( t ) { return attrChip( group.taxonomy, t, active ); } ).join( '' ) +
			'</div>' +
		'</div>';
	}

	/**
	 * Sestaví obsah dropdownu. Bloky (Oblíbená vyhledávání, Kategorie,
	 * jednotlivé vlastnosti, Výsledky) se poskládají podle pořadí, které si
	 * admin nastavil (data.sidebar_order pro desktop, data.mobile_order pro
	 * mobil/tablet - viz záložka Nastavení). Na desktopu tvoří všechno kromě
	 * výsledků JEDNU grid buňku vlevo (viz .zgss-sidebar v CSS - řeší to
	 * bug, kdy by se řádek natahoval podle výšky výsledků). Na mobilu/
	 * tabletu se řadí úplně plocho (flex column), takže tam může být
	 * "main" (výsledky) na libovolné pozici, ne jen dole.
	 */
	function buildDropdown( root, data, hasQuery, rawQuery ) {
		var dropdown = root._zgssDropdown;
		var inner = dropdown ? dropdown.querySelector( '.zgss-search__dropdown-inner' ) : null;
		if ( ! inner ) {
			return;
		}

		var activeFilters = root._zgssFilters || {};
		var hasActiveFilters = Object.keys( activeFilters ).some( function ( k ) {
			return activeFilters[ k ] && activeFilters[ k ].length;
		} );

		var blocks = {};

		if ( data.popular && data.popular.length ) {
			blocks.popular = '<div class="zgss-col zgss-block--popular">' +
				'<div class="zgss-block-title">' + escapeHtml( CFG.i18n.popularSearches ) + '</div>' +
				'<div class="zgss-chips">' + data.popular.map( function ( row ) { return chip( row.term ); } ).join( '' ) + '</div>' +
			'</div>';
		}

		if ( data.categories && data.categories.length ) {
			blocks.categories = '<div class="zgss-col zgss-block--categories">' +
				'<div class="zgss-block-title">' + escapeHtml( CFG.i18n.categories ) + '</div>' +
				'<div class="zgss-cats">' + data.categories.map( categoryRow ).join( '' ) + '</div>' +
			'</div>';
		}

		( data.attribute_filters || [] ).forEach( function ( group ) {
			if ( group.terms && group.terms.length ) {
				blocks[ group.taxonomy ] = attrGroupBlock( group, activeFilters );
			}
		} );

		var right = '<div class="zgss-col zgss-col--main">';

		var cardWithQuery = function ( p ) { return productCard( p, rawQuery ); };

		if ( hasQuery ) {
			if ( data.products && data.products.length ) {
				right += '<div class="zgss-block-title">' + escapeHtml( CFG.i18n.products ) + '</div>';
				right += '<div class="zgss-grid">' + data.products.map( cardWithQuery ).join( '' ) + '</div>';
				if ( data.more_url ) {
					right += '<a class="zgss-more" href="' + escapeHtml( data.more_url ) + '">' + escapeHtml( CFG.i18n.moreResults ) + '</a>';
				}
			} else {
				right += '<div class="zgss-empty">' + escapeHtml( CFG.i18n.noResults ) + '</div>';
				// Nic se nenašlo - nabídneme aspoň relevantní alternativy
				// (bestsellery v rámci aktivních filtrů, pokud jsou -
				// zajišťuje to backend, viz ZGSS_Ajax::get_recommendations()).
				if ( data.suggestions && data.suggestions.length ) {
					right += '<div class="zgss-block-title zgss-block-title--sub">' + escapeHtml( CFG.i18n.recommended ) + '</div>';
					right += '<div class="zgss-grid">' + data.suggestions.map( function ( p ) { return productCard( p, '' ); } ).join( '' ) + '</div>';
				}
			}
		} else if ( data.suggestions && data.suggestions.length ) {
			right += '<div class="zgss-block-title">' + escapeHtml( CFG.i18n.recommended ) + '</div>';
			right += '<div class="zgss-grid">' + data.suggestions.map( function ( p ) { return productCard( p, '' ); } ).join( '' ) + '</div>';
		}

		if ( hasQuery && data.suggestions && data.suggestions.length && data.products && data.products.length ) {
			right += '<div class="zgss-block-title zgss-block-title--sub">' + escapeHtml( CFG.i18n.youMayLike ) + '</div>';
			right += '<div class="zgss-grid zgss-grid--sub">' + data.suggestions.map( function ( p ) { return productCard( p, '' ); } ).join( '' ) + '</div>';
		}

		right += '</div>';
		blocks.main = right;

		// Poskládat bloky v pořadí, tlačítko "Zrušit filtry" přilepit k
		// prvnímu bloku vlastnosti, na který v pořadí narazíme.
		function assemble( order ) {
			var resetInserted = false;
			return ( order || [] ).map( function ( key ) {
				var html = blocks[ key ] || '';
				if ( html && hasActiveFilters && ! resetInserted && key !== 'popular' && key !== 'categories' && key !== 'main' ) {
					resetInserted = true;
					html = html.replace(
						/^(<div class="zgss-col zgss-block--attrs"[^>]*>)/,
						'$1<button type="button" class="zgss-attr-reset">' + escapeHtml( CFG.i18n.resetFilters || 'Zrušit filtry' ) + '</button>'
					);
				}
				return html;
			} ).join( '' );
		}

		root._zgssLastData = data;
		root._zgssLastHasQuery = hasQuery;
		root._zgssLastRawQuery = rawQuery;

		var isMobile = window.innerWidth <= 782;
		root._zgssLastIsMobile = isMobile;

		if ( isMobile ) {
			// Plochý seznam, žádné seskupování - "main" (výsledky) může být
			// kdekoliv v pořadí podle nastavení admina.
			inner.innerHTML = assemble( data.mobile_order );
		} else {
			// Vše kromě výsledků tvoří jednu grid buňku vlevo (viz CSS
			// .zgss-sidebar) - výsledky jsou samostatná buňka vpravo.
			inner.innerHTML = '<div class="zgss-sidebar">' + assemble( data.sidebar_order ) + '</div>' + blocks.main;
		}

		positionDropdown( root );
		dropdown.hidden = false;
		if ( root._zgssArrow ) {
			root._zgssArrow.hidden = false;
		}
		root.classList.add( 'zgss-search--open' );
	}

	/**
	 * Přepočítá pozici "portálovaného" dropdownu (a šipky) podle aktuální
	 * polohy vyhledávacího pole na obrazovce. Dropdown i šipka jsou fyzicky
	 * přesunuté jako přímé děti <body>, aby je nemohl žádný nadřazený
	 * element webu (menu, sticky header apod.) se svým vlastním stacking
	 * kontextem/z-indexem přebít nebo oříznout.
	 *
	 * @param {HTMLElement} root Kořenový element instance vyhledávání.
	 */
	function positionDropdown( root ) {
		var bar = root.querySelector( '.zgss-search__bar' );
		var dropdown = root._zgssDropdown;
		var arrow = root._zgssArrow;
		if ( ! bar || ! dropdown ) {
			return;
		}

		var rect = bar.getBoundingClientRect();
		var isMobile = window.innerWidth <= 782;

		// "top" počítáme vždy podle skutečné pozice pole hledání - i na
		// mobilu - aby okno výsledků nikdy nepřekrývalo samotné pole
		// (jen na mobilu roztáhneme šířku a výšku přes CSS, viz media query).
		dropdown.style.top = ( rect.bottom + 10 ) + 'px';

		if ( isMobile ) {
			// Šířku/okraje řídí CSS (left:0; right:0;) - inline left nechceme.
			dropdown.style.left = '';
		} else {
			dropdown.style.left = rect.left + 'px';
		}

		if ( arrow && ! isMobile ) {
			arrow.style.top = ( rect.bottom + 3 ) + 'px';
			arrow.style.left = ( rect.left + 28 ) + 'px';
		}
	}

	function closeDropdown( root ) {
		var dropdown = root._zgssDropdown;
		if ( dropdown ) {
			dropdown.hidden = true;
		}
		if ( root._zgssArrow ) {
			root._zgssArrow.hidden = true;
		}
		root.classList.remove( 'zgss-search--open' );
		// Označíme jako ručně zavřené, aby ho případná pozdě doručená AJAX
		// odpověď (z requestu odeslaného před zavřením) znovu neotevřela.
		root.dataset.zgssClosed = '1';
	}

	function fetchResults( root, term ) {
		// Nový request = uživatel chce vidět výsledky, zrušíme příznak zavření.
		root.dataset.zgssClosed = '';

		var filters = root._zgssFilters || {};
		var hasActiveFilters = Object.keys( filters ).some( function ( k ) {
			return filters[ k ] && filters[ k ].length;
		} );

		var body = new URLSearchParams();
		body.set( 'action', 'zgss_search' );
		body.set( 'nonce', CFG.nonce );
		body.set( 'term', term );
		if ( hasActiveFilters ) {
			body.set( 'filters', JSON.stringify( filters ) );
		}

		fetch( CFG.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString(),
		} )
			.then( function ( res ) { return res.json(); } )
			.then( function ( json ) {
				if ( ! json || ! json.success ) {
					return;
				}
				if ( '1' === root.dataset.zgssClosed ) {
					// Mezitím uživatel okno ručně zavřel - respektujeme to.
					return;
				}
				var trimmed = term.trim();
				if ( json.data.search_base_url ) {
					root.dataset.searchBase = json.data.search_base_url;
				}
				var hasQuery = trimmed.length >= CFG.minChars || hasActiveFilters;
				buildDropdown( root, json.data, hasQuery, json.data.highlight_term || trimmed );
			} )
			.catch( function () {
				// Tichý fail - vyhledávání jednoduše nezobrazí dropdown.
			} );
	}

	function searchUrl( root, term ) {
		var base = root.dataset.searchBase || ( window.location.origin + '/' );
		var sep  = base.indexOf( '?' ) === -1 ? '?' : '&';
		return base + sep + 's=' + encodeURIComponent( term );
	}

	// --- "Psací stroj" - animovaná nápověda v prázdném, nefokusovaném poli,
	// aby upoutala pozornost (píše a maže ukázkové výrazy do placeholderu).

	function startTypewriter( input ) {
		var phrases = CFG.typewriterPhrases;
		if ( ! CFG.enableTypewriter || ! phrases || ! phrases.length ) {
			return;
		}

		var basePlaceholder = input.getAttribute( 'placeholder' ) || '';
		var phraseIndex = 0;
		var charIndex   = 0;
		var deleting    = false;
		var timer       = null;
		var paused      = false;

		function isIdle() {
			return document.activeElement !== input && '' === input.value;
		}

		function tick() {
			if ( paused ) {
				return;
			}
			if ( ! isIdle() ) {
				input.setAttribute( 'placeholder', basePlaceholder );
				timer = setTimeout( tick, 400 );
				return;
			}

			var phrase = phrases[ phraseIndex ];
			var delay  = 55;

			if ( ! deleting ) {
				charIndex++;
				input.setAttribute( 'placeholder', phrase.slice( 0, charIndex ) );
				if ( charIndex === phrase.length ) {
					deleting = true;
					delay = 1400;
				}
			} else {
				charIndex--;
				input.setAttribute( 'placeholder', phrase.slice( 0, charIndex ) );
				delay = 30;
				if ( 0 === charIndex ) {
					deleting = false;
					phraseIndex = ( phraseIndex + 1 ) % phrases.length;
					delay = 400;
				}
			}

			timer = setTimeout( tick, delay );
		}

		input.addEventListener( 'focus', function () {
			paused = true;
			clearTimeout( timer );
			input.setAttribute( 'placeholder', basePlaceholder );
		} );

		input.addEventListener( 'blur', function () {
			if ( '' === input.value ) {
				paused = false;
				timer = setTimeout( tick, 500 );
			}
		} );

		timer = setTimeout( tick, 800 );
	}

	function initInstance( root ) {
		var input   = root.querySelector( '.zgss-search__input' );
		var button  = root.querySelector( '.zgss-search__btn' );
		var dropdown = root.querySelector( '.zgss-search__dropdown' );
		var arrow    = root.querySelector( '.zgss-search__arrow' );

		if ( ! input ) {
			return;
		}

		// Stav zvolených filtrů podle vlastností produktu pro tuto instanci
		// vyhledávání - {taxonomy: [term_slug, ...]}. Žije jen po dobu
		// otevřeného dropdownu/session na stránce, nepersistuje se.
		root._zgssFilters = {};

		// "Portál" - přesuneme dropdown a šipku fyzicky pod <body>, ať je
		// nemůže žádný nadřazený element webu (menu, sticky header) svým
		// stacking kontextem přebít ani oříznout. Pozice se dopočítává v JS
		// podle skutečné polohy vyhledávacího pole (viz positionDropdown).
		if ( dropdown ) {
			dropdown.classList.add( 'zgss-search__dropdown--portal' );
			document.body.appendChild( dropdown );
		}
		if ( arrow ) {
			arrow.classList.add( 'zgss-search__arrow--portal' );
			document.body.appendChild( arrow );
		}
		root._zgssDropdown = dropdown;
		root._zgssArrow = arrow;

		if ( dropdown ) {
			var closeBtn = dropdown.querySelector( '.zgss-search__close' );
			if ( closeBtn ) {
				closeBtn.addEventListener( 'click', function () {
					closeDropdown( root );
				} );
			}
		}

		function isLatestMode() {
			return 'latest' === CFG.filterCombineMode;
		}

		startTypewriter( input );

		var doSearch = debounce( function () {
			// "Podle posledního výběru" - psaní textu přepíše dřív zvolené
			// filtry (výsledek pak řídí jen text, ne kombinace obojího).
			if ( isLatestMode() && root._zgssFilters && Object.keys( root._zgssFilters ).length ) {
				root._zgssFilters = {};
			}
			fetchResults( root, input.value.trim() );
		}, CFG.debounce );

		input.addEventListener( 'input', doSearch );

		input.addEventListener( 'focus', function () {
			fetchResults( root, input.value.trim() );
		} );

		input.addEventListener( 'keydown', function ( e ) {
			if ( 'Escape' === e.key ) {
				closeDropdown( root );
				input.blur();
			} else if ( 'Enter' === e.key ) {
				e.preventDefault();
				var term = input.value.trim();
				if ( term.length >= CFG.minChars ) {
					window.location.href = searchUrl( root, term );
				}
			}
		} );

		if ( button ) {
			button.addEventListener( 'click', function () {
				var term = input.value.trim();
				if ( term.length >= CFG.minChars ) {
					window.location.href = searchUrl( root, term );
				}
			} );
		}

		// Chipy ("Oblíbená vyhledávání") a klikací filtry vlastností produktu
		// se renderují dovnitř dropdownu, který teď žije pod <body> -
		// posloucháme proto přímo na něm, ne na root.
		if ( dropdown ) {
			dropdown.addEventListener( 'click', function ( e ) {
				var chipEl = e.target.closest( '.zgss-chip' );
				if ( chipEl && chipEl.classList.contains( 'zgss-chip--attr' ) ) {
					var tax  = chipEl.getAttribute( 'data-tax' );
					var slug = chipEl.getAttribute( 'data-term' );

					if ( isLatestMode() ) {
						// "Podle posledního výběru" - vždy jen JEDNA aktivní
						// hodnota celkem (žádné kombinování, ani v rámci
						// stejné vlastnosti). Klik na už aktivní hodnotu ji
						// zruší, klik na cokoliv jiného přepíše celý výběr.
						var current = root._zgssFilters[ tax ] || [];
						var isSameSingleActive = 1 === current.length && current[ 0 ] === slug && 1 === Object.keys( root._zgssFilters ).length;
						root._zgssFilters = {};
						if ( ! isSameSingleActive ) {
							root._zgssFilters[ tax ] = [ slug ];
						}
						input.value = '';
					} else {
						// "A zároveň" - normální multi-select: v rámci stejné
						// vlastnosti OR (víc hodnot najednou), napříč
						// vlastnostmi AND (viz ZGSS_Index::filter_candidates()).
						var list = root._zgssFilters[ tax ] || [];
						var idx  = list.indexOf( slug );
						if ( idx === -1 ) {
							list.push( slug );
						} else {
							list.splice( idx, 1 );
						}
						if ( list.length ) {
							root._zgssFilters[ tax ] = list;
						} else {
							delete root._zgssFilters[ tax ];
						}
					}
					fetchResults( root, input.value.trim() );
					return;
				}
				if ( e.target.closest( '.zgss-attr-reset' ) ) {
					root._zgssFilters = {};
					fetchResults( root, input.value.trim() );
					return;
				}
				if ( chipEl ) {
					input.value = chipEl.getAttribute( 'data-term' );
					if ( isLatestMode() ) {
						root._zgssFilters = {};
					}
					fetchResults( root, input.value );
					input.focus();
				}
			} );
		}

		document.addEventListener(
			'click',
			function ( e ) {
				var insideBar = root.contains( e.target );
				var insideDropdown = dropdown && dropdown.contains( e.target );
				if ( ! insideBar && ! insideDropdown ) {
					closeDropdown( root );
				}
			},
			true
		);

		var reposition = function () {
			if ( ! root.classList.contains( 'zgss-search--open' ) ) {
				return;
			}
			// Pokud se mezitím překročila hranice mobil/desktop (např. otočení
			// tabletu), obsah přeskládáme podle správného pořadí (sidebar_order
			// vs. mobile_order) - jinak by zůstal v layoutu z doby posledního fetchResults().
			var isMobileNow = window.innerWidth <= 782;
			if ( root._zgssLastData && isMobileNow !== root._zgssLastIsMobile ) {
				buildDropdown( root, root._zgssLastData, root._zgssLastHasQuery, root._zgssLastRawQuery );
				return;
			}
			positionDropdown( root );
		};
		window.addEventListener( 'resize', reposition );
		window.addEventListener( 'scroll', reposition, true );
	}

	function init() {
		var roots = document.querySelectorAll( '[data-zgss-search]' );
		roots.forEach( initInstance );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
