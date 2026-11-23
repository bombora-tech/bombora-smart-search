<?php
/**
 * Ukládání a čtení statistik hledaných výrazů - "Oblíbená vyhledávání".
 *
 * @package ZG_Smart_Search
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZGSS_Popular_Terms {

	/**
	 * Vrátí název DB tabulky.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'zgss_search_terms';
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
			lang VARCHAR(10) NOT NULL DEFAULT 'default',
			hits BIGINT UNSIGNED NOT NULL DEFAULT 1,
			results_count INT UNSIGNED NOT NULL DEFAULT 0,
			last_searched DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY term_lang (term_norm, lang),
			KEY hits (hits)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * Zaznamená hledaný výraz (upsert - pokud existuje, jen zvýší počítadlo).
	 *
	 * @param string $term          Hledaný výraz (v původní podobě, jak jej napsal uživatel).
	 * @param string $lang          Jazykový kód.
	 * @param int    $results_count Počet nalezených produktů (pro filtr "jen výrazy s výsledky").
	 */
	public static function log( $term, $lang, $results_count ) {
		global $wpdb;

		$term      = trim( wp_strip_all_tags( $term ) );
		$term_norm = ZGSS_Fuzzy::normalize( $term );

		if ( '' === $term_norm || strlen( $term_norm ) < 2 ) {
			return;
		}

		$table = self::table_name();

		$existing_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE term_norm = %s AND lang = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$term_norm,
				$lang
			)
		);

		if ( $existing_id ) {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET hits = hits + 1, results_count = %d, last_searched = %s WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					(int) $results_count,
					current_time( 'mysql' ),
					(int) $existing_id
				)
			);
		} else {
			$wpdb->insert(
				$table,
				array(
					'term'          => $term,
					'term_norm'     => $term_norm,
					'lang'          => $lang,
					'hits'          => 1,
					'results_count' => (int) $results_count,
					'last_searched' => current_time( 'mysql' ),
				),
				array( '%s', '%s', '%s', '%d', '%d', '%s' )
			);
		}
	}

	/**
	 * Vrátí nejoblíbenější hledané výrazy pro daný jazyk (jen ty, které měly výsledky).
	 *
	 * @param string $lang         Jazykový kód.
	 * @param int    $limit        Počet výrazů.
	 * @param string $exclude_norm Normalizovaný výraz, který se má vynechat (aktuální dotaz).
	 * @return array Pole {term, hits}.
	 */
	public static function get_popular( $lang, $limit = 6, $exclude_norm = '' ) {
		global $wpdb;

		$table = self::table_name();

		if ( '' !== $exclude_norm ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT term, hits FROM {$table} WHERE lang = %s AND results_count > 0 AND term_norm != %s ORDER BY hits DESC, last_searched DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$lang,
					$exclude_norm,
					$limit
				),
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT term, hits FROM {$table} WHERE lang = %s AND results_count > 0 ORDER BY hits DESC, last_searched DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$lang,
					$limit
				),
				ARRAY_A
			);
		}

		return $rows ? $rows : array();
	}

	/**
	 * Vrátí posledních/nejčastějších hledaných výrazů pro přehled v administraci.
	 *
	 * @param int    $limit   Počet řádků.
	 * @param string $orderby 'hits' nebo 'recent'.
	 * @return array
	 */
	public static function get_all( $limit = 100, $orderby = 'hits' ) {
		global $wpdb;
		$table = self::table_name();
		$order = ( 'recent' === $orderby ) ? 'last_searched DESC' : 'hits DESC';

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} ORDER BY {$order} LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$limit
			),
			ARRAY_A
		);

		return $rows ? $rows : array();
	}

	/**
	 * Souhrnné statistiky pro dashboard v administraci.
	 *
	 * @return array {unique_terms, total_searches, zero_result_terms}
	 */
	public static function get_totals() {
		global $wpdb;
		$table = self::table_name();

		$unique_terms      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$total_searches    = (int) $wpdb->get_var( "SELECT SUM(hits) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$zero_result_terms = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE results_count = 0" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array(
			'unique_terms'      => $unique_terms,
			'total_searches'    => $total_searches,
			'zero_result_terms' => $zero_result_terms,
		);
	}

	/**
	 * Smaže všechny statistiky (tlačítko v administraci).
	 */
	public static function reset() {
		global $wpdb;
		$table = self::table_name();
		$wpdb->query( "TRUNCATE TABLE {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}
}
