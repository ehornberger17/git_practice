<?php
/**
 * Public read-only REST endpoints used by the ticker and product price tables.
 * Only cached, already-public prices are exposed; the API token never leaves the server.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DGBP_REST {

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes() {
		register_rest_route(
			'dgbp/v1',
			'/spot',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'spot' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			'dgbp/v1',
			'/products',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'products' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'ids' => array( 'required' => true, 'type' => 'string' ),
				),
			)
		);
	}

	private static function no_cache( WP_REST_Response $r ) {
		$r->header( 'Cache-Control', 'no-store, max-age=0' );
		return $r;
	}

	public static function spot() {
		$spot   = DGBP_API::get_spot();
		$metals = (array) DGBP_Settings::get( 'ticker_metals' );
		$out    = array();
		foreach ( $metals as $metal ) {
			if ( isset( $spot['prices'][ $metal ] ) ) {
				$out[ $metal ] = $spot['prices'][ $metal ];
			}
		}
		return self::no_cache(
			new WP_REST_Response(
				array(
					'prices'  => $out,
					'updated' => (int) $spot['updated'],
					'stale'   => ! empty( $spot['stale'] ),
				)
			)
		);
	}

	public static function products( WP_REST_Request $req ) {
		if ( ! function_exists( 'wc_price' ) ) {
			return new WP_Error( 'dgbp_no_wc', 'WooCommerce is not active.', array( 'status' => 404 ) );
		}
		$ids = array_slice( array_filter( array_map( 'absint', explode( ',', (string) $req['ids'] ) ) ), 0, 50 );
		$out = array();
		foreach ( $ids as $id ) {
			$p = DGBP_Pricing::for_product( $id );
			if ( ! $p ) {
				continue;
			}
			$row = array( 'available' => $p['available'], 'updated' => $p['updated'] );
			foreach ( DGBP_Settings::TIERS as $tier ) {
				$row[ $tier ] = null === $p[ $tier ] ? null : html_entity_decode( wp_strip_all_tags( wc_price( $p[ $tier ] ) ) );
			}
			$out[ $id ] = $row;
		}
		return self::no_cache( new WP_REST_Response( array( 'products' => $out ) ) );
	}
}
