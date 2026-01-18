<?php

namespace Zerohold\Shipping\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Zerohold\Shipping\Models\RateQuote;

/**
 * Class RateNormalizer
 * 
 * Unifies rate schemas from different carriers (Shiprocket, Nimbuspost, etc.)
 */
class RateNormalizer {

	/**
	 * Normalizes Shiprocket serviceability response into a RateQuote model.
	 * 
	 * @param array $response
	 * @return RateQuote
	 */
	public function normalizeShiprocket( $response ) {
		// This assumes Shiprocket response format
		return new RateQuote([
			'base'    => isset($response['freight_charge']) ? $response['freight_charge'] : 0,
			'zone'    => isset($response['zone']) ? $response['zone'] : '',
			'edd'     => isset($response['edd']) ? $response['edd'] : '',
			'courier' => isset($response['courier_name']) ? $response['courier_name'] : 'Shiprocket'
		]);
	}

	/**
	 * Normalizes Nimbuspost response into a RateQuote model.
	 * 
	 * @param array $response
	 * @return RateQuote
	 */
	public function normalizeNimbus( $response ) {
		// Future implementation
		return new RateQuote([]);
	}
}
