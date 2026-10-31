<?php
/**
 * Bullion price calculation.
 *
 * Wire price  = basis + premium
 *   basis     = spot ask × troy oz   (basis "spot")
 *            or Dillon Gage product ask (basis "dg_product")
 *   premium   = $ per oz × oz | flat $ per item | % of basis
 * Debit price = wire × (1 + debit %)
 * Credit price= wire × (1 + credit %)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DGBP_Pricing {

	private static $cache = array();

	/**
	 * Pure calculation (no WordPress calls) so it can be unit-tested.
	 */
	public static function compute( $basis, $oz, $premium_type, $premium_value, $debit_pct, $credit_pct, $decimals = 2 ) {
		$basis         = (float) $basis;
		$oz            = (float) $oz;
		$premium_value = (float) $premium_value;

		switch ( $premium_type ) {
			case 'flat':
				$premium = $premium_value;
				break;
			case 'percent':
				$premium = $basis * $premium_value / 100;
				break;
			case 'per_oz':
			default:
				$premium = $premium_value * $oz;
				break;
		}

		$wire = round( $basis + $premium, $decimals );

		return array(
			'wire'   => $wire,
			'debit'  => round( $wire * ( 1 + (float) $debit_pct / 100 ), $decimals ),
			'credit' => round( $wire * ( 1 + (float) $credit_pct / 100 ), $decimals ),
		);
	}

	public static function is_bullion( $product_id ) {
		return 'yes' === get_post_meta( $product_id, '_dgbp_enabled', true );
	}

	/**
	 * Tier prices for a product.
	 *
	 * @return array|null { wire, debit, credit, updated, stale, available, spot } or null if not a bullion product.
	 */
	public static function for_product( $product_id ) {
		$product_id = (int) $product_id;
		if ( isset( self::$cache[ $product_id ] ) ) {
			return self::$cache[ $product_id ];
		}
		if ( ! self::is_bullion( $product_id ) ) {
			return self::$cache[ $product_id ] = null;
		}

		$m      = function ( $key ) use ( $product_id ) {
			return get_post_meta( $product_id, $key, true );
		};
		$metal  = $m( '_dgbp_metal' ) ? $m( '_dgbp_metal' ) : 'gold';
		$oz     = (float) $m( '_dgbp_weight_oz' );
		$basis  = $m( '_dgbp_basis' ) ? $m( '_dgbp_basis' ) : 'spot';
		$ptype  = $m( '_dgbp_premium_type' ) ? $m( '_dgbp_premium_type' ) : 'per_oz';
		$pval   = (float) $m( '_dgbp_premium_value' );
		$dpct   = '' !== $m( '_dgbp_debit_pct' ) ? (float) $m( '_dgbp_debit_pct' ) : (float) DGBP_Settings::get( 'debit_pct' );
		$cpct   = '' !== $m( '_dgbp_credit_pct' ) ? (float) $m( '_dgbp_credit_pct' ) : (float) DGBP_Settings::get( 'credit_pct' );

		$base_amount = null;
		$updated     = 0;
		$spot_ask    = null;

		if ( 'dg_product' === $basis ) {
			$p           = DGBP_API::get_product_price( $m( '_dgbp_dg_code' ) );
			$base_amount = $p['price'];
			$updated     = (int) $p['updated'];
		} else {
			$spot = DGBP_API::get_spot();
			if ( isset( $spot['prices'][ $metal ]['ask'] ) && $oz > 0 ) {
				$spot_ask    = (float) $spot['prices'][ $metal ]['ask'];
				$base_amount = $spot_ask * $oz;
				$updated     = (int) $spot['updated'];
			}
		}

		if ( null === $base_amount || $base_amount <= 0 ) {
			return self::$cache[ $product_id ] = array(
				'wire'      => null,
				'debit'     => null,
				'credit'    => null,
				'updated'   => 0,
				'stale'     => true,
				'available' => false,
				'spot'      => null,
			);
		}

		$decimals = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;
		$prices   = self::compute( $base_amount, $oz, $ptype, $pval, $dpct, $cpct, $decimals );
		$too_old  = DGBP_API::is_too_old( $updated );

		$result = array_merge(
			$prices,
			array(
				'updated'   => $updated,
				'stale'     => $too_old,
				'available' => ! $too_old,
				'spot'      => $spot_ask,
			)
		);

		return self::$cache[ $product_id ] = apply_filters( 'dgbp_product_prices', $result, $product_id );
	}

	public static function flush() {
		self::$cache = array();
	}
}
