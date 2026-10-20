<?php
/**
 * Plugin Name: Spot Price Ticker
 * Description: Precious-metal spot price bar fed by the Dillon Gage FizTrade API. Place it with the [spot_ticker] shortcode. Settings → Spot Price Ticker.
 * Version:     1.0.0
 * Requires PHP: 7.4
 * License:     GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

const SPT_VERSION   = '1.0.0';
const SPT_OPTION    = 'spt_settings';
const SPT_CACHE     = 'spt_prices';
const SPT_LAST_GOOD = 'spt_last_good';
const SPT_MAX_STALE = 1800; // Hide prices older than 30 minutes rather than show stale quotes.
const SPT_METALS    = array(
	'gold'      => 'Gold',
	'silver'    => 'Silver',
	'platinum'  => 'Platinum',
	'palladium' => 'Palladium',
);

function spt_defaults() {
	return array(
		'endpoint'        => 'https://stage-connect.fiztrade.com/FizServices/GetSpotPriceData/{token}',
		'token'           => '',
		'cache_seconds'   => 60,
		'refresh_seconds' => 60,
		'metals'          => array_keys( SPT_METALS ),
		'price'           => 'ask',
		'show_change'     => 1,
		'credit_text'     => '',
		'credit_url'      => '',
		'bg'              => '',
		'text'            => '',
		'accent'          => '',
	);
}

function spt_settings() {
	return wp_parse_args( get_option( SPT_OPTION, array() ), spt_defaults() );
}

/* ------------------------------------------------------------------ *
 * Fetching and parsing
 * ------------------------------------------------------------------ */

/**
 * Calls the price API. The token only ever travels server-to-server.
 *
 * @return array|WP_Error Decoded JSON.
 */
function spt_fetch_remote( array $s ) {
	if ( '' === $s['token'] ) {
		return new WP_Error( 'spt_no_token', 'No API token has been saved yet.' );
	}
	$url = str_replace( '{token}', rawurlencode( $s['token'] ), $s['endpoint'] );
	$res = wp_remote_get( $url, array(
		'timeout' => 8,
		'headers' => array( 'Accept' => 'application/json' ),
	) );
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	$code = (int) wp_remote_retrieve_response_code( $res );
	$body = wp_remote_retrieve_body( $res );
	if ( 200 !== $code ) {
		return new WP_Error( 'spt_http', 'The API answered with HTTP ' . $code . '.', $body );
	}
	$data = json_decode( $body, true );
	if ( ! is_array( $data ) ) {
		return new WP_Error( 'spt_json', 'The API response was not JSON.', $body );
	}
	return $data;
}

/**
 * Flattens the response into lowercase keys like "goldask" => 2650.1, so the
 * parser works whether prices come as flat fields or as one object per metal.
 */
function spt_flatten( $data, $prefix = '', array &$out = array() ) {
	foreach ( $data as $k => $v ) {
		if ( is_array( $v ) && is_int( $k ) ) {
			// A list item describing one metal: use its name as the prefix.
			$name = '';
			foreach ( array( 'metal', 'name', 'symbol', 'commodity', 'product' ) as $name_key ) {
				foreach ( $v as $vk => $vv ) {
					if ( is_string( $vv ) && strtolower( (string) $vk ) === $name_key ) {
						$name = $vv;
						break 2;
					}
				}
			}
			spt_flatten( $v, $prefix . $name, $out );
		} elseif ( is_array( $v ) ) {
			spt_flatten( $v, $prefix . $k, $out );
		} else {
			$n = is_string( $v ) ? str_replace( array( ',', '$' ), '', $v ) : $v;
			if ( is_numeric( $n ) ) {
				$out[ strtolower( preg_replace( '/[^a-z0-9]/i', '', $prefix . $k ) ) ] = (float) $n;
			}
		}
	}
	return $out;
}

function spt_pick( array $flat, $metal, array $names, array $exclude = array() ) {
	foreach ( $names as $n ) {
		if ( isset( $flat[ $metal . $n ] ) ) {
			return $flat[ $metal . $n ];
		}
	}
	foreach ( $names as $n ) {
		foreach ( $flat as $k => $v ) {
			if ( false === strpos( $k, $metal ) || substr( $k, -strlen( $n ) ) !== $n ) {
				continue;
			}
			foreach ( $exclude as $x ) {
				if ( false !== strpos( $k, $x ) ) {
					continue 2;
				}
			}
			return $v;
		}
	}
	return null;
}

