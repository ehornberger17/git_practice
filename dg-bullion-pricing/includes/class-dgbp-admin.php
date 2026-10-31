<?php
/**
 * Settings page: Settings > DG Bullion.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DGBP_Admin {

	const CHECKBOXES = array( 'ticker_enabled', 'ticker_change', 'show_table', 'use_elementor_colors' );

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_ajax_dgbp_test', array( __CLASS__, 'ajax_test' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( DGBP_FILE ), array( __CLASS__, 'action_links' ) );
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=dgbp' ) ) . '">Settings</a>' );
		return $links;
	}

	public static function menu() {
		add_options_page( 'DG Bullion Ticker & Pricing', 'DG Bullion', 'manage_options', 'dgbp', array( __CLASS__, 'page' ) );
	}

	public static function register() {
		register_setting(
			'dgbp',
			DGBP_Settings::OPTION,
			array( 'sanitize_callback' => array( __CLASS__, 'sanitize' ) )
		);
	}

	public static function assets( $hook ) {
		if ( 'settings_page_dgbp' !== $hook ) {
			return;
		}
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'dgbp-admin', DGBP_URL . 'assets/admin.js', array( 'jquery', 'wp-color-picker' ), DGBP_VERSION, true );
		wp_localize_script(
			'dgbp-admin',
			'DGBPAdmin',
			array(
				'ajax'  => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'dgbp_test' ),
			)
		);
	}

	public static function notices() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( '' === (string) DGBP_Settings::get( 'token' ) && in_array( $screen->id, array( 'settings_page_dgbp', 'plugins' ), true ) ) {
			echo '<div class="notice notice-warning"><p><strong>DG Bullion:</strong> add your FizTrade API token under <a href="' . esc_url( admin_url( 'options-general.php?page=dgbp' ) ) . '">Settings &rarr; DG Bullion</a>.</p></div>';
		}
		// Per-payment-method pricing needs the classic checkout.
		if ( 'settings_page_dgbp' === $screen->id && function_exists( 'wc_get_page_id' ) ) {
			$checkout = get_post( wc_get_page_id( 'checkout' ) );
			if ( $checkout && function_exists( 'has_block' ) && has_block( 'woocommerce/checkout', $checkout ) ) {
				echo '<div class="notice notice-error"><p><strong>DG Bullion:</strong> your Checkout page uses the Checkout <em>block</em>. Wire / debit / credit pricing switches when the customer picks a payment method, which requires the classic checkout. Edit the Checkout page and replace the block with the <code>[woocommerce_checkout]</code> shortcode.</p></div>';
			}
		}
	}

	public static function sanitize( $input ) {
		$d   = DGBP_Settings::defaults();
		$out = DGBP_Settings::all();
		$in  = is_array( $input ) ? $input : array();

		foreach ( self::CHECKBOXES as $cb ) {
			$out[ $cb ] = empty( $in[ $cb ] ) ? 0 : 1;
		}

		$text = array( 'spot_path', 'product_path', 'product_price_key', 'ticker_selector', 'ticker_label', 'ticker_note', 'label_wire', 'label_debit', 'label_credit' );
		foreach ( $text as $k ) {
			if ( isset( $in[ $k ] ) ) {
				$out[ $k ] = sanitize_text_field( wp_unslash( $in[ $k ] ) );
			}
		}

		// Keep the existing token if the field is left blank.
		if ( isset( $in['token'] ) && '' !== trim( $in['token'] ) ) {
			$out['token'] = sanitize_text_field( wp_unslash( $in['token'] ) );
		}
		if ( ! empty( $in['token_clear'] ) ) {
			$out['token'] = '';
		}

		foreach ( array( 'base_url_staging', 'base_url_production' ) as $k ) {
			if ( isset( $in[ $k ] ) ) {
				$out[ $k ] = esc_url_raw( trim( $in[ $k ] ) );
			}
		}

		$enums = array(
			'environment'          => array( 'staging', 'production' ),
			'ticker_show'          => array( 'ask', 'bid', 'both' ),
			'ticker_mode'          => array( 'static', 'scroll' ),
			'ticker_placement'     => array( 'after_header', 'top', 'shortcode' ),
			'display_tier'         => DGBP_Settings::TIERS,
			'default_gateway_tier' => DGBP_Settings::TIERS,
		);
		foreach ( $enums as $k => $allowed ) {
			if ( isset( $in[ $k ] ) && in_array( $in[ $k ], $allowed, true ) ) {
				$out[ $k ] = $in[ $k ];
			}
		}

		$ints = array(
			'cache_seconds'    => array( 5, 3600 ),
			'stale_minutes'    => array( 1, 1440 ),
			'ticker_refresh'   => array( 10, 3600 ),
			'ticker_height'    => array( 20, 80 ),
			'ticker_font_size' => array( 10, 24 ),
		);
		foreach ( $ints as $k => $range ) {
			if ( isset( $in[ $k ] ) ) {
				$out[ $k ] = min( $range[1], max( $range[0], (int) $in[ $k ] ) );
			}
		}

		foreach ( array( 'debit_pct', 'credit_pct' ) as $k ) {
			if ( isset( $in[ $k ] ) ) {
				$out[ $k ] = min( 50, max( 0, (float) $in[ $k ] ) );
			}
		}

		foreach ( array( 'color_bg', 'color_text', 'color_label', 'color_up', 'color_down', 'color_border' ) as $k ) {
			if ( isset( $in[ $k ] ) ) {
				$c         = sanitize_hex_color( $in[ $k ] );
				$out[ $k ] = $c ? $c : $d[ $k ];
			}
		}

		$metals              = isset( $in['ticker_metals'] ) ? (array) $in['ticker_metals'] : array();
		$out['ticker_metals'] = array_values( array_intersect( array_keys( DGBP_Settings::METALS ), $metals ) );

		if ( isset( $in['gateway_map'] ) && is_array( $in['gateway_map'] ) ) {
			$map = array();
			foreach ( $in['gateway_map'] as $gid => $tier ) {
				if ( in_array( $tier, array( 'wire', 'debit', 'credit', 'ask' ), true ) ) {
					$map[ sanitize_key( $gid ) ] = $tier;
				}
			}
			$out['gateway_map'] = $map;
		}

		// Settings changed: drop cached prices.
		delete_transient( DGBP_API::SPOT_TRANSIENT );

		return $out;
	}

	/* --------------------------------------------------------------------
	 * Field helpers
	 * ----------------------------------------------------------------- */

	private static function name( $key ) {
		return DGBP_Settings::OPTION . '[' . $key . ']';
	}

	private static function text( $key, $s, $type = 'text', $attrs = '' ) {
		printf(
			'<input type="%s" class="regular-text" name="%s" value="%s" %s />',
			esc_attr( $type ),
			esc_attr( self::name( $key ) ),
			esc_attr( $s[ $key ] ),
			$attrs // phpcs:ignore -- static attribute strings only.
		);
	}

	private static function select( $key, $s, array $options ) {
		echo '<select name="' . esc_attr( self::name( $key ) ) . '">';
		foreach ( $options as $val => $label ) {
			echo '<option value="' . esc_attr( $val ) . '" ' . selected( $s[ $key ], $val, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
	}

	private static function checkbox( $key, $s, $label ) {
		echo '<label><input type="checkbox" name="' . esc_attr( self::name( $key ) ) . '" value="1" ' . checked( $s[ $key ], 1, false ) . ' /> ' . esc_html( $label ) . '</label>';
	}

	private static function color( $key, $s ) {
		echo '<input type="text" class="dgbp-color" name="' . esc_attr( self::name( $key ) ) . '" value="' . esc_attr( $s[ $key ] ) . '" data-default-color="' . esc_attr( DGBP_Settings::defaults()[ $key ] ) . '" />';
	}

	private static function row( $label, $cb, $help = '' ) {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>';
		$cb();
		if ( $help ) {
			echo '<p class="description">' . wp_kses_post( $help ) . '</p>';
		}
		echo '</td></tr>';
	}

	/* --------------------------------------------------------------------
	 * Page
	 * ----------------------------------------------------------------- */

	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s = DGBP_Settings::all();
		?>
		<div class="wrap">
			<h1>DG Bullion Ticker &amp; Pricing</h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'dgbp' ); ?>

				<h2>1. Dillon Gage FizTrade API</h2>
				<table class="form-table" role="presentation">
					<?php
					self::row( 'Environment', function () use ( $s ) {
						self::select( 'environment', $s, array( 'staging' => 'Staging (testing)', 'production' => 'Production (live)' ) );
					}, 'Use staging until Dillon Gage gives you a production token.' );
					self::row( 'Staging base URL', function () use ( $s ) {
						self::text( 'base_url_staging', $s, 'url' );
					} );
					self::row( 'Production base URL', function () use ( $s ) {
						self::text( 'base_url_production', $s, 'url' );
					} );
					self::row( 'API token', function () use ( $s ) {
						$has = '' !== $s['token'];
						echo '<input type="password" class="regular-text" autocomplete="new-password" name="' . esc_attr( self::name( 'token' ) ) . '" value="" placeholder="' . esc_attr( $has ? '•••••••• saved — leave blank to keep' : 'Paste token from Dillon Gage' ) . '" />';
						if ( $has ) {
							echo ' <label><input type="checkbox" name="' . esc_attr( self::name( 'token_clear' ) ) . '" value="1" /> Remove saved token</label>';
						}
					}, 'Stored server-side only; it is never sent to visitors\' browsers.' );
					self::row( 'Spot price endpoint', function () use ( $s ) {
						self::text( 'spot_path', $s );
					}, 'Appended to the base URL. <code>{token}</code> is replaced with your token.' );
					self::row( 'Product price endpoint', function () use ( $s ) {
						self::text( 'product_path', $s );
					}, '<code>{code}</code> is replaced with the Dillon Gage product code set on each WooCommerce product.' );
					self::row( 'Product price field', function () use ( $s ) {
						self::text( 'product_price_key', $s, 'text', 'placeholder="auto-detect (ask price)"' );
					}, 'Leave blank to auto-detect. If the test below shows the wrong number, enter the field name to use (e.g. <code>tiers.1.ask</code>).' );
					self::row( 'Cache prices for (seconds)', function () use ( $s ) {
						self::text( 'cache_seconds', $s, 'number', 'min="5" max="3600" style="width:90px"' );
					} );
					self::row( 'Stop selling if prices older than (minutes)', function () use ( $s ) {
						self::text( 'stale_minutes', $s, 'number', 'min="1" max="1440" style="width:90px"' );
					}, 'If the API is down longer than this, bullion products become unpurchasable instead of selling at an old price.' );
					?>
					<tr><th scope="row">Test connection</th><td>
						<button type="button" class="button" id="dgbp-test-spot">Test spot prices</button>
						&nbsp; <input type="text" id="dgbp-test-code" placeholder="DG product code" style="width:160px" />
						<button type="button" class="button" id="dgbp-test-product">Test product price</button>
						<p class="description">Save your settings first. Shows the raw API response and what the plugin read from it.</p>
						<pre id="dgbp-test-out" style="display:none;max-height:360px;overflow:auto;background:#f6f7f7;padding:10px;border:1px solid #dcdcde;"></pre>
					</td></tr>
				</table>

				<h2>2. Header ticker</h2>
				<table class="form-table" role="presentation">
					<?php
					self::row( 'Show ticker', function () use ( $s ) {
						self::checkbox( 'ticker_enabled', $s, 'Enabled' );
					} );
					self::row( 'Placement', function () use ( $s ) {
						self::select( 'ticker_placement', $s, array(
							'after_header' => 'Bottom of the site header (automatic)',
							'top'          => 'Very top of the page',
							'shortcode'    => 'Only where I place [dg_ticker]',
						) );
					} );
					self::row( 'Header CSS selector', function () use ( $s ) {
						self::text( 'ticker_selector', $s, 'text', 'style="width:28em"' );
					}, 'The ticker is inserted directly after the first element that matches. Most themes work with the default; for a custom theme enter its header selector (e.g. <code>.elementor-location-header</code>).' );
					self::row( 'Metals', function () use ( $s ) {
						foreach ( DGBP_Settings::METALS as $k => $label ) {
							echo '<label style="margin-right:14px"><input type="checkbox" name="' . esc_attr( self::name( 'ticker_metals' ) ) . '[]" value="' . esc_attr( $k ) . '" ' . checked( in_array( $k, (array) $s['ticker_metals'], true ), true, false ) . ' /> ' . esc_html( $label ) . '</label>';
						}
					} );
					self::row( 'Price shown', function () use ( $s ) {
						self::select( 'ticker_show', $s, array( 'ask' => 'Ask', 'bid' => 'Bid', 'both' => 'Bid / Ask' ) );
					} );
					self::row( 'Daily change', function () use ( $s ) {
						self::checkbox( 'ticker_change', $s, 'Show change with up/down arrow' );
					} );
					self::row( 'Style', function () use ( $s ) {
						self::select( 'ticker_mode', $s, array( 'static' => 'Static (fits on one line, scrolls on small screens)', 'scroll' => 'Continuous scroll' ) );
					} );
					self::row( 'Label', function () use ( $s ) {
						self::text( 'ticker_label', $s );
					}, 'Shown at the left of the bar. Leave blank to hide.' );
					self::row( 'Note (right side)', function () use ( $s ) {
						self::text( 'ticker_note', $s, 'text', 'placeholder="e.g. Call 800-555-0100"' );
					} );
					self::row( 'Refresh every (seconds)', function () use ( $s ) {
						self::text( 'ticker_refresh', $s, 'number', 'min="10" max="3600" style="width:90px"' );
					} );
					self::row( 'Bar height (px)', function () use ( $s ) {
						self::text( 'ticker_height', $s, 'number', 'min="20" max="80" style="width:90px"' );
					} );
					self::row( 'Font size (px)', function () use ( $s ) {
						self::text( 'ticker_font_size', $s, 'number', 'min="10" max="24" style="width:90px"' );
					} );
					self::row( 'Elementor Global Colors', function () use ( $s ) {
						self::checkbox( 'use_elementor_colors', $s, 'Match my Elementor site colors automatically' );
					}, 'Background uses your Elementor <em>Primary</em> color; metal names and the bottom border use <em>Accent</em>; font uses your Elementor <em>Text</em> font. The pickers below are used for anything Elementor doesn\'t define. Untick to use only the pickers.' );
					self::row( 'Colors', function () use ( $s ) {
						$labels = array(
							'color_bg'     => 'Background',
							'color_text'   => 'Prices',
							'color_label'  => 'Metal names',
							'color_up'     => 'Up',
							'color_down'   => 'Down',
							'color_border' => 'Bottom border',
						);
						echo '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:8px 18px">';
						foreach ( $labels as $k => $l ) {
							echo '<div><div style="font-weight:600;margin-bottom:2px">' . esc_html( $l ) . '</div>';
							self::color( $k, $s );
							echo '</div>';
						}
						echo '</div>';
					}, 'Set these to your website\'s brand colors.' );
					?>
				</table>

				<h2>3. WooCommerce bullion pricing</h2>
				<?php if ( ! class_exists( 'WooCommerce' ) ) : ?>
					<p><em>WooCommerce is not active. These settings apply once it is.</em></p>
				<?php endif; ?>
				<table class="form-table" role="presentation">
					<?php
					self::row( 'Debit card surcharge (%)', function () use ( $s ) {
						self::text( 'debit_pct', $s, 'number', 'step="0.01" min="0" max="50" style="width:90px"' );
					}, 'Added on top of the wire price. Can be overridden per product.' );
					self::row( 'Credit card surcharge (%)', function () use ( $s ) {
						self::text( 'credit_pct', $s, 'number', 'step="0.01" min="0" max="50" style="width:90px"' );
					} );
					self::row( 'Price labels', function () use ( $s ) {
						foreach ( DGBP_Settings::TIERS as $t ) {
							echo '<input type="text" style="width:140px;margin-right:6px" name="' . esc_attr( self::name( 'label_' . $t ) ) . '" value="' . esc_attr( $s[ 'label_' . $t ] ) . '" />';
						}
					} );
					self::row( 'Headline price on shop & product pages', function () use ( $s ) {
						self::select( 'display_tier', $s, array( 'wire' => 'Wire price (lowest)', 'debit' => 'Debit card price', 'credit' => 'Credit card price' ) );
					}, 'Also the price used in the cart until the customer chooses a payment method at checkout.' );
					self::row( 'Price table', function () use ( $s ) {
						self::checkbox( 'show_table', $s, 'Show all three prices on the product page' );
					} );

					if ( function_exists( 'WC' ) && WC()->payment_gateways() ) {
						self::row( 'Payment methods', function () use ( $s ) {
							$gateways = WC()->payment_gateways()->payment_gateways();
							$opts     = array(
								''       => '— default —',
								'wire'   => 'Wire price',
								'debit'  => 'Debit card price',
								'credit' => 'Credit card price',
								'ask'    => 'Card: ask customer debit or credit',
							);
							echo '<table class="widefat striped" style="max-width:640px"><thead><tr><th>Payment method</th><th>Charges</th></tr></thead><tbody>';
							foreach ( $gateways as $id => $gw ) {
								$cur = isset( $s['gateway_map'][ $id ] ) ? $s['gateway_map'][ $id ] : '';
								echo '<tr><td>' . esc_html( $gw->get_method_title() ) . ' <code>' . esc_html( $id ) . '</code>' . ( 'yes' === $gw->enabled ? '' : ' <em>(disabled)</em>' ) . '</td><td><select name="' . esc_attr( self::name( 'gateway_map' ) ) . '[' . esc_attr( $id ) . ']">';
								foreach ( $opts as $v => $l ) {
									echo '<option value="' . esc_attr( $v ) . '" ' . selected( $cur, $v, false ) . '>' . esc_html( $l ) . '</option>';
								}
								echo '</select></td></tr>';
							}
							echo '</tbody></table>';
						}, 'Map each checkout payment method to a price. Card processors usually can\'t tell debit from credit, so choose “ask customer” for your card gateway to show a Debit / Credit choice at checkout.' );
						self::row( 'Unmapped payment methods charge', function () use ( $s ) {
							self::select( 'default_gateway_tier', $s, array( 'credit' => 'Credit card price (safest)', 'debit' => 'Debit card price', 'wire' => 'Wire price' ) );
						} );
					}
					?>
				</table>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	public static function ajax_test() {
		check_ajax_referer( 'dgbp_test', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Not allowed' );
		}

		$type = isset( $_POST['type'] ) ? sanitize_key( $_POST['type'] ) : 'spot';
		$code = isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : '';

		$url = 'product' === $type
			? DGBP_API::build_url( DGBP_Settings::get( 'product_path' ), $code )
			: DGBP_API::build_url( DGBP_Settings::get( 'spot_path' ) );

		$raw = DGBP_API::request( $url );

		// Never echo the token back.
		$token    = (string) DGBP_Settings::get( 'token' );
		$safe_url = $token ? str_replace( rawurlencode( $token ), '{token}', $url ) : $url;

		if ( is_wp_error( $raw ) ) {
			$data = $raw->get_error_data();
			wp_send_json_error(
				array(
					'url'   => $safe_url,
					'error' => $raw->get_error_message(),
					'body'  => is_array( $data ) && isset( $data['body'] ) ? $data['body'] : '',
				)
			);
		}

		$parsed = 'product' === $type ? array( 'dealer_ask' => DGBP_API::parse_product_price( $raw ) ) : DGBP_API::parse_spot( $raw );

		if ( 'spot' === $type ) {
			delete_transient( DGBP_API::SPOT_TRANSIENT );
		}

		wp_send_json_success(
			array(
				'url'    => $safe_url,
				'parsed' => $parsed,
				'raw'    => $raw,
			)
		);
	}
}
