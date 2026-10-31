<?php
/**
 * Dillon Gage FizTrade / FizConnect API client.
 *
 * The token is only ever used server-side; browsers talk to this site's own
 * REST endpoints, never to Dillon Gage directly.
 *
 * Field names are matched loosely (case/underscore-insensitive, nested objects
 * flattened) so the client keeps working if staging and production differ
 * slightly. Use Settings > DG Bullion > Test connection to see raw output.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DGBP_API {

	const SPOT_TRANSIENT  = 'dgbp_spot';
	const SPOT_LAST_GOOD  = 'dgbp_spot_last_good';
	const PROD_LAST_GOOD  = 'dgbp_product_last_good';
	const HOLD            = 'dgbp_hold';
	const ACCEPT_UNTIL    = 'dgbp_accept_until';

	/* --------------------------------------------------------------------
	 * Spot-jump hold
	 * ----------------------------------------------------------------- */

	/** True if a single update moved further than the allowed percentage. */
	public static function jumped( $old, $new ) {
		$pct = (float) DGBP_Settings::get( 'max_jump_pct' );
		if ( $pct <= 0 || (float) $old <= 0 ) {
			return false;
		}
		return abs( (float) $new - (float) $old ) / (float) $old * 100 > $pct;
	}

	private static function accepting() {
		return (int) get_option( self::ACCEPT_UNTIL, 0 ) > time();
	}

	/** @return array key => { label, old, new, time } */
	public static function holds() {
		$h = get_option( self::HOLD, array() );
		return is_array( $h ) ? $h : array();
	}

	/** Replace all hold entries whose key starts with $prefix. */
	private static function set_holds( $prefix, array $entries ) {
		$all = self::holds();
		foreach ( array_keys( $all ) as $k ) {
			$is_group = ':' === substr( $prefix, -1 );
			if ( $k === $prefix || ( $is_group && 0 === strpos( $k, $prefix ) ) ) {
				unset( $all[ $k ] );
			}
		}
		$all = array_merge( $all, $entries );
		if ( $all !== self::holds() ) {
			update_option( self::HOLD, $all, false );
		}
	}

	/** Admin accepted the new prices: take them on the next fetch. */
	public static function accept_current() {
		update_option( self::ACCEPT_UNTIL, time() + 120, false );
		delete_option( self::HOLD );
		self::cron_refresh();
		delete_option( self::ACCEPT_UNTIL );
	}

	/* --------------------------------------------------------------------
	 * HTTP
	 * ----------------------------------------------------------------- */

	public static function build_url( $path_template, $code = '' ) {
		$token = (string) DGBP_Settings::get( 'token' );
		$path  = str_replace(
			array( '{token}', '{code}' ),
			array( rawurlencode( $token ), rawurlencode( (string) $code ) ),
			ltrim( (string) $path_template, '/' )
		);
		return DGBP_Settings::base_url() . '/' . $path;
	}

	/**
	 * @return array|WP_Error Decoded JSON.
	 */
	public static function request( $url ) {
		if ( '' === (string) DGBP_Settings::get( 'token' ) ) {
			return new WP_Error( 'dgbp_no_token', 'No FizTrade API token is configured.' );
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 8,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'dgbp_http', sprintf( 'FizTrade returned HTTP %d.', $code ), array( 'body' => substr( $body, 0, 500 ) ) );
		}

		$data = json_decode( $body, true );
		if ( null === $data ) {
			return new WP_Error( 'dgbp_json', 'FizTrade response was not valid JSON.', array( 'body' => substr( $body, 0, 500 ) ) );
		}
		return $data;
	}

	/* --------------------------------------------------------------------
	 * Parsing helpers
	 * ----------------------------------------------------------------- */

	public static function norm_key( $key ) {
		return preg_replace( '/[^a-z0-9]/', '', strtolower( (string) $key ) );
	}

	/**
	 * Flatten nested arrays into normalized concatenated keys,
	 * e.g. {"gold":{"Ask":1}} => goldask, {"tiers":{"1":{"ask":2}}} => tiers1ask.
	 * Lists of objects carrying a metal/name field are re-keyed by that name.
	 */
	public static function flatten( $data, $prefix = '' ) {
		$out = array();
		if ( ! is_array( $data ) ) {
			return $out;
		}
		foreach ( $data as $key => $value ) {
			$k = self::norm_key( $key );
			if ( is_array( $value ) ) {
				// Re-key list items by their metal / name if present.
				if ( is_int( $key ) ) {
					foreach ( array( 'metal', 'metalname', 'name', 'commodity', 'symbol' ) as $name_field ) {
						foreach ( $value as $ik => $iv ) {
							if ( self::norm_key( $ik ) === $name_field && is_scalar( $iv ) ) {
								$k = self::norm_key( $iv );
								break 2;
							}
						}
					}
				}
				$out = array_merge( $out, self::flatten( $value, $prefix . $k ) );
			} else {
				$out[ $prefix . $k ] = $value;
			}
		}
		return $out;
	}

	public static function to_number( $value ) {
		if ( is_numeric( $value ) ) {
			return round( (float) $value, 4 );
		}
		if ( is_string( $value ) ) {
			$clean = preg_replace( '/[^0-9.\-]/', '', $value );
			if ( is_numeric( $clean ) ) {
				return round( (float) $clean, 4 );
			}
		}
		return null;
	}

	private static function pick( array $flat, array $candidates ) {
		foreach ( $candidates as $c ) {
			if ( array_key_exists( $c, $flat ) ) {
				$n = self::to_number( $flat[ $c ] );
				if ( null !== $n ) {
					return $n;
				}
			}
		}
		// Tolerate wrapper objects, e.g. {"data":{"goldAsk":..}} => datagoldask.
		foreach ( $candidates as $c ) {
			if ( strlen( $c ) < 6 ) {
				continue;
			}
			foreach ( $flat as $k => $v ) {
				if ( strlen( $k ) > strlen( $c ) && substr( $k, -strlen( $c ) ) === $c ) {
					$n = self::to_number( $v );
					if ( null !== $n ) {
						return $n;
					}
				}
			}
		}
		return null;
	}

	/**
	 * Normalize a spot response to:
	 * [ 'gold' => [ask, bid, change, change_pct], ... ]
	 */
	public static function parse_spot( $raw ) {
		$flat   = self::flatten( $raw );
		$prices = array();

		$aliases = array(
			'gold'      => array( 'gold', 'au', 'xau' ),
			'silver'    => array( 'silver', 'ag', 'xag' ),
			'platinum'  => array( 'platinum', 'pt', 'xpt' ),
			'palladium' => array( 'palladium', 'pd', 'xpd' ),
		);

		foreach ( $aliases as $metal => $names ) {
			$ask = $bid = $chg = $pct = null;
			foreach ( $names as $n ) {
				$ask = null !== $ask ? $ask : self::pick( $flat, array( "{$n}ask", "{$n}askprice", "{$n}spotask", "{$n}offer" ) );
				$bid = null !== $bid ? $bid : self::pick( $flat, array( "{$n}bid", "{$n}bidprice", "{$n}spotbid" ) );
				$chg = null !== $chg ? $chg : self::pick( $flat, array( "{$n}change", "{$n}changeamount", "{$n}chg", "{$n}netchange" ) );
				$pct = null !== $pct ? $pct : self::pick( $flat, array( "{$n}changepercent", "{$n}changepct", "{$n}percentchange", "{$n}pctchange", "{$n}changeperc" ) );
			}
			if ( null === $ask && null !== $bid ) {
				$ask = $bid;
			}
			if ( null !== $ask ) {
				if ( null === $pct && null !== $chg && ( $ask - $chg ) != 0 ) {
					$pct = $chg / ( $ask - $chg ) * 100;
				}
				$prices[ $metal ] = array(
					'ask'        => $ask,
					'bid'        => null !== $bid ? $bid : $ask,
					'change'     => null !== $chg ? $chg : 0.0,
					'change_pct' => null !== $pct ? $pct : 0.0,
				);
			}
		}

		return apply_filters( 'dgbp_parse_spot', $prices, $raw );
	}

	/**
	 * Extract the dealer ask price from a product price response.
	 */
	public static function parse_product_price( $raw ) {
		$flat   = self::flatten( $raw );
		$custom = self::norm_key( DGBP_Settings::get( 'product_price_key' ) );

		if ( $custom ) {
			$price = self::pick( $flat, array( $custom ) );
		} else {
			$price = self::pick( $flat, array( 'tiers1ask', 'tier1ask', 'ask', 'askprice', 'price', 'sellprice' ) );
			if ( null === $price ) {
				foreach ( $flat as $k => $v ) {
					if ( substr( $k, -3 ) === 'ask' && null !== self::to_number( $v ) ) {
						$price = self::to_number( $v );
						break;
					}
				}
			}
		}
		return apply_filters( 'dgbp_parse_product_price', $price, $raw );
	}

	/* --------------------------------------------------------------------
	 * Cached getters
	 * ----------------------------------------------------------------- */

	/**
	 * @return array { prices: array, updated: int, stale: bool, error?: string }
	 */
	public static function get_spot( $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( self::SPOT_TRANSIENT );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$raw = self::request( self::build_url( DGBP_Settings::get( 'spot_path' ) ) );
		if ( ! is_wp_error( $raw ) ) {
			$prices = self::parse_spot( $raw );
			if ( ! empty( $prices ) ) {
				// Hold if any metal moved suspiciously far since the last accepted price.
				$last  = get_option( self::SPOT_LAST_GOOD );
				$holds = array();
				if ( ! self::accepting() && is_array( $last ) && ! empty( $last['prices'] ) ) {
					foreach ( $prices as $metal => $p ) {
						if ( isset( $last['prices'][ $metal ]['ask'] ) && self::jumped( $last['prices'][ $metal ]['ask'], $p['ask'] ) ) {
							$holds[ 'spot:' . $metal ] = array(
								'label' => DGBP_Settings::METALS[ $metal ] . ' spot',
								'old'   => (float) $last['prices'][ $metal ]['ask'],
								'new'   => (float) $p['ask'],
								'time'  => time(),
							);
						}
					}
				}
				self::set_holds( 'spot:', $holds );
				if ( $holds ) {
					$held          = $last;
					$held['stale'] = false;
					$held['held']  = true;
					set_transient( self::SPOT_TRANSIENT, $held, max( 5, (int) DGBP_Settings::get( 'cache_seconds' ) ) );
					return $held;
				}

				$result = array(
					'prices'  => $prices,
					'updated' => time(),
					'stale'   => false,
				);
				set_transient( self::SPOT_TRANSIENT, $result, max( 5, (int) DGBP_Settings::get( 'cache_seconds' ) ) );
				update_option( self::SPOT_LAST_GOOD, $result, false );
				return $result;
			}
			$raw = new WP_Error( 'dgbp_parse', 'Could not find metal prices in the FizTrade response.' );
		}

		// Fall back to last good data.
		$last = get_option( self::SPOT_LAST_GOOD );
		$out  = is_array( $last ) ? $last : array( 'prices' => array(), 'updated' => 0 );
		$out['stale'] = true;
		$out['error'] = $raw->get_error_message();
		// Short negative cache so a dead API isn't hammered.
		set_transient( self::SPOT_TRANSIENT, $out, 10 );
		return $out;
	}

	/**
	 * Dealer ask for a Dillon Gage product code.
	 *
	 * @return array { price: float|null, updated: int, stale: bool }
	 */
	public static function get_product_price( $code, $force = false ) {
		$code = trim( (string) $code );
		if ( '' === $code ) {
			return array( 'price' => null, 'updated' => 0, 'stale' => true );
		}
		$tkey = 'dgbp_p_' . md5( $code );

		if ( ! $force ) {
			$cached = get_transient( $tkey );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$raw   = self::request( self::build_url( DGBP_Settings::get( 'product_path' ), $code ) );
		$price = is_wp_error( $raw ) ? null : self::parse_product_price( $raw );
		$last  = get_option( self::PROD_LAST_GOOD, array() );
		$last  = is_array( $last ) ? $last : array();

		if ( null !== $price && $price > 0 && ! self::accepting() && isset( $last[ $code ]['price'] ) && self::jumped( $last[ $code ]['price'], $price ) ) {
			self::set_holds(
				'code:' . $code,
				array(
					'code:' . $code => array(
						'label' => 'Dillon Gage ' . $code,
						'old'   => (float) $last[ $code ]['price'],
						'new'   => (float) $price,
						'time'  => time(),
					),
				)
			);
			$held          = $last[ $code ];
			$held['stale'] = false;
			$held['held']  = true;
			set_transient( $tkey, $held, max( 5, (int) DGBP_Settings::get( 'cache_seconds' ) ) );
			return $held;
		}

		if ( null !== $price && $price > 0 ) {
			self::set_holds( 'code:' . $code, array() );
			$result = array( 'price' => $price, 'updated' => time(), 'stale' => false );
			set_transient( $tkey, $result, max( 5, (int) DGBP_Settings::get( 'cache_seconds' ) ) );
			$last[ $code ] = $result;
			update_option( self::PROD_LAST_GOOD, $last, false );
			return $result;
		}

		$out          = isset( $last[ $code ] ) ? $last[ $code ] : array( 'price' => null, 'updated' => 0 );
		$out['stale'] = true;
		set_transient( $tkey, $out, 10 );
		return $out;
	}

	/**
	 * Is a timestamp too old to sell against?
	 */
	public static function is_too_old( $updated ) {
		$max = max( 1, (int) DGBP_Settings::get( 'stale_minutes' ) ) * MINUTE_IN_SECONDS;
		return ! $updated || ( time() - (int) $updated ) > $max;
	}

	/**
	 * WP-Cron: keep caches warm.
	 */
	public static function cron_refresh() {
		self::get_spot( true );

		$codes = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => 200,
				'fields'         => 'ids',
				'meta_query'     => array(
					array( 'key' => '_dgbp_enabled', 'value' => 'yes' ),
					array( 'key' => '_dgbp_basis', 'value' => 'dg_product' ),
				),
			)
		);
		foreach ( $codes as $pid ) {
			$code = get_post_meta( $pid, '_dgbp_dg_code', true );
			if ( $code ) {
				self::get_product_price( $code, true );
			}
		}
	}
}
