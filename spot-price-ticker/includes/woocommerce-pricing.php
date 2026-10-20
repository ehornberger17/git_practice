<?php
/**
 * Spot-based WooCommerce pricing.
 *
 * Price = spot ask × fine troy oz + premium, with a wire price and a card
 * price per product, and the spot locked for each customer once they reach
 * checkout. Settings: WooCommerce → Metal Pricing.
 */

defined( 'ABSPATH' ) || exit;

const SPT_WC_OPTION = 'spt_wc_settings';
const SPT_WC_STATE  = 'spt_wc_state';
const SPT_WC_LOG    = 'spt_wc_log';
const SPT_WC_HOOK   = 'spt_wc_reprice';
const SPT_WC_FIELDS = array( '_spt_metal', '_spt_oz', '_spt_premium', '_spt_card_premium', '_spt_min_price' );

function spt_wc_defaults() {
	return array(
		'enabled'        => 0,
		'update_minutes' => 5,
		'lock_minutes'   => 10,
		'stale_minutes'  => 15,
		'max_jump_pct'   => 5,
		'card_pct'       => 0,
		'wire_gateways'  => array( 'bacs' ),
		'catalog_price'  => 'card',
	);
}

function spt_wc_settings() {
	return wp_parse_args( get_option( SPT_WC_OPTION, array() ), spt_wc_defaults() );
}

function spt_wc_on() {
	return (bool) spt_wc_settings()['enabled'];
}

/** The spot the store currently sells on, as accepted by the last update. */
function spt_wc_state() {
	return wp_parse_args( get_option( SPT_WC_STATE, array() ), array(
		'spot'       => array(),
		'updated'    => 0,
		'hold'       => null,
		'last_run'   => 0,
		'last_count' => 0,
	) );
}

function spt_wc_log( $msg ) {
	$log = get_option( SPT_WC_LOG, array() );
	if ( $log && $log[0][1] === $msg ) {
		return; // Don't flood the log with the same message every few minutes.
	}
	array_unshift( $log, array( time(), $msg ) );
	update_option( SPT_WC_LOG, array_slice( $log, 0, 100 ), false );
}

function spt_wc_money( $v ) {
	return '$' . number_format( (float) $v, 2 );
}

/* ------------------------------------------------------------------ *
 * Pricing
 * ------------------------------------------------------------------ */

/** A product's (or variation's) metal settings, or null if it isn't spot-priced. */
function spt_wc_config( $product ) {
	if ( ! $product instanceof WC_Product ) {
		return null;
	}
	$metal = (string) $product->get_meta( '_spt_metal' );
	$oz    = (float) $product->get_meta( '_spt_oz' );
	if ( ! array_key_exists( $metal, SPT_METALS ) || $oz <= 0 ) {
		return null;
	}
	$card = (string) $product->get_meta( '_spt_card_premium' );
	return array(
		'metal'        => $metal,
		'oz'           => $oz,
		'premium'      => (float) $product->get_meta( '_spt_premium' ),
		'card_premium' => '' === $card ? null : (float) $card,
		'min'          => (float) $product->get_meta( '_spt_min_price' ),
	);
}

/** @return array{wire: float, card: float}|null */
function spt_wc_prices( array $cfg, array $spot, array $set ) {
	if ( empty( $spot[ $cfg['metal'] ] ) ) {
		return null;
	}
	$melt = $spot[ $cfg['metal'] ] * $cfg['oz'];
	$wire = round( $melt + $cfg['premium'], 2 );
	$card = null === $cfg['card_premium']
		? round( $wire * ( 1 + $set['card_pct'] / 100 ), 2 )
		: round( $melt + $cfg['card_premium'], 2 );
	return array(
		'wire' => max( $wire, $cfg['min'] ),
		'card' => max( $card, $cfg['min'] ),
	);
}

/** Accepted spot if it is recent enough to sell on, else null. */
function spt_wc_fresh_spot() {
	$st = spt_wc_state();
	if ( ! $st['spot'] || time() - (int) $st['updated'] > spt_wc_settings()['stale_minutes'] * 60 ) {
		return null;
	}
	return $st;
}

