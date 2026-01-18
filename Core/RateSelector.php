<?php

namespace Zerohold\Shipping\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RateSelector {
	/**
	 * Selects the best available rate.
	 * 
	 * @param array $quotes
	 * @return mixed
	 */
	public function selectBestRate( array $quotes ) {
		// For now: return shiprocket as the primary choice
		return isset( $quotes['shiprocket'] ) ? $quotes['shiprocket'] : reset( $quotes );
	}
}

