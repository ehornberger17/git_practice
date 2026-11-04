<?php
/**
 * Florida sales-tax rules for bullion products.
 *
 * Sets each bullion product's WooCommerce tax status automatically:
 *   - Gold, silver, platinum bars / ingots / rounds ............ exempt  (§ 212.08(7)(ww), F.S., no minimum since 8/1/2025)
 *   - Gold coins >= 99.5% pure, silver coins >= 99.9% pure ...... exempt  (CS/HB 999, legal tender, from 7/1/2026)
 *   - U.S. legal tender coins below those purities .............. per setting (gray area — confirm with your CPA)
 *   - Palladium, other coins, numismatics, jewelry/other ........ taxable
 *
 * A per-product override always wins. Not tax advice.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DGBP_Tax {

	const FORMS = array(
		'bar'   => 'Bar / ingot',
		'round' => 'Round',
		'coin'  => 'Coin',
		'other' => 'Other (numismatic, jewelry, set…)',
	);

	public static function init() {
		add_filter( 'woocommerce_product_get_tax_status', array( __CLASS__, 'filter_tax_status' ), 99, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'line_item_meta' ), 20, 3 );
	}

	/** Purity as a fraction: accepts 0.9999 or 99.99. */
	public static function norm_purity( $p ) {
		$p = (float) $p;
		return $p > 1 ? $p / 100 : $p;
	}

	/**
	 * Pure rule function (no WordPress calls) so it can be unit-tested.
	 *
	 * @return array { exempt: bool, reason: string }
	 */
	public static function classify_florida( $metal, $form, $purity, $us_legal_tender, $gray_exempt ) {
		$purity = self::norm_purity( $purity );

		if ( 'palladium' === $metal ) {
			return array( 'exempt' => false, 'reason' => 'Palladium is not covered by Florida\'s bullion exemption' );
		}
		if ( in_array( $form, array( 'bar', 'round' ), true ) && in_array( $metal, array( 'gold', 'silver', 'platinum' ), true ) ) {
			return array( 'exempt' => true, 'reason' => ucfirst( $metal ) . ' bullion ' . $form . ' — § 212.08(7)(ww), F.S.' );
		}
		if ( 'coin' === $form ) {
			if ( 'gold' === $metal && $purity >= 0.995 ) {
				return array( 'exempt' => true, 'reason' => 'Gold coin ≥ 99.5% — Florida legal tender (HB 999)' );
			}
			if ( 'silver' === $metal && $purity >= 0.999 ) {
				return array( 'exempt' => true, 'reason' => 'Silver coin ≥ 99.9% — Florida legal tender (HB 999)' );
			}
			if ( $us_legal_tender ) {
				return $gray_exempt
					? array( 'exempt' => true, 'reason' => 'U.S. legal tender coin (exempt per your setting)' )
					: array( 'exempt' => false, 'reason' => 'U.S. legal tender coin below HB 999 purity — taxed per your setting' );
			}
			return array( 'exempt' => false, 'reason' => 'Coin below Florida legal-tender purity and not U.S. legal tender' );
		}
		if ( '' === (string) $form ) {
			return array( 'exempt' => false, 'reason' => 'Form not set — taxed until you choose bar, round or coin' );
		}
		return array( 'exempt' => false, 'reason' => 'Not bullion or a qualifying coin' );
	}

	/**
	 * Result for a product, or null if the plugin isn't managing its tax.
	 *
	 * @return array|null { exempt, reason, source: auto|override }
	 */
	public static function for_product( $product_id ) {
		if ( 'florida' !== DGBP_Settings::get( 'tax_rules' ) || ! DGBP_Pricing::is_bullion( $product_id ) ) {
			return null;
		}
		$override = get_post_meta( $product_id, '_dgbp_tax_override', true );
		if ( 'exempt' === $override || 'taxable' === $override ) {
			return array(
				'exempt' => 'exempt' === $override,
				'reason' => 'Set manually on this product',
				'source' => 'override',
			);
		}
		$r           = self::classify_florida(
			get_post_meta( $product_id, '_dgbp_metal', true ) ? get_post_meta( $product_id, '_dgbp_metal', true ) : 'gold',
			get_post_meta( $product_id, '_dgbp_form', true ),
			get_post_meta( $product_id, '_dgbp_purity', true ),
			'yes' === get_post_meta( $product_id, '_dgbp_us_legal_tender', true ),
			'exempt' === DGBP_Settings::get( 'tax_gray_coins' )
		);
		$r['source'] = 'auto';
		return $r;
	}

	public static function filter_tax_status( $status, $product ) {
		if ( ! $product instanceof WC_Product ) {
			return $status;
		}
		$r = self::for_product( $product->get_id() );
		if ( null === $r ) {
			return $status;
		}
		return $r['exempt'] ? 'none' : 'taxable';
	}

	/** Keep the tax decision on each order line for your records. */
	public static function line_item_meta( $item, $cart_item_key, $values ) {
		$product = isset( $values['data'] ) ? $values['data'] : null;
		if ( ! $product ) {
			return;
		}
		$r = self::for_product( $product->get_id() );
		if ( $r ) {
			$item->add_meta_data( '_dgbp_fl_tax', ( $r['exempt'] ? 'Exempt: ' : 'Taxable: ' ) . $r['reason'], true );
		}
	}
}
