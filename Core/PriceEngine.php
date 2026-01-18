<?php

namespace Zerohold\Shipping\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PriceEngine {
	
	/**
	 * Applies the slab-based margin on base freight.
	 * 
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
		return $base * 0.20; // floor for 160+ and 200+
	}

	/**
	 * Computes the split shares for Vendor and Retailer.
	 * 
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

	public function calculatePrice( $rate ) {
		// Historical method, can be bridged to computeVendorRetailer if needed
		return $this->computeVendorRetailer( $rate );
	}
}

