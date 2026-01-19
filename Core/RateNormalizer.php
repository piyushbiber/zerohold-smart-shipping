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
		return new RateQuote([
			'base'     => isset($response['freight_charges']) ? $response['freight_charges'] : 0,
			'zone'     => isset($response['zone']) ? $response['zone'] : '', // Zone resolver if needed later
			'edd'      => isset($response['estimated_delivery_days']) ? $response['estimated_delivery_days'] : '',
			'courier'  => isset($response['courier_name']) ? $response['courier_name'] : 'Nimbus',
			'platform' => 'nimbus', // Explicitly tag platform
		]);
	}
}
