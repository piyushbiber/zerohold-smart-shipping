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
		// Flatten the quotes (mapped by platform) into a single list
		$all_rates = [];
		foreach ( $quotes as $platform_rates ) {
			if ( is_array( $platform_rates ) ) {
				$all_rates = array_merge( $all_rates, $platform_rates );
			}
		}

		// NUCLEAR FIX: Enforce Object Type for all rates
		// This handles cases where adapters might return arrays instead of RateQuote objects
		foreach ( $all_rates as $key => $rate ) {
			if ( is_array( $rate ) ) {
				$all_rates[ $key ] = new \Zerohold\Shipping\Models\RateQuote( $rate );
			}
		}

		if ( empty( $all_rates ) ) {
			return null;
		}

		// Sort by price (base cost)
		usort( $all_rates, function( $a, $b ) {
            // Robust access: Support both Object (expected) and Array (fallback)
            $base_a = is_object($a) ? $a->base : ( $a['base'] ?? 0 );
            $base_b = is_object($b) ? $b->base : ( $b['base'] ?? 0 );

			if ( $base_a == $base_b ) {
				return 0;
			}
			return ( $base_a < $base_b ) ? -1 : 1;
		} );

		// Debug Logging (Temporary)
		foreach ( $all_rates as $rate ) {
			error_log( sprintf( 'ZSS Rate - %s: %s Rs - %s', ucfirst( $rate->platform ), $rate->base, $rate->courier ) );
		}

		$winner = $all_rates[0];
		error_log( sprintf( 'ZSS Winner: %s (%s) at %s Rs', ucfirst( $winner->platform ), $winner->courier, $winner->base ) );

		// Return the winner
		return $winner;
	}
}
