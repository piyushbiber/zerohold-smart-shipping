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
		// Fix: Map 'total_shipping_charges' (from debug logs) to base
		$base = isset($response['total_shipping_charges']) ? (float) $response['total_shipping_charges'] : ( isset($response['freight_charges']) ? (float) $response['freight_charges'] : 0 );
		$courier_name = isset($response['courier_name']) ? trim($response['courier_name']) : '';

        // DEBUG: Inspect extraction
        error_log( "ZSS DEBUG: normalizeBigShip Input Keys: " . implode(',', array_keys($response)) );
        error_log( "ZSS DEBUG: Extracted Base: $base, Courier: $courier_name" );

        // Strict Filter: Reject invalid rates immediately
        if ( $base <= 0 || empty( $courier_name ) ) {
            error_log( "ZSS DEBUG WARNING: Dropping Invalid BigShip Rate. Base: $base, Courier: $courier_name" );
            return null;
        }

		return new RateQuote([
			'base'     => $base,
			'zone'     => isset($response['zone']) ? $response['zone'] : '',
			'edd'      => isset($response['edd']) ? $response['edd'] : '',
			'courier'  => $courier_name,
			'courier_id' => isset($response['courier_id']) ? $response['courier_id'] : '', // Capture ID for booking
			'platform' => 'bigship',
		]);
	}
}
