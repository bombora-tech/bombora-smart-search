<?php
/**
 * "Moderní" doporučovací funkce - bestsellery, related produkty a
 * personalizace podle historie objednávek přihlášeného zákazníka.
 *
 * @package ZG_Smart_Search
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZGSS_Recommendations {

	/**
	 * Nejprodávanější (nebo jinak seřazené, dle nastavení - viz níže)
	 * produkty, volitelně bez zadaných ID.
	 *
	 * Řazení řídí nastavení "Doporučené produkty" (recommendation_mode):
	 * popularity/newest/price_low/price_high, nebo 'manual' - ruční výběr
	 * konkrétních produktů (jen když není omezeno na kategorii - viz níže).
	 * Vyloučené produkty (ZGSS_Admin::get_excluded_ids()) se nikdy nenabídnou,
	 * a to i v ručním výběru.
	 *
	 * @param int   $limit       Počet produktů.
	 * @param array $exclude_ids ID k vyloučení (např. už zobrazené výsledky).
	 * @param array $category_slugs Volitelné omezení na kategorie.
	 * @return array
	 */
	public static function bestsellers( $limit = 4, $exclude_ids = array(), $category_slugs = array() ) {
		$exclude_ids = array_merge( $exclude_ids, ZGSS_Admin::get_excluded_ids() );
		$settings    = ZGSS_Admin::get_settings();
		$mode        = isset( $settings['recommendation_mode'] ) ? $settings['recommendation_mode'] : 'popularity';

		// Ruční výběr dává smysl jen pro obecné (nekategorizované) doporučení -
		// ruční seznam produktů nemá vztah ke konkrétní kategorii/filtru, ze
		// kterého se volá personalizace nebo "podobné produkty po filtru".
		if ( 'manual' === $mode && empty( $category_slugs ) ) {
			$manual = self::manual_selection( $limit, $exclude_ids );
			if ( count( $manual ) >= $limit ) {
				return $manual;
			}
			// Nevybráno dost produktů (nebo některé mezitím vyprodané/smazané) -
			// zbytek doplníme podle prodejnosti, ať se řádek nezobrazí poloprázdný.
			$manual_ids     = wp_list_pluck( $manual, 'id' );
			$fill           = self::bestsellers_ordered( $limit - count( $manual ), array_merge( $exclude_ids, $manual_ids ), array(), 'popularity' );
			return array_merge( $manual, $fill );
		}

		return self::bestsellers_ordered( $limit, $exclude_ids, $category_slugs, $mode );
	}

	/**
	 * Ruční výběr doporučených produktů z nastavení - v pořadí, jak je
	 * admin vybral, s vyloučením ID z $exclude_ids (vč. globálně
	 * vyloučených) a s ověřením, že produkt pořád existuje a je skladem.
	 *
	 * @param int   $limit       Max. počet.
	 * @param array $exclude_ids ID k vyloučení.
	 * @return array
	 */
	protected static function manual_selection( $limit, $exclude_ids ) {
		$settings  = ZGSS_Admin::get_settings();
		$ids       = isset( $settings['recommendation_manual_ids'] ) ? (array) $settings['recommendation_manual_ids'] : array();
		$ids       = array_values( array_diff( array_map( 'intval', $ids ), array_map( 'intval', $exclude_ids ) ) );
		$ids       = array_slice( $ids, 0, $limit );

		if ( empty( $ids ) ) {
			return array();
		}

		// wc_get_products s 'include' bohužel nezachová pořadí ID - dořadíme ručně.
		$products = wc_get_products(
			array(
				'include'      => $ids,
				'status'       => 'publish',
				'stock_status' => 'instock',
				'limit'        => -1,
				'return'       => 'objects',
			)
		);

		$by_id = array();
		foreach ( $products as $product ) {
			$by_id[ $product->get_id() ] = $product;
		}

		$ordered = array();
		foreach ( $ids as $id ) {
			if ( isset( $by_id[ $id ] ) ) {
				$ordered[] = $by_id[ $id ];
			}
		}

		return self::format_products( $ordered );
	}

	/**
	 * Produkty seřazené podle zvoleného kritéria (interní pomůcka pro
	 * bestsellers() a "doplnění" ručního výběru).
	 *
	 * Řadí se ručně v PHP (ne přes 'orderby' u wc_get_products()) - na
	 * některých webech totiž něco jiného (motiv/jiný plugin zapojený do
	 * produktových dotazů přes hook jako woocommerce_product_query nebo
	 * pre_get_posts) předaný 'orderby' přebije a vrací pořád stejné
	 * pořadí bez ohledu na zvolený režim. Ruční řazení po stažení dat je
	 * o něco náročnější (stahuje se celý dostupný fond), ale je spolehlivé
	 * za všech okolností - proto i krátká transientová cache níže.
	 *
	 * @param int    $limit          Počet produktů.
	 * @param array  $exclude_ids    ID k vyloučení.
	 * @param array  $category_slugs Volitelné omezení na kategorie.
	 * @param string $mode           popularity|newest|price_low|price_high|random.
	 * @return array
	 */
	protected static function bestsellers_ordered( $limit, $exclude_ids, $category_slugs, $mode ) {
		if ( $limit <= 0 ) {
			return array();
		}

		// Náhodný výběr se cíleně NEcachuje - jinak by "náhodné" bylo
		// hodinu pořád to samé (cache klíč by vyšel stejně pro každý request).
		$use_cache = 'random' !== $mode;
		$cache_key = '';

		if ( $use_cache ) {
			$cache_key = 'zgss_bord_' . md5( wp_json_encode( array( $limit, $exclude_ids, $category_slugs, $mode, ZGSS_Index::current_lang() ) ) );
			$cached    = get_transient( $cache_key );
			if ( false !== $cached ) {
				return $cached;
			}
		}

		$args = array(
			'status'       => 'publish',
			'stock_status' => 'instock',
			'limit'        => -1, // Celý dostupný fond - potřebujeme ho celý, abychom mohli spolehlivě seřadit sami.
			'exclude'      => $exclude_ids,
			'return'       => 'objects',
		);

		if ( ! empty( $category_slugs ) ) {
			$args['category'] = $category_slugs;
		}

		$pool = wc_get_products( $args );

		// Pokud omezení na kategorii nevrátilo nic, zkusíme to bez omezení
		// (lepší nabídnout něco relevantního než nic).
		if ( ! empty( $category_slugs ) && empty( $pool ) ) {
			$pool = wc_get_products(
				array(
					'status'       => 'publish',
					'stock_status' => 'instock',
					'limit'        => -1,
					'exclude'      => $exclude_ids,
					'return'       => 'objects',
				)
			);
		}

		if ( 'random' === $mode ) {
			shuffle( $pool );
		} else {
			usort(
				$pool,
				function ( $a, $b ) use ( $mode ) {
					switch ( $mode ) {
						case 'newest':
							$a_date = $a->get_date_created();
							$b_date = $b->get_date_created();
							$a_ts   = $a_date ? $a_date->getTimestamp() : 0;
							$b_ts   = $b_date ? $b_date->getTimestamp() : 0;
							return $b_ts <=> $a_ts;
						case 'price_low':
							return ( (float) $a->get_price() ) <=> ( (float) $b->get_price() );
						case 'price_high':
							return ( (float) $b->get_price() ) <=> ( (float) $a->get_price() );
						default: // 'popularity'.
							return ( (int) $b->get_total_sales() ) <=> ( (int) $a->get_total_sales() );
					}
				}
			);
		}

		$result = self::format_products( array_slice( $pool, 0, $limit ) );

		if ( $use_cache ) {
			set_transient( $cache_key, $result, HOUR_IN_SECONDS );
		}

		return $result;
	}

	/**
	 * Produkty související s daným produktem (WooCommerce nativní logika -
	 * stejné kategorie/štítky, cross-sells apod.).
	 *
	 * @param int $product_id  ID výchozího produktu.
	 * @param int $limit       Počet produktů.
	 * @param array $exclude_ids Další ID k vyloučení.
	 * @return array
	 */
	public static function related_to( $product_id, $limit = 4, $exclude_ids = array() ) {
		$exclude_ids = array_merge( $exclude_ids, ZGSS_Admin::get_excluded_ids() );
		$related_ids = wc_get_related_products( $product_id, $limit + count( $exclude_ids ), $exclude_ids );
		$related_ids = array_diff( $related_ids, $exclude_ids );
		$related_ids = array_slice( $related_ids, 0, $limit );

		if ( empty( $related_ids ) ) {
			return array();
		}

		$products = wc_get_products(
			array(
				'include'      => $related_ids,
				'status'       => 'publish',
				'stock_status' => 'instock',
				'return'       => 'objects',
			)
		);

		return self::format_products( $products );
	}


	/**
	 * Vrátí ID produktů, které se počítají jako "bestseller" (top N podle
	 * total_sales v aktuálním jazyce), cachované v transientu. Používá se
	 * pro štítek "Bestseller" ve výsledcích, pokud je v nastavení zapnutý.
	 *
	 * @param int $limit Kolik nejprodávanějších produktů se počítá jako bestseller.
	 * @return int[]
	 */
	public static function get_bestseller_ids( $limit ) {
		$cache_key = 'zgss_bestseller_ids_' . ZGSS_Index::current_lang() . '_' . (int) $limit;
		$ids       = get_transient( $cache_key );

		if ( is_array( $ids ) ) {
			return $ids;
		}

		$ids = wc_get_products(
			array(
				'status'       => 'publish',
				'stock_status' => 'instock',
				'limit'        => (int) $limit,
				'orderby'      => 'popularity',
				'order'        => 'DESC',
				'return'       => 'ids',
			)
		);

		set_transient( $cache_key, $ids, 12 * HOUR_IN_SECONDS );

		return $ids;
	}

	/**
	 * Nejprodávanější produkty OMEZENÉ na zvolené filtry podle vlastností -
	 * použije se jako "doporučené alternativy", když fulltextové hledání
	 * nic nenajde, ale uživatel má aktivní filtry (např. Barva/Typ vína) -
	 * relevantnější než úplně obecné bestsellery.
	 *
	 * @param int   $limit       Počet produktů.
	 * @param array $exclude_ids ID k vyloučení.
	 * @param array $filters     {taxonomy_slug: [term_slug, ...]}
	 * @return array
	 */
	public static function bestsellers_by_attributes( $limit, $exclude_ids, $filters ) {
		$filters = array_filter( (array) $filters );
		if ( empty( $filters ) ) {
			return array();
		}

		$exclude_ids = array_merge( $exclude_ids, ZGSS_Admin::get_excluded_ids() );

		$tax_query = array();
		foreach ( $filters as $taxonomy => $terms ) {
			$tax_query[] = array(
				'taxonomy' => $taxonomy,
				'field'    => 'slug',
				'terms'    => array_values( $terms ),
			);
		}

		$products = wc_get_products(
			array(
				'status'       => 'publish',
				'stock_status' => 'instock',
				'limit'        => $limit,
				'orderby'      => 'popularity',
				'order'        => 'DESC',
				'exclude'      => $exclude_ids,
				'tax_query'    => $tax_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				'return'       => 'objects',
			)
		);

		return self::format_products( $products );
	}

	/**
	 * Sjednotí formát pole produktů (WC_Product[]) do jednoduchých asociativních polí pro JSON.
	 *
	 * @param array $products Pole WC_Product objektů.
	 * @return array
	 */
	public static function format_products( $products ) {
		$out              = array();
		$settings         = ZGSS_Admin::get_settings();
		$bestseller_ids   = ! empty( $settings['show_bestseller_badge'] )
			? self::get_bestseller_ids( (int) $settings['bestseller_count'] )
			: array();

		foreach ( $products as $product ) {
			if ( ! ZGSS_Index::product_available( $product ) ) {
				continue;
			}

			$image_id  = $product->get_image_id();
			$image_url = $image_id ? wp_get_attachment_image_url( $image_id, 'medium' ) : '';
			if ( ! $image_url ) {
				$image_url = wc_placeholder_img_src( 'medium' );
			}

			$regular = (float) $product->get_regular_price();
			$sale    = (float) $product->get_sale_price();
			$percent = 0;
			if ( $product->is_on_sale() && $regular > 0 && $sale > 0 ) {
				$percent = (int) round( ( ( $regular - $sale ) / $regular ) * 100 );
			}

			$out[] = array(
				'id'            => $product->get_id(),
				'title'         => $product->get_name(),
				'price_html'    => $product->get_price_html(),
				'permalink'     => get_permalink( $product->get_id() ),
				'image'         => $image_url,
				'in_stock'      => $product->is_in_stock(),
				'on_sale'       => $product->is_on_sale(),
				'sale_percent'  => $percent,
				'is_bestseller' => in_array( $product->get_id(), $bestseller_ids, true ),
			);
		}

		return $out;
	}
}
