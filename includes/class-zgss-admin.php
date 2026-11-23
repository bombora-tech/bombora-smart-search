<?php
/**
 * Administrační stránka pluginu (WooCommerce > Smart Search) se záložkami
 * Nastavení / Náhrady / Statistika.
 *
 * @package ZG_Smart_Search
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZGSS_Admin {

	const OPTION_KEY = 'zgss_settings';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ), 20 );
		add_action( 'admin_init', array( __CLASS__, 'handle_actions' ) );
		add_action( 'wp_ajax_zgss_admin_search_products', array( __CLASS__, 'ajax_search_products' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * Načte drobný inline skript pro widget "vyloučené produkty", jen na naší stránce nastavení.
	 *
	 * @param string $hook Aktuální admin hook/stránka.
	 */
	public static function enqueue_assets( $hook ) {
		if ( 'woocommerce_page_zgss-settings' !== $hook ) {
			return;
		}
		wp_enqueue_script( 'zgss-admin', ZGSS_URL . 'assets/js/zg-admin.js', array(), ZGSS_VERSION, true );
		wp_localize_script(
			'zgss-admin',
			'ZGSS_ADMIN',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'zgss_admin_nonce' ),
			)
		);
	}

	/**
	 * Vrátí ID produktů vyloučených z vyhledávání a doporučení.
	 *
	 * @return int[]
	 */
	public static function get_excluded_ids() {
		$settings = self::get_settings();
		$ids      = isset( $settings['excluded_products'] ) ? $settings['excluded_products'] : array();
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * AJAX: vyhledávání produktů v administraci pro přidání do vyloučeného seznamu.
	 */
	public static function ajax_search_products() {
		check_ajax_referer( 'zgss_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error();
		}

		$term = isset( $_GET['term'] ) ? sanitize_text_field( wp_unslash( $_GET['term'] ) ) : '';

		if ( strlen( $term ) < 2 ) {
			wp_send_json_success( array() );
		}

		$products = wc_get_products(
			array(
				's'      => $term,
				'limit'  => 15,
				'status' => 'publish',
				'return' => 'objects',
			)
		);

		$out = array();
		foreach ( $products as $product ) {
			$out[] = array(
				'id'   => $product->get_id(),
				'text' => $product->get_name() . ' (#' . $product->get_id() . ')',
			);
		}

		wp_send_json_success( $out );
	}

	/**
	 * Výchozí hodnoty nastavení.
	 *
	 * @return array
	 */
	public static function default_settings() {
		return array(
			'results_count'          => 8,
			'suggestions_count'      => 4,
			'min_chars'              => 2,
			'debounce_ms'            => 300,
			'cache_hours'            => 12,
			'enable_stats'           => 1,
			'include_backorder'      => 0,
			'excluded_products'      => array(),
			'show_bestseller_badge'  => 0,
			'bestseller_badge_text'  => __( 'Bestseller', 'zg-smart-search' ),
			'bestseller_count'       => 20,
			'enable_typewriter'      => 0,
			'typewriter_phrases'     => "Kindzmarauli\nSaperavi\nGruzínský čaj\nČača",
			// Elasticsearch - viz includes/class-zgss-elasticsearch.php.
			// 'search_engine' = 'mysql' (výchozí, beze změny chování) nebo 'elasticsearch'.
			'search_engine'          => 'mysql',
			'es_scheme'              => 'https',
			'es_host'                => '',
			'es_port'                => 9200,
			'es_index_prefix'        => 'zgss_products',
			'es_auth_type'           => 'none',
			'es_username'            => '',
			'es_password'            => '',
			'es_api_key'             => '',
			'es_verify_ssl'          => 1,
			'es_stats_enabled'       => 1,
			// Viditelnost bloků v levém sloupci dropdownu.
			'show_popular_block'     => 1,
			'show_categories_block'  => 1,
			// Vlastnosti produktů (WooCommerce atributy, např. pa_barva,
			// pa_typ-vina) povolené jako klikací filtry - viz
			// get_wc_attributes()/get_attribute_filter_groups() v ZGSS_Ajax.
			'enabled_attributes'     => array(),
			// Pořadí bloků v levém sloupci na desktopu (výsledky jsou tam
			// vždy samostatný sloupec vpravo, do pořadí nepatří).
			'sidebar_order'          => array( 'popular', 'categories' ),
			// Pořadí VŠECH bloků na mobilu/tabletu shora dolů, včetně
			// výsledků ("main") - tam je jeden svislý sloupec, takže
			// výsledky mohou být kdekoliv v pořadí.
			'mobile_order'           => array( 'popular', 'main', 'categories' ),
			// Jak se kombinuje napsaný text s vybranými filtry vlastností:
			// 'and'    = platí obojí zároveň (výchozí, dosavadní chování)
			// 'latest' = platí jen to, co uživatel zvolil naposledy (napsání
			//            textu zruší aktivní filtry a naopak)
			'filter_combine_mode'    => 'and',
			// Jak se vybírají doporučené produkty (prázdný stav vyhledávání
			// a obecný fallback, když nic jiného relevantnějšího nelze
			// nabídnout - viz ZGSS_Recommendations::bestsellers()):
			// 'popularity' | 'newest' | 'price_low' | 'price_high' | 'manual'
			'recommendation_mode'       => 'popularity',
			'recommendation_manual_ids' => array(),
		);
	}

	/**
	 * Vrátí seznam všech WooCommerce atributů produktů (globálních, s
	 * taxonomií pa_*) pro výběr, které z nich nabídnout jako klikací filtry.
	 *
	 * @return array Pole {taxonomy, label}.
	 */
	public static function get_wc_attributes() {
		if ( ! function_exists( 'wc_get_attribute_taxonomies' ) ) {
			return array();
		}

		$out = array();
		foreach ( (array) wc_get_attribute_taxonomies() as $attr ) {
			$taxonomy = wc_attribute_taxonomy_name( $attr->attribute_name );
			$out[]    = array(
				'taxonomy' => $taxonomy,
				'label'    => $attr->attribute_label ? $attr->attribute_label : $attr->attribute_name,
			);
		}
		return $out;
	}

	/**
	 * Vrátí aktuální nastavení sloučené s výchozími hodnotami.
	 *
	 * @return array
	 */
	public static function get_settings() {
		$saved = get_option( self::OPTION_KEY, array() );
		return wp_parse_args( $saved, self::default_settings() );
	}

	public static function add_menu() {
		// Pod menu Bombora (Bombora Manager), jinak pod WooCommerce. Adresa stránky zůstává stejná.
		$parent = isset( $GLOBALS['admin_page_hooks']['bombora'] ) ? 'bombora' : 'woocommerce';
		add_submenu_page(
			$parent,
			__( 'Bombora Smart Search', 'zg-smart-search' ),
			__( 'Smart Search', 'zg-smart-search' ),
			'manage_woocommerce',
			'zgss-settings',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Zpracuje uložení formulářů a akční tlačítka napříč všemi záložkami.
	 */
	public static function handle_actions() {
		if ( ! isset( $_POST['zgss_action'] ) || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		check_admin_referer( 'zgss_settings_save' );

		$action = sanitize_text_field( wp_unslash( $_POST['zgss_action'] ) );

		if ( 'save_settings' === $action ) {
			$excluded = array();
			if ( isset( $_POST['excluded_products'] ) && is_array( $_POST['excluded_products'] ) ) {
				$excluded = array_unique( array_map( 'intval', wp_unslash( $_POST['excluded_products'] ) ) );
			}

			$valid_taxonomies  = wp_list_pluck( self::get_wc_attributes(), 'taxonomy' );
			$enabled_attrs_raw = ( isset( $_POST['enabled_attributes'] ) && is_array( $_POST['enabled_attributes'] ) ) ? wp_unslash( $_POST['enabled_attributes'] ) : array();
			$enabled_attrs     = array_values( array_intersect( array_map( 'sanitize_key', $enabled_attrs_raw ), $valid_taxonomies ) );

			$sidebar_order = ( isset( $_POST['sidebar_order'] ) && is_array( $_POST['sidebar_order'] ) ) ? array_map( 'sanitize_key', wp_unslash( $_POST['sidebar_order'] ) ) : array();
			$mobile_order  = ( isset( $_POST['mobile_order'] ) && is_array( $_POST['mobile_order'] ) ) ? array_map( 'sanitize_key', wp_unslash( $_POST['mobile_order'] ) ) : array();

			$filter_combine_mode = ( isset( $_POST['filter_combine_mode'] ) && 'latest' === $_POST['filter_combine_mode'] ) ? 'latest' : 'and';

			$allowed_rec_modes = array( 'popularity', 'newest', 'price_low', 'price_high', 'random', 'manual' );
			$recommendation_mode = ( isset( $_POST['recommendation_mode'] ) && in_array( $_POST['recommendation_mode'], $allowed_rec_modes, true ) )
				? $_POST['recommendation_mode']
				: 'popularity';

			$recommendation_manual_ids = array();
			if ( isset( $_POST['recommendation_manual_ids'] ) && is_array( $_POST['recommendation_manual_ids'] ) ) {
				$recommendation_manual_ids = array_unique( array_map( 'intval', wp_unslash( $_POST['recommendation_manual_ids'] ) ) );
			}

			$settings = array(
				'results_count'          => max( 1, (int) ( $_POST['results_count'] ?? 8 ) ),
				'suggestions_count'      => max( 1, (int) ( $_POST['suggestions_count'] ?? 4 ) ),
				'min_chars'              => max( 1, (int) ( $_POST['min_chars'] ?? 2 ) ),
				'debounce_ms'            => max( 100, (int) ( $_POST['debounce_ms'] ?? 300 ) ),
				'cache_hours'            => max( 1, (int) ( $_POST['cache_hours'] ?? 12 ) ),
				'enable_stats'           => isset( $_POST['enable_stats'] ) ? 1 : 0,
				'include_backorder'      => isset( $_POST['include_backorder'] ) ? 1 : 0,
				'excluded_products'      => $excluded,
				'show_bestseller_badge'  => isset( $_POST['show_bestseller_badge'] ) ? 1 : 0,
				'bestseller_badge_text'  => sanitize_text_field( wp_unslash( $_POST['bestseller_badge_text'] ?? 'Bestseller' ) ),
				'bestseller_count'       => max( 1, (int) ( $_POST['bestseller_count'] ?? 20 ) ),
				'enable_typewriter'      => isset( $_POST['enable_typewriter'] ) ? 1 : 0,
				'typewriter_phrases'     => isset( $_POST['typewriter_phrases'] ) ? sanitize_textarea_field( wp_unslash( $_POST['typewriter_phrases'] ) ) : '',
				'show_popular_block'     => isset( $_POST['show_popular_block'] ) ? 1 : 0,
				'show_categories_block'  => isset( $_POST['show_categories_block'] ) ? 1 : 0,
				'enabled_attributes'     => $enabled_attrs,
				// Uložit rovnou, co přišlo - vynechání neplatných/nových
				// klíčů řeší get_ordered_blocks() při čtení (self-healing),
				// takže tady netřeba validovat proti $enabled_attrs.
				'sidebar_order'          => $sidebar_order,
				'mobile_order'           => $mobile_order,
				'filter_combine_mode'    => $filter_combine_mode,
				'recommendation_mode'       => $recommendation_mode,
				'recommendation_manual_ids' => $recommendation_manual_ids,
			);
			update_option( self::OPTION_KEY, $settings );
			ZGSS_Index::flush_all_caches();
			add_settings_error( 'zgss', 'zgss_saved', __( 'Nastavení uloženo.', 'zg-smart-search' ), 'success' );
		} elseif ( 'clear_cache' === $action ) {
			ZGSS_Index::flush_all_caches();
			add_settings_error( 'zgss', 'zgss_cache', __( 'Cache indexu produktů byla vymazána.', 'zg-smart-search' ), 'success' );
		} elseif ( 'reset_stats' === $action ) {
			ZGSS_Popular_Terms::reset();
			add_settings_error( 'zgss', 'zgss_stats', __( 'Statistiky hledaných výrazů byly vynulovány.', 'zg-smart-search' ), 'success' );
		} elseif ( 'add_replacement' === $action ) {
			$term        = isset( $_POST['replacement_term'] ) ? sanitize_text_field( wp_unslash( $_POST['replacement_term'] ) ) : '';
			$replacement = isset( $_POST['replacement_value'] ) ? sanitize_text_field( wp_unslash( $_POST['replacement_value'] ) ) : '';
			$highlight   = isset( $_POST['replacement_highlight'] );

			if ( ZGSS_Replacements::add( $term, $replacement, $highlight ) ) {
				add_settings_error( 'zgss', 'zgss_repl_added', __( 'Náhrada byla přidána.', 'zg-smart-search' ), 'success' );
			} else {
				add_settings_error( 'zgss', 'zgss_repl_error', __( 'Vyplňte prosím oba výrazy.', 'zg-smart-search' ), 'error' );
			}
		} elseif ( 'delete_replacement' === $action ) {
			ZGSS_Replacements::delete( (int) ( $_POST['replacement_id'] ?? 0 ) );
			add_settings_error( 'zgss', 'zgss_repl_deleted', __( 'Náhrada byla smazána.', 'zg-smart-search' ), 'success' );
		} elseif ( 'save_es_settings' === $action ) {
			$settings = self::get_settings();

			$engine = isset( $_POST['search_engine'] ) && 'elasticsearch' === $_POST['search_engine'] ? 'elasticsearch' : 'mysql';

			$settings['search_engine']    = $engine;
			$settings['es_scheme']        = ( isset( $_POST['es_scheme'] ) && 'http' === $_POST['es_scheme'] ) ? 'http' : 'https';
			$settings['es_host']          = isset( $_POST['es_host'] ) ? sanitize_text_field( wp_unslash( $_POST['es_host'] ) ) : '';
			$settings['es_port']          = isset( $_POST['es_port'] ) ? max( 1, (int) $_POST['es_port'] ) : 9200;
			$settings['es_index_prefix']  = isset( $_POST['es_index_prefix'] ) ? sanitize_key( wp_unslash( $_POST['es_index_prefix'] ) ) : 'zgss_products';
			$settings['es_auth_type']     = isset( $_POST['es_auth_type'] ) && in_array( $_POST['es_auth_type'], array( 'none', 'basic', 'api_key' ), true ) ? $_POST['es_auth_type'] : 'none';
			$settings['es_username']      = isset( $_POST['es_username'] ) ? sanitize_text_field( wp_unslash( $_POST['es_username'] ) ) : '';
			// Heslo/API klíč: pokud pole necháš prázdné (např. jen znovu ukládáš host), zůstane zachovaná stávající hodnota.
			if ( ! empty( $_POST['es_password'] ) ) {
				$settings['es_password'] = sanitize_text_field( wp_unslash( $_POST['es_password'] ) );
			}
			if ( ! empty( $_POST['es_api_key'] ) ) {
				$settings['es_api_key'] = sanitize_text_field( wp_unslash( $_POST['es_api_key'] ) );
			}
			$settings['es_verify_ssl']    = isset( $_POST['es_verify_ssl'] ) ? 1 : 0;
			$settings['es_stats_enabled'] = isset( $_POST['es_stats_enabled'] ) ? 1 : 0;

			update_option( self::OPTION_KEY, $settings );
			add_settings_error( 'zgss', 'zgss_es_saved', __( 'Nastavení Elasticsearch uloženo.', 'zg-smart-search' ), 'success' );
		}
	}

	/**
	 * Čitelný popisek bloku pro admin UI (řazení) i jako obecná pomůcka.
	 *
	 * @param string $key 'popular' | 'categories' | 'main' | taxonomy atributu (pa_*).
	 * @return string
	 */
	public static function get_block_label( $key ) {
		if ( 'popular' === $key ) {
			return __( 'Oblíbená vyhledávání', 'zg-smart-search' );
		}
		if ( 'categories' === $key ) {
			return __( 'Kategorie', 'zg-smart-search' );
		}
		if ( 'main' === $key ) {
			return __( 'Výsledky vyhledávání', 'zg-smart-search' );
		}
		if ( function_exists( 'wc_attribute_label' ) && 0 === strpos( $key, 'pa_' ) ) {
			return wc_attribute_label( $key );
		}
		return $key;
	}

	/**
	 * Vrátí finální (self-healing) pořadí bloků pro daný kontext. Zachovává
	 * uložené pořadí pro klíče, které stále existují, klíče, co mezitím
	 * zmizely (např. vypnutá vlastnost), tiše vynechá a nové (nově povolená
	 * vlastnost) doplní na konec - takže se objeví, i než je admin ručně
	 * přeuspořádá a uloží.
	 *
	 * @param string $context 'sidebar' (desktop, bez 'main') nebo 'mobile' (včetně 'main').
	 * @return string[]
	 */
	public static function get_ordered_blocks( $context ) {
		$settings = self::get_settings();
		$attrs    = isset( $settings['enabled_attributes'] ) ? (array) $settings['enabled_attributes'] : array();

		// Do seznamu se počítají jen bloky, které jsou reálně zapnuté k
		// zobrazení (viz přepínače "Zobrazení bloků v levém sloupci") -
		// vypnutý blok nemá smysl v pořadí nabízet. "main" (výsledky) na
		// mobilu žádný přepínač nemá, zobrazuje se vždy.
		$base = ! empty( $settings['show_popular_block'] ) ? array( 'popular' ) : array();
		$base = array_merge( $base, $attrs );
		$categories_key = ! empty( $settings['show_categories_block'] ) ? array( 'categories' ) : array();

		if ( 'mobile' === $context ) {
			$valid_keys = array_merge( $base, array( 'main' ), $categories_key );
			$saved      = isset( $settings['mobile_order'] ) ? (array) $settings['mobile_order'] : array();
		} else {
			$valid_keys = array_merge( $base, $categories_key );
			$saved      = isset( $settings['sidebar_order'] ) ? (array) $settings['sidebar_order'] : array();
		}

		$kept    = array_values( array_intersect( $saved, $valid_keys ) );
		$missing = array_values( array_diff( $valid_keys, $kept ) );

		return array_merge( $kept, $missing );
	}

	/**
	 * Pro zobrazení chipů (vyloučené produkty, ruční výběr doporučených…)
	 * potřebujeme jejich aktuální název podle ID.
	 *
	 * @param array $ids Pole ID produktů.
	 * @return array Pole {id, title}.
	 */
	protected static function get_products_for_display( $ids ) {
		$out = array();
		foreach ( (array) $ids as $id ) {
			$product = wc_get_product( (int) $id );
			$out[]   = array(
				'id'    => (int) $id,
				'title' => $product ? $product->get_name() : sprintf( '#%d', (int) $id ),
			);
		}
		return $out;
	}

	/**
	 * Vykreslí widget pro výběr produktů (textové hledání + chipy) -
	 * znovupoužitelný pro "Vyloučit produkty" i "Ruční výběr doporučených".
	 * Obsluhuje ho generický JS v zg-admin.js podle třídy .zgss-product-picker
	 * a atributu data-field (ne pevná ID, ať jde mít na stránce víc instancí).
	 *
	 * @param string $field_name   Název POST pole (bez []).
	 * @param array  $selected_ids Aktuálně vybraná ID produktů.
	 */
	protected static function render_product_picker( $field_name, $selected_ids ) {
		?>
		<div class="zgss-product-picker" data-field="<?php echo esc_attr( $field_name ); ?>">
			<input type="text" class="zgss-pp-input regular-text" autocomplete="off" placeholder="<?php esc_attr_e( 'Začněte psát název produktu…', 'zg-smart-search' ); ?>" />
			<div class="zgss-pp-suggestions" hidden></div>
			<div class="zgss-pp-chips">
				<?php foreach ( self::get_products_for_display( $selected_ids ) as $p ) : ?>
					<span class="zgss-pp-chip" data-id="<?php echo esc_attr( $p['id'] ); ?>">
						<?php echo esc_html( $p['title'] ); ?>
						<input type="hidden" name="<?php echo esc_attr( $field_name ); ?>[]" value="<?php echo esc_attr( $p['id'] ); ?>" />
						<button type="button" class="zgss-pp-chip__remove" aria-label="<?php esc_attr_e( 'Odebrat', 'zg-smart-search' ); ?>">&times;</button>
					</span>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Vykreslí celou administrační stránku (záložky + obsah).
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'settings'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $tab, array( 'settings', 'replacements', 'stats', 'elasticsearch' ), true ) ) {
			$tab = 'settings';
		}

		settings_errors( 'zgss' );
		self::render_styles();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Bombora Smart Search', 'zg-smart-search' ); ?></h1>
			<p><?php esc_html_e( 'Vložte shortcode [zg_smart_search] do stránky (např. přes Bricks element "Shortcode") tam, kde má být vyhledávací pole.', 'zg-smart-search' ); ?></p>

			<h2 class="nav-tab-wrapper">
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'settings' ) ); ?>" class="nav-tab <?php echo 'settings' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Nastavení', 'zg-smart-search' ); ?></a>
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'replacements' ) ); ?>" class="nav-tab <?php echo 'replacements' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Náhrady', 'zg-smart-search' ); ?></a>
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'stats' ) ); ?>" class="nav-tab <?php echo 'stats' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Statistika', 'zg-smart-search' ); ?></a>
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'elasticsearch' ) ); ?>" class="nav-tab <?php echo 'elasticsearch' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Elasticsearch', 'zg-smart-search' ); ?></a>
			</h2>

			<div class="zgss-tab-content">
				<?php
				if ( 'replacements' === $tab ) {
					self::render_replacements_tab();
				} elseif ( 'stats' === $tab ) {
					self::render_stats_tab();
				} elseif ( 'elasticsearch' === $tab ) {
					self::render_elasticsearch_tab();
				} else {
					self::render_settings_tab();
				}
				?>
			</div>
		</div>
		<?php
	}

	protected static function render_styles() {
		?>
		<style>
			.zgss-tab-content { margin-top: 20px; }
			.zgss-product-picker { position: relative; max-width: 480px; }
			.zgss-pp-suggestions { position: absolute; top: 100%; left: 0; right: 0; background: #fff; border: 1px solid #ccd0d4; box-shadow: 0 2px 6px rgba(0,0,0,.1); z-index: 10; max-height: 220px; overflow-y: auto; }
			.zgss-pp-suggestions button { display: block; width: 100%; text-align: left; padding: 8px 10px; border: 0; background: none; cursor: pointer; }
			.zgss-pp-suggestions button:hover { background: #f0f0f1; }
			.zgss-pp-chips { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 10px; }
			.zgss-pp-chip { display: inline-flex; align-items: center; gap: 6px; background: #f0f0f1; border-radius: 4px; padding: 4px 8px; font-size: 13px; }
			.zgss-pp-chip__remove { border: 0; background: none; cursor: pointer; font-size: 15px; line-height: 1; color: #a00; padding: 0; }
			.zgss-repl-form { display: flex; gap: 10px; align-items: flex-end; flex-wrap: wrap; background: #fff; border: 1px solid #ccd0d4; padding: 15px; margin-bottom: 20px; }
			.zgss-repl-form .zgss-field label { display: block; font-weight: 600; margin-bottom: 4px; font-size: 12px; }
			.zgss-repl-table { border-collapse: collapse; width: 100%; max-width: 760px; background: #fff; }
			.zgss-repl-table th, .zgss-repl-table td { text-align: left; padding: 8px 12px; border-bottom: 1px solid #eee; }
			.zgss-repl-arrow { color: #999; padding: 0 6px; }
			.zgss-stat-cards { display: flex; gap: 16px; margin-bottom: 24px; flex-wrap: wrap; }
			.zgss-stat-card { background: #fff; border: 1px solid #ccd0d4; padding: 16px 22px; min-width: 160px; }
			.zgss-stat-card__num { font-size: 26px; font-weight: 700; display: block; }
			.zgss-stat-card__label { color: #666; font-size: 12px; text-transform: uppercase; }
			.zgss-terms-table { border-collapse: collapse; width: 100%; max-width: 900px; background: #fff; }
			.zgss-terms-table th, .zgss-terms-table td { text-align: left; padding: 8px 12px; border-bottom: 1px solid #eee; }
			.zgss-terms-table tr.zgss-zero-result { background: #fff5f5; }
			.zgss-badge-zero { display: inline-block; background: #d63638; color: #fff; font-size: 11px; padding: 2px 6px; border-radius: 3px; margin-left: 6px; }
			.zgss-es-status { display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; border-radius: 4px; font-size: 13px; font-weight: 600; margin-bottom: 16px; }
			.zgss-es-status.is-ok { background: #edfaef; color: #1a7a30; }
			.zgss-es-status.is-error { background: #fdeeee; color: #a00; }
			.zgss-es-status.is-off { background: #f0f0f1; color: #555; }
			.zgss-es-metrics { display: flex; gap: 20px; flex-wrap: wrap; margin: 16px 0 24px; }
			.zgss-es-gauge { background: #fff; border: 1px solid #ccd0d4; padding: 16px 18px; min-width: 220px; }
			.zgss-es-gauge__label { font-size: 12px; color: #666; text-transform: uppercase; margin-bottom: 6px; display: flex; justify-content: space-between; }
			.zgss-es-gauge__bar { height: 10px; background: #eee; border-radius: 5px; overflow: hidden; }
			.zgss-es-gauge__fill { height: 100%; background: #2271b1; transition: width .4s ease; }
			.zgss-es-gauge__fill.is-warn { background: #dba617; }
			.zgss-es-gauge__fill.is-crit { background: #d63638; }
			.zgss-es-gauge__num { font-size: 22px; font-weight: 700; margin-top: 4px; display: block; }
			.zgss-es-spark { border: 1px solid #ccd0d4; background: #fff; padding: 10px; margin-top: 4px; }
			.zgss-es-spark canvas { display: block; width: 100%; height: 60px; }
			.zgss-es-node-name { font-size: 11px; color: #888; margin-top: 6px; }
			table.form-table.zgss-es-table th { width: 220px; }
			.zgss-order-list { list-style: none; margin: 10px 0; padding: 0; min-width: 260px; }
			.zgss-order-list li { display: flex; align-items: center; justify-content: space-between; gap: 8px; background: #fff; border: 1px solid #ccd0d4; padding: 7px 10px; margin-bottom: 4px; border-radius: 4px; }
			.zgss-order-list li span { font-size: 13px; }
			.zgss-order-up, .zgss-order-down { border: 1px solid #ccd0d4; background: #f6f7f7; border-radius: 3px; width: 26px; height: 26px; cursor: pointer; line-height: 1; }
			.zgss-order-up:hover, .zgss-order-down:hover { background: #eee; }
		</style>
		<?php
	}

	/**
	 * Záložka "Nastavení".
	 */
	protected static function render_settings_tab() {
		$settings = self::get_settings();
		?>
		<form method="post">
			<?php wp_nonce_field( 'zgss_settings_save' ); ?>
			<input type="hidden" name="zgss_action" value="save_settings" />
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="results_count"><?php esc_html_e( 'Počet zobrazených produktů', 'zg-smart-search' ); ?></label></th>
					<td><input type="number" min="1" max="24" id="results_count" name="results_count" value="<?php echo esc_attr( $settings['results_count'] ); ?>" class="small-text" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="suggestions_count"><?php esc_html_e( 'Počet doporučených produktů ("Mohlo by se vám líbit")', 'zg-smart-search' ); ?></label></th>
					<td><input type="number" min="1" max="12" id="suggestions_count" name="suggestions_count" value="<?php echo esc_attr( $settings['suggestions_count'] ); ?>" class="small-text" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="min_chars"><?php esc_html_e( 'Minimální počet znaků pro spuštění hledání', 'zg-smart-search' ); ?></label></th>
					<td><input type="number" min="1" max="5" id="min_chars" name="min_chars" value="<?php echo esc_attr( $settings['min_chars'] ); ?>" class="small-text" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="debounce_ms"><?php esc_html_e( 'Zpoždění hledání po psaní (ms)', 'zg-smart-search' ); ?></label></th>
					<td><input type="number" min="100" max="2000" step="50" id="debounce_ms" name="debounce_ms" value="<?php echo esc_attr( $settings['debounce_ms'] ); ?>" class="small-text" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="cache_hours"><?php esc_html_e( 'Platnost cache indexu produktů (hodiny)', 'zg-smart-search' ); ?></label></th>
					<td><input type="number" min="1" max="72" id="cache_hours" name="cache_hours" value="<?php echo esc_attr( $settings['cache_hours'] ); ?>" class="small-text" />
					<p class="description"><?php esc_html_e( 'Index se navíc automaticky přepočítá při uložení/smazání jakéhokoliv produktu.', 'zg-smart-search' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Statistiky vyhledávání', 'zg-smart-search' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="enable_stats" <?php checked( ! empty( $settings['enable_stats'] ) ); ?> />
							<?php esc_html_e( 'Zaznamenávat hledané výrazy (pro záložku "Statistika" a "Oblíbená vyhledávání")', 'zg-smart-search' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Předobjednávky ve výsledcích', 'zg-smart-search' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="include_backorder" <?php checked( ! empty( $settings['include_backorder'] ) ); ?> />
							<?php esc_html_e( 'Zahrnout do vyhledávání i produkty ve stavu "Na objednávku" (onbackorder)', 'zg-smart-search' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Po uložení se index přepočítá automaticky. Při Elasticsearch enginu spusťte reindex.', 'zg-smart-search' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Štítek u bestsellerů', 'zg-smart-search' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="show_bestseller_badge" <?php checked( ! empty( $settings['show_bestseller_badge'] ) ); ?> />
							<?php esc_html_e( 'Zobrazovat u nejprodávanějších produktů štítek ve výsledcích vyhledávání', 'zg-smart-search' ); ?>
						</label>
						<p>
							<label for="bestseller_badge_text"><?php esc_html_e( 'Text štítku:', 'zg-smart-search' ); ?></label>
							<input type="text" id="bestseller_badge_text" name="bestseller_badge_text" value="<?php echo esc_attr( $settings['bestseller_badge_text'] ); ?>" class="regular-text" style="margin-left:6px;" />
						</p>
						<p>
							<label for="bestseller_count"><?php esc_html_e( 'Počet nejprodávanějších produktů, které štítek dostanou:', 'zg-smart-search' ); ?></label>
							<input type="number" min="1" max="100" id="bestseller_count" name="bestseller_count" value="<?php echo esc_attr( $settings['bestseller_count'] ); ?>" class="small-text" style="margin-left:6px;" />
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Animovaná nápověda v poli hledání', 'zg-smart-search' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="enable_typewriter" <?php checked( ! empty( $settings['enable_typewriter'] ) ); ?> />
							<?php esc_html_e( 'Když je pole prázdné a nemá fokus, postupně v něm "vypisovat" ukázkové výrazy, ať upoutá pozornost', 'zg-smart-search' ); ?>
						</label>
						<p>
							<label for="typewriter_phrases"><?php esc_html_e( 'Výrazy (jeden na řádek):', 'zg-smart-search' ); ?></label><br />
							<textarea id="typewriter_phrases" name="typewriter_phrases" rows="5" class="regular-text" style="margin-top:4px;"><?php echo esc_textarea( $settings['typewriter_phrases'] ); ?></textarea>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Zobrazení bloků v levém sloupci', 'zg-smart-search' ); ?></th>
					<td>
						<label style="display:block;margin-bottom:6px;">
							<input type="checkbox" name="show_popular_block" <?php checked( ! empty( $settings['show_popular_block'] ) ); ?> />
							<?php esc_html_e( 'Zobrazovat "Oblíbená vyhledávání"', 'zg-smart-search' ); ?>
						</label>
						<label style="display:block;">
							<input type="checkbox" name="show_categories_block" <?php checked( ! empty( $settings['show_categories_block'] ) ); ?> />
							<?php esc_html_e( 'Zobrazovat "Kategorie"', 'zg-smart-search' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Vlastnosti produktů jako klikací filtry', 'zg-smart-search' ); ?></th>
					<td>
						<?php $wc_attributes = self::get_wc_attributes(); ?>
						<?php if ( empty( $wc_attributes ) ) : ?>
							<p class="description"><?php esc_html_e( 'Ve WooCommerce zatím nemáte založené žádné globální atributy (Produkty > Atributy).', 'zg-smart-search' ); ?></p>
						<?php else : ?>
							<?php foreach ( $wc_attributes as $attr ) : ?>
								<label style="display:block;margin-bottom:4px;">
									<input type="checkbox" name="enabled_attributes[]" value="<?php echo esc_attr( $attr['taxonomy'] ); ?>" <?php checked( in_array( $attr['taxonomy'], (array) $settings['enabled_attributes'], true ) ); ?> />
									<?php echo esc_html( $attr['label'] ); ?> <code><?php echo esc_html( $attr['taxonomy'] ); ?></code>
								</label>
							<?php endforeach; ?>
							<p class="description">
								<?php esc_html_e( 'Zákazník bude moci kliknutím vybrat hodnoty (např. Barva: červené, bílé) a kombinovat je i napříč vlastnostmi. Po změně výběru proveď prosím znovu reindexaci v záložce Elasticsearch (pokud ho používáš) - MySQL cache se přepočítá automaticky.', 'zg-smart-search' ); ?>
							</p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Kombinace textu a filtrů vlastností', 'zg-smart-search' ); ?></th>
					<td>
						<label style="display:block;margin-bottom:6px;">
							<input type="radio" name="filter_combine_mode" value="and" <?php checked( 'and' === $settings['filter_combine_mode'] ); ?> />
							<?php esc_html_e( 'A zároveň (AND) - napsaný text i vybrané filtry platí současně', 'zg-smart-search' ); ?>
						</label>
						<label style="display:block;">
							<input type="radio" name="filter_combine_mode" value="latest" <?php checked( 'latest' === $settings['filter_combine_mode'] ); ?> />
							<?php esc_html_e( 'Podle posledního výběru - napsání textu zruší aktivní filtry a naopak (co zvolím jako poslední, podle toho se hledá)', 'zg-smart-search' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Pořadí bloků', 'zg-smart-search' ); ?></th>
					<td>
						<div style="display:flex;gap:48px;flex-wrap:wrap;">
							<div>
								<strong><?php esc_html_e( 'Desktop (levý sloupec)', 'zg-smart-search' ); ?></strong>
								<ul class="zgss-order-list">
									<?php foreach ( self::get_ordered_blocks( 'sidebar' ) as $key ) : ?>
										<li>
											<span><?php echo esc_html( self::get_block_label( $key ) ); ?></span>
											<input type="hidden" name="sidebar_order[]" value="<?php echo esc_attr( $key ); ?>" />
											<button type="button" class="zgss-order-up" aria-label="<?php esc_attr_e( 'Posunout nahoru', 'zg-smart-search' ); ?>">&uarr;</button>
											<button type="button" class="zgss-order-down" aria-label="<?php esc_attr_e( 'Posunout dolů', 'zg-smart-search' ); ?>">&darr;</button>
										</li>
									<?php endforeach; ?>
								</ul>
								<p class="description"><?php esc_html_e( 'Výsledky vyhledávání jsou na desktopu vždy samostatný sloupec vpravo - do tohoto pořadí nepatří.', 'zg-smart-search' ); ?></p>
							</div>
							<div>
								<strong><?php esc_html_e( 'Mobil a tablet (shora dolů)', 'zg-smart-search' ); ?></strong>
								<ul class="zgss-order-list">
									<?php foreach ( self::get_ordered_blocks( 'mobile' ) as $key ) : ?>
										<li>
											<span><?php echo esc_html( self::get_block_label( $key ) ); ?></span>
											<input type="hidden" name="mobile_order[]" value="<?php echo esc_attr( $key ); ?>" />
											<button type="button" class="zgss-order-up" aria-label="<?php esc_attr_e( 'Posunout nahoru', 'zg-smart-search' ); ?>">&uarr;</button>
											<button type="button" class="zgss-order-down" aria-label="<?php esc_attr_e( 'Posunout dolů', 'zg-smart-search' ); ?>">&darr;</button>
										</li>
									<?php endforeach; ?>
								</ul>
								<p class="description"><?php esc_html_e( 'Na mobilu je vše v jednom sloupci - "Výsledky vyhledávání" mezi ně lze zařadit kamkoliv.', 'zg-smart-search' ); ?></p>
							</div>
						</div>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Vyloučit produkty z vyhledávání', 'zg-smart-search' ); ?></th>
					<td>
						<?php self::render_product_picker( 'excluded_products', $settings['excluded_products'] ); ?>
						<p class="description"><?php esc_html_e( 'Tyto produkty se nikdy nezobrazí ve výsledcích vyhledávání ani v doporučeních.', 'zg-smart-search' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Doporučené produkty', 'zg-smart-search' ); ?></th>
					<td>
						<?php
						$rec_mode    = isset( $settings['recommendation_mode'] ) ? $settings['recommendation_mode'] : 'popularity';
						$rec_options = array(
							'popularity' => __( 'Nejprodávanější (podle celkových prodejů)', 'zg-smart-search' ),
							'newest'     => __( 'Nejnovější produkty', 'zg-smart-search' ),
							'price_low'  => __( 'Nejlevnější', 'zg-smart-search' ),
							'price_high' => __( 'Nejdražší', 'zg-smart-search' ),
							'random'     => __( 'Náhodné produkty', 'zg-smart-search' ),
							'manual'     => __( 'Ruční výběr (vyber konkrétní produkty níže)', 'zg-smart-search' ),
						);
						?>
						<?php foreach ( $rec_options as $value => $label ) : ?>
							<label style="display:block;margin-bottom:4px;">
								<input type="radio" name="recommendation_mode" class="zgss-rec-mode-radio" value="<?php echo esc_attr( $value ); ?>" <?php checked( $rec_mode === $value ); ?> />
								<?php echo esc_html( $label ); ?>
							</label>
						<?php endforeach; ?>

						<div class="zgss-rec-manual-picker" style="margin-top:12px;">
							<?php self::render_product_picker( 'recommendation_manual_ids', $settings['recommendation_manual_ids'] ); ?>
							<p class="description"><?php esc_html_e( 'Použije se jen v režimu "Ruční výběr". Pokud nevybereš dost produktů na doplnění celého řádku, zbytek se doplní podle prodejnosti.', 'zg-smart-search' ); ?></p>
						</div>

						<p class="description">
							<?php esc_html_e( 'Platí pro prázdný stav vyhledávání ("Doporučujeme") a jako obecný záložní výběr, když nelze nabídnout nic relevantnějšího. Vyloučené produkty (viz výše) se nikdy nenabídnou, ani v ručním výběru. Doporučení už nezohledňují historii nákupů přihlášeného zákazníka - řídí se výhradně tímto nastavením.', 'zg-smart-search' ); ?>
						</p>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Uložit nastavení', 'zg-smart-search' ) ); ?>
		</form>

		<hr />
		<h2><?php esc_html_e( 'Údržba', 'zg-smart-search' ); ?></h2>
		<form method="post" style="display:inline-block;margin-right:10px;">
			<?php wp_nonce_field( 'zgss_settings_save' ); ?>
			<input type="hidden" name="zgss_action" value="clear_cache" />
			<?php submit_button( __( 'Vymazat cache indexu produktů', 'zg-smart-search' ), 'secondary', 'submit', false ); ?>
		</form>
		<?php
	}

	/**
	 * Záložka "Náhrady" - ruční slovník synonym/typo.
	 */
	protected static function render_replacements_tab() {
		$rules = ZGSS_Replacements::get_all();
		?>
		<p><?php esc_html_e( 'Náhrady mají přednost před automatickým rozpoznáváním překlepů. Použijte je pro konkrétní, opakující se dotazy - typické překlepy, cizí výrazy nebo formátování (např. "750" → "0,75").', 'zg-smart-search' ); ?></p>

		<form method="post" class="zgss-repl-form">
			<?php wp_nonce_field( 'zgss_settings_save' ); ?>
			<input type="hidden" name="zgss_action" value="add_replacement" />
			<div class="zgss-field">
				<label for="replacement_term"><?php esc_html_e( 'Hledaný výraz', 'zg-smart-search' ); ?></label>
				<input type="text" id="replacement_term" name="replacement_term" placeholder="<?php esc_attr_e( 'např. aksaneli', 'zg-smart-search' ); ?>" required />
			</div>
			<div class="zgss-field">
				<label for="replacement_value"><?php esc_html_e( 'Nahradit za', 'zg-smart-search' ); ?></label>
				<input type="text" id="replacement_value" name="replacement_value" placeholder="<?php esc_attr_e( 'např. askaneli', 'zg-smart-search' ); ?>" required />
			</div>
			<div class="zgss-field">
				<label><input type="checkbox" name="replacement_highlight" checked /> <?php esc_html_e( 'Zvýraznit', 'zg-smart-search' ); ?></label>
			</div>
			<?php submit_button( __( 'Přidat', 'zg-smart-search' ), 'primary', 'submit', false ); ?>
		</form>

		<table class="zgss-repl-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Hledaný výraz', 'zg-smart-search' ); ?></th>
					<th></th>
					<th><?php esc_html_e( 'Nahradit za', 'zg-smart-search' ); ?></th>
					<th><?php esc_html_e( 'Zvýraznit', 'zg-smart-search' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $rules ) ) : ?>
					<tr><td colspan="5"><?php esc_html_e( 'Zatím žádné náhrady.', 'zg-smart-search' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $rules as $rule ) : ?>
						<tr>
							<td><?php echo esc_html( $rule['term'] ); ?></td>
							<td class="zgss-repl-arrow">→</td>
							<td><?php echo esc_html( $rule['replacement'] ); ?></td>
							<td><?php echo ! empty( $rule['highlight'] ) ? esc_html__( 'Ano', 'zg-smart-search' ) : esc_html__( 'Ne', 'zg-smart-search' ); ?></td>
							<td>
								<form method="post" onsubmit="return confirm('<?php echo esc_js( __( 'Smazat tuto náhradu?', 'zg-smart-search' ) ); ?>');">
									<?php wp_nonce_field( 'zgss_settings_save' ); ?>
									<input type="hidden" name="zgss_action" value="delete_replacement" />
									<input type="hidden" name="replacement_id" value="<?php echo esc_attr( $rule['id'] ); ?>" />
									<button type="submit" class="button-link" style="color:#a00;"><?php esc_html_e( 'Smazat', 'zg-smart-search' ); ?></button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Záložka "Statistika" - co lidé hledají.
	 */
	protected static function render_stats_tab() {
		$totals = ZGSS_Popular_Terms::get_totals();
		$terms  = ZGSS_Popular_Terms::get_all( 100, 'hits' );
		?>
		<div class="zgss-stat-cards">
			<div class="zgss-stat-card">
				<span class="zgss-stat-card__num"><?php echo esc_html( number_format_i18n( $totals['unique_terms'] ) ); ?></span>
				<span class="zgss-stat-card__label"><?php esc_html_e( 'Unikátních výrazů', 'zg-smart-search' ); ?></span>
			</div>
			<div class="zgss-stat-card">
				<span class="zgss-stat-card__num"><?php echo esc_html( number_format_i18n( $totals['total_searches'] ) ); ?></span>
				<span class="zgss-stat-card__label"><?php esc_html_e( 'Celkem vyhledávání', 'zg-smart-search' ); ?></span>
			</div>
			<div class="zgss-stat-card">
				<span class="zgss-stat-card__num"><?php echo esc_html( number_format_i18n( $totals['zero_result_terms'] ) ); ?></span>
				<span class="zgss-stat-card__label"><?php esc_html_e( 'Výrazů bez výsledků', 'zg-smart-search' ); ?></span>
			</div>
		</div>

		<p class="description">
			<?php esc_html_e( 'Řádky bez výsledků jsou zvýrazněné - to jsou dotazy, které zákazníci hledali, ale nic se nenašlo. Dobrý zdroj nápadů na nové "Náhrady" nebo mezery v katalogu.', 'zg-smart-search' ); ?>
		</p>

		<table class="zgss-terms-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Výraz', 'zg-smart-search' ); ?></th>
					<th><?php esc_html_e( 'Počet hledání', 'zg-smart-search' ); ?></th>
					<th><?php esc_html_e( 'Nalezeno produktů', 'zg-smart-search' ); ?></th>
					<th><?php esc_html_e( 'Naposledy hledáno', 'zg-smart-search' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $terms ) ) : ?>
					<tr><td colspan="4"><?php esc_html_e( 'Zatím žádná data. Statistiky se plní z reálného provozu.', 'zg-smart-search' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $terms as $row ) : ?>
						<tr class="<?php echo 0 === (int) $row['results_count'] ? 'zgss-zero-result' : ''; ?>">
							<td>
								<?php echo esc_html( $row['term'] ); ?>
								<?php if ( 0 === (int) $row['results_count'] ) : ?>
									<span class="zgss-badge-zero"><?php esc_html_e( '0 výsledků', 'zg-smart-search' ); ?></span>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( $row['hits'] ); ?></td>
							<td><?php echo esc_html( $row['results_count'] ); ?></td>
							<td><?php echo esc_html( mysql2date( 'j. n. Y H:i', $row['last_searched'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>

		<p style="margin-top:20px;">
			<form method="post" onsubmit="return confirm('<?php echo esc_js( __( 'Opravdu vynulovat statistiky hledaných výrazů?', 'zg-smart-search' ) ); ?>');">
				<?php wp_nonce_field( 'zgss_settings_save' ); ?>
				<input type="hidden" name="zgss_action" value="reset_stats" />
				<?php submit_button( __( 'Vynulovat statistiky vyhledávání', 'zg-smart-search' ), 'secondary', 'submit', false ); ?>
			</form>
		</p>
		<?php
	}

	/**
	 * Záložka "Elasticsearch" - přepínač enginu, připojení a živé metriky.
	 */
	protected static function render_elasticsearch_tab() {
		$settings    = self::get_settings();
		$is_es       = 'elasticsearch' === $settings['search_engine'];
		$last_reindex = get_option( 'zgss_es_last_reindex', '' );
		$last_error  = class_exists( 'ZGSS_Elasticsearch' ) ? ZGSS_Elasticsearch::get_last_error() : null;
		?>
		<p>
			<?php esc_html_e( 'Elasticsearch je volitelná výměnná vrstva pro vyhledávání. Původní MySQL/fuzzy vyhledávání zůstává vždy plně funkční a nijak se touto záložkou neupravuje - jde jen o přepínač.', 'zg-smart-search' ); ?>
		</p>

		<div class="zgss-es-status <?php echo $is_es ? 'is-ok' : 'is-off'; ?>">
			<?php
			if ( $is_es ) {
				esc_html_e( 'Aktivní vyhledávací engine: Elasticsearch', 'zg-smart-search' );
			} else {
				esc_html_e( 'Aktivní vyhledávací engine: MySQL (výchozí)', 'zg-smart-search' );
			}
			?>
		</div>

		<?php if ( $last_error ) : ?>
			<div class="zgss-es-status is-error">
				<?php
				printf(
					/* translators: 1: time, 2: error message */
					esc_html__( 'Poslední chyba komunikace s Elasticsearch (%1$s): %2$s', 'zg-smart-search' ),
					esc_html( $last_error['time'] ),
					esc_html( $last_error['message'] )
				);
				?>
			</div>
		<?php endif; ?>

		<form method="post">
			<?php wp_nonce_field( 'zgss_settings_save' ); ?>
			<input type="hidden" name="zgss_action" value="save_es_settings" />
			<table class="form-table zgss-es-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Vyhledávací engine', 'zg-smart-search' ); ?></th>
					<td>
						<label style="margin-right:18px;">
							<input type="radio" name="search_engine" value="mysql" <?php checked( ! $is_es ); ?> />
							<?php esc_html_e( 'MySQL (výchozí, fuzzy vyhledávání)', 'zg-smart-search' ); ?>
						</label>
						<label>
							<input type="radio" name="search_engine" value="elasticsearch" <?php checked( $is_es ); ?> />
							<?php esc_html_e( 'Elasticsearch', 'zg-smart-search' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Pokud Elasticsearch kdykoliv selže (výpadek, chyba), vyhledávání na webu automaticky a tiše spadne zpět na MySQL - live search nikdy nezůstane nefunkční.', 'zg-smart-search' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="es_host"><?php esc_html_e( 'Host', 'zg-smart-search' ); ?></label></th>
					<td>
						<select name="es_scheme" style="width:90px;">
							<option value="https" <?php selected( $settings['es_scheme'], 'https' ); ?>>https</option>
							<option value="http" <?php selected( $settings['es_scheme'], 'http' ); ?>>http</option>
						</select>
						<input type="text" id="es_host" name="es_host" value="<?php echo esc_attr( $settings['es_host'] ); ?>" class="regular-text" placeholder="es.vas-hosting.cz" style="width:280px;" />
						:
						<input type="number" name="es_port" value="<?php echo esc_attr( $settings['es_port'] ); ?>" class="small-text" min="1" max="65535" />
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="es_index_prefix"><?php esc_html_e( 'Prefix názvu indexu', 'zg-smart-search' ); ?></label></th>
					<td>
						<input type="text" id="es_index_prefix" name="es_index_prefix" value="<?php echo esc_attr( $settings['es_index_prefix'] ); ?>" class="regular-text" />
						<p class="description"><?php esc_html_e( 'Skutečný index bude např. "prefix_default" nebo "prefix_ru" (samostatný index pro každou jazykovou verzi).', 'zg-smart-search' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Autentizace', 'zg-smart-search' ); ?></th>
					<td>
						<select name="es_auth_type" id="es_auth_type">
							<option value="none" <?php selected( $settings['es_auth_type'], 'none' ); ?>><?php esc_html_e( 'Žádná', 'zg-smart-search' ); ?></option>
							<option value="basic" <?php selected( $settings['es_auth_type'], 'basic' ); ?>><?php esc_html_e( 'Basic (uživatel/heslo)', 'zg-smart-search' ); ?></option>
							<option value="api_key" <?php selected( $settings['es_auth_type'], 'api_key' ); ?>><?php esc_html_e( 'API klíč', 'zg-smart-search' ); ?></option>
						</select>
						<p class="zgss-es-auth-basic" style="margin-top:8px;">
							<label><?php esc_html_e( 'Uživatel:', 'zg-smart-search' ); ?> <input type="text" name="es_username" value="<?php echo esc_attr( $settings['es_username'] ); ?>" class="regular-text" /></label><br />
							<label style="margin-top:6px;display:inline-block;"><?php esc_html_e( 'Heslo:', 'zg-smart-search' ); ?> <input type="password" name="es_password" value="" placeholder="<?php echo $settings['es_password'] ? esc_attr__( '•••••••• (zadejte jen pokud měníte)', 'zg-smart-search' ) : ''; ?>" class="regular-text" autocomplete="new-password" /></label>
						</p>
						<p class="zgss-es-auth-apikey" style="margin-top:8px;">
							<label><?php esc_html_e( 'API klíč:', 'zg-smart-search' ); ?> <input type="password" name="es_api_key" value="" placeholder="<?php echo $settings['es_api_key'] ? esc_attr__( '•••••••• (zadejte jen pokud měníte)', 'zg-smart-search' ) : ''; ?>" class="regular-text" autocomplete="new-password" /></label>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'SSL certifikát', 'zg-smart-search' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="es_verify_ssl" <?php checked( ! empty( $settings['es_verify_ssl'] ) ); ?> />
							<?php esc_html_e( 'Ověřovat platnost SSL certifikátu (vypněte jen dočasně, např. u self-signed certifikátu na testovacím serveru)', 'zg-smart-search' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Metriky CPU/paměti', 'zg-smart-search' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="es_stats_enabled" <?php checked( ! empty( $settings['es_stats_enabled'] ) ); ?> />
							<?php esc_html_e( 'Zobrazovat živý panel využití CPU/paměti clusteru níže (vyžaduje přístup k Elasticsearch API _nodes/stats - u některých managed služeb může být omezeno)', 'zg-smart-search' ); ?>
						</label>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Uložit nastavení Elasticsearch', 'zg-smart-search' ) ); ?>
		</form>

		<p>
			<button type="button" class="button" id="zgss-es-test"><?php esc_html_e( 'Otestovat připojení', 'zg-smart-search' ); ?></button>
			<button type="button" class="button button-primary" id="zgss-es-reindex"><?php esc_html_e( 'Naindexovat produkty do Elasticsearch', 'zg-smart-search' ); ?></button>
			<span id="zgss-es-action-result" style="margin-left:10px;"></span>
		</p>
		<?php if ( $last_reindex ) : ?>
			<p class="description">
				<?php
				printf(
					/* translators: %s: date/time of last reindex */
					esc_html__( 'Poslední reindexace: %s', 'zg-smart-search' ),
					esc_html( mysql2date( 'j. n. Y H:i', $last_reindex ) )
				);
				?>
			</p>
		<?php endif; ?>

		<h2><?php esc_html_e( 'Vytížení clusteru', 'zg-smart-search' ); ?></h2>
		<div id="zgss-es-metrics" class="zgss-es-metrics">
			<div class="zgss-es-gauge">
				<div class="zgss-es-gauge__label"><span><?php esc_html_e( 'CPU', 'zg-smart-search' ); ?></span><span id="zgss-es-cpu-num">–</span></div>
				<div class="zgss-es-gauge__bar"><div class="zgss-es-gauge__fill" id="zgss-es-cpu-fill" style="width:0%"></div></div>
				<div class="zgss-es-spark"><canvas id="zgss-es-cpu-spark" width="260" height="60"></canvas></div>
			</div>
			<div class="zgss-es-gauge">
				<div class="zgss-es-gauge__label"><span><?php esc_html_e( 'Paměť (OS)', 'zg-smart-search' ); ?></span><span id="zgss-es-mem-num">–</span></div>
				<div class="zgss-es-gauge__bar"><div class="zgss-es-gauge__fill" id="zgss-es-mem-fill" style="width:0%"></div></div>
				<div class="zgss-es-spark"><canvas id="zgss-es-mem-spark" width="260" height="60"></canvas></div>
			</div>
			<div class="zgss-es-gauge">
				<div class="zgss-es-gauge__label"><span><?php esc_html_e( 'JVM Heap', 'zg-smart-search' ); ?></span><span id="zgss-es-heap-num">–</span></div>
				<div class="zgss-es-gauge__bar"><div class="zgss-es-gauge__fill" id="zgss-es-heap-fill" style="width:0%"></div></div>
				<div class="zgss-es-spark"><canvas id="zgss-es-heap-spark" width="260" height="60"></canvas></div>
			</div>
		</div>
		<div class="zgss-es-node-name" id="zgss-es-node-name"></div>
		<?php
	}
}
