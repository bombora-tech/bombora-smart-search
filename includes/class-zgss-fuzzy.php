<?php
/**
 * Fuzzy matching engine – rozpoznávání překlepů a částečných shod.
 *
 * @package ZG_Smart_Search
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZGSS_Fuzzy {

	/**
	 * Praktická transliterace ruské azbuky do latinky (fonetická, ne
	 * striktně dle normy) - cílem je, aby "киндзмараули" po normalizaci
	 * dopadlo na stejný řetězec jako "Kindzmarauli", a naopak aby produkt
	 * uložený v ruské (Cyrillic) jazykové verzi šel najít i při psaní
	 * latinkou. Aplikuje se automaticky uvnitř normalize() na jakýkoliv
	 * text (dotaz i indexovaná pole), takže funguje obousměrně bez ohledu
	 * na to, na které straně azbuka je.
	 *
	 * @var array
	 */
	protected static $cyrillic_map = array(
		'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd',
		'е' => 'e', 'ё' => 'e', 'ж' => 'zh', 'з' => 'z', 'и' => 'i',
		'й' => 'i', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n',
		'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't',
		'у' => 'u', 'ф' => 'f', 'х' => 'h', 'ц' => 'ts', 'ч' => 'ch',
		'ш' => 'sh', 'щ' => 'shch', 'ъ' => '', 'ы' => 'y', 'ь' => '',
		'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
	);

	/**
	 * Přepíše azbuku v řetězci na latinku (pokud nějaká je); jinak vrátí
	 * řetězec beze změny.
	 *
	 * @param string $str Vstupní řetězec (očekává se malá písmena, UTF-8).
	 * @return string
	 */
	protected static function transliterate_cyrillic( $str ) {
		if ( ! preg_match( '/[а-яё]/u', $str ) ) {
			return $str;
		}
		return strtr( $str, self::$cyrillic_map );
	}

	/**
	 * Veřejná varianta transliterace pro použití mimo tuto třídu (JS
	 * zvýrazňování shody potřebuje dotaz už v latince, i když ho uživatel
	 * napsal azbukou - viz ZGSS_Ajax::handle_search()). Na rozdíl od
	 * normalize() zachovává mezery mezi slovy a nekolabuje interpunkci,
	 * jen převádí na malá písmena, azbuku na latinku a odstraňuje diakritiku.
	 *
	 * @param string $str Vstupní řetězec.
	 * @return string
	 */
	public static function to_latin_words( $str ) {
		$str = wp_strip_all_tags( (string) $str );
		$str = function_exists( 'mb_strtolower' ) ? mb_strtolower( $str, 'UTF-8' ) : strtolower( $str );
		$str = self::transliterate_cyrillic( $str );
		$str = remove_accents( $str );
		return trim( $str );
	}

	/**
	 * Normalizuje řetězec pro porovnávání: přepíše azbuku na latinku,
	 * odstraní diakritiku, převede na malá písmena a nechá jen
	 * alfanumerické znaky a mezery.
	 *
	 * @param string $str Vstupní řetězec.
	 * @return string
	 */
	public static function normalize( $str ) {
		$str = wp_strip_all_tags( (string) $str );
		$str = function_exists( 'mb_strtolower' ) ? mb_strtolower( $str, 'UTF-8' ) : strtolower( $str );
		$str = self::transliterate_cyrillic( $str );
		$str = remove_accents( $str );
		$str = preg_replace( '/[^a-z0-9\s]/', ' ', $str );
		$str = preg_replace( '/\s+/', ' ', trim( $str ) );
		return $str;
	}

	/**
	 * Skóre shody jednoho slova z dotazu vůči jednomu slovu z pole (0-100).
	 *
	 * @param string $query_word Slovo z dotazu.
	 * @param string $field_word Slovo z indexovaného pole.
	 * @return float
	 */
	protected static function word_score( $query_word, $field_word ) {
		if ( '' === $query_word || '' === $field_word ) {
			return 0;
		}
		if ( $field_word === $query_word ) {
			return 100;
		}
		if ( 0 === strpos( $field_word, $query_word ) ) {
			// Pole začíná dotazem - typické u částečných hledání jako "kind" -> "kindzmarauli".
			return 92;
		}
		if ( false !== strpos( $field_word, $query_word ) ) {
			return 78;
		}

		$len = max( strlen( $query_word ), strlen( $field_word ) );
		if ( $len < 3 ) {
			return 0;
		}

		// Práh povolené editační vzdálenosti roste s délkou slova - delší slova
		// mohou mít víc překlepů (chybějící/přebývající písmeno), u krátkých
		// musíme být přísnější, ať nechytáme náhodné shody.
		if ( $len <= 4 ) {
			$threshold = 1;
		} elseif ( $len <= 8 ) {
			$threshold = 2;
		} elseif ( $len <= 12 ) {
			$threshold = 3;
		} else {
			$threshold = 4;
		}

		$distance = levenshtein( $query_word, $field_word );
		if ( $distance <= $threshold ) {
			return max( 0, 74 - ( $distance * 18 ) );
		}

		similar_text( $query_word, $field_word, $percent );
		if ( $percent >= 72 ) {
			return max( 0, $percent - 25 );
		}

		return 0;
	}

	/**
	 * Skóre shody celého dotazu (víc slov) vůči jednomu textovému poli.
	 *
	 * @param array  $query_words Slova dotazu (normalizovaná).
	 * @param string $field_norm  Normalizovaný text pole.
	 * @return float
	 */
	public static function field_score( $query_words, $field_norm ) {
		if ( '' === $field_norm || empty( $query_words ) ) {
			return 0;
		}

		$field_words = explode( ' ', $field_norm );
		$total       = 0;
		$matched     = 0;
		$word_count  = 0;

		foreach ( $query_words as $qw ) {
			if ( '' === $qw ) {
				continue;
			}
			++$word_count;
			$best = 0;
			foreach ( $field_words as $fw ) {
				$s = self::word_score( $qw, $fw );
				if ( $s > $best ) {
					$best = $s;
				}
			}
			$total += $best;
			if ( $best > 0 ) {
				++$matched;
			}
		}

		if ( 0 === $word_count ) {
			return 0;
		}

		$avg      = $total / $word_count;
		$coverage = $matched / $word_count; // kolik slov z dotazu vůbec něco našlo.

		// Penalizace, pokud se u víceslovného dotazu netrefí všechna slova,
		// ale necháváme i tak nějaké skóre pro volnější "OR" chování.
		return $avg * ( 0.4 + 0.6 * $coverage );
	}

	/**
	 * Hlavní vyhledávací funkce - projede kandidáty z indexu a vrátí seřazené shody.
	 *
	 * @param string $term    Hledaný výraz (raw, uživatelský vstup).
	 * @param int    $limit   Max. počet výsledků.
	 * @param array  $filters Volitelné filtry podle vlastností produktu -
	 *                        {taxonomy_slug: [term_slug, ...]} (viz
	 *                        ZGSS_Index::filter_candidates()). Prázdné pole
	 *                        (výchozí) = beze změny původního chování.
	 * @return array
	 */
	public static function search( $term, $limit = 8, $filters = array() ) {
		$norm_term  = self::normalize( $term );
		$candidates = ZGSS_Index::get_candidates();

		if ( ! empty( $filters ) ) {
			$candidates = ZGSS_Index::filter_candidates( $candidates, $filters );
		}

		if ( '' === $norm_term ) {
			if ( empty( $filters ) ) {
				return array(); // Beze změny původního chování (prázdný dotaz bez filtrů = žádné výsledky odsud).
			}

			// Jen filtry, bez textu = "prohlížení" podle vlastností -
			// seřadit podle popularity (total_sales), ne podle skóre shody.
			usort(
				$candidates,
				function ( $a, $b ) {
					return $b['total_sales'] <=> $a['total_sales'];
				}
			);
			return array_slice( $candidates, 0, $limit );
		}

		$query_words = explode( ' ', $norm_term );
		$scored      = array();

		foreach ( $candidates as $c ) {
			$title_score = self::field_score( $query_words, $c['title_norm'] );
			$sku_score   = ( '' !== $c['sku_norm'] ) ? self::field_score( $query_words, $c['sku_norm'] ) : 0;
			$cat_score   = ( '' !== $c['cats_norm'] ) ? self::field_score( $query_words, $c['cats_norm'] ) : 0;

			$score = ( $title_score * 1.0 ) + ( $sku_score * 0.55 ) + ( $cat_score * 0.35 );

			if ( $score <= 14 ) {
				continue; // příliš slabá shoda, ignorujeme šum.
			}

			// Drobný "tie-breaker" podle popularity produktu (bestsellery se
			// při remízách zobrazí výš).
			$score += min( 6, $c['total_sales'] / 40 );

			$c['score'] = $score;
			$scored[]   = $c;
		}

		usort(
			$scored,
			function ( $a, $b ) {
				return $b['score'] <=> $a['score'];
			}
		);

		return array_slice( $scored, 0, $limit );
	}
}
