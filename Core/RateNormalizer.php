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
		// Mapping: Try standard flat keys first, then nested structure
        
        // 1. Base Price
		$base = 0;
        if ( isset( $response['total_shipping_charges'] ) ) {
            $base = (float) $response['total_shipping_charges'];
        } elseif ( isset( $response['freight_charges'] ) ) {
            $base = (float) $response['freight_charges'];
        } elseif ( isset( $response['rate']['total_shipping_charges'] ) ) { // Nested check
            $base = (float) $response['rate']['total_shipping_charges'];
        }
        
        // 2. Courier Name
		$courier_name = '';
        if ( isset( $response['courier_name'] ) ) {
            $courier_name = trim( $response['courier_name'] );
        } elseif ( isset( $response['courier']['courier_name'] ) ) { // Nested check
            $courier_name = trim( $response['courier']['courier_name'] );
        } elseif ( isset( $response['courier_company'] ) ) {
             $courier_name = trim( $response['courier_company'] );
        }

        // Strict Filter: Reject invalid rates
        // We reject if base is 0 OR if courier is empty.
        if ( $base <= 0 || empty( $courier_name ) ) {
            return null;
        }

		$quote = new RateQuote([
			'base'     => $base,
			'zone'     => isset($response['zone']) ? $response['zone'] : '',
			'edd'      => isset($response['edd']) ? $response['edd'] : '',
			'courier'  => $courier_name,
			'courier_id' => isset($response['courier_id']) ? $response['courier_id'] : '', 
			'platform' => 'bigship',
		]);
        
        // Verify Object
        
        return $quote;
	}
}
