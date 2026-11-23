<?php
/**
 * AJAX endpointy pro live vyhledávání.
 *
 * @package ZG_Smart_Search
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZGSS_Ajax {

	public static function init() {
		add_action( 'wp_ajax_zgss_search', array( __CLASS__, 'handle_search' ) );
		add_action( 'wp_ajax_nopriv_zgss_search', array( __CLASS__, 'handle_search' ) );
	}

	/**
	 * Hlavní AJAX handler. Vrací JSON s produkty, kategoriemi, oblíbenými
	 * výrazy a doporučeními (bestsellery / related / personalizované).
	 */
	public static function handle_search() {
		check_ajax_referer( 'zgss_search_nonce', 'nonce' );

		$term     = isset( $_POST['term'] ) ? sanitize_text_field( wp_unslash( $_POST['term'] ) ) : '';
		$settings = ZGSS_Admin::get_settings();
		$lang     = ZGSS_Index::current_lang();
		$filters  = self::parse_filters( $settings );

		$product_limit    = max( 1, (int) $settings['results_count'] );
		$suggestion_limit = max( 1, (int) $settings['suggestions_count'] );

		$response = array(
			'term'              => $term,
			'products'          => array(),
			'categories'        => ! empty( $settings['show_categories_block'] ) ? self::get_quick_categories() : array(),
			'popular'           => array(),
			'suggestions'       => array(),
			'more_url'          => '',
			'highlight_term'    => $term,
			'search_base_url'   => self::get_localized_home_url(),
			'attribute_filters' => self::get_attribute_filter_groups( $settings ),
			'active_filters'    => $filters,
			'sidebar_order'     => ZGSS_Admin::get_ordered_blocks( 'sidebar' ),
			'mobile_order'      => ZGSS_Admin::get_ordered_blocks( 'mobile' ),
		);

		$norm_term    = ZGSS_Fuzzy::normalize( $term );
		$has_filters  = ! empty( $filters );

		if ( strlen( $norm_term ) < (int) $settings['min_chars'] && ! $has_filters ) {
			// Krátký/prázdný dotaz a žádné filtry -> "prázdný stav": doporučené produkty + oblíbená hledání.
			if ( ! empty( $settings['show_popular_block'] ) ) {
				$response['popular'] = self::format_popular( ZGSS_Popular_Terms::get_popular( $lang, 6 ) );
			}
			$response['suggestions'] = self::get_recommendations( array(), $suggestion_limit, $filters );
			wp_send_json_success( $response );
		}

		$matches = ZGSS_Search_Router::search( $term, $product_limit, $filters );

		// Pokud fuzzy hledání "na tvrdo" nic nenašlo (nebo našlo málo), zkusíme
		// ruční slovník náhrad (např. "aksaneli" -> "askaneli") a hledáme znovu
		// s nahrazeným výrazem - to pokrývá konkrétní, opakovaně se vyskytující
		// překlepy/cizí výrazy spolehlivěji než obecný algoritmus. Dává smysl
		// jen pokud vůbec něco píšeme (u "jen filtry" bez textu se přeskakuje).
		$highlight_for = $term;

		if ( '' !== trim( $term ) ) {
			$replacement = ZGSS_Replacements::apply( $term );

			if ( $replacement['changed'] ) {
				$replaced_matches = ZGSS_Search_Router::search( $replacement['effective'], $product_limit, $filters );
				if ( ! empty( $replaced_matches ) ) {
					$matches = $replaced_matches;
					if ( $replacement['highlight'] ) {
						$highlight_for = $replacement['effective'];
					}
				}
			}
		}

		$response['products']       = array_map( array( __CLASS__, 'format_candidate' ), $matches );
		$response['highlight_term'] = ZGSS_Fuzzy::to_latin_words( $highlight_for );

		$search_base                 = self::get_localized_home_url();
		$response['search_base_url'] = $search_base;
		$response['more_url']        = self::build_more_url( $search_base, $term, $filters );

		$matched_ids = wp_list_pluck( $matches, 'id' );

		// Zaznamenat statistiku hledání (pro "oblíbená vyhledávání") - jen
		// u skutečného textového dotazu, ne u prohlížení přes filtry.
		if ( ! empty( $settings['enable_stats'] ) && '' !== trim( $term ) ) {
			ZGSS_Popular_Terms::log( $term, $lang, count( $matches ) );
		}

		if ( ! empty( $settings['show_popular_block'] ) ) {
			$response['popular'] = self::format_popular( ZGSS_Popular_Terms::get_popular( $lang, 6, $norm_term ) );
		}
		$response['suggestions'] = self::get_recommendations( $matched_ids, $suggestion_limit, $filters );

		wp_send_json_success( $response );
	}

	/**
	 * Načte a sanitizuje filtry podle vlastností produktu odeslané z
	 * frontendu ($_POST['filters'], JSON). Bezpečnostně omezuje klíče jen
	 * na taxonomie, které admin výslovně povolil v nastavení - jakýkoliv
	 * jiný klíč se tiše zahodí.
	 *
	 * @param array $settings Aktuální nastavení pluginu.
	 * @return array {taxonomy_slug: [term_slug, ...]}
	 */
	protected static function parse_filters( $settings ) {
		if ( empty( $_POST['filters'] ) ) {
			return array();
		}

		$raw = json_decode( wp_unslash( $_POST['filters'] ), true ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$allowed = isset( $settings['enabled_attributes'] ) ? (array) $settings['enabled_attributes'] : array();
		$out     = array();

		foreach ( $raw as $taxonomy => $terms ) {
			$taxonomy = sanitize_key( $taxonomy );
			if ( ! in_array( $taxonomy, $allowed, true ) || ! is_array( $terms ) ) {
				continue;
			}
			$terms = array_values( array_filter( array_map( 'sanitize_title', $terms ) ) );
			if ( ! empty( $terms ) ) {
				$out[ $taxonomy ] = $terms;
			}
		}

		return $out;
	}

	/**
	 * Sestaví klikací skupiny filtrů (vlastnosti produktu) pro levý sloupec.
	 * Vrací jen taxonomie, které mají po odfiltrování prázdných term aspoň
	 * jednu hodnotu.
	 *
	 * @param array $settings Aktuální nastavení pluginu.
	 * @return array Pole {taxonomy, label, terms: [{slug, name, count}]}.
	 */
	protected static function get_attribute_filter_groups( $settings ) {
		$taxonomies = isset( $settings['enabled_attributes'] ) ? (array) $settings['enabled_attributes'] : array();
		if ( empty( $taxonomies ) ) {
			return array();
		}

		// Počty bereme z indexu (jen skutečně dostupné produkty skladem),
		// ne z WordPress term->count - ten běžně počítá i vyprodané
		// produkty, takže by ukazoval zavádějící čísla.
		$stock_counts = ZGSS_Index::count_attribute_terms();

		$groups = array();
		foreach ( $taxonomies as $taxonomy ) {
			$terms = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
				)
			);
			if ( is_wp_error( $terms ) || empty( $terms ) ) {
				continue;
			}

			$term_counts     = isset( $stock_counts[ $taxonomy ] ) ? $stock_counts[ $taxonomy ] : array();
			$available_terms = array();

			foreach ( $terms as $term ) {
				$count = isset( $term_counts[ $term->slug ] ) ? $term_counts[ $term->slug ] : 0;
				if ( $count < 1 ) {
					continue; // Žádný skladem dostupný produkt s touto hodnotou - filtr by vedl jen na prázdný výsledek.
				}
				$available_terms[] = array(
					'slug'  => $term->slug,
					'name'  => $term->name,
					'count' => $count,
				);
			}

			if ( empty( $available_terms ) ) {
				continue;
			}

			$label = function_exists( 'wc_attribute_label' ) ? wc_attribute_label( $taxonomy ) : $taxonomy;

			$groups[] = array(
				'taxonomy' => $taxonomy,
				'label'    => $label,
				'terms'    => $available_terms,
			);
		}

		return $groups;
	}

	/**
	 * Sestaví odkaz "Zobrazit více výsledků" - pokud jsou aktivní i filtry
	 * podle vlastností, přidá je jako standardní WooCommerce parametry
	 * vrstveného filtrování (?filter_{atribut}=slug1,slug2), aby výpis na
	 * cílové stránce zohlednil stejný výběr jako dropdown.
	 *
	 * @param string $search_base Základní (lokalizovaná) URL.
	 * @param string $term        Hledaný výraz.
	 * @param array  $filters     {taxonomy_slug: [term_slug, ...]}
	 * @return string
	 */
	protected static function build_more_url( $search_base, $term, $filters ) {
		$args = array();
		if ( '' !== trim( $term ) ) {
			$args['s'] = rawurlencode( $term );
		}
		foreach ( $filters as $taxonomy => $terms ) {
			$attr_name          = preg_replace( '/^pa_/', '', $taxonomy );
			$args[ 'filter_' . $attr_name ] = implode( ',', array_map( 'rawurlencode', $terms ) );
		}
		return add_query_arg( $args, $search_base );
	}

	/**
	 * Sestaví doporučení: related produkty k top shodě (pokud nějaká je),
	 * jinak bestsellery v rámci aktivních filtrů (pokud jsou), jinak obecné
	 * doporučené produkty podle zvoleného režimu (Nastavení > Doporučené
	 * produkty). Personalizace podle historie nákupů byla úmyslně odstraněna -
	 * doporučení teď vždy řídí jen tohle nastavení, bez ohledu na
	 * přihlášení/historii zákazníka.
	 *
	 * @param array $exclude_ids ID produktů, které se nemají v doporučení opakovat.
	 * @param int   $limit       Počet doporučených produktů.
	 * @param array $filters     Aktivní filtry podle vlastností - {taxonomy_slug: [term_slug, ...]}.
	 * @return array
	 */
	protected static function get_recommendations( $exclude_ids, $limit, $filters = array() ) {
		if ( ! empty( $exclude_ids ) ) {
			$related = ZGSS_Recommendations::related_to( $exclude_ids[0], $limit, $exclude_ids );
			if ( ! empty( $related ) ) {
				return $related;
			}
		}

		if ( ! empty( $filters ) ) {
			$by_attrs = ZGSS_Recommendations::bestsellers_by_attributes( $limit, $exclude_ids, $filters );
			if ( ! empty( $by_attrs ) ) {
				return $by_attrs;
			}
		}

		return ZGSS_Recommendations::bestsellers( $limit, $exclude_ids );
	}

	/**
	 * Vrátí seznam hlavních kategorií produktů s počtem produktů (levý sloupec dropdownu).
	 *
	 * @return array
	 */
	protected static function get_quick_categories() {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => true,
				'parent'     => 0,
				'orderby'    => 'count',
				'order'      => 'DESC',
				'number'     => 6,
			)
		);

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return array();
		}

		$out = array();
		foreach ( $terms as $term ) {
			$out[] = array(
				'name'  => $term->name,
				'count' => $term->count,
				'url'   => get_term_link( $term ),
			);
		}

		return $out;
	}

	/**
	 * Naformátuje výraz z tabulky oblíbených hledání pro JSON výstup.
	 *
	 * @param array $rows Výsledek z ZGSS_Popular_Terms::get_popular().
	 * @return array
	 */
	protected static function format_popular( $rows ) {
		$out = array();
		foreach ( $rows as $row ) {
			$out[] = array(
				'term' => $row['term'],
				'hits' => (int) $row['hits'],
			);
		}
		return $out;
	}

	/**
	 * Vrátí URL domovské stránky v aktuálním jazyce (WPML) - pro ruskou verzi
	 * tedy např. https://www.zgruzie.cz/ru/, jinak https://www.zgruzie.cz/.
	 * Na tuto URL se pak jen připojí ?s=vyraz.
	 *
	 * @return string
	 */
	protected static function get_localized_home_url() {
		$home = home_url( '/' );
		$lang = apply_filters( 'wpml_current_language', null );

		if ( $lang ) {
			$translated = apply_filters( 'wpml_permalink', $home, $lang );
			if ( $translated ) {
				$home = $translated;
			}
		}

		return trailingslashit( $home );
	}

	/**
	 * Naformátuje jednoho kandidáta z fuzzy indexu pro JSON výstup.
	 *
	 * @param array $c Kandidát z ZGSS_Fuzzy::search().
	 * @return array
	 */
	protected static function format_candidate( $c ) {
		return array(
			'id'            => $c['id'],
			'title'         => $c['title'],
			'price_html'    => $c['price_html'],
			'permalink'     => $c['permalink'],
			'image'         => $c['image'],
			'in_stock'      => $c['in_stock'],
			'stock_status'  => isset( $c['stock_status'] ) ? $c['stock_status'] : ( ! empty( $c['in_stock'] ) ? 'instock' : 'outofstock' ),
			'on_sale'       => $c['on_sale'],
			'sale_percent'  => $c['sale_percent'],
			'is_bestseller' => ! empty( $c['is_bestseller'] ),
		);
	}
}
