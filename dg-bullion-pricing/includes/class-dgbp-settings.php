<?php
/**
 * Central settings store with defaults.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DGBP_Settings {

	const OPTION = 'dgbp_settings';

	const METALS = array(
		'gold'      => 'Gold',
		'silver'    => 'Silver',
		'platinum'  => 'Platinum',
		'palladium' => 'Palladium',
	);

	const TIERS = array( 'wire', 'debit', 'credit' );

	public static function defaults() {
		return array(
			// API.
			'environment'        => 'staging',
			'base_url_staging'   => 'https://stage-connect.fiztrade.com/FizServices',
			'base_url_production'=> 'https://connect.fiztrade.com/FizServices',
			'token'              => '',
			'spot_path'          => 'GetSpotPriceData/{token}',
			'product_path'       => 'GetPriceForProduct/{token}/{code}',
			'product_price_key'  => '',
			'cache_seconds'      => 30,
			'stale_minutes'      => 10,
			'max_jump_pct'       => 5,     // Hold prices if one update moves more than this. 0 = off.
			'lock_minutes'       => 10,    // Checkout price lock. 0 = off.

			// Ticker.
			'ticker_enabled'     => 1,
			'ticker_metals'      => array( 'gold', 'silver', 'platinum', 'palladium' ),
			'ticker_show'        => 'ask',      // ask | bid | both.
			'ticker_change'      => 1,
			'ticker_mode'        => 'static',   // static | scroll.
			'ticker_placement'   => 'after_header',
			'ticker_selector'    => '.elementor-location-header, header.site-header, #masthead, header#header, .site-header, header',
			'use_elementor_colors' => 1,
			'ticker_refresh'     => 30,
			'ticker_label'       => 'Live Spot',
			'ticker_note'        => '',
			'color_bg'           => '#0f2340',
			'color_text'         => '#ffffff',
			'color_label'        => '#c9a54a',
			'color_up'           => '#3ccf7a',
			'color_down'         => '#ff6b6b',
			'color_border'       => '#c9a54a',
			'ticker_height'      => 34,
			'ticker_font_size'   => 13,

			// Pricing.
			'debit_pct'          => 3.0,
			'credit_pct'         => 4.0,
			'display_tier'       => 'wire',
			'label_wire'         => 'Wire / Check',
			'label_debit'        => 'Debit Card',
			'label_credit'       => 'Credit Card',
			'show_table'         => 1,
			'tax_rules'          => 'florida', // florida | off.
			'tax_gray_coins'     => 'taxable', // U.S. legal tender coins below HB 999 purity: taxable | exempt.
			'gateway_map'        => array(),    // gateway_id => wire|debit|credit|ask.
			'default_gateway_tier'=> 'credit',  // Safe default for unmapped gateways.
		);
	}

	public static function all() {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	public static function base_url() {
		$s   = self::all();
		$url = 'production' === $s['environment'] ? $s['base_url_production'] : $s['base_url_staging'];
		return untrailingslashit( $url );
	}

	public static function tier_label( $tier ) {
		$label = self::get( 'label_' . $tier );
		return $label ? $label : ucfirst( $tier );
	}
}