function spt_wc_is_wire( $gateway ) {
	return in_array( (string) $gateway, spt_wc_settings()['wire_gateways'], true );
}

/* ------------------------------------------------------------------ *
 * Scheduled catalog update
 * ------------------------------------------------------------------ */

add_filter( 'cron_schedules', function ( $schedules ) {
	$m = max( 1, (int) spt_wc_settings()['update_minutes'] );
	$schedules['spt_wc_interval'] = array( 'interval' => $m * 60, 'display' => "Every $m minutes (metal pricing)" );
	return $schedules;
} );

add_action( 'init', function () {
	if ( spt_wc_on() ) {
		if ( ! wp_next_scheduled( SPT_WC_HOOK ) ) {
			wp_schedule_event( time() + 30, 'spt_wc_interval', SPT_WC_HOOK );
		}
	} elseif ( wp_next_scheduled( SPT_WC_HOOK ) ) {
		wp_clear_scheduled_hook( SPT_WC_HOOK );
	}
} );

// Clearing the event makes init reschedule it with the new interval.
add_action( 'add_option_' . SPT_WC_OPTION, function () { wp_clear_scheduled_hook( SPT_WC_HOOK ); } );
add_action( 'update_option_' . SPT_WC_OPTION, function () { wp_clear_scheduled_hook( SPT_WC_HOOK ); } );
add_action( SPT_WC_HOOK, function () { spt_wc_run(); } );

/**
 * Accepts the latest spot (unless it jumped suspiciously) and reprices the catalog.
 *
 * @param bool $force_accept Accept the spot even if it moved more than the allowed amount.
 */
function spt_wc_run( $force_accept = false ) {
	if ( ! spt_wc_on() ) {
		return;
	}
	$set     = spt_wc_settings();
	$st      = spt_wc_state();
	$payload = spt_get_prices();

	$st['last_run'] = time();
	if ( ! empty( $payload['stale'] ) || empty( $payload['items'] ) ) {
		update_option( SPT_WC_STATE, $st, false );
		spt_wc_log( 'No fresh spot prices from the API, so store prices were not updated.' );
		return;
	}

	$spot = array();
	foreach ( $payload['items'] as $it ) {
		if ( null !== $it['ask'] && $it['ask'] > 0 ) {
			$spot[ $it['metal'] ] = (float) $it['ask'];
		}
	}

	if ( ! $force_accept && $st['spot'] ) {
		foreach ( $spot as $m => $v ) {
			$old = $st['spot'][ $m ] ?? 0;
			if ( $old > 0 && abs( $v - $old ) / $old * 100 > $set['max_jump_pct'] ) {
				$st['hold'] = array( 'metal' => $m, 'old' => $old, 'new' => $v, 'time' => time() );
				update_option( SPT_WC_STATE, $st, false );
				spt_wc_log( sprintf(
					'Held: %s moved from %s to %s, more than %s%%. Store prices were not updated. If the new price is right, click "Accept current spot".',
					SPT_METALS[ $m ], spt_wc_money( $old ), spt_wc_money( $v ), $set['max_jump_pct']
				) );
				return;
			}
		}
	}

	$st['spot']       = $spot;
	$st['updated']    = (int) $payload['updated'];
	$st['hold']       = null;
	$st['last_count'] = spt_wc_reprice_catalog( $spot, $set );
	update_option( SPT_WC_STATE, $st, false );
	if ( $force_accept ) {
		spt_wc_log( 'Current spot accepted manually; ' . $st['last_count'] . ' prices updated.' );
	}
}

/** Writes the catalog price onto every spot-priced product. Returns how many changed. */
function spt_wc_reprice_catalog( array $spot, array $set ) {
	$ids = get_posts( array(
		'post_type'      => array( 'product', 'product_variation' ),
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'meta_query'     => array( array( 'key' => '_spt_metal', 'value' => '', 'compare' => '!=' ) ),
	) );

	$count   = 0;
	$parents = array();
	foreach ( $ids as $id ) {
		$product = wc_get_product( $id );
		if ( spt_wc_apply_catalog_price( $product, $spot, $set ) ) {
			$product->save();
			$count++;
			if ( $product->is_type( 'variation' ) ) {
				$parents[ $product->get_parent_id() ] = true;
			}
		}
	}
	foreach ( array_keys( $parents ) as $parent_id ) {
		WC_Product_Variable::sync( $parent_id );
	}
	return $count;
}