function spt_normalize( array $data, array $s ) {
	$flat  = spt_flatten( $data );
	$items = array();
	foreach ( $s['metals'] as $m ) {
		if ( ! array_key_exists( $m, SPT_METALS ) ) {
			continue;
		}
		$row = array(
			'metal'      => $m,
			'label'      => SPT_METALS[ $m ],
			'ask'        => spt_pick( $flat, $m, array( 'ask', 'askprice', 'offer', 'offerprice' ) ),
			'bid'        => spt_pick( $flat, $m, array( 'bid', 'bidprice' ) ),
			'change'     => spt_pick( $flat, $m, array( 'change', 'netchange', 'chg' ), array( 'percent', 'pct' ) ),
			'change_pct' => spt_pick( $flat, $m, array( 'changepercent', 'changepct', 'percentchange', 'pctchange' ) ),
		);
		if ( null !== $row['ask'] || null !== $row['bid'] ) {
			$items[] = $row;
		}
	}
	return $items;
}

/**
 * Prices for visitors. However many people load the site, the API is called
 * at most once per cache period.
 */
function spt_get_prices() {
	$cached = get_transient( SPT_CACHE );
	if ( false !== $cached ) {
		return $cached;
	}
	$s     = spt_settings();
	$data  = spt_fetch_remote( $s );
	$items = is_wp_error( $data ) ? array() : spt_normalize( $data, $s );

	if ( $items ) {
		$payload = array( 'items' => $items, 'updated' => time(), 'stale' => false );
		update_option( SPT_LAST_GOOD, $payload, false );
	} else {
		// Keep the last good prices for a while instead of an empty bar.
		$payload          = get_option( SPT_LAST_GOOD, array( 'items' => array(), 'updated' => 0 ) );
		$payload['stale'] = true;
		if ( time() - (int) $payload['updated'] > SPT_MAX_STALE ) {
			$payload['items'] = array();
		}
	}
	set_transient( SPT_CACHE, $payload, max( 15, (int) $s['cache_seconds'] ) );
	return $payload;
}

function spt_clear_cache() {
	delete_transient( SPT_CACHE );
}
add_action( 'add_option_' . SPT_OPTION, 'spt_clear_cache' );
add_action( 'update_option_' . SPT_OPTION, 'spt_clear_cache' );

add_action( 'rest_api_init', function () {
	register_rest_route( 'spot-ticker/v1', '/prices', array(
		'methods'             => 'GET',
		'permission_callback' => '__return_true',
		'callback'            => function () {
			$res = new WP_REST_Response( spt_get_prices() );
			// Page caches must not hold on to live prices.
			$res->header( 'Cache-Control', 'no-store, no-cache, max-age=0' );
			return $res;
		},
	) );
} );

/* ------------------------------------------------------------------ *
 * Front end
 * ------------------------------------------------------------------ */

function spt_register_assets() {
	$url = plugin_dir_url( __FILE__ ) . 'assets/';
	wp_register_style( 'spt-ticker', $url . 'ticker.css', array(), SPT_VERSION );
	wp_register_script( 'spt-ticker', $url . 'ticker.js', array(), SPT_VERSION, true );
}
add_action( 'wp_enqueue_scripts', function () {
	spt_register_assets();
	wp_enqueue_style( 'spt-ticker' ); // Tiny; loading it up front avoids a flash of unstyled bar.
} );
add_action( 'elementor/preview/enqueue_scripts', function () {
	spt_register_assets();
	wp_enqueue_style( 'spt-ticker' );
	wp_enqueue_script( 'spt-ticker' );
} );

add_shortcode( 'spot_ticker', function ( $atts ) {
	$s = spt_settings();
	$a = shortcode_atts( array(
		'price'  => $s['price'],
		'bg'     => $s['bg'],
		'color'  => $s['text'],
		'accent' => $s['accent'],
	), $atts, 'spot_ticker' );

	wp_enqueue_style( 'spt-ticker' );
	wp_enqueue_script( 'spt-ticker' );

	$style = '';
	foreach ( array( 'bg' => '--spt-bg', 'color' => '--spt-text', 'accent' => '--spt-accent' ) as $key => $var ) {
		$hex = sanitize_hex_color( $a[ $key ] );
		if ( $hex ) {
			$style .= $var . ':' . $hex . ';';
		}
	}
	$price = in_array( $a['price'], array( 'ask', 'bid', 'both' ), true ) ? $a['price'] : 'ask';

	ob_start();
	?>
	<div class="spt-bar" role="region" aria-label="Precious metal spot prices"
		data-endpoint="<?php echo esc_url( rest_url( 'spot-ticker/v1/prices' ) ); ?>"
		data-refresh="<?php echo (int) $s['refresh_seconds']; ?>"
		data-price="<?php echo esc_attr( $price ); ?>"
		data-change="<?php echo $s['show_change'] ? '1' : '0'; ?>"
		<?php echo $style ? 'style="' . esc_attr( $style ) . '"' : ''; ?>>
		<span class="spt-time">Spot</span>
		<div class="spt-viewport">
			<ul class="spt-track" aria-live="off"><li class="spt-item spt-loading">Loading spot prices…</li></ul>
		</div>
		<?php if ( $s['credit_text'] ) : ?>
			<?php if ( $s['credit_url'] ) : ?>
				<a class="spt-credit" href="<?php echo esc_url( $s['credit_url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $s['credit_text'] ); ?></a>
			<?php else : ?>
				<span class="spt-credit"><?php echo esc_html( $s['credit_text'] ); ?></span>
			<?php endif; ?>
		<?php endif; ?>
	</div>
	<?php
	return ob_get_clean();
} );

