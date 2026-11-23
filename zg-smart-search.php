<?php
/**
 * Plugin Name: Smart Search Advanced
 * Plugin URI: https://github.com/bombora-tech/bombora-smart-search
 * Update URI: https://github.com/bombora-tech/bombora-smart-search
 * Description: Inteligentní live vyhledávání produktů pro WooCommerce s tolerancí překlepů (fuzzy matching), oblíbenými vyhledáváními, kategoriemi a personalizovanými doporučeními (bestsellery, related produkty, historie nákupů). Plně kompatibilní s WPML.
 * Version: 1.9.1
 * Author: Bombora group s.r.o.
 * Text Domain: zg-smart-search
 * Domain Path: /languages
 * Requires Plugins: woocommerce
 * WC requires at least: 6.0
 *
 * @package ZG_Smart_Search
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Přímý přístup zakázán.
}

define( 'ZGSS_VERSION', '1.9.1' );
define( 'ZGSS_PATH', plugin_dir_path( __FILE__ ) );
define( 'ZGSS_URL', plugin_dir_url( __FILE__ ) );
define( 'ZGSS_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Zkontroluje, že je aktivní WooCommerce. Pokud ne, plugin se sám deaktivuje a napíše hlášku.
 */
function zgss_check_dependencies() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action(
			'admin_notices',
			function () {
				echo '<div class="notice notice-error"><p>';
				echo esc_html__( 'Smart Search Advanced vyžaduje aktivní plugin WooCommerce.', 'zg-smart-search' );
				echo '</p></div>';
			}
		);
		return false;
	}
	return true;
}

/**
 * Načte přeložené .mo soubory z /languages podle aktuálního jazyka (WPML
 * automaticky přepíná WP "locale" za návštěvníka, takže se tím pádem načte
 * správný soubor zg-smart-search-{locale}.mo - např. zg-smart-search-ru_RU.mo
 * na ruské verzi webu). Plugin dodává hotové překlady pro ru_RU, de_DE,
 * sk_SK, en_US a fr_FR; další jazyk se přidá stejnojmenným .mo souborem do
 * této složky, viz languages/readme.txt.
 */
function zgss_load_textdomain() {
	load_plugin_textdomain( 'zg-smart-search', false, dirname( ZGSS_BASENAME ) . '/languages' );
}
add_action( 'init', 'zgss_load_textdomain' );

/**
 * Načtení jednotlivých tříd pluginu.
 */
function zgss_includes() {
	require_once ZGSS_PATH . 'includes/class-zgss-index.php';
	require_once ZGSS_PATH . 'includes/class-zgss-fuzzy.php';
	require_once ZGSS_PATH . 'includes/class-zgss-recommendations.php';
	require_once ZGSS_PATH . 'includes/class-zgss-popular-terms.php';
	require_once ZGSS_PATH . 'includes/class-zgss-replacements.php';
	require_once ZGSS_PATH . 'includes/class-zgss-wpml.php';
	require_once ZGSS_PATH . 'includes/class-zgss-elasticsearch.php';
	require_once ZGSS_PATH . 'includes/class-zgss-search-router.php';
	require_once ZGSS_PATH . 'includes/class-zgss-ajax.php';
	require_once ZGSS_PATH . 'includes/class-zgss-shortcode.php';
	require_once ZGSS_PATH . 'includes/class-zgss-admin.php';
}
zgss_includes();

/**
 * Inicializace pluginu.
 */
function zgss_init() {
	if ( ! zgss_check_dependencies() ) {
		return;
	}

	zgss_maybe_upgrade();

	ZGSS_Ajax::init();
	ZGSS_Shortcode::init();
	ZGSS_Admin::init();
	ZGSS_Elasticsearch::init(); // AJAX endpointy pro test připojení / reindexaci / metriky.
	ZGSS_Index::init(); // hooky pro cache-busting indexu při uložení/smazání produktu.
}
add_action( 'plugins_loaded', 'zgss_init' );

/**
 * Zajistí, že DB tabulky existují i po pouhém nahrání nové verze souborů
 * (bez deaktivace/aktivace pluginu), kdy se register_activation_hook nespustí.
 */
