<?php
/**
 * Plugin Name: Bombora Smart Search
 * Plugin URI: https://github.com/bombora-tech/bombora-smart-search
 * Update URI: https://github.com/bombora-tech/bombora-smart-search
 * Description: Inteligentní live vyhledávání produktů pro WooCommerce s tolerancí překlepů (fuzzy matching), oblíbenými vyhledáváními, kategoriemi a personalizovanými doporučeními (bestsellery, related produkty, historie nákupů). Plně kompatibilní s WPML.
 * Version: 2.0.0
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

// Pojistka: jiná kopie pluginu (např. stará složka zg-smart-search) už běží – nic znovu nedeklarovat.
if ( defined( 'ZGSS_VERSION' ) ) {
	return;
}

define( 'ZGSS_VERSION', '2.0.0' );
define( 'ZGSS_PATH', plugin_dir_path( __FILE__ ) );
define( 'ZGSS_URL', plugin_dir_url( __FILE__ ) );
define( 'ZGSS_BASENAME', plugin_basename( __FILE__ ) );
define( 'ZGSS_FILE', __FILE__ );

require_once ZGSS_PATH . 'includes/bootstrap.php';