/** Sets the catalog price on the object (without saving). Returns true if it changed. */
function spt_wc_apply_catalog_price( $product, array $spot, array $set ) {
	$cfg    = spt_wc_config( $product );
	$prices = $cfg ? spt_wc_prices( $cfg, $spot, $set ) : null;
	if ( ! $prices ) {
		return false;
	}
	$new = $prices[ $set['catalog_price'] ];
	if ( (float) $product->get_regular_price() === $new && '' === $product->get_sale_price() ) {
		return false;
	}
	$product->set_regular_price( $new );
	$product->set_sale_price( '' );
	return true;
}

/* ------------------------------------------------------------------ *
 * Cart, checkout lock and payment-method pricing
 * ------------------------------------------------------------------ */

function spt_wc_lock() {
	if ( ! WC()->session ) {
		return null;
	}
	$lock = WC()->session->get( 'spt_lock' );
	return ( is_array( $lock ) && $lock['expires'] > time() ) ? $lock : null;
}

function spt_wc_start_lock() {
	$fresh = spt_wc_fresh_spot();
	if ( ! $fresh || ! WC()->session ) {
		return null;
	}
	$lock = array(
		'spot'      => $fresh['spot'],
		'spot_time' => (int) $fresh['updated'],
		'expires'   => time() + spt_wc_settings()['lock_minutes'] * 60,
	);
	WC()->session->set( 'spt_lock', $lock );
	return $lock;
}

/** Spot to price the cart with: the customer's checkout lock if running, else the live spot. */
function spt_wc_cart_spot() {
	$lock = spt_wc_lock();
	if ( $lock ) {
		return $lock['spot'];
	}
	$fresh = spt_wc_fresh_spot();
	return $fresh ? $fresh['spot'] : null;
}

/** True if every spot-priced item in the cart can be priced from $spot. False if one can't; null if there are none. */
function spt_wc_cart_priceable( $spot ) {
	$found = false;
	foreach ( WC()->cart->get_cart() as $item ) {
		$cfg = spt_wc_config( $item['data'] );
		if ( ! $cfg ) {
			continue;
		}
		$found = true;
		if ( ! $spot || empty( $spot[ $cfg['metal'] ] ) ) {
			return false;
		}
	}
	return $found ? true : null;
}

function spt_wc_unavailable_message() {
	return 'Live metal pricing is temporarily unavailable, so orders for metal products can’t be placed right now. Please try again in a few minutes or call us.';
}

add_action( 'woocommerce_before_calculate_totals', function ( $cart ) {
	if ( ! spt_wc_on() || ( is_admin() && ! wp_doing_ajax() ) || ! WC()->session ) {
		return;
	}
	$spot = spt_wc_cart_spot();
	if ( ! $spot ) {
		return; // Checkout is blocked below until fresh prices return.
	}
	$set  = spt_wc_settings();
	$wire = spt_wc_is_wire( WC()->session->get( 'chosen_payment_method' ) );
	foreach ( $cart->get_cart() as $item ) {
		$cfg    = spt_wc_config( $item['data'] );
		$prices = $cfg ? spt_wc_prices( $cfg, $spot, $set ) : null;
		if ( $prices ) {
			$item['data']->set_price( $wire ? $prices['wire'] : $prices['card'] );
		}
	}
}, 20 );