/* ------------------------------------------------------------------ *
 * Settings page
 * ------------------------------------------------------------------ */

add_action( 'admin_init', function () {
	register_setting( 'spt', SPT_OPTION, array( 'sanitize_callback' => 'spt_sanitize' ) );
} );

function spt_sanitize( $in ) {
	$old = spt_settings();
	$in  = is_array( $in ) ? $in : array();

	$endpoint = trim( sanitize_text_field( $in['endpoint'] ?? '' ) );
	$token    = trim( sanitize_text_field( $in['token'] ?? '' ) );
	$price    = $in['price'] ?? 'ask';

	return array(
		// esc_url_raw() would strip the {token} placeholder, so only require https.
		'endpoint'        => preg_match( '#^https://#i', $endpoint ) ? $endpoint : $old['endpoint'],
		// A blank token field means "keep the saved one".
		'token'           => '' !== $token ? $token : $old['token'],
		'cache_seconds'   => max( 15, min( 3600, absint( $in['cache_seconds'] ?? 60 ) ) ),
		'refresh_seconds' => max( 15, min( 3600, absint( $in['refresh_seconds'] ?? 60 ) ) ),
		'metals'          => array_values( array_intersect( array_keys( SPT_METALS ), (array) ( $in['metals'] ?? array() ) ) ),
		'price'           => in_array( $price, array( 'ask', 'bid', 'both' ), true ) ? $price : 'ask',
		'show_change'     => empty( $in['show_change'] ) ? 0 : 1,
		'credit_text'     => sanitize_text_field( $in['credit_text'] ?? '' ),
		'credit_url'      => esc_url_raw( $in['credit_url'] ?? '' ),
		'bg'              => (string) sanitize_hex_color( $in['bg'] ?? '' ),
		'text'            => (string) sanitize_hex_color( $in['text'] ?? '' ),
		'accent'          => (string) sanitize_hex_color( $in['accent'] ?? '' ),
	);
}

add_action( 'admin_menu', function () {
	add_options_page( 'Spot Price Ticker', 'Spot Price Ticker', 'manage_options', 'spot-price-ticker', 'spt_settings_page' );
} );

