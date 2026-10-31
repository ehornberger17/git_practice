<?php
/**
 * WooCommerce integration: live bullion prices with wire / debit / credit tiers.
 *
 * Applies to simple products with "Price from Dillon Gage" ticked.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DGBP_Woo {

	const CART_TIER_META = '_dgbp_cart_tier';
	const LOCK_SESSION   = 'dgbp_lock';

	public static function init() {
		// Product editor.
		add_action( 'woocommerce_product_options_pricing', array( __CLASS__, 'product_fields' ) );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save_product' ) );

		// Live prices.
		foreach ( array( 'woocommerce_product_get_price', 'woocommerce_product_get_regular_price' ) as $hook ) {
			add_filter( $hook, array( __CLASS__, 'filter_price' ), 99, 2 );
		}
		add_filter( 'woocommerce_product_get_sale_price', array( __CLASS__, 'filter_sale_price' ), 99, 2 );
		add_filter( 'woocommerce_product_is_on_sale', array( __CLASS__, 'filter_on_sale' ), 99, 2 );
		add_filter( 'woocommerce_is_purchasable', array( __CLASS__, 'filter_purchasable' ), 99, 2 );
		add_filter( 'woocommerce_get_price_html', array( __CLASS__, 'price_html' ), 99, 2 );
		add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'price_table' ), 11 );
		add_shortcode( 'dg_product_prices', array( __CLASS__, 'shortcode_prices' ) );
		add_shortcode( 'spot_product_prices', array( __CLASS__, 'shortcode_prices' ) ); // Name used by the earlier plugin.

		// Cart & checkout.
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'apply_cart_tier' ), 20 );
		add_action( 'woocommerce_check_cart_items', array( __CLASS__, 'check_cart_items' ) );
		add_action( 'woocommerce_checkout_update_order_review', array( __CLASS__, 'capture_review_post' ) );
		add_action( 'woocommerce_checkout_process', array( __CLASS__, 'checkout_process' ), 1 );
		add_action( 'woocommerce_review_order_before_payment', array( __CLASS__, 'card_type_field' ) );
		add_action( 'woocommerce_review_order_before_order_total', array( __CLASS__, 'tier_row' ) );
		add_action( 'woocommerce_cart_totals_before_order_total', array( __CLASS__, 'cart_tier_row' ) );
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'line_item_meta' ), 10, 3 );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'order_meta' ), 10, 2 );
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'order_processed' ), 10, 3 );
		add_action( 'template_redirect', array( __CLASS__, 'checkout_page_lock' ) );
		add_filter( 'woocommerce_hidden_order_itemmeta', array( __CLASS__, 'hide_item_meta' ) );
		add_action( 'woocommerce_admin_order_data_after_billing_address', array( __CLASS__, 'admin_order_tier' ) );

		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	/* ====================================================================
	 * Product editor
	 * ================================================================= */

	public static function product_fields() {
		global $post;
		$pid = $post ? $post->ID : 0;

		echo '<div class="options_group dgbp-product-fields" style="border-top:1px solid #eee">';
		echo '<p style="margin:12px 12px 0"><strong>Dillon Gage bullion pricing</strong></p>';

		woocommerce_wp_checkbox(
			array(
				'id'          => '_dgbp_enabled',
				'label'       => 'Price from Dillon Gage',
				'description' => 'Ignore the Regular/Sale price above and price this item live.',
			)
		);
		woocommerce_wp_select(
			array(
				'id'      => '_dgbp_basis',
				'label'   => 'Price basis',
				'options' => array(
					'spot'       => 'Metal spot × weight',
					'dg_product' => 'Dillon Gage product price',
				),
				'desc_tip'    => true,
				'description' => 'Spot × weight works for any bullion. Dillon Gage product price uses DG\'s ask for that exact item (your cost), so it already includes their premium.',
			)
		);
		woocommerce_wp_select(
			array(
				'id'      => '_dgbp_metal',
				'label'   => 'Metal',
				'options' => DGBP_Settings::METALS,
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'                => '_dgbp_weight_oz',
				'label'             => 'Fine weight (troy oz)',
				'type'              => 'number',
				'custom_attributes' => array( 'step' => '0.0001', 'min' => '0' ),
				'desc_tip'          => true,
				'description'       => 'Pure metal content, e.g. 1 for a 1 oz Eagle, 0.7234 for a $20 Liberty, 10 for a 10 oz bar, 32.15 for a kilo.',
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'          => '_dgbp_dg_code',
				'label'       => 'Dillon Gage product code',
				'desc_tip'    => true,
				'description' => 'Required when the basis is Dillon Gage product price. Find it in FizTrade.',
			)
		);
		woocommerce_wp_select(
			array(
				'id'      => '_dgbp_premium_type',
				'label'   => 'Your premium',
				'options' => array(
					'per_oz'  => '$ per troy oz',
					'flat'    => '$ per item',
					'percent' => '% of basis',
				),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'                => '_dgbp_premium_value',
				'label'             => 'Premium amount',
				'type'              => 'number',
				'custom_attributes' => array( 'step' => '0.01' ),
				'description'       => 'Added to the basis to make the wire price.',
				'desc_tip'          => true,
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'                => '_dgbp_debit_pct',
				'label'             => 'Debit surcharge % (optional)',
				'type'              => 'number',
				'placeholder'       => 'Default: ' . DGBP_Settings::get( 'debit_pct' ),
				'custom_attributes' => array( 'step' => '0.01', 'min' => '0' ),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'                => '_dgbp_credit_pct',
				'label'             => 'Credit surcharge % (optional)',
				'type'              => 'number',
				'placeholder'       => 'Default: ' . DGBP_Settings::get( 'credit_pct' ),
				'custom_attributes' => array( 'step' => '0.01', 'min' => '0' ),
			)
		);

		if ( $pid && DGBP_Pricing::is_bullion( $pid ) ) {
			$p = DGBP_Pricing::for_product( $pid );
			echo '<p class="form-field"><label>Current prices</label>';
			if ( $p && $p['available'] ) {
				$parts = array();
				foreach ( DGBP_Settings::TIERS as $t ) {
					$parts[] = esc_html( DGBP_Settings::tier_label( $t ) ) . ': <strong>' . wp_kses_post( wc_price( $p[ $t ] ) ) . '</strong>';
				}
				echo implode( ' &nbsp;|&nbsp; ', $parts ); // phpcs:ignore
			} else {
				echo '<em style="color:#b32d2e">No live price — check the API settings, product code and weight.</em>';
			}
			echo '</p>';
		}
		echo '</div>';
	}

	public static function save_product( $product ) {
		// phpcs:disable WordPress.Security.NonceVerification -- WooCommerce verifies the product form nonce.
		$product->update_meta_data( '_dgbp_enabled', isset( $_POST['_dgbp_enabled'] ) ? 'yes' : 'no' );

		$enum = array(
			'_dgbp_basis'        => array( 'spot', 'dg_product' ),
			'_dgbp_metal'        => array_keys( DGBP_Settings::METALS ),
			'_dgbp_premium_type' => array( 'per_oz', 'flat', 'percent' ),
		);
		foreach ( $enum as $k => $allowed ) {
			if ( isset( $_POST[ $k ] ) && in_array( $_POST[ $k ], $allowed, true ) ) {
				$product->update_meta_data( $k, sanitize_key( $_POST[ $k ] ) );
			}
		}
		foreach ( array( '_dgbp_weight_oz', '_dgbp_premium_value', '_dgbp_debit_pct', '_dgbp_credit_pct' ) as $k ) {
			if ( isset( $_POST[ $k ] ) ) {
				$v = trim( wp_unslash( $_POST[ $k ] ) );
				$product->update_meta_data( $k, '' === $v ? '' : (string) (float) $v );
			}
		}
		if ( isset( $_POST['_dgbp_dg_code'] ) ) {
			$product->update_meta_data( '_dgbp_dg_code', sanitize_text_field( wp_unslash( $_POST['_dgbp_dg_code'] ) ) );
		}
		// phpcs:enable
	}

	/* ====================================================================
	 * Price filters
	 * ================================================================= */

	private static function applies( $product ) {
		return $product instanceof WC_Product
			&& $product->is_type( 'simple' )
			&& DGBP_Pricing::is_bullion( $product->get_id() );
	}

	private static function tier_for_product( $product ) {
		$t = $product->get_meta( self::CART_TIER_META, true );
		return in_array( $t, DGBP_Settings::TIERS, true ) ? $t : DGBP_Settings::get( 'display_tier' );
	}

	public static function filter_price( $price, $product ) {
		if ( ! self::applies( $product ) ) {
			return $price;
		}
		$p = DGBP_Pricing::for_product( $product->get_id() );
		if ( ! $p || ! $p['available'] ) {
			return '';
		}
		return (string) $p[ self::tier_for_product( $product ) ];
	}

	public static function filter_sale_price( $price, $product ) {
		return self::applies( $product ) ? '' : $price;
	}

	public static function filter_on_sale( $on_sale, $product ) {
		return self::applies( $product ) ? false : $on_sale;
	}

	public static function filter_purchasable( $purchasable, $product ) {
		if ( ! self::applies( $product ) ) {
			return $purchasable;
		}
		$p = DGBP_Pricing::for_product( $product->get_id() );
		return $purchasable && $p && $p['available'];
	}

	public static function price_html( $html, $product ) {
		if ( ! self::applies( $product ) ) {
			return $html;
		}
		$pid = $product->get_id();
		$p   = DGBP_Pricing::for_product( $pid );
		if ( ! $p || ! $p['available'] ) {
			return '<span class="dgbp-unavailable">Price temporarily unavailable</span>';
		}
		$tier = DGBP_Settings::get( 'display_tier' );
		return sprintf(
			'<span class="dgbp-live-price" data-dgbp-pid="%1$d"><span data-dgbp-tier="%2$s">%3$s</span> <small class="dgbp-tier-note">%4$s</small></span>',
			$pid,
			esc_attr( $tier ),
			wc_price( $p[ $tier ] ),
			esc_html( DGBP_Settings::tier_label( $tier ) . ' price' )
		);
	}

	private static $table_shown = array();

	public static function render_table( $product ) {
		if ( ! self::applies( $product ) ) {
			return '';
		}
		$pid = $product->get_id();
		$p   = DGBP_Pricing::for_product( $pid );
		if ( ! $p || ! $p['available'] ) {
			return '<p class="dgbp-unavailable">Live pricing is temporarily unavailable.</p>';
		}
		self::$table_shown[ $pid ] = true;
		$html = '<table class="dgbp-price-table" data-dgbp-pid="' . (int) $pid . '"><thead><tr><th>Payment method</th><th>Price</th></tr></thead><tbody>';
		foreach ( DGBP_Settings::TIERS as $t ) {
			$html .= '<tr class="dgbp-row-' . esc_attr( $t ) . '"><td>' . esc_html( DGBP_Settings::tier_label( $t ) ) . '</td><td data-dgbp-tier="' . esc_attr( $t ) . '">' . wp_kses_post( wc_price( $p[ $t ] ) ) . '</td></tr>';
		}
		$html .= '</tbody></table>';
		$mins  = (int) DGBP_Settings::get( 'lock_minutes' );
		$note  = $mins > 0
			? sprintf( 'Prices move with the live metals market and are locked for %d minutes once you start checkout.', $mins )
			: 'Prices move with the live metals market and are set when your order is placed.';
		$html .= '<p class="dgbp-price-note">' . esc_html( $note ) . '</p>';
		return $html;
	}

	public static function price_table() {
		global $product;
		if ( ! DGBP_Settings::get( 'show_table' ) || ! $product || isset( self::$table_shown[ $product->get_id() ] ) ) {
			return;
		}
		echo self::render_table( $product ); // phpcs:ignore -- escaped in render_table().
	}

	/** [dg_product_prices] or [dg_product_prices id="123"] — for Elementor product templates. */
	public static function shortcode_prices( $atts ) {
		$atts    = shortcode_atts( array( 'id' => 0 ), $atts );
		$product = $atts['id'] ? wc_get_product( (int) $atts['id'] ) : ( isset( $GLOBALS['product'] ) && $GLOBALS['product'] instanceof WC_Product ? $GLOBALS['product'] : wc_get_product( get_the_ID() ) );
		if ( ! $product || isset( self::$table_shown[ $product->get_id() ] ) ) {
			return '';
		}
		return self::render_table( $product );
	}

	/* ====================================================================
	 * Determine the price tier from the chosen payment method
	 * ================================================================= */

	private static $review_post = null;

	/** Store posted checkout fields from the AJAX order-review refresh. */
	public static function capture_review_post( $post_data ) {
		$parsed = array();
		parse_str( (string) $post_data, $parsed );
		self::$review_post = $parsed;
		if ( WC()->session ) {
			if ( isset( $parsed['dgbp_card_type'] ) ) {
				WC()->session->set( 'dgbp_card_type', sanitize_key( $parsed['dgbp_card_type'] ) );
			}
		}
	}

	private static function posted( $key ) {
		// phpcs:disable WordPress.Security.NonceVerification -- read-only, WooCommerce verifies checkout nonce.
		if ( isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ) {
			return sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
		}
		if ( is_array( self::$review_post ) && isset( self::$review_post[ $key ] ) ) {
			return sanitize_text_field( self::$review_post[ $key ] );
		}
		// phpcs:enable
		return '';
	}

	private static function in_checkout() {
		$wc_ajax = isset( $_GET['wc-ajax'] ) ? sanitize_key( $_GET['wc-ajax'] ) : ''; // phpcs:ignore
		if ( in_array( $wc_ajax, array( 'update_order_review', 'checkout' ), true ) ) {
			return true;
		}
		return function_exists( 'is_checkout' ) && is_checkout() && ! ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-received' ) );
	}

	public static function gateway_tier( $gateway_id, $card_type = '' ) {
		$map  = (array) DGBP_Settings::get( 'gateway_map' );
		$tier = isset( $map[ $gateway_id ] ) ? $map[ $gateway_id ] : '';

		if ( '' === $tier ) {
			// Sensible defaults for common offline gateways.
			$tier = in_array( $gateway_id, array( 'bacs', 'cheque' ), true ) ? 'wire' : DGBP_Settings::get( 'default_gateway_tier' );
		}
		if ( 'ask' === $tier ) {
			$tier = 'debit' === $card_type ? 'debit' : 'credit';
		}
		return in_array( $tier, DGBP_Settings::TIERS, true ) ? $tier : 'credit';
	}

	public static function gateway_asks_card_type( $gateway_id ) {
		$map = (array) DGBP_Settings::get( 'gateway_map' );
		return isset( $map[ $gateway_id ] ) && 'ask' === $map[ $gateway_id ];
	}

	private static function chosen_gateway() {
		$gw = self::posted( 'payment_method' );
		if ( '' === $gw && WC()->session ) {
			$gw = (string) WC()->session->get( 'chosen_payment_method' );
		}
		return $gw;
	}

	private static function chosen_card_type() {
		$ct = self::posted( 'dgbp_card_type' );
		if ( '' === $ct && WC()->session ) {
			$ct = (string) WC()->session->get( 'dgbp_card_type' );
		}
		return $ct;
	}

	public static function current_tier() {
		if ( ! self::in_checkout() ) {
			return DGBP_Settings::get( 'display_tier' );
		}
		$gw = self::chosen_gateway();
		if ( '' === $gw ) {
			return DGBP_Settings::get( 'display_tier' );
		}
		return self::gateway_tier( $gw, self::chosen_card_type() );
	}

	/* ====================================================================
	 * Cart & checkout
	 * ================================================================= */

	/* --------------------------------------------------------------------
	 * Checkout price lock
	 * ----------------------------------------------------------------- */

	private static function lock_enabled() {
		return (int) DGBP_Settings::get( 'lock_minutes' ) > 0;
	}

	/** The customer's running lock, or null if none / expired. */
	public static function session_lock() {
		if ( ! WC()->session ) {
			return null;
		}
		$lock = WC()->session->get( self::LOCK_SESSION );
		return ( is_array( $lock ) && (int) $lock['expires'] > time() ) ? $lock : null;
	}

	/** Snapshot current prices for this customer. Returns null if prices aren't fresh enough to lock. */
	public static function start_lock() {
		if ( ! WC()->session || ! self::lock_enabled() ) {
			return null;
		}
		DGBP_Pricing::set_lock( null );
		$spot = DGBP_API::get_spot();
		if ( empty( $spot['prices'] ) || DGBP_API::is_too_old( $spot['updated'] ) ) {
			return null;
		}
		$products = array();
		if ( WC()->cart ) {
			foreach ( WC()->cart->get_cart() as $item ) {
				$pid = isset( $item['data'] ) ? $item['data']->get_id() : 0;
				if ( $pid && 'dg_product' === get_post_meta( $pid, '_dgbp_basis', true ) ) {
					$code = (string) get_post_meta( $pid, '_dgbp_dg_code', true );
					$pp   = DGBP_API::get_product_price( $code );
					if ( null !== $pp['price'] && ! DGBP_API::is_too_old( $pp['updated'] ) ) {
						$products[ $code ] = (float) $pp['price'];
					}
				}
			}
		}
		$lock = array(
			'spot'      => $spot['prices'],
			'spot_time' => (int) $spot['updated'],
			'products'  => $products,
			'time'      => time(),
			'expires'   => time() + (int) DGBP_Settings::get( 'lock_minutes' ) * MINUTE_IN_SECONDS,
		);
		WC()->session->set( self::LOCK_SESSION, $lock );
		return $lock;
	}

	/** Make sure checkout requests price against the customer's lock, starting one if needed. */
	private static function use_lock( $may_start = true ) {
		if ( ! self::lock_enabled() || ! self::in_checkout() || ! self::cart_has_bullion() ) {
			DGBP_Pricing::set_lock( null );
			return null;
		}
		$lock = self::session_lock();
		if ( ! $lock && $may_start ) {
			$lock = self::start_lock();
		}
		DGBP_Pricing::set_lock( $lock );
		return $lock;
	}

	/** Start the lock as soon as the checkout page is opened. */
	public static function checkout_page_lock() {
		if ( function_exists( 'is_checkout' ) && is_checkout() && ! is_wc_endpoint_url() ) {
			self::use_lock();
		}
	}

	private static $in_tier_pass = false;

	public static function apply_cart_tier( $cart ) {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}
		if ( ! self::$in_tier_pass ) {
			self::$in_tier_pass = true;
			// Final order submission must not silently start a new lock; checkout_process handles that.
			self::use_lock( ! self::is_submitting() );
			self::$in_tier_pass = false;
		}
		$tier = self::current_tier();
		foreach ( $cart->get_cart() as $item ) {
			if ( isset( $item['data'] ) && self::applies( $item['data'] ) ) {
				// In-memory only (never saved): tells filter_price which tier to return.
				$item['data']->update_meta_data( self::CART_TIER_META, $tier );
			}
		}
		if ( WC()->session ) {
			WC()->session->set( 'dgbp_tier', $tier );
		}
	}

	public static function check_cart_items() {
		foreach ( WC()->cart->get_cart() as $item ) {
			$product = isset( $item['data'] ) ? $item['data'] : null;
			if ( $product && self::applies( $product ) ) {
				$p = DGBP_Pricing::for_product( $product->get_id() );
				if ( ! $p || ! $p['available'] ) {
					wc_add_notice(
						sprintf( 'Live pricing for “%s” is temporarily unavailable, so it can\'t be purchased right now. Please try again shortly.', $product->get_name() ),
						'error'
					);
				}
			}
		}
	}

	private static function is_submitting() {
		return isset( $_GET['wc-ajax'] ) && 'checkout' === $_GET['wc-ajax']; // phpcs:ignore
	}

	/** Runs at the start of order submission: re-price at the chosen payment method. */
	public static function checkout_process() {
		// Expired lock: re-lock at current prices and make the customer review the new total.
		if ( self::lock_enabled() && self::cart_has_bullion() && ! self::session_lock() ) {
			if ( self::start_lock() ) {
				wc_add_notice( 'Your price lock ran out, so your order has been updated to current metal prices. Please review the new total and place your order again.', 'error' );
				WC()->session->set( 'refresh_totals', true );
			}
		}
		self::use_lock( false );
		$gw = self::posted( 'payment_method' );
		if ( self::gateway_asks_card_type( $gw ) && ! in_array( self::posted( 'dgbp_card_type' ), array( 'debit', 'credit' ), true ) ) {
			wc_add_notice( 'Please choose whether you are paying by debit card or credit card.', 'error' );
		}
		if ( WC()->session && $gw ) {
			WC()->session->set( 'chosen_payment_method', $gw );
			WC()->session->set( 'dgbp_card_type', sanitize_key( self::posted( 'dgbp_card_type' ) ) );
		}
		WC()->cart->calculate_totals();
	}

	public static function card_type_field() {
		$map  = (array) DGBP_Settings::get( 'gateway_map' );
		$asks = array_keys( array_filter( $map, function ( $t ) { return 'ask' === $t; } ) );
		if ( empty( $asks ) ) {
			return;
		}
		$current = self::chosen_card_type();
		?>
		<div id="dgbp-card-type" class="dgbp-card-type" data-gateways="<?php echo esc_attr( implode( ',', $asks ) ); ?>" style="display:none">
			<p class="form-row"><strong>Card type</strong> <abbr class="required" title="required">*</abbr></p>
			<label style="margin-right:18px"><input type="radio" name="dgbp_card_type" value="debit" <?php checked( $current, 'debit' ); ?> /> <?php echo esc_html( DGBP_Settings::tier_label( 'debit' ) ); ?></label>
			<label><input type="radio" name="dgbp_card_type" value="credit" <?php checked( $current, 'credit' ); ?> /> <?php echo esc_html( DGBP_Settings::tier_label( 'credit' ) ); ?></label>
		</div>
		<?php
	}

	private static function cart_has_bullion() {
		if ( ! WC()->cart ) {
			return false;
		}
		foreach ( WC()->cart->get_cart() as $item ) {
			if ( isset( $item['data'] ) && self::applies( $item['data'] ) ) {
				return true;
			}
		}
		return false;
	}

	public static function tier_row() {
		if ( ! self::cart_has_bullion() ) {
			return;
		}
		$tier = self::current_tier();
		echo '<tr class="dgbp-tier-row"><th>Bullion pricing</th><td>' . esc_html( DGBP_Settings::tier_label( $tier ) ) . ' price</td></tr>';
		$lock = DGBP_Pricing::get_lock();
		if ( $lock ) {
			echo '<tr class="dgbp-lock-row"><th>Prices locked until</th><td data-dgbp-expires="' . (int) $lock['expires'] . '">' . esc_html( wp_date( get_option( 'time_format', 'g:i A' ), (int) $lock['expires'] ) ) . '</td></tr>';
		}
	}

	public static function cart_tier_row() {
		if ( ! self::cart_has_bullion() ) {
			return;
		}
		$tier = DGBP_Settings::get( 'display_tier' );
		echo '<tr class="dgbp-tier-row"><th>Bullion pricing</th><td>' . esc_html( DGBP_Settings::tier_label( $tier ) ) . ' price shown. Final price depends on the payment method you choose at checkout.</td></tr>';
	}

	public static function line_item_meta( $item, $cart_item_key, $values ) {
		$product = isset( $values['data'] ) ? $values['data'] : null;
		if ( ! $product || ! self::applies( $product ) ) {
			return;
		}
		$p    = DGBP_Pricing::for_product( $product->get_id() );
		$tier = self::current_tier();
		$item->add_meta_data( '_dgbp_tier', $tier, true );
		if ( $p ) {
			$item->add_meta_data( '_dgbp_price_time', gmdate( 'c', (int) $p['updated'] ), true );
			if ( null !== $p['spot'] ) {
				$item->add_meta_data( '_dgbp_spot', $p['spot'], true );
			}
		}
	}

	public static function order_meta( $order, $data ) {
		$order->update_meta_data( '_dgbp_tier', self::current_tier() );
		$ct = self::chosen_card_type();
		if ( $ct ) {
			$order->update_meta_data( '_dgbp_card_type', $ct );
		}
		$lock   = DGBP_Pricing::get_lock();
		$spot   = $lock ? array( 'prices' => $lock['spot'], 'updated' => $lock['spot_time'] ) : DGBP_API::get_spot();
		if ( ! empty( $spot['prices'] ) ) {
			$order->update_meta_data( '_dgbp_spot_snapshot', wp_json_encode( $spot['prices'] ) );
			$order->update_meta_data( '_dgbp_spot_time', (int) $spot['updated'] );
		}
		if ( $lock ) {
			$order->update_meta_data( '_dgbp_lock', wp_json_encode( $lock ) );
		}
	}

	/** Add an order note recording the prices used, then release the customer's lock. */
	public static function order_processed( $order_id, $posted, $order ) {
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
		if ( ! $order || ! $order->get_meta( '_dgbp_tier' ) ) {
			return;
		}
		$snap  = json_decode( (string) $order->get_meta( '_dgbp_spot_snapshot' ), true );
		$parts = array();
		foreach ( (array) $snap as $metal => $p ) {
			if ( isset( DGBP_Settings::METALS[ $metal ], $p['ask'] ) ) {
				$parts[] = DGBP_Settings::METALS[ $metal ] . ' $' . number_format( (float) $p['ask'], 2 );
			}
		}
		$lock = json_decode( (string) $order->get_meta( '_dgbp_lock' ), true );
		if ( is_array( $lock ) && ! empty( $lock['products'] ) ) {
			foreach ( $lock['products'] as $code => $price ) {
				$parts[] = 'DG ' . $code . ' $' . number_format( (float) $price, 2 );
			}
		}
		$order->add_order_note(
			sprintf(
				'Bullion priced at the %s price using %s spot from %s%s: %s.',
				DGBP_Settings::tier_label( $order->get_meta( '_dgbp_tier' ) ),
				'production' === DGBP_Settings::get( 'environment' ) ? 'live' : 'STAGING',
				wp_date( 'M j, Y g:i A', (int) $order->get_meta( '_dgbp_spot_time' ) ),
				is_array( $lock ) ? ' (checkout lock)' : '',
				implode( ', ', $parts )
			)
		);
		if ( WC()->session ) {
			WC()->session->set( self::LOCK_SESSION, null );
		}
	}

	public static function hide_item_meta( $keys ) {
		return array_merge( $keys, array( '_dgbp_price_time' ) );
	}

	public static function admin_order_tier( $order ) {
		$tier = $order->get_meta( '_dgbp_tier' );
		if ( ! $tier ) {
			return;
		}
		echo '<p><strong>Bullion price tier:</strong> ' . esc_html( DGBP_Settings::tier_label( $tier ) ) . '</p>';
		$snap = json_decode( (string) $order->get_meta( '_dgbp_spot_snapshot' ), true );
		if ( is_array( $snap ) ) {
			$bits = array();
			foreach ( $snap as $metal => $p ) {
				$bits[] = esc_html( ucfirst( $metal ) ) . ' $' . esc_html( number_format( (float) $p['ask'], 2 ) );
			}
			echo '<p><strong>Spot at order:</strong> ' . implode( ', ', $bits ) . '</p>'; // phpcs:ignore
		}
	}

	/* ====================================================================
	 * Front-end assets
	 * ================================================================= */

	public static function assets() {
		wp_enqueue_style( 'dgbp-shop', DGBP_URL . 'assets/shop.css', array(), DGBP_VERSION );
		wp_enqueue_script( 'dgbp-shop', DGBP_URL . 'assets/shop.js', array( 'jquery' ), DGBP_VERSION, true );
		wp_localize_script(
			'dgbp-shop',
			'DGBPShop',
			array(
				'endpoint' => esc_url_raw( rest_url( 'dgbp/v1/products' ) ),
				'refresh'  => max( 15, (int) DGBP_Settings::get( 'cache_seconds' ) ),
			)
		);
	}
}