// Start the price lock when the customer opens checkout.
add_action( 'template_redirect', function () {
	if ( ! spt_wc_on() || ! is_checkout() || is_wc_endpoint_url() || ! WC()->cart ) {
		return;
	}
	$priceable = spt_wc_cart_priceable( spt_wc_cart_spot() );
	if ( null === $priceable ) {
		return; // No metal products in the cart.
	}
	if ( false === $priceable ) {
		wc_add_notice( spt_wc_unavailable_message(), 'error' );
		return;
	}
	$lock = spt_wc_lock() ?: spt_wc_start_lock();
	if ( $lock ) {
		$fmt = get_option( 'time_format' );
		wc_add_notice( sprintf(
			'Your metal prices are locked until %s, based on the spot price at %s.',
			wp_date( $fmt, $lock['expires'] ),
			wp_date( $fmt, $lock['spot_time'] )
		), 'notice' );
	}
} );

// Reprice when the customer switches between wire and card.
add_action( 'wp_enqueue_scripts', function () {
	if ( spt_wc_on() && is_checkout() && ! is_wc_endpoint_url() ) {
		wp_add_inline_script( 'wc-checkout', 'jQuery(function($){$(document.body).on("change","form.checkout input[name=\'payment_method\']",function(){$(document.body).trigger("update_checkout");});});' );
	}
}, 20 );

add_action( 'woocommerce_after_checkout_validation', function ( $data, $errors ) {
	if ( ! spt_wc_on() ) {
		return;
	}
	$priceable = spt_wc_cart_priceable( spt_wc_cart_spot() );
	if ( null === $priceable || ( $priceable && spt_wc_lock() ) ) {
		return; // No metal items, or a valid lock is in place.
	}
	if ( $priceable && spt_wc_start_lock() ) {
		$errors->add( 'spt_relock', 'Your price lock ran out, so your order has been updated to the current spot price. Please review the new total and place your order again.' );
	} else {
		$errors->add( 'spt_unavailable', spt_wc_unavailable_message() );
	}
	WC()->session->set( 'refresh_totals', true );
}, 10, 2 );

// Record the spot each order was priced on.
add_action( 'woocommerce_checkout_order_processed', function ( $order_id, $posted, $order ) {
	if ( ! spt_wc_on() || ! WC()->session ) {
		return;
	}
	$lock = WC()->session->get( 'spt_lock' );
	if ( ! is_array( $lock ) ) {
		return;
	}
	$metals = array();
	foreach ( $order->get_items() as $item ) {
		$cfg = spt_wc_config( $item->get_product() );
		if ( $cfg && isset( $lock['spot'][ $cfg['metal'] ] ) ) {
			$metals[ $cfg['metal'] ] = SPT_METALS[ $cfg['metal'] ] . ' ' . spt_wc_money( $lock['spot'][ $cfg['metal'] ] );
		}
	}
	if ( ! $metals ) {
		return;
	}
	$order->update_meta_data( '_spt_spot', $lock['spot'] );
	$order->update_meta_data( '_spt_spot_time', $lock['spot_time'] );
	$order->add_order_note( sprintf(
		'Priced at %s pricing on spot ask from %s: %s.',
		spt_wc_is_wire( $order->get_payment_method() ) ? 'wire' : 'card',
		wp_date( 'M j, Y g:i A', $lock['spot_time'] ),
		implode( ', ', $metals )
	) );
	$order->save();
	WC()->session->set( 'spt_lock', null );
}, 10, 3 );

add_filter( 'woocommerce_add_to_cart_validation', function ( $ok, $product_id, $qty, $variation_id = 0 ) {
	if ( ! $ok || ! spt_wc_on() ) {
		return $ok;
	}
	$cfg  = spt_wc_config( wc_get_product( $variation_id ?: $product_id ) );
	$spot = spt_wc_cart_spot();
	if ( $cfg && ( ! $spot || empty( $spot[ $cfg['metal'] ] ) ) ) {
		wc_add_notice( spt_wc_unavailable_message(), 'error' );
		return false;
	}
	return $ok;
}, 10, 4 );

/* ------------------------------------------------------------------ *
 * Wire and card prices on product pages
 * ------------------------------------------------------------------ */

