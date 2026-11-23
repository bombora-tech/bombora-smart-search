<?php
/**
 * Buduje a cachuje "index" produktů použitý pro fuzzy vyhledávání.
 * Index je jazykově závislý (WPML) - pro každý jazyk se cachuje zvlášť,
 * protože názvy produktů a kategorií se v jednotlivých jazykových verzích liší.
 *
 * @package ZG_Smart_Search
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZGSS_Index {

	const CACHE_GROUP = 'zgss_index_';

	/**
	 * Zaregistruje hooky, které invalidují cache při změně produktů.
	 */
	public static function init() {
		add_action( 'save_post_product', array( __CLASS__, 'flush_all_caches' ) );
		add_action( 'woocommerce_update_product', array( __CLASS__, 'flush_all_caches' ) );
		add_action( 'woocommerce_new_product', array( __CLASS__, 'flush_all_caches' ) );
		add_action( 'before_delete_post', array( __CLASS__, 'flush_all_caches' ) );
		add_action( 'woocommerce_product_set_stock', array( __CLASS__, 'flush_all_caches' ) );
		add_action( 'woocommerce_variation_set_stock', array( __CLASS__, 'flush_all_caches' ) );
		add_action( 'woocommerce_product_set_stock_status', array( __CLASS__, 'flush_all_caches' ) );
	}

	/**
	 * Spolehlivá kontrola dostupnosti produktu - nespoléhá jen na pole
	 * "stav skladu" (to může být po importu/desynchronizaci chybně "skladem"
	 * i když je reálná zásoba 0), ale ověří i skutečné skladové množství
	 * u produktů se zapnutým sledováním skladu. Používá se v indexu i ve
	 * všech doporučeních (bestsellery, related, personalizace).
	 *
	 * @param WC_Product|false|null $product Produkt k ověření.
	 * @return bool
	 */
	public static function product_available( $product ) {
		if ( ! $product instanceof WC_Product ) {
			return false;
		}

		$status = $product->get_stock_status();

		if ( ! in_array( $status, self::allowed_stock_statuses(), true ) ) {
			return false;
		}

		// Předobjednávka (onbackorder) má záporné/nulové množství záměrně -
		// kontrolu množství u ní přeskočit.
		if ( 'onbackorder' === $status ) {
			return true;
		}

		if ( $product->managing_stock() ) {
			$qty = $product->get_stock_quantity();
			if ( null !== $qty && $qty <= 0 ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Stavy skladu, které se zahrnují do výsledků vyhledávání.
	 * Nastavení > "Předobjednávky ve výsledcích".
	 *
	 * @return string[]
	 */
	public static function allowed_stock_statuses() {
		$settings = class_exists( 'ZGSS_Admin' ) ? ZGSS_Admin::get_settings() : array();
		$statuses = array( 'instock' );
		if ( ! empty( $settings['include_backorder'] ) ) {
			$statuses[] = 'onbackorder';
		}
		return (array) apply_filters( 'zgss_allowed_stock_statuses', $statuses );
	}

	/**
	 * Vrátí aktuální jazykový kód (WPML), nebo 'default' pokud WPML není aktivní.
	 *
	 * @return string
	 */
	public static function current_lang() {
		$lang = apply_filters( 'wpml_current_language', null );
		return $lang ? $lang : 'default';
	}

	/**
	 * Vrátí pole kandidátů pro fuzzy vyhledávání (z cache, nebo je nově sestaví).
	 *
	 * @return array
	 */
	public static function get_candidates() {
		$lang      = self::current_lang();
		$cache_key = self::CACHE_GROUP . $lang;

		$data = get_transient( $cache_key );
		if ( false !== $data && is_array( $data ) ) {
			return $data;
		}

		$data = self::build_index();

		$settings   = class_exists( 'ZGSS_Admin' ) ? ZGSS_Admin::get_settings() : array();
		$ttl_hours  = isset( $settings['cache_hours'] ) ? (int) $settings['cache_hours'] : 12;
		$ttl_hours  = $ttl_hours > 0 ? $ttl_hours : 12;

		set_transient( $cache_key, $data, $ttl_hours * HOUR_IN_SECONDS );

		return $data;
	}

	/**
	 * Veřejná varianta build_index() pro externí spotřebitele dat (např.
	 * ZGSS_Elasticsearch::reindex_all()), kteří potřebují čerstvá data bez
	 * transientové cache. Samotná logika sestavení indexu zůstává
	 * nezměněná v build_index().
	 *
	 * @return array
	 */
	public static function get_all_products_data() {
		return self::build_index();
	}

	/**
	 * Sestaví index ze všech publikovaných produktů v aktuálním jazyce.
	 *
	 * @return array
	 */
	protected static function build_index() {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return array();
		}

		$products = wc_get_products(
			array(
				'status'       => 'publish',
				'stock_status' => self::allowed_stock_statuses(),
				'limit'        => -1,
				'return'       => 'objects',
			)
		);

		$excluded       = class_exists( 'ZGSS_Admin' ) ? ZGSS_Admin::get_excluded_ids() : array();
		$settings       = class_exists( 'ZGSS_Admin' ) ? ZGSS_Admin::get_settings() : array();
		$bestseller_ids = ! empty( $settings['show_bestseller_badge'] ) && class_exists( 'ZGSS_Recommendations' )
			? ZGSS_Recommendations::get_bestseller_ids( (int) ( $settings['bestseller_count'] ?? 20 ) )
			: array();

		$data = array();

		foreach ( $products as $product ) {
			if ( ! self::product_available( $product ) ) {
				continue;
			}

			if ( in_array( $product->get_id(), $excluded, true ) ) {
				continue;
			}

			$cat_names = array();
			$cats      = get_the_terms( $product->get_id(), 'product_cat' );
			if ( $cats && ! is_wp_error( $cats ) ) {
				foreach ( $cats as $cat ) {
					$cat_names[] = $cat->name;
				}
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

			$data[] = array(
				'id'            => $product->get_id(),
				'title'         => $product->get_name(),
				'title_norm'    => ZGSS_Fuzzy::normalize( $product->get_name() ),
				'sku'           => $product->get_sku(),
				'sku_norm'      => ZGSS_Fuzzy::normalize( $product->get_sku() ),
				'cats'          => $cat_names,
				'cats_norm'     => ZGSS_Fuzzy::normalize( implode( ' ', $cat_names ) ),
				'price_html'    => $product->get_price_html(),
				'permalink'     => get_permalink( $product->get_id() ),
				'image'         => $image_url,
				'in_stock'      => $product->is_in_stock(),
				'stock_status'  => $product->get_stock_status(),
				'on_sale'       => $product->is_on_sale(),
				'sale_percent'  => $percent,
				'total_sales'   => (int) $product->get_total_sales(),
				'category_ids'  => $product->get_category_ids(),
				'is_bestseller' => in_array( $product->get_id(), $bestseller_ids, true ),
				'attr_terms'    => self::get_attribute_terms( $product->get_id() ),
			);
		}

		return $data;
	}

	/**
	 * Vrátí hodnoty (slugy) WooCommerce atributů produktu, ale jen pro
	 * atributy povolené v nastavení jako klikací filtry (Nastavení >
	 * Vlastnosti produktů jako klikací filtry) - ať index zbytečně
	 * nenarůstá o atributy, které se nikde nepoužívají jako filtr.
	 *
	 * @param int $product_id ID produktu.
	 * @return array {taxonomy_slug: [term_slug, ...]}
	 */
	protected static function get_attribute_terms( $product_id ) {
		$settings   = class_exists( 'ZGSS_Admin' ) ? ZGSS_Admin::get_settings() : array();
		$taxonomies = isset( $settings['enabled_attributes'] ) ? (array) $settings['enabled_attributes'] : array();

		if ( empty( $taxonomies ) ) {
			return array();
		}

		$out = array();
		foreach ( $taxonomies as $taxonomy ) {
			$terms = get_the_terms( $product_id, $taxonomy );
			if ( $terms && ! is_wp_error( $terms ) ) {
				$out[ $taxonomy ] = wp_list_pluck( $terms, 'slug' );
			}
		}
		return $out;
	}

	/**
	 * Vyfiltruje pole kandidátů (z get_candidates()/build_index()) podle
	 * vybraných hodnot vlastností. Napříč různými vlastnostmi se filtruje
	 * jako AND (produkt musí sedět na každou zvolenou vlastnost), v rámci
	 * jedné vlastnosti jako OR (stačí jedna ze zvolených hodnot) - takže
	 * lze kombinovat např. Barva=červené + Typ vína=polosladké.
	 *
	 * @param array $candidates Pole kandidátů.
	 * @param array $filters    {taxonomy_slug: [term_slug, ...]}
	 * @return array
	 */
	public static function filter_candidates( $candidates, $filters ) {
		$filters = array_filter( (array) $filters );
		if ( empty( $filters ) ) {
			return $candidates;
		}

		return array_values(
			array_filter(
				$candidates,
				function ( $c ) use ( $filters ) {
					$attrs = isset( $c['attr_terms'] ) ? $c['attr_terms'] : array();
					foreach ( $filters as $taxonomy => $wanted_terms ) {
						$have = isset( $attrs[ $taxonomy ] ) ? $attrs[ $taxonomy ] : array();
						if ( ! array_intersect( $wanted_terms, $have ) ) {
							return false; // U téhle vlastnosti produkt nesedí na žádnou ze zvolených hodnot.
						}
					}
					return true;
				}
			)
		);
	}

	/**
	 * Spočítá, kolik SKUTEČNĚ dostupných (skladem) produktů má každou
	 * hodnotu vlastnosti - na rozdíl od WordPress/WooCommerce term->count
	 * (get_terms()), který běžně počítá i produkty, co nejsou skladem.
	 * Počítá se přímo z indexu (get_candidates()), který už obsahuje jen
	 * dostupné produkty (viz product_available() v build_index()) - žádný
	 * další DB dotaz navíc, index je stejně cachovaný.
	 *
	 * @return array {taxonomy_slug: {term_slug: count}}
	 */
	public static function count_attribute_terms() {
		$counts = array();
		foreach ( self::get_candidates() as $c ) {
			if ( empty( $c['attr_terms'] ) ) {
				continue;
			}
			foreach ( $c['attr_terms'] as $taxonomy => $slugs ) {
				foreach ( (array) $slugs as $slug ) {
					if ( ! isset( $counts[ $taxonomy ] ) ) {
						$counts[ $taxonomy ] = array();
					}
					if ( ! isset( $counts[ $taxonomy ][ $slug ] ) ) {
						$counts[ $taxonomy ][ $slug ] = 0;
					}
					++$counts[ $taxonomy ][ $slug ];
				}
			}
		}
		return $counts;
	}

	/**
	 * Smaže cache indexu pro všechny jazyky (volá se při uložení/smazání produktu).
	 */
	public static function flush_all_caches() {
		global $wpdb;

		$languages = array( 'default' );

		if ( function_exists( 'wpml_get_active_languages_filter' ) ) {
			$active = apply_filters( 'wpml_active_languages', array() );
			if ( is_array( $active ) ) {
				$languages = array_merge( $languages, array_keys( $active ) );
			}
		}

		foreach ( array_unique( $languages ) as $lang ) {
			delete_transient( self::CACHE_GROUP . $lang );
		}

		// Fallback: smaže i všechny transienty odpovídající prefixu, kdyby
		// byl aktivní jazyk, který jsme výše nezachytili.
		$like = $wpdb->esc_like( '_transient_' . self::CACHE_GROUP ) . '%';
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		// Smaže i cachovaný seznam bestsellerů (štítek "Bestseller" a
		// personalizace ho používají) - musí se přepočítat při každé
		// změně skladu, aby vyprodaný produkt hned přestal být bestseller.
		$like_bestseller = $wpdb->esc_like( '_transient_zgss_bestseller_ids_' ) . '%';
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like_bestseller ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}
}
