<?php
/**
 * Narrow spot-price ticker bar.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DGBP_Ticker {

	private static $printed = false;

	public static function init() {
		add_shortcode( 'dg_ticker', array( __CLASS__, 'shortcode' ) );

		if ( ! DGBP_Settings::get( 'ticker_enabled' ) || is_admin() ) {
			return;
		}
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );

		if ( 'shortcode' !== DGBP_Settings::get( 'ticker_placement' ) ) {
			add_action( 'wp_body_open', array( __CLASS__, 'print_auto' ), 5 );
			// Fallback for themes that don't call wp_body_open().
			add_action( 'wp_footer', array( __CLASS__, 'print_auto' ), 5 );
		}
	}

	public static function assets() {
		$s = DGBP_Settings::all();
		wp_enqueue_style( 'dgbp-ticker', DGBP_URL . 'assets/ticker.css', array(), DGBP_VERSION );
		// Elementor exposes the site's Global Colors as CSS variables; use them when present,
		// falling back to the colors picked in settings.
		$el = ! empty( $s['use_elementor_colors'] );
		$c  = function ( $key, $el_var ) use ( $s, $el ) {
			$hex = esc_attr( $s[ $key ] );
			return $el && $el_var ? "var(--e-global-color-{$el_var}, {$hex})" : $hex;
		};
		wp_add_inline_style(
			'dgbp-ticker',
			sprintf(
				'.dgbp-ticker{--dgbp-bg:%s;--dgbp-text:%s;--dgbp-label:%s;--dgbp-up:%s;--dgbp-down:%s;--dgbp-border:%s;--dgbp-h:%dpx;--dgbp-fs:%dpx;%s}',
				$c( 'color_bg', 'primary' ),
				esc_attr( $s['color_text'] ),
				$c( 'color_label', 'accent' ),
				esc_attr( $s['color_up'] ),
				esc_attr( $s['color_down'] ),
				$c( 'color_border', 'accent' ),
				(int) $s['ticker_height'],
				(int) $s['ticker_font_size'],
				$el ? 'font-family:var(--e-global-typography-text-font-family, inherit);' : ''
			)
		);
		wp_enqueue_script( 'dgbp-ticker', DGBP_URL . 'assets/ticker.js', array(), DGBP_VERSION, true );
		wp_localize_script(
			'dgbp-ticker',
			'DGBPTicker',
			array(
				'endpoint'  => esc_url_raw( rest_url( 'dgbp/v1/spot' ) ),
				'refresh'   => (int) $s['ticker_refresh'],
				'show'      => $s['ticker_show'],
				'change'    => (int) $s['ticker_change'],
				'placement' => $s['ticker_placement'],
				'selector'  => $s['ticker_selector'],
				'names'     => DGBP_Settings::METALS,
			)
		);
	}

	public static function print_auto() {
		if ( self::$printed ) {
			return;
		}
		echo self::render( 'after_header' === DGBP_Settings::get( 'ticker_placement' ) ); // phpcs:ignore -- escaped in render().
	}

	public static function shortcode() {
		if ( self::$printed ) {
			return '';
		}
		if ( ! wp_script_is( 'dgbp-ticker', 'enqueued' ) ) {
			self::assets();
		}
		return self::render( false );
	}

	public static function fmt( $n ) {
		return '$' . number_format( (float) $n, 2 );
	}

	public static function item_html( $metal, array $p, array $s ) {
		$names = DGBP_Settings::METALS;
		if ( 'both' === $s['ticker_show'] ) {
			$price = self::fmt( $p['bid'] ) . ' / ' . self::fmt( $p['ask'] );
		} else {
			$price = self::fmt( 'bid' === $s['ticker_show'] ? $p['bid'] : $p['ask'] );
		}

		$html  = '<span class="dgbp-item" data-metal="' . esc_attr( $metal ) . '">';
		$html .= '<span class="dgbp-name">' . esc_html( $names[ $metal ] ) . '</span>';
		$html .= '<span class="dgbp-price">' . esc_html( $price ) . '</span>';
		if ( $s['ticker_change'] ) {
			$chg   = (float) $p['change'];
			$cls   = $chg > 0 ? 'dgbp-up' : ( $chg < 0 ? 'dgbp-down' : 'dgbp-flat' );
			$arrow = $chg > 0 ? '▲' : ( $chg < 0 ? '▼' : '•' );
			$html .= '<span class="dgbp-chg ' . $cls . '">' . $arrow . ' ' . esc_html( number_format( abs( $chg ), 2 ) . ' (' . number_format( abs( (float) $p['change_pct'] ), 2 ) . '%)' ) . '</span>';
		}
		$html .= '</span>';
		return $html;
	}

	public static function render( $pending ) {
		self::$printed = true;
		$s     = DGBP_Settings::all();
		$spot  = DGBP_API::get_spot();
		$items = '';
		foreach ( (array) $s['ticker_metals'] as $metal ) {
			if ( isset( $spot['prices'][ $metal ] ) ) {
				$items .= self::item_html( $metal, $spot['prices'][ $metal ], $s );
			}
		}
		if ( '' === $items ) {
			$items = '<span class="dgbp-item dgbp-loading">Loading prices…</span>';
		}

		$classes = 'dgbp-ticker dgbp-mode-' . esc_attr( $s['ticker_mode'] ) . ( $pending ? ' dgbp-pending' : '' );

		ob_start();
		?>
		<div id="dgbp-ticker" class="<?php echo $classes; // phpcs:ignore ?>" role="region" aria-label="Precious metal spot prices" data-updated="<?php echo (int) $spot['updated']; ?>">
			<div class="dgbp-inner">
				<?php if ( $s['ticker_label'] ) : ?>
					<span class="dgbp-label"><span class="dgbp-dot" aria-hidden="true"></span><?php echo esc_html( $s['ticker_label'] ); ?></span>
				<?php endif; ?>
				<div class="dgbp-track">
					<div class="dgbp-items" aria-live="off"><?php echo $items; // phpcs:ignore -- built from escaped parts. ?></div>
				</div>
				<span class="dgbp-meta">
					<span class="dgbp-time"></span>
					<?php if ( $s['ticker_note'] ) : ?>
						<span class="dgbp-note"><?php echo esc_html( $s['ticker_note'] ); ?></span>
					<?php endif; ?>
				</span>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}
}
