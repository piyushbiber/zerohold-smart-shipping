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
			'courier'  => isset($response['courier_name']) ? $response['courier_name'] : 'Shiprocket',
			'platform' => 'shiprocket',
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

	/**
	 * Normalizes BigShip response into a RateQuote model.
	 * 
	 * @param array $response
	 * @return RateQuote
	 */
	public function normalizeBigShip( $response ) {
		// Mapping based on common BigShip response fields
		return new RateQuote([
			'base'     => isset($response['total_charges']) ? $response['total_charges'] : ( isset($response['freight_charges']) ? $response['freight_charges'] : 0 ),
			'zone'     => isset($response['zone']) ? $response['zone'] : '',
			'edd'      => isset($response['edd']) ? $response['edd'] : '',
			'courier'  => isset($response['courier_name']) ? $response['courier_name'] : 'BigShip',
			'courier_id' => isset($response['courier_id']) ? $response['courier_id'] : '', // Capture ID for booking
			'platform' => 'bigship',
		]);
	}
}