function spt_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$s    = spt_settings();
	$name = SPT_OPTION;
	$test = isset( $_GET['spt_test'] ) && check_admin_referer( 'spt_test' );
	?>
	<div class="wrap">
		<h1>Spot Price Ticker</h1>
		<p>Add the bar anywhere with a Shortcode widget: <code>[spot_ticker]</code>.
			Optional: <code>[spot_ticker price="both" bg="#0b1f3a" color="#ffffff" accent="#c9a646"]</code></p>

		<form method="post" action="options.php">
			<?php settings_fields( 'spt' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="spt-endpoint">API URL</label></th>
					<td><input id="spt-endpoint" class="large-text code" name="<?php echo esc_attr( $name ); ?>[endpoint]" value="<?php echo esc_attr( $s['endpoint'] ); ?>">
						<p class="description">The spot price URL from your FizTrade documentation. <code>{token}</code> is replaced with your token. Switch from the staging URL to the production URL before going live.</p></td>
				</tr>
				<tr>
					<th scope="row"><label for="spt-token">API token</label></th>
					<td><input id="spt-token" type="password" class="regular-text" autocomplete="new-password" name="<?php echo esc_attr( $name ); ?>[token]" placeholder="<?php echo $s['token'] ? esc_attr( 'Saved — leave blank to keep' ) : ''; ?>">
						<p class="description">Stored on your server only; never sent to visitors' browsers.</p></td>
				</tr>
				<tr>
					<th scope="row">Metals</th>
					<td><?php foreach ( SPT_METALS as $key => $label ) : ?>
						<label style="margin-right:16px"><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[metals][]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $s['metals'], true ) ); ?>> <?php echo esc_html( $label ); ?></label>
					<?php endforeach; ?></td>
				</tr>
				<tr>
					<th scope="row"><label for="spt-price">Price shown</label></th>
					<td><select id="spt-price" name="<?php echo esc_attr( $name ); ?>[price]">
						<option value="ask" <?php selected( $s['price'], 'ask' ); ?>>Ask</option>
						<option value="bid" <?php selected( $s['price'], 'bid' ); ?>>Bid</option>
						<option value="both" <?php selected( $s['price'], 'both' ); ?>>Bid / Ask</option>
					</select>
					<label style="margin-left:16px"><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[show_change]" value="1" <?php checked( $s['show_change'], 1 ); ?>> Show daily change</label></td>
				</tr>
				<tr>
					<th scope="row"><label for="spt-cache">Check the API every</label></th>
					<td><input id="spt-cache" type="number" min="15" max="3600" class="small-text" name="<?php echo esc_attr( $name ); ?>[cache_seconds]" value="<?php echo (int) $s['cache_seconds']; ?>"> seconds
						<p class="description">However many visitors you have, the API is called at most this often (60 seconds = up to 1,440 calls a day).</p></td>
				</tr>
				<tr>
					<th scope="row"><label for="spt-refresh">Refresh visitors' bar every</label></th>
					<td><input id="spt-refresh" type="number" min="15" max="3600" class="small-text" name="<?php echo esc_attr( $name ); ?>[refresh_seconds]" value="<?php echo (int) $s['refresh_seconds']; ?>"> seconds</td>
				</tr>
				<tr>
					<th scope="row"><label for="spt-credit">Credit</label></th>
					<td><input id="spt-credit" class="regular-text" placeholder="e.g. Prices: Dillon Gage" name="<?php echo esc_attr( $name ); ?>[credit_text]" value="<?php echo esc_attr( $s['credit_text'] ); ?>">
						<input class="regular-text" placeholder="https://…" name="<?php echo esc_attr( $name ); ?>[credit_url]" value="<?php echo esc_attr( $s['credit_url'] ); ?>">
						<p class="description">Shown at the right end of the bar, if your agreement asks for attribution.</p></td>
				</tr>
				<tr>
					<th scope="row">Colors</th>
					<td>
						<?php foreach ( array( 'bg' => 'Background', 'text' => 'Text', 'accent' => 'Accent' ) as $key => $label ) : ?>
							<label style="margin-right:16px"><?php echo esc_html( $label ); ?> <input class="small-text" style="width:90px" placeholder="#rrggbb" name="<?php echo esc_attr( $name ); ?>[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $s[ $key ] ); ?>"></label>
						<?php endforeach; ?>
						<p class="description">Leave blank to use your Elementor global colors (background = Primary, accent = Accent; text is white).</p>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>

		<h2>Test the connection</h2>
		<p><a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'options-general.php?page=spot-price-ticker&spt_test=1' ), 'spt_test' ) ); ?>">Call the API now</a></p>
		<?php if ( $test ) : spt_render_test( $s ); endif; ?>
	</div>
	<?php
}

function spt_render_test( array $s ) {
	$data = spt_fetch_remote( $s );
	if ( is_wp_error( $data ) ) {
		$raw = (string) $data->get_error_data();
		echo '<div class="notice notice-error inline"><p><strong>Failed:</strong> ' . esc_html( $data->get_error_message() ) . '</p></div>';
		if ( '' !== $raw ) {
			echo '<pre style="max-height:300px;overflow:auto;background:#fff;padding:12px">' . esc_html( spt_redact( substr( $raw, 0, 4000 ), $s ) ) . '</pre>';
		}
		return;
	}
	$items = spt_normalize( $data, $s );
	if ( $items ) {
		echo '<div class="notice notice-success inline"><p>Connected. These are the prices the bar will show:</p></div>';
		echo '<table class="widefat striped" style="max-width:720px"><thead><tr><th>Metal</th><th>Bid</th><th>Ask</th><th>Change</th><th>Change %</th></tr></thead><tbody>';
		foreach ( $items as $r ) {
			echo '<tr><td>' . esc_html( $r['label'] ) . '</td>';
			foreach ( array( 'bid', 'ask', 'change', 'change_pct' ) as $f ) {
				echo '<td>' . ( null === $r[ $f ] ? '—' : esc_html( number_format_i18n( $r[ $f ], 2 ) ) ) . '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table>';
	} else {
		echo '<div class="notice notice-warning inline"><p>The API answered, but no metal prices were recognized in the response below. Send this response to whoever is helping you set this up.</p></div>';
	}
	echo '<h3>Raw response</h3><pre style="max-height:400px;overflow:auto;background:#fff;padding:12px">' . esc_html( spt_redact( wp_json_encode( $data, JSON_PRETTY_PRINT ), $s ) ) . '</pre>';
}

function spt_redact( $text, array $s ) {
	return '' === $s['token'] ? $text : str_replace( $s['token'], '[token]', $text );
}

register_uninstall_hook( __FILE__, 'spt_uninstall' );
function spt_uninstall() {
	delete_option( SPT_OPTION );
	delete_option( SPT_LAST_GOOD );
	delete_transient( SPT_CACHE );
}
