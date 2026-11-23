<?php
/**
 * Elasticsearch vrstva vyhledávání - alternativa k MySQL/fuzzy vyhledávání
 * (ZGSS_Fuzzy). Aktivuje/deaktivuje se přepínačem v nastavení
 * (WooCommerce > Smart Search Advanced > Elasticsearch) a nijak neupravuje
 * původní MySQL logiku - jde o samostatnou, vyměnitelnou vrstvu.
 *
 * Dokumenty se do ES ukládají už normalizované přes ZGSS_Fuzzy::normalize()
 * (stejná transliterace azbuky, diakritika, malá písmena jako u MySQL
 * varianty), takže chování překlepů/azbuky/diakritiky zůstává mezi oběma
 * enginy konzistentní - liší se jen "motor", který nad daty hledá.
 *
 * @package ZG_Smart_Search
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZGSS_Elasticsearch {

	const STATS_CACHE_KEY = 'zgss_es_stats_cache';
	const LAST_ERROR_KEY   = 'zgss_es_last_error';

	/**
	 * Zaregistruje AJAX endpointy pro admin stránku (test připojení,
	 * reindexace, živé metriky CPU/paměti).
	 */
	public static function init() {
		add_action( 'wp_ajax_zgss_es_test', array( __CLASS__, 'ajax_test_connection' ) );
		add_action( 'wp_ajax_zgss_es_reindex', array( __CLASS__, 'ajax_reindex' ) );
		add_action( 'wp_ajax_zgss_es_stats', array( __CLASS__, 'ajax_get_stats' ) );
	}

	/**
	 * Vrátí relevantní část nastavení pro Elasticsearch.
	 *
	 * @return array
	 */
	public static function get_config() {
		$settings = ZGSS_Admin::get_settings();
		return array(
			'scheme'      => ! empty( $settings['es_scheme'] ) ? $settings['es_scheme'] : 'https',
			'host'        => trim( (string) ( $settings['es_host'] ?? '' ) ),
			'port'        => (int) ( $settings['es_port'] ?? 9200 ),
			'index_prefix'=> ! empty( $settings['es_index_prefix'] ) ? sanitize_key( $settings['es_index_prefix'] ) : 'zgss_products',
			'auth_type'   => ! empty( $settings['es_auth_type'] ) ? $settings['es_auth_type'] : 'none',
			'username'    => (string) ( $settings['es_username'] ?? '' ),
			'password'    => (string) ( $settings['es_password'] ?? '' ),
			'api_key'     => (string) ( $settings['es_api_key'] ?? '' ),
			'verify_ssl'  => ! isset( $settings['es_verify_ssl'] ) || ! empty( $settings['es_verify_ssl'] ),
			'stats_enabled' => ! empty( $settings['es_stats_enabled'] ),
		);
	}

	/**
	 * Je Elasticsearch nastaven natolik, že to dává smysl zkusit použít
	 * (má vyplněný alespoň host)?
	 *
	 * @return bool
	 */
	public static function is_configured() {
		$cfg = self::get_config();
		return '' !== $cfg['host'];
	}

	/**
	 * Sestaví základní URL clusteru (bez cesty).
	 *
	 * @param array $cfg Konfigurace z get_config().
	 * @return string
	 */
	protected static function base_url( $cfg ) {
		return sprintf( '%s://%s:%d', $cfg['scheme'], $cfg['host'], $cfg['port'] );
	}

	/**
	 * Sestaví autorizační hlavičku podle zvoleného typu.
	 *
	 * @param array $cfg Konfigurace z get_config().
	 * @return array
	 */
	protected static function auth_headers( $cfg ) {
		if ( 'basic' === $cfg['auth_type'] && '' !== $cfg['username'] ) {
			return array( 'Authorization' => 'Basic ' . base64_encode( $cfg['username'] . ':' . $cfg['password'] ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}
		if ( 'api_key' === $cfg['auth_type'] && '' !== $cfg['api_key'] ) {
			return array( 'Authorization' => 'ApiKey ' . $cfg['api_key'] );
		}
		return array();
	}

	/**
	 * Provede HTTP request na Elasticsearch REST API.
	 *
	 * @param string     $method  GET/POST/PUT/HEAD/DELETE.
	 * @param string     $path    Cesta včetně lomítka, např. '/_cluster/health'.
	 * @param array|null $body    Tělo požadavku (bude JSON-encodováno), nebo NDJSON string pro _bulk.
	 * @param int        $timeout Timeout v sekundách.
	 * @return array|WP_Error Dekódovaná JSON odpověď, nebo WP_Error.
	 */
	protected static function request( $method, $path, $body = null, $timeout = 8 ) {
		$cfg = self::get_config();

		if ( '' === $cfg['host'] ) {
			return new WP_Error( 'zgss_es_not_configured', __( 'Elasticsearch není nakonfigurován (chybí host).', 'zg-smart-search' ) );
		}

		$url     = self::base_url( $cfg ) . $path;
		$headers = array_merge( array( 'Content-Type' => 'application/json' ), self::auth_headers( $cfg ) );

		$is_ndjson = is_string( $body ) && false !== strpos( $path, '_bulk' );
		if ( $is_ndjson ) {
			$headers['Content-Type'] = 'application/x-ndjson';
		}

		$args = array(
			'method'    => $method,
			'headers'   => $headers,
			'timeout'   => $timeout,
			'sslverify' => $cfg['verify_ssl'],
		);

		if ( null !== $body ) {
			$args['body'] = $is_ndjson ? $body : wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			self::log_error( $method . ' ' . $path, $response->get_error_message() );
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( $code >= 400 ) {
			$msg = is_array( $data ) && isset( $data['error']['reason'] ) ? $data['error']['reason'] : ( 'HTTP ' . $code );
			self::log_error( $method . ' ' . $path, $msg );
			return new WP_Error( 'zgss_es_http_error', $msg, array( 'status' => $code ) );
		}

		return is_array( $data ) ? $data : array();
	}

	/**
	 * Uloží poslední chybu komunikace s ES pro zobrazení v administraci.
	 *
	 * @param string $context Co se dělalo (endpoint).
	 * @param string $message Chybová hláška.
	 */
	protected static function log_error( $context, $message ) {
		set_transient(
			self::LAST_ERROR_KEY,
			array(
				'time'    => current_time( 'mysql' ),
				'context' => $context,
				'message' => $message,
			),
			DAY_IN_SECONDS
		);
	}

	/**
	 * Vrátí poslední zalogovanou chybu (nebo null).
	 *
	 * @return array|null
	 */
	public static function get_last_error() {
		$err = get_transient( self::LAST_ERROR_KEY );
		return $err ? $err : null;
	}

	/**
	 * Název indexu pro daný jazyk.
	 *
	 * @param string $lang Jazykový kód (nebo 'default').
	 * @return string
	 */
	public static function index_name( $lang ) {
		$cfg = self::get_config();
		return $cfg['index_prefix'] . '_' . sanitize_key( $lang );
	}

	/**
	 * Ověří dostupnost clusteru (zdraví clusteru).
	 *
	 * @return array {ok: bool, message: string, details?: array}
	 */
	public static function test_connection() {
		$result = self::request( 'GET', '/_cluster/health', null, 5 );

		if ( is_wp_error( $result ) ) {
			return array(
				'ok'      => false,
				'message' => $result->get_error_message(),
			);
		}

		return array(
			'ok'      => true,
			'message' => sprintf(
				/* translators: 1: cluster name, 2: cluster status (green/yellow/red) */
				__( 'Připojeno ke clusteru "%1$s" (stav: %2$s).', 'zg-smart-search' ),
				$result['cluster_name'] ?? '?',
				strtoupper( $result['status'] ?? '?' )
			),
			'details' => $result,
		);
	}

	/**
	 * Zajistí, že index pro daný jazyk existuje (vytvoří ho s mapováním, pokud chybí).
	 *
	 * @param string $lang Jazykový kód.
	 * @return bool
	 */
	protected static function ensure_index( $lang ) {
		$index = self::index_name( $lang );
		$check = self::request( 'HEAD', '/' . $index, null, 5 );

		// HEAD úspěšně vrátí prázdné pole [] i při 200 (žádné tělo) - chybu poznáme jen přes WP_Error.
		if ( ! is_wp_error( $check ) ) {
			return true;
		}

		$mapping = array(
			'settings' => array(
				'number_of_shards'   => 1,
				'number_of_replicas' => 0,
			),
			'mappings' => array(
				'properties' => array(
					'id'            => array( 'type' => 'integer' ),
					'title'         => array( 'type' => 'text' ),
					'title_norm'    => array( 'type' => 'text' ),
					'sku'           => array( 'type' => 'keyword' ),
					'sku_norm'      => array( 'type' => 'text' ),
					'cats_norm'     => array( 'type' => 'text' ),
					'price_html'    => array( 'type' => 'keyword', 'index' => false ),
					'permalink'     => array( 'type' => 'keyword', 'index' => false ),
					'image'         => array( 'type' => 'keyword', 'index' => false ),
					'in_stock'      => array( 'type' => 'boolean' ),
					'stock_status'  => array( 'type' => 'keyword' ),
					'on_sale'       => array( 'type' => 'boolean' ),
					'sale_percent'  => array( 'type' => 'integer' ),
					'total_sales'   => array( 'type' => 'integer' ),
					'category_ids'  => array( 'type' => 'integer' ),
					'is_bestseller' => array( 'type' => 'boolean' ),
				),
			),
		);

		$created = self::request( 'PUT', '/' . $index, $mapping, 10 );
		return ! is_wp_error( $created );
	}

	/**
	 * Znovu naindexuje produkty do Elasticsearch pro všechny aktivní jazyky.
	 * Přepíná WPML jazyk kvůli lokalizovaným názvům/kategoriím - po dokončení
	 * vrací původní jazyk zpět.
	 *
	 * @return array {ok: bool, message: string, indexed?: int}
	 */
	public static function reindex_all() {
		if ( ! self::is_configured() ) {
			return array(
				'ok'      => false,
				'message' => __( 'Elasticsearch není nakonfigurován.', 'zg-smart-search' ),
			);
		}

		$languages = array( 'default' );
		if ( function_exists( 'apply_filters' ) ) {
			$active = apply_filters( 'wpml_active_languages', array() );
			if ( is_array( $active ) && ! empty( $active ) ) {
				$languages = array_keys( $active );
			}
		}

		$original_lang = apply_filters( 'wpml_current_language', null );
		$total_indexed = 0;

		foreach ( array_unique( $languages ) as $lang ) {
			if ( has_action( 'wpml_switch_language' ) ) {
				do_action( 'wpml_switch_language', $lang );
			}

			if ( ! self::ensure_index( $lang ) ) {
				continue;
			}

			$products = ZGSS_Index::get_all_products_data();
			if ( empty( $products ) ) {
				continue;
			}

			$index = self::index_name( $lang );
			$ndjson = '';
			foreach ( $products as $p ) {
				$ndjson .= wp_json_encode( array( 'index' => array( '_index' => $index, '_id' => $p['id'] ) ) ) . "\n";
				$ndjson .= wp_json_encode( $p ) . "\n";
			}

			$result = self::request( 'POST', '/_bulk', $ndjson, 30 );
			if ( ! is_wp_error( $result ) ) {
				$total_indexed += count( $products );
			}
		}

		if ( $original_lang && has_action( 'wpml_switch_language' ) ) {
			do_action( 'wpml_switch_language', $original_lang );
		}

		update_option( 'zgss_es_last_reindex', current_time( 'mysql' ) );

		return array(
			'ok'      => true,
			'message' => sprintf(
				/* translators: %d: number of indexed products */
				__( 'Naindexováno %d produktů (napříč všemi jazyky).', 'zg-smart-search' ),
				$total_indexed
			),
			'indexed' => $total_indexed,
		);
	}

	/**
	 * Vyhledá produkty v Elasticsearch. Vrací stejný tvar dat jako
	 * ZGSS_Fuzzy::search(), aby ho ZGSS_Ajax mohl formátovat beze změny.
	 *
	 * @param string $term    Hledaný výraz (raw).
	 * @param int    $limit   Max. počet výsledků.
	 * @param array  $filters Volitelné filtry podle vlastností produktu -
	 *                        {taxonomy_slug: [term_slug, ...]}.
	 * @return array|null Pole kandidátů, nebo null při chybě (volající pak spadne zpět na MySQL).
	 */
	public static function search( $term, $limit = 8, $filters = array() ) {
		$norm_term = ZGSS_Fuzzy::normalize( $term );
		$filters   = array_filter( (array) $filters );

		if ( '' === $norm_term && empty( $filters ) ) {
			return array();
		}

		$lang  = ZGSS_Index::current_lang();
		$index = self::index_name( $lang );

		$bool = array();

		if ( '' !== $norm_term ) {
			// "match" s fuzziness chytá překlepy u CELÝCH slov, ale ne
			// částečně napsaný výraz za psaní (např. "saper" u "saperavi") -
			// editační vzdálenost mezi "saper" a "saperavi" je moc velká na
			// AUTO fuzziness. Proto přidáváme i "match_bool_prefix" - ten
			// bere poslední napsané slovo jako prefix (přesně pro live
			// vyhledávání za psaní), beze změny mapování/reindexace.
			$bool['should']               = array(
				array( 'match' => array( 'title_norm' => array( 'query' => $norm_term, 'fuzziness' => 'AUTO', 'boost' => 1.0 ) ) ),
				array( 'match_bool_prefix' => array( 'title_norm' => array( 'query' => $norm_term, 'boost' => 0.9 ) ) ),
				array( 'match' => array( 'sku_norm' => array( 'query' => $norm_term, 'fuzziness' => 'AUTO', 'boost' => 0.55 ) ) ),
				array( 'match_bool_prefix' => array( 'sku_norm' => array( 'query' => $norm_term, 'boost' => 0.5 ) ) ),
				array( 'match' => array( 'cats_norm' => array( 'query' => $norm_term, 'fuzziness' => 'AUTO', 'boost' => 0.35 ) ) ),
				array( 'match_bool_prefix' => array( 'cats_norm' => array( 'query' => $norm_term, 'boost' => 0.3 ) ) ),
			);
			$bool['minimum_should_match'] = 1;
		}

		if ( ! empty( $filters ) ) {
			$filter_clauses = array();
			foreach ( $filters as $taxonomy => $terms ) {
				$filter_clauses[] = array(
					'terms' => array(
						'attr_terms.' . sanitize_key( $taxonomy ) . '.keyword' => array_values( $terms ),
					),
				);
			}
			$bool['filter'] = $filter_clauses;
		}

		$query = array(
			'size'  => $limit,
			'query' => array( 'bool' => $bool ),
		);

		// Bez textového dotazu (jen filtry - "prohlížení") není podle čeho
		// řadit relevanci, takže řadíme podle popularity, stejně jako MySQL varianta.
		if ( '' === $norm_term ) {
			$query['sort'] = array( array( 'total_sales' => 'desc' ) );
		}

		$result = self::request( 'POST', '/' . $index . '/_search', $query, 5 );

		if ( is_wp_error( $result ) || ! isset( $result['hits']['hits'] ) ) {
			return null;
		}

		$out = array();
		foreach ( $result['hits']['hits'] as $hit ) {
			$src = $hit['_source'];
			$src['score'] = $hit['_score'];
			$out[] = $src;
		}

		return $out;
	}

	/**
	 * Získá metriky uzlů clusteru (CPU, paměť) - krátce cachované (30s),
	 * ať admin stránka při rychlém refreshi/pollingu zbytečně nezatěžuje ES.
	 *
	 * @param bool $bypass_cache Přeskočit cache (např. při ručním obnovení).
	 * @return array {ok: bool, message?: string, nodes?: array}
	 */
	public static function get_node_stats( $bypass_cache = false ) {
		if ( ! $bypass_cache ) {
			$cached = get_transient( self::STATS_CACHE_KEY );
			if ( false !== $cached ) {
				return $cached;
			}
		}

		$result = self::request( 'GET', '/_nodes/stats/os,process,jvm', null, 5 );

		if ( is_wp_error( $result ) || ! isset( $result['nodes'] ) ) {
			$out = array(
				'ok'      => false,
				'message' => is_wp_error( $result )
					? $result->get_error_message()
					: __( 'Neočekávaná odpověď z Elasticsearch.', 'zg-smart-search' ),
			);
			set_transient( self::STATS_CACHE_KEY, $out, 15 );
			return $out;
		}

		$nodes = array();
		foreach ( $result['nodes'] as $node ) {
			$nodes[] = array(
				'name'        => $node['name'] ?? '?',
				'cpu_percent' => (int) ( $node['os']['cpu']['percent'] ?? 0 ),
				'mem_percent' => (int) ( $node['os']['mem']['used_percent'] ?? 0 ),
				'jvm_heap_percent' => (int) ( $node['jvm']['mem']['heap_used_percent'] ?? 0 ),
			);
		}

		$out = array(
			'ok'    => true,
			'nodes' => $nodes,
			'time'  => current_time( 'mysql' ),
		);

		set_transient( self::STATS_CACHE_KEY, $out, 30 );
		return $out;
	}

	/**
	 * AJAX: test připojení k Elasticsearch (tlačítko v administraci).
	 */
	public static function ajax_test_connection() {
		check_ajax_referer( 'zgss_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Nedostatečná oprávnění.', 'zg-smart-search' ) ) );
		}
		wp_send_json_success( self::test_connection() );
	}

	/**
	 * AJAX: spustí reindexaci (tlačítko v administraci).
	 */
	public static function ajax_reindex() {
		check_ajax_referer( 'zgss_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Nedostatečná oprávnění.', 'zg-smart-search' ) ) );
		}
		wp_send_json_success( self::reindex_all() );
	}

	/**
	 * AJAX: vrátí aktuální metriky CPU/paměti pro živý panel v administraci.
	 */
	public static function ajax_get_stats() {
		check_ajax_referer( 'zgss_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Nedostatečná oprávnění.', 'zg-smart-search' ) ) );
		}
		wp_send_json_success( self::get_node_stats() );
	}
}
