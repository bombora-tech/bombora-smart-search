<?php
/**
 * Stránka výsledků hledání (?s=…) používá stejný engine jako live našeptávač.
 *
 * Bez toho běží na stránce výsledků nativní WordPress hledání (LIKE v názvu
 * a obsahu), které nezvládá překlepy, přepis ani náhrady – našeptávač tak
 * produkt najde („čurčchela“ → Churchkhela), ale stránka výsledků ukáže 0.
 *
 * Řešení: u frontendových dotazů s parametrem "s", které hledají produkty
 * (hlavní dotaz hledání i query loopy Bricks s post_type=product), se
 * výsledek nahradí seznamem ID: nejdřív shody z fuzzy/Elasticsearch enginu
 * (vč. slovníku náhrad), pak doplněné o výsledky nativního hledání (aby se
 * neztratilo nic, co se dřív zobrazovalo – např. produkty mimo index).
 * Funguje pro každou jazykovou verzi (WPML filtruje index i nativní dotaz
 * podle aktuálního jazyka).
 *
 * @package ZG_Smart_Search
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZGSS_Results_Page {

	/** Max. počet ID z fuzzy enginu pro stránku výsledků. */
	const LIMIT = 300;

	/** @var array Cache ID v rámci jednoho požadavku – klíč jazyk|post_type|výraz. */
	protected static $cache = array();

	public static function init() {
		if ( ! apply_filters( 'zgss_enable_results_page', true ) ) {
			return;
		}
		add_action( 'pre_get_posts', array( __CLASS__, 'pre_get_posts' ), 1000 );
		add_filter( 'posts_search', array( __CLASS__, 'posts_search' ), 1000, 2 );
		add_filter( 'posts_search_orderby', array( __CLASS__, 'posts_search_orderby' ), 1000, 2 );
	}

	/**
	 * Je to dotaz, který máme obsloužit?
	 *
	 * @param WP_Query $q Dotaz.
	 * @return bool
	 */
	protected static function applies( $q ) {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return false;
		}
		if ( $q->get( 'zgss_skip' ) ) {
			return false;
		}
		$s = $q->get( 's' );
		if ( ! is_string( $s ) || '' === trim( $s ) ) {
			return false;
		}

		$post_type = $q->get( 'post_type' );
		if ( 'product' === $post_type || ( is_array( $post_type ) && in_array( 'product', $post_type, true ) ) ) {
			return true;
		}

		// Hlavní dotaz hledání bez určeného typu (home/?s=…) – zahrnuje i produkty.
		return $q->is_main_query() && $q->is_search() && ( empty( $post_type ) || 'any' === $post_type );
	}

	/**
	 * @param WP_Query $q Dotaz.
	 */
	public static function pre_get_posts( $q ) {
		if ( ! self::applies( $q ) ) {
			return;
		}

		$raw  = (string) $q->get( 's' ); // Query var je "slashed" (wp_magic_quotes).
		$ids  = self::get_ids( wp_unslash( $raw ), $raw, $q->get( 'post_type' ) );

		if ( empty( $ids ) ) {
			return; // Nic navíc nenašel ani engine – nechat nativní chování.
		}

		$existing = $q->get( 'post__in' );
		if ( ! empty( $existing ) ) {
			$ids = array_values( array_intersect( $ids, array_map( 'intval', (array) $existing ) ) );
			if ( empty( $ids ) ) {
				$ids = array( 0 );
			}
		}

		$q->set( 'post__in', $ids );
		$q->set( 'zgss_results', 1 );

		// Výchozí řazení = podle relevance enginu. Ruční volba (cena, novinky…) zůstává.
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( '' === $orderby || 'relevance' === $orderby ) {
			$q->set( 'orderby', 'post__in' );
			$q->set( 'order', 'ASC' );
		}
	}

	/**
	 * Vypne nativní LIKE podmínku – výběr už určuje post__in.
	 *
	 * @param string   $search SQL.
	 * @param WP_Query $q      Dotaz.
	 * @return string
	 */
	public static function posts_search( $search, $q ) {
		return $q->get( 'zgss_results' ) ? '' : $search;
	}

	/**
	 * @param string   $orderby SQL.
	 * @param WP_Query $q       Dotaz.
	 * @return string
	 */
	public static function posts_search_orderby( $orderby, $q ) {
		return $q->get( 'zgss_results' ) ? '' : $orderby;
	}

	/**
	 * ID výsledků: shody enginu (seřazené podle skóre) + nativní hledání.
	 *
	 * @param string       $term      Hledaný výraz (unslashed).
	 * @param string       $raw       Původní hodnota query var "s" pro nativní dotaz.
	 * @param string|array $post_type post_type původního dotazu.
	 * @return int[]
	 */
	protected static function get_ids( $term, $raw, $post_type ) {
		$key = ZGSS_Index::current_lang() . '|' . wp_json_encode( $post_type ) . '|' . $term;
		if ( isset( self::$cache[ $key ] ) ) {
			return self::$cache[ $key ];
		}

		$matches = ZGSS_Search_Router::search( $term, self::LIMIT );

		$replacement = ZGSS_Replacements::apply( $term );
		if ( $replacement['changed'] ) {
			$replaced = ZGSS_Search_Router::search( $replacement['effective'], self::LIMIT );
			if ( ! empty( $replaced ) ) {
				$matches = $replaced;
			}
		}

		$engine_ids = array_map( 'intval', wp_list_pluck( $matches, 'id' ) );

		// Nativní hledání se stejným typem obsahu – zachová vše, co se zobrazovalo dřív.
		$native_ids = get_posts(
			array(
				's'                => $raw,
				'post_type'        => empty( $post_type ) ? 'any' : $post_type,
				'post_status'      => 'publish',
				'posts_per_page'   => self::LIMIT,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'zgss_skip'        => 1,
				'suppress_filters' => false, // WPML jazykový filtr.
			)
		);

		$ids = array_values( array_unique( array_filter( array_merge( $engine_ids, array_map( 'intval', (array) $native_ids ) ) ) ) );

		// Když engine nic nepřidal, není důvod měnit nativní dotaz.
		if ( empty( $engine_ids ) ) {
			$ids = array();
		}

		self::$cache[ $key ] = $ids;
		return $ids;
	}
}
