<?php
/**
 * Ruční slovník náhrad/synonym - stejná myšlenka jako "Náhrady" v pluginu
 * Magrano. Na rozdíl od automatického fuzzy algoritmu jde o explicitní
 * pravidla, která si obchodník sám doplňuje podle toho, co lidé reálně
 * hledají (typické překlepy, cizí výrazy, formátování typu "750" -> "0,75").
 *
 * Pravidla se aplikují na úrovni jednotlivých slov dotazu, PŘED fuzzy
 * vyhledáváním - je to tedy spolehlivější a rychlejší způsob, jak pokrýt
 * konkrétní, opakovaně se vyskytující dotazy, než spoléhat jen na obecný
 * algoritmus.
 *
 * @package ZG_Smart_Search
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZGSS_Replacements {

	const CACHE_KEY = 'zgss_replacements_map';

	/**
	 * Název DB tabulky.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'zgss_replacements';
	}

	/**
	 * Vytvoří DB tabulku (voláno při aktivaci pluginu).
	 */
	public static function create_table() {
		global $wpdb;

		$table_name      = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			term VARCHAR(191) NOT NULL,
			term_norm VARCHAR(191) NOT NULL,
			replacement VARCHAR(191) NOT NULL,
			highlight TINYINT(1) NOT NULL DEFAULT 1,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY term_norm (term_norm)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * Přidá nové pravidlo náhrady (nebo přepíše existující se stejným term_norm).
	 *
	 * @param string $term        Hledaný výraz, jak jej zadává uživatel (např. "aksaneli").
	 * @param string $replacement Za co se má při hledání dosadit (např. "askaneli").
	 * @param bool   $highlight   Zda se má nahrazený výraz i vizuálně zvýraznit ve výsledcích.
	 * @return bool Úspěch.
	 */
	public static function add( $term, $replacement, $highlight = true ) {
		global $wpdb;

		$term        = trim( wp_strip_all_tags( $term ) );
		$replacement = trim( wp_strip_all_tags( $replacement ) );

		if ( '' === $term || '' === $replacement ) {
			return false;
		}

		$term_norm = ZGSS_Fuzzy::normalize( $term );
		if ( '' === $term_norm ) {
			return false;
		}

		$table  = self::table_name();
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE term_norm = %s", $term_norm ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( $exists ) {
			$wpdb->update(
				$table,
				array(
					'term'        => $term,
					'replacement' => $replacement,
					'highlight'   => $highlight ? 1 : 0,
				),
				array( 'id' => $exists ),
				array( '%s', '%s', '%d' ),
				array( '%d' )
			);
		} else {
			$wpdb->insert(
				$table,
				array(
					'term'        => $term,
					'term_norm'   => $term_norm,
					'replacement' => $replacement,
					'highlight'   => $highlight ? 1 : 0,
					'created_at'  => current_time( 'mysql' ),
				),
				array( '%s', '%s', '%s', '%d', '%s' )
			);
		}

		delete_transient( self::CACHE_KEY );
		return true;
	}

	/**
	 * Smaže pravidlo podle ID.
	 *
	 * @param int $id ID pravidla.
	 */
	public static function delete( $id ) {
		global $wpdb;
		$wpdb->delete( self::table_name(), array( 'id' => (int) $id ), array( '%d' ) );
		delete_transient( self::CACHE_KEY );
	}

	/**
	 * Vrátí všechna pravidla (pro výpis v administraci).
	 *
	 * @return array
	 */
	public static function get_all() {
		global $wpdb;
		$table = self::table_name();
		$rows  = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY term ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $rows ? $rows : array();
	}

	/**
	 * Vrátí mapu term_norm => {replacement, highlight}, cachovanou v transientu.
	 *
	 * @return array
	 */
	protected static function get_map() {
		$map = get_transient( self::CACHE_KEY );
		if ( is_array( $map ) ) {
			return $map;
		}

		$map = array();
		foreach ( self::get_all() as $row ) {
			$map[ $row['term_norm'] ] = array(
				'replacement' => $row['replacement'],
				'highlight'   => ! empty( $row['highlight'] ),
			);
		}

		set_transient( self::CACHE_KEY, $map, DAY_IN_SECONDS );
		return $map;
	}

	/**
	 * Aplikuje pravidla náhrad na dotaz uživatele, slovo po slovu.
	 *
	 * @param string $raw_query Původní dotaz, jak jej napsal uživatel.
	 * @return array {
	 *     @type string $effective  Dotaz po aplikaci náhrad (normalizovaný text) - použije se pro fuzzy hledání.
	 *     @type bool   $highlight  Zda alespoň jedno použité pravidlo mělo zapnuté zvýraznění.
	 *     @type bool   $changed    Zda vůbec nějaká náhrada proběhla.
	 * }
	 */
	public static function apply( $raw_query ) {
		$norm  = ZGSS_Fuzzy::normalize( $raw_query );
		$words = explode( ' ', $norm );
		$map   = self::get_map();

		$out       = array();
		$highlight = false;
		$changed   = false;

		foreach ( $words as $word ) {
			if ( '' === $word ) {
				continue;
			}
			if ( isset( $map[ $word ] ) ) {
				$out[]   = $map[ $word ]['replacement'];
				$changed = true;
				if ( $map[ $word ]['highlight'] ) {
					$highlight = true;
				}
			} else {
				$out[] = $word;
			}
		}

		return array(
			'effective' => trim( implode( ' ', $out ) ),
			'highlight' => $highlight,
			'changed'   => $changed,
		);
	}
}
