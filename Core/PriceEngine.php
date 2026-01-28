<?php

namespace Zerohold\Shipping\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PriceEngine {
	
	/**
	 * Calculates the final share for a specific user type (vendor or retailer).
	 * 
	 * @param float  $base_price Original carrier price.
	 * @param string $type       'vendor' or 'retailer'
	 * @param int    $user_id    The user ID to check for exclusions.
	 * @return float Adjusted price (share + cap).
	 */
	public static function calculate_share_and_cap( $base_price, $type = 'vendor', $user_id = 0 ) {
		if ( $base_price <= 0 ) return 0;

		// 1. Calculate Base Share
		$share_percent = (float) get_option( "zh_{$type}_shipping_share_percentage", 50 );
		$share_amount  = $base_price * ( $share_percent / 100 );

		// 2. Check Exclusions
		$excluded_emails_str = get_option( "zh_excluded_{$type}_emails", "" );
		if ( ! empty( $excluded_emails_str ) && $user_id ) {
			$user = get_user_by( "id", $user_id );
			if ( $user ) {
				$user_email = strtolower( trim( $user->user_email ) );
				$excluded_list = array_map( "trim", explode( ",", strtolower( $excluded_emails_str ) ) );
				if ( in_array( $user_email, $excluded_list ) ) {
					return $share_amount; // Return base share without cap
				}
			}
		}

		// 3. Apply Hidden Profit Cap
		$option_name = "zh_{$type}_hidden_cap_slabs";
		
		// Fallback for legacy vendor naming (zh_hidden_cap_slabs)
		if ( $type === 'vendor' && ! get_option( $option_name ) ) {
			$option_name = "zh_hidden_cap_slabs";
		}

		$slabs = get_option( $option_name, [] );
		if ( empty( $slabs ) ) {
			return $share_amount;
		}

		foreach ( $slabs as $slab ) {
			$min = isset( $slab['min'] ) ? floatval( $slab['min'] ) : 0;
			$max = ( isset( $slab['max'] ) && $slab['max'] !== '' ) ? floatval( $slab['max'] ) : PHP_FLOAT_MAX;
			$pct = isset( $slab['percent'] ) ? floatval( $slab['percent'] ) : 0;

			// Logic: Inclusive of Max (<=) to handle case where share hits the exact bound
			if ( $share_amount >= $min && $share_amount <= $max ) {
				$cap = $share_amount * ( $pct / 100 );
				return $share_amount + $cap;
			}
		}

		return $share_amount;
	}

	/**
	 * Applies the slab-based margin on base freight.
	 * 
	 * @deprecated Use calculate_share_and_cap
	 * @param float $base
	 * @return float
	 */
	public function applySlabMargin( $base ) {
		if ( $base <= 80 ) {
			return $base * 0.50;
		}
		if ( $base <= 120 ) {
			return $base * 0.40;
		}
		if ( $base <= 160 ) {
			return $base * 0.30;
		}
		return $base * 0.20; 
	}

	/**
	 * Computes the split shares for Vendor and Retailer.
	 * 
	 * @deprecated Use calculate_share_and_cap
	 * @param float $base
	 * @return array
	 */
	public function computeVendorRetailer( $base ) {
		$m = $this->applySlabMargin( $base );
		return [
			'vendor'   => ( $base / 2 ) + $m,
			'retailer' => ( $base / 2 ) + $m,
			'margin'   => 2 * $m
		];
	}
}

