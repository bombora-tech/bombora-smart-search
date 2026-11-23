<?php
/**
 * Jediné místo, které rozhoduje, jestli se hledá přes MySQL/fuzzy
 * (ZGSS_Fuzzy) nebo přes Elasticsearch (ZGSS_Elasticsearch). Podle
 * nastavení "search_engine" (WooCommerce > Smart Search Advanced >
 * Elasticsearch). Pokud je zvolen Elasticsearch, ale selže (výpadek,
 * špatné přihlašovací údaje apod.), tiše spadne zpět na MySQL, aby
 * live vyhledávání na webu nikdy nezůstalo nefunkční.
 *
 * @package ZG_Smart_Search
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZGSS_Search_Router {

	/**
	 * Provede vyhledávání aktuálně zvoleným enginem.
	 *
	 * @param string $term    Hledaný výraz.
	 * @param int    $limit   Max. počet výsledků.
	 * @param array  $filters Volitelné filtry podle vlastností produktu -
	 *                        {taxonomy_slug: [term_slug, ...]}.
	 * @return array
	 */
	public static function search( $term, $limit = 8, $filters = array() ) {
		$settings = ZGSS_Admin::get_settings();
		$engine   = $settings['search_engine'] ?? 'mysql';

		if ( 'elasticsearch' === $engine && class_exists( 'ZGSS_Elasticsearch' ) && ZGSS_Elasticsearch::is_configured() ) {
			$results = ZGSS_Elasticsearch::search( $term, $limit, $filters );
			if ( is_array( $results ) ) {
				return $results;
			}
			// ES selhal (výpadek/chyba dotazu) - tichý fallback na MySQL,
			// chyba je zalogovaná uvnitř ZGSS_Elasticsearch::request() a
			// vidět v záložce Elasticsearch.
		}

		return ZGSS_Fuzzy::search( $term, $limit, $filters );
	}

	/**
	 * Který engine reálně obsloužil poslední dotaz (pro info v administraci
	 * není potřeba - toto se používá jen pro budoucí rozšíření/debug).
	 *
	 * @return string
	 */
	public static function active_engine() {
		$settings = ZGSS_Admin::get_settings();
		return $settings['search_engine'] ?? 'mysql';
	}
}
