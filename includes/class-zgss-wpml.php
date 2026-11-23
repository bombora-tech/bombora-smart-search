<?php
/**
 * Pomocné funkce pro spolupráci s WPML.
 *
 * Poznámka k architektuře: WooCommerce Multilingual automaticky filtruje
 * WP_Query/wc_get_products podle aktuálně zvoleného jazyka (pokud není
 * explicitně nastaveno suppress_filters=true), takže index i vyhledávání
 * v ZGSS_Index a ZGSS_Fuzzy není potřeba nijak speciálně přizpůsobovat -
 * vždy automaticky pracují jen s produkty aktuálního jazyka. Tady jen
 * registrujeme přeložitelné texty pluginu (mimo produkty) do WPML String
 * Translation, aby šly přeložit i popisky UI vytvořené tímto pluginem.
 *
 * @package ZG_Smart_Search
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZGSS_WPML {

	/**
	 * Zaregistruje volitelný text placeholderu do WPML String Translation,
	 * aby ho šlo v administraci WPML přeložit pro každou jazykovou verzi zvlášť.
	 *
	 * @param string $string_value Text k registraci.
	 * @param string $string_name  Interní název stringu.
	 */
	public static function register_string( $string_value, $string_name ) {
		if ( function_exists( 'icl_register_string' ) && '' !== trim( (string) $string_value ) ) {
			icl_register_string( 'Smart Search Advanced', $string_name, $string_value );
		}
	}

	/**
	 * Vrátí přeloženou verzi zaregistrovaného stringu pro aktuální jazyk.
	 *
	 * @param string $string_value Originální (fallback) text.
	 * @param string $string_name  Interní název stringu.
	 * @return string
	 */
	public static function translate_string( $string_value, $string_name ) {
		if ( function_exists( 'icl_t' ) ) {
			return icl_t( 'Smart Search Advanced', $string_name, $string_value );
		}
		return $string_value;
	}
}
