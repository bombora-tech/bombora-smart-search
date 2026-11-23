<?php
/**
 * Shortcode [zg_smart_search] - vykreslí vyhledávací pole + prázdný kontejner
 * pro dropdown, který se naplní přes JS/AJAX.
 *
 * Doporučené použití v Bricks Builder: element "Shortcode" s hodnotou
 * [zg_smart_search]. Tento přístup se vyhýbá známým problémům s
 * duplikací JS eventů u vlastních Bricks elementů se shortcody (viz
 * poznámky k dopravnímu popup pluginu).
 *
 * @package ZG_Smart_Search
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZGSS_Shortcode {

	public static function init() {
		add_shortcode( 'zg_smart_search', array( __CLASS__, 'render' ) );
	}

	/**
	 * @param array $atts Atributy shortcode.
	 * @return string
	 */
	public static function render( $atts ) {
		$atts = shortcode_atts(
			array(
				'placeholder' => __( 'Hledejte produkty…', 'zg-smart-search' ),
				'id'          => 'zgss-' . wp_unique_id(),
			),
			$atts,
			'zg_smart_search'
		);

		ZGSS_WPML::register_string( $atts['placeholder'], 'search_placeholder' );
		$placeholder = ZGSS_WPML::translate_string( $atts['placeholder'], 'search_placeholder' );

		ob_start();
		?>
		<style>
			/* Kritické minimum přímo v HTML, ať pole hledání nevypadá
			   "rozbité" než se stihne načíst plný styl (zejména při
			   agresivní optimalizaci CSS pluginy jako WP Rocket/Perfmatters). */
			#<?php echo esc_attr( $atts['id'] ); ?> .zgss-search__bar{display:flex;align-items:center;border:1px solid #e7e7e7;border-radius:999px;background:#fff;padding:4px 6px 4px 18px;}
			#<?php echo esc_attr( $atts['id'] ); ?> .zgss-search__input{flex:1;border:0;outline:none;font-size:15px;padding:10px 6px;background:transparent;}
			#<?php echo esc_attr( $atts['id'] ); ?> .zgss-search__btn{display:inline-flex;align-items:center;justify-content:center;width:40px;height:40px;border:0;border-radius:50%;background:#c8102e;color:#fff;cursor:pointer;flex-shrink:0;}
			#<?php echo esc_attr( $atts['id'] ); ?> .zgss-search__dropdown,
			#<?php echo esc_attr( $atts['id'] ); ?> .zgss-search__arrow{display:none;}
			@media (max-width:1024px){
				#<?php echo esc_attr( $atts['id'] ); ?>{width:100% !important;max-width:100% !important;flex:1 1 100% !important;min-width:0 !important;}
			}
			@media (min-width:481px) and (max-width:1024px){
				#<?php echo esc_attr( $atts['id'] ); ?> .zgss-search__bar{padding:3px 4px 3px 14px;}
				#<?php echo esc_attr( $atts['id'] ); ?> .zgss-search__input{font-size:14px;padding:8px 4px;}
				#<?php echo esc_attr( $atts['id'] ); ?> .zgss-search__btn{width:34px;height:34px;}
			}
		</style>
		<div class="zgss-search" id="<?php echo esc_attr( $atts['id'] ); ?>" data-zgss-search="1">
			<div class="zgss-search__bar">
				<input
					type="text"
					class="zgss-search__input"
					autocomplete="off"
					placeholder="<?php echo esc_attr( $placeholder ); ?>"
					aria-label="<?php echo esc_attr( $placeholder ); ?>"
				/>
				<button type="button" class="zgss-search__btn" aria-label="<?php esc_attr_e( 'Hledat', 'zg-smart-search' ); ?>">
					<svg viewBox="0 0 24 24" width="18" height="18" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
						<circle cx="11" cy="11" r="7" stroke="currentColor" stroke-width="2"/>
						<line x1="16.4" y1="16.4" x2="21" y2="21" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
					</svg>
				</button>
			</div>
			<div class="zgss-search__arrow" hidden></div>
			<div class="zgss-search__dropdown" hidden>
				<button type="button" class="zgss-search__close" aria-label="<?php esc_attr_e( 'Zavřít', 'zg-smart-search' ); ?>">&times;</button>
				<div class="zgss-search__dropdown-inner"></div>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}
}