function spt_wc_price_note( $product ) {
	$cfg = spt_wc_config( $product );
	if ( ! $cfg ) {
		return '';
	}
	$fresh  = spt_wc_fresh_spot();
	$set    = spt_wc_settings();
	$prices = $fresh ? spt_wc_prices( $cfg, $fresh['spot'], $set ) : null;
	if ( ! $prices ) {
		return '<p class="spt-wc-prices spt-wc-unavailable">Live pricing is temporarily unavailable. Please call us for a quote.</p>';
	}
	return sprintf(
		'<p class="spt-wc-prices"><span class="spt-wc-wire">Wire / bank transfer: <strong>%s</strong></span><br><span class="spt-wc-card">Credit card: <strong>%s</strong></span><br><small>Prices follow the spot market and are locked for %d minutes once you start checkout.</small></p>',
		wc_price( $prices['wire'] ),
		wc_price( $prices['card'] ),
		(int) $set['lock_minutes']
	);
}

// Classic product pages.
add_action( 'woocommerce_single_product_summary', function () {
	global $product;
	if ( spt_wc_on() && $product instanceof WC_Product && ! $product->is_type( 'variable' ) ) {
		echo spt_wc_price_note( $product ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
	}
}, 11 );

// Elementor product templates: [spot_product_prices] in a Shortcode widget.
add_shortcode( 'spot_product_prices', function () {
	$product = wc_get_product( get_the_ID() );
	return ( spt_wc_on() && $product && ! $product->is_type( 'variable' ) ) ? spt_wc_price_note( $product ) : '';
} );

// Variations: shown when the customer picks one.
add_filter( 'woocommerce_available_variation', function ( $data, $parent, $variation ) {
	if ( spt_wc_on() ) {
		$data['price_html'] .= spt_wc_price_note( $variation );
	}
	return $data;
}, 10, 3 );

/* ------------------------------------------------------------------ *
 * Product edit fields
 * ------------------------------------------------------------------ */

function spt_wc_field_defs() {
	return array(
		'_spt_metal'        => array( 'Spot-priced metal', 'Choose a metal to have this price set automatically from spot. The price fields above will be overwritten.' ),
		'_spt_oz'           => array( 'Fine metal weight (troy oz)', 'Pure metal content, e.g. 1 for a 1 oz Gold Eagle, 0.715 for $1 face of 90% silver.' ),
		'_spt_premium'      => array( 'Wire premium over spot ($)', 'Added to spot × weight for wire / bank transfer orders.' ),
		'_spt_card_premium' => array( 'Card premium over spot ($)', 'Leave blank to use the wire price plus the card percentage in WooCommerce → Metal Pricing.' ),
		'_spt_min_price'    => array( 'Minimum price ($)', 'Optional. The price will never be set below this.' ),
	);
}

function spt_wc_render_fields( $values, $name_suffix = '', $id_suffix = '', $wrapper = '' ) {
	foreach ( spt_wc_field_defs() as $key => $def ) {
		$args = array(
			'id'            => $key . $id_suffix,
			'name'          => $key . $name_suffix,
			'label'         => $def[0],
			'description'   => $def[1],
			'desc_tip'      => true,
			'value'         => $values[ $key ] ?? '',
			'wrapper_class' => $wrapper,
		);
		if ( '_spt_metal' === $key ) {
			$args['options'] = array( '' => 'Not spot-priced' ) + SPT_METALS;
			woocommerce_wp_select( $args );
		} else {
			$args['data_type'] = '_spt_oz' === $key ? 'decimal' : 'price';
			woocommerce_wp_text_input( $args );
		}
	}
}

function spt_wc_clean_field( $key, $raw ) {
	$raw = wc_clean( wp_unslash( $raw ) );
	if ( '_spt_metal' === $key ) {
		return array_key_exists( $raw, SPT_METALS ) ? $raw : '';
	}
	return '' === $raw ? '' : wc_format_decimal( $raw );
}

/** Saves the fields onto the product and, if spot is fresh, prices it right away. */
function spt_wc_save_fields( $product, $values ) {
	foreach ( SPT_WC_FIELDS as $key ) {
		if ( array_key_exists( $key, $values ) ) {
			$product->update_meta_data( $key, spt_wc_clean_field( $key, $values[ $key ] ) );
		}
	}
	$fresh = spt_wc_on() ? spt_wc_fresh_spot() : null;
	if ( $fresh ) {
		spt_wc_apply_catalog_price( $product, $fresh['spot'], spt_wc_settings() );
	}
}

add_action( 'woocommerce_product_options_pricing', function () {
	global $product_object;
	$values = array();
	foreach ( SPT_WC_FIELDS as $key ) {
		$values[ $key ] = $product_object ? $product_object->get_meta( $key ) : '';
	}
	echo '<div class="options_group spt-wc-fields">';
	spt_wc_render_fields( $values );
	echo '</div>';
} );

add_action( 'woocommerce_admin_process_product_object', function ( $product ) {
	if ( $product->is_type( 'variable' ) ) {
		return;
	}
	$values = array();
	foreach ( SPT_WC_FIELDS as $key ) {
		if ( isset( $_POST[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- WooCommerce verifies the product nonce.
			$values[ $key ] = $_POST[ $key ]; // phpcs:ignore
		}
	}
	spt_wc_save_fields( $product, $values );
} );

add_action( 'woocommerce_variation_options_pricing', function ( $loop, $variation_data, $variation ) {
	$values = array();
	foreach ( SPT_WC_FIELDS as $key ) {
		$values[ $key ] = get_post_meta( $variation->ID, $key, true );
	}
	spt_wc_render_fields( $values, "[$loop]", (string) $loop, 'form-row form-row-full' );
}, 10, 3 );

add_action( 'woocommerce_admin_process_variation_object', function ( $variation, $i ) {
	$values = array();
	foreach ( SPT_WC_FIELDS as $key ) {
		if ( isset( $_POST[ $key ][ $i ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- WooCommerce verifies the variation nonce.
			$values[ $key ] = $_POST[ $key ][ $i ]; // phpcs:ignore
		}
	}
	spt_wc_save_fields( $variation, $values );
}, 10, 2 );

/* ------------------------------------------------------------------ *
 * WooCommerce → Metal Pricing
 * ------------------------------------------------------------------ */

add_action( 'admin_init', function () {
	register_setting( 'spt_wc', SPT_WC_OPTION, array( 'sanitize_callback' => 'spt_wc_sanitize' ) );
} );
add_filter( 'option_page_capability_spt_wc', function () { return 'manage_woocommerce'; } );

function spt_wc_sanitize( $in ) {
	$in       = is_array( $in ) ? $in : array();
	$gateways = array_keys( WC()->payment_gateways()->payment_gateways() );
	$update   = max( 1, min( 60, absint( $in['update_minutes'] ?? 5 ) ) );
	$catalog  = $in['catalog_price'] ?? 'card';
	return array(
		'enabled'        => empty( $in['enabled'] ) ? 0 : 1,
		'update_minutes' => $update,
		'lock_minutes'   => max( 1, min( 60, absint( $in['lock_minutes'] ?? 10 ) ) ),
		// Must outlast at least two update cycles, or one slow run would pause checkout.
		'stale_minutes'  => max( 2 * $update, min( 240, absint( $in['stale_minutes'] ?? 15 ) ) ),
		'max_jump_pct'   => max( 0.5, min( 50, (float) ( $in['max_jump_pct'] ?? 5 ) ) ),
		'card_pct'       => max( 0, min( 20, (float) ( $in['card_pct'] ?? 0 ) ) ),
		'wire_gateways'  => array_values( array_intersect( $gateways, (array) ( $in['wire_gateways'] ?? array() ) ) ),
		'catalog_price'  => in_array( $catalog, array( 'card', 'wire' ), true ) ? $catalog : 'card',
	);
}

add_action( 'admin_menu', function () {
	add_submenu_page( 'woocommerce', 'Metal Pricing', 'Metal Pricing', 'manage_woocommerce', 'spt-metal-pricing', 'spt_wc_page' );
} );

foreach ( array( 'spt_wc_run' => false, 'spt_wc_accept' => true ) as $spt_action => $spt_force ) {
	add_action( 'admin_post_' . $spt_action, function () use ( $spt_action, $spt_force ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( $spt_action );
		delete_transient( SPT_CACHE ); // Use a brand-new reading for a manual run.
		spt_wc_run( $spt_force );
		wp_safe_redirect( admin_url( 'admin.php?page=spt-metal-pricing&spt_done=1' ) );
		exit;
	} );
}

function spt_wc_page() {
	$set   = spt_wc_settings();
	$st    = spt_wc_state();
	$fresh = spt_wc_fresh_spot();
	$name  = SPT_WC_OPTION;
	$fmt   = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
	$next  = wp_next_scheduled( SPT_WC_HOOK );
	$count = count( get_posts( array(
		'post_type'      => array( 'product', 'product_variation' ),
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'meta_query'     => array( array( 'key' => '_spt_metal', 'value' => '', 'compare' => '!=' ) ),
	) ) );
	?>
	<div class="wrap">
		<h1>Metal Pricing</h1>
		<p>Each spot-priced product is priced as <strong>spot ask × fine troy oz + premium</strong>, rounded to the cent.
			Set a product's metal, weight and premiums in its <em>General</em> tab (or in each variation).
			Spot comes from the API set up in <a href="<?php echo esc_url( admin_url( 'options-general.php?page=spot-price-ticker' ) ); ?>">Settings → Spot Price Ticker</a>.</p>

		<?php if ( isset( $_GET['spt_done'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
			<div class="notice notice-info is-dismissible"><p>Done. See the status and log below.</p></div>
		<?php endif; ?>

		<h2>Status</h2>
		<table class="widefat striped" style="max-width:820px"><tbody>
			<tr><th style="width:240px">Pricing</th><td><?php echo $set['enabled'] ? '<strong>On</strong>' : '<strong>Off</strong> — prices are not being changed.'; ?></td></tr>
			<tr><th>Spot-priced products</th><td><?php echo (int) $count; ?> (products and variations)</td></tr>
			<tr><th>Spot the store is using</th><td>
				<?php
				if ( $st['spot'] ) {
					$parts = array();
					foreach ( $st['spot'] as $m => $v ) {
						$parts[] = esc_html( SPT_METALS[ $m ] . ' ' . spt_wc_money( $v ) );
					}
					echo implode( ' · ', $parts ) . '<br>'; // phpcs:ignore
					echo esc_html( 'From ' . wp_date( $fmt, $st['updated'] ) . ( $fresh ? '' : ' — too old to sell on; checkout is paused for metal products.' ) );
				} else {
					echo 'None yet.';
				}
				?>
			</td></tr>
			<?php if ( $st['hold'] ) : ?>
				<tr><th>On hold</th><td style="color:#b32d2e"><?php echo esc_html( sprintf( '%s jumped from %s to %s at %s. Prices are frozen until you accept it or it settles back.', SPT_METALS[ $st['hold']['metal'] ], spt_wc_money( $st['hold']['old'] ), spt_wc_money( $st['hold']['new'] ), wp_date( $fmt, $st['hold']['time'] ) ) ); ?></td></tr>
			<?php endif; ?>
			<tr><th>Last update</th><td><?php echo $st['last_run'] ? esc_html( wp_date( $fmt, $st['last_run'] ) . ' — ' . $st['last_count'] . ' prices changed' ) : 'Never'; ?></td></tr>
			<tr><th>Next update</th><td><?php echo $next ? esc_html( wp_date( $fmt, $next ) ) : '—'; ?></td></tr>
		</tbody></table>
		<p>
			<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=spt_wc_run' ), 'spt_wc_run' ) ); ?>">Update prices now</a>
			<?php if ( $st['hold'] ) : ?>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=spt_wc_accept' ), 'spt_wc_accept' ) ); ?>" onclick="return confirm('Accept the current spot price and reprice the store?');">Accept current spot</a>
			<?php endif; ?>
		</p>

		<h2>Settings</h2>
		<form method="post" action="options.php">
			<?php settings_fields( 'spt_wc' ); ?>
			<table class="form-table" role="presentation">
				<tr><th scope="row">Spot pricing</th>
					<td><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[enabled]" value="1" <?php checked( $set['enabled'], 1 ); ?>> Turn on automatic pricing</label>
					<p class="description">Set up a few products and test with “Update prices now” before turning this on for the live store.</p></td></tr>
				<tr><th scope="row"><label for="spt-wc-update">Update prices every</label></th>
					<td><input id="spt-wc-update" type="number" min="1" max="60" class="small-text" name="<?php echo esc_attr( $name ); ?>[update_minutes]" value="<?php echo (int) $set['update_minutes']; ?>"> minutes</td></tr>
				<tr><th scope="row"><label for="spt-wc-lock">Lock prices at checkout for</label></th>
					<td><input id="spt-wc-lock" type="number" min="1" max="60" class="small-text" name="<?php echo esc_attr( $name ); ?>[lock_minutes]" value="<?php echo (int) $set['lock_minutes']; ?>"> minutes
					<p class="description">Starts when the customer opens checkout. If it runs out, they're shown the updated total before the order goes through.</p></td></tr>
				<tr><th scope="row"><label for="spt-wc-stale">Pause checkout if spot is older than</label></th>
					<td><input id="spt-wc-stale" type="number" min="2" max="240" class="small-text" name="<?php echo esc_attr( $name ); ?>[stale_minutes]" value="<?php echo (int) $set['stale_minutes']; ?>"> minutes</td></tr>
				<tr><th scope="row"><label for="spt-wc-jump">Hold updates if spot moves more than</label></th>
					<td><input id="spt-wc-jump" type="number" min="0.5" max="50" step="0.1" class="small-text" name="<?php echo esc_attr( $name ); ?>[max_jump_pct]" value="<?php echo esc_attr( $set['max_jump_pct'] ); ?>"> % in one update</td></tr>
				<tr><th scope="row"><label for="spt-wc-card">Card price</label></th>
					<td>Wire price + <input id="spt-wc-card" type="number" min="0" max="20" step="0.01" class="small-text" name="<?php echo esc_attr( $name ); ?>[card_pct]" value="<?php echo esc_attr( $set['card_pct'] ); ?>"> %
					<p class="description">Used for products without their own card premium.</p></td></tr>
				<tr><th scope="row">Wire pricing applies to</th>
					<td><?php foreach ( WC()->payment_gateways()->payment_gateways() as $id => $gw ) : ?>
						<label style="display:block"><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[wire_gateways][]" value="<?php echo esc_attr( $id ); ?>" <?php checked( in_array( $id, $set['wire_gateways'], true ) ); ?>>
							<?php echo esc_html( $gw->get_method_title() ?: $id ); ?><?php echo 'yes' === $gw->enabled ? '' : ' <span style="color:#777">(disabled)</span>'; ?></label>
					<?php endforeach; ?>
					<p class="description">Every other payment method gets the card price.</p></td></tr>
				<tr><th scope="row"><label for="spt-wc-catalog">Price shown in the shop</label></th>
					<td><select id="spt-wc-catalog" name="<?php echo esc_attr( $name ); ?>[catalog_price]">
						<option value="card" <?php selected( $set['catalog_price'], 'card' ); ?>>Card price (wire shown as the lower option)</option>
						<option value="wire" <?php selected( $set['catalog_price'], 'wire' ); ?>>Wire price</option>
					</select></td></tr>
			</table>
			<?php submit_button(); ?>
		</form>

		<h2>Log</h2>
		<?php $log = get_option( SPT_WC_LOG, array() ); ?>
		<?php if ( $log ) : ?>
			<table class="widefat striped" style="max-width:820px"><tbody>
				<?php foreach ( $log as $row ) : ?>
					<tr><td style="width:180px"><?php echo esc_html( wp_date( $fmt, $row[0] ) ); ?></td><td><?php echo esc_html( $row[1] ); ?></td></tr>
				<?php endforeach; ?>
			</tbody></table>
		<?php else : ?>
			<p>Nothing to report yet.</p>
		<?php endif; ?>
	</div>
	<?php
}
