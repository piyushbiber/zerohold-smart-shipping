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
		// Phase-6: Rate Normalization Fix (Pick Lowest)
		// API Response -> data -> available_courier_companies (Array)
		
		$couriers = $response['data']['available_courier_companies'] ?? [];
		
		if ( empty( $couriers ) || ! is_array( $couriers ) ) {
			// Fallback or error
			return new RateQuote([
				'base'     => 0, // Invalid
				'platform' => 'shiprocket'
			]);
		}

		$lowest_rate = null;
		$winner      = null;

		foreach ( $couriers as $courier ) {
			$rate = isset( $courier['freight_charge'] ) ? floatval( $courier['freight_charge'] ) : ( isset( $courier['rate'] ) ? floatval( $courier['rate'] ) : 0 );
			
			// Phase-7: Reject Zero Rates
			if ( $rate <= 0 ) {
				continue;
			}

			if ( is_null( $lowest_rate ) || $rate < $lowest_rate ) {
				$lowest_rate = $rate;
				$winner      = $courier;
			}
		}

		if ( ! $winner ) {
			return new RateQuote([ 'base' => 0, 'platform' => 'shiprocket' ]);
		}

		return new RateQuote([
			'base'     => $lowest_rate,
			'zone'     => $winner['zone'] ?? '', // SR response usually has zone?
			'edd'      => $winner['etd'] ?? $winner['edd'] ?? '',
			'courier'  => $winner['courier_name'] ?? 'Shiprocket',
			'courier_id'=> $winner['courier_company_id'] ?? '', // Useful if we need specific execution?
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