function zgss_maybe_upgrade() {
	if ( get_option( 'zgss_db_version' ) === ZGSS_VERSION ) {
		return;
	}
	ZGSS_Popular_Terms::create_table();
	ZGSS_Replacements::create_table();
	// Zahodit starou cache indexu/bestsellerů, ať se po aktualizaci hned
	// projeví případné změny v logice (např. přísnější kontrola skladu).
	ZGSS_Index::flush_all_caches();
	update_option( 'zgss_db_version', ZGSS_VERSION );
}

/**
 * Aktivace pluginu – vytvoření DB tabulky pro statistiky vyhledávání.
 */
function zgss_activate() {
	require_once ZGSS_PATH . 'includes/class-zgss-popular-terms.php';
	require_once ZGSS_PATH . 'includes/class-zgss-replacements.php';
	ZGSS_Popular_Terms::create_table();
	ZGSS_Replacements::create_table();

	if ( ! get_option( 'zgss_settings' ) ) {
		update_option( 'zgss_settings', ZGSS_Admin::default_settings() );
	}
}
register_activation_hook( __FILE__, 'zgss_activate' );

/**
 * Deaktivace – smaže cache indexu, DB tabulku ponechá (data se hodí i po reaktivaci).
 */
function zgss_deactivate() {
	require_once ZGSS_PATH . 'includes/class-zgss-index.php';
	ZGSS_Index::flush_all_caches();
}
register_deactivation_hook( __FILE__, 'zgss_deactivate' );

/**
 * Enqueue frontend assetů. JS se sám postará o to, že nic nedělá, pokud na
 * stránce není vyhledávací widget (kontrola existence elementu v DOM).
 */
function zgss_enqueue_assets() {
	if ( is_admin() ) {
		return;
	}

	wp_enqueue_style( 'zgss-style', ZGSS_URL . 'assets/css/zg-search.css', array(), ZGSS_VERSION );
	wp_enqueue_script( 'zgss-script', ZGSS_URL . 'assets/js/zg-search.js', array(), ZGSS_VERSION, true );

	$settings = ZGSS_Admin::get_settings();

	// Text štítku "Bestseller" pochází z nastavení (ne z pevného textu v kódu),
	// takže ho pro překlad registrujeme do WPML String Translation - tam si ho
	// pak Aleš může pro každý jazyk přepsat vlastním textem.
	$bestseller_text = $settings['bestseller_badge_text'];
	ZGSS_WPML::register_string( $bestseller_text, 'bestseller_badge_text' );
	$bestseller_text = ZGSS_WPML::translate_string( $bestseller_text, 'bestseller_badge_text' );

	wp_localize_script(
		'zgss-script',
		'ZGSS',
		array(
			'ajaxUrl'            => admin_url( 'admin-ajax.php' ),
			'nonce'              => wp_create_nonce( 'zgss_search_nonce' ),
			'minChars'           => (int) $settings['min_chars'],
			'debounce'           => (int) $settings['debounce_ms'],
			'showBestseller'     => ! empty( $settings['show_bestseller_badge'] ),
			'bestsellerLabel'    => $bestseller_text,
			'enableTypewriter'   => ! empty( $settings['enable_typewriter'] ),
			'typewriterPhrases'  => array_values( array_filter( array_map( 'trim', explode( "\n", (string) $settings['typewriter_phrases'] ) ) ) ),
			'filterCombineMode'  => ( isset( $settings['filter_combine_mode'] ) && 'latest' === $settings['filter_combine_mode'] ) ? 'latest' : 'and',
			'i18n'               => array(
				'popularSearches' => __( 'Oblíbená vyhledávání', 'zg-smart-search' ),
				'categories'      => __( 'Kategórie', 'zg-smart-search' ),
				'products'        => __( 'PRODUKTY', 'zg-smart-search' ),
				'inStock'         => __( 'Skladem', 'zg-smart-search' ),
				'outOfStock'      => __( 'Vyprodáno', 'zg-smart-search' ),
				'onBackorder'     => __( 'Předobjednávka', 'zg-smart-search' ),
				'moreResults'     => __( 'Více výsledků', 'zg-smart-search' ),
				'youMayLike'      => __( 'Mohlo by se vám líbit', 'zg-smart-search' ),
				'noResults'       => __( 'Nic jsme nenašli. Zkuste to prosím jinak.', 'zg-smart-search' ),
				'recommended'     => __( 'Doporučujeme', 'zg-smart-search' ),
				'resetFilters'    => __( 'Zrušit filtry', 'zg-smart-search' ),
			),
		)
	);
}
add_action( 'wp_enqueue_scripts', 'zgss_enqueue_assets' );
