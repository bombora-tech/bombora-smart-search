<?php
/**
 * Plugin Name: Bombora Smart Search
 * Plugin URI: https://github.com/bombora-tech/bombora-smart-search
 * Update URI: https://github.com/bombora-tech/bombora-smart-search
 * Description: Inteligentní live vyhledávání produktů pro WooCommerce s tolerancí překlepů (fuzzy matching), oblíbenými vyhledáváními, kategoriemi a personalizovanými doporučeními (bestsellery, related produkty, historie nákupů). Plně kompatibilní s WPML.
 * Version: 2.1.0
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

// Pojistka: jiná kopie už běží, nebo je aktivní stará složka zg-smart-search (načítá se až po této) –
// pak tato kopie nic nedeklaruje a běží jen stará verze, dokud ji Bombora Manager nepřevede.
if ( defined( 'ZGSS_VERSION' ) || in_array( 'zg-smart-search/zg-smart-search.php', (array) get_option( 'active_plugins', array() ), true ) ) {
	return;
}

define( 'ZGSS_VERSION', '2.1.0' );
define( 'ZGSS_PATH', plugin_dir_path( __FILE__ ) );
define( 'ZGSS_URL', plugin_dir_url( __FILE__ ) );
define( 'ZGSS_BASENAME', plugin_basename( __FILE__ ) );
define( 'ZGSS_FILE', __FILE__ );

require_once ZGSS_PATH . 'includes/bootstrap.php';
