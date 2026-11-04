<?php
/**
 * Plugin Name:       DG Bullion Ticker & Pricing
 * Description:       Live precious-metal ticker bar under your site header plus WooCommerce bullion pricing (wire / debit card / credit card) powered by the Dillon Gage FizTrade (FizConnect) API.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Your Company
 * Text Domain:       dg-bullion
 * WC requires at least: 7.0
 * WC tested up to:   9.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DGBP_VERSION', '1.0.0' );
define( 'DGBP_FILE', __FILE__ );
define( 'DGBP_DIR', plugin_dir_path( __FILE__ ) );
define( 'DGBP_URL', plugin_dir_url( __FILE__ ) );

require_once DGBP_DIR . 'includes/class-dgbp-settings.php';
require_once DGBP_DIR . 'includes/class-dgbp-api.php';
require_once DGBP_DIR . 'includes/class-dgbp-pricing.php';
require_once DGBP_DIR . 'includes/class-dgbp-rest.php';
require_once DGBP_DIR . 'includes/class-dgbp-ticker.php';
require_once DGBP_DIR . 'includes/class-dgbp-admin.php';
require_once DGBP_DIR . 'includes/class-dgbp-woo.php';
require_once DGBP_DIR . 'includes/class-dgbp-tax.php';

// Declare compatibility with WooCommerce High-Performance Order Storage.
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

add_action(
	'plugins_loaded',
	function () {
		DGBP_REST::init();
		DGBP_Ticker::init();
		if ( is_admin() ) {
			DGBP_Admin::init();
		}
		if ( class_exists( 'WooCommerce' ) ) {
			DGBP_Woo::init();
			DGBP_Tax::init();
		}
	}
);

// Background refresh so visitors rarely wait on the API.
add_filter(
	'cron_schedules',
	function ( $schedules ) {
		$schedules['dgbp_minute'] = array(
			'interval' => 60,
			'display'  => 'Every minute (DG Bullion)',
		);
		return $schedules;
	}
);

add_action( 'dgbp_refresh_prices', array( 'DGBP_API', 'cron_refresh' ) );

register_activation_hook(
	__FILE__,
	function () {
		if ( ! wp_next_scheduled( 'dgbp_refresh_prices' ) ) {
			wp_schedule_event( time() + 60, 'dgbp_minute', 'dgbp_refresh_prices' );
		}
	}
);

register_deactivation_hook(
	__FILE__,
	function () {
		wp_clear_scheduled_hook( 'dgbp_refresh_prices' );
	}
);
