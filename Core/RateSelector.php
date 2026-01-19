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

		if ( empty( $all_rates ) ) {
			return null;
		}

		// Sort by price (base cost)
		usort( $all_rates, function( $a, $b ) {
			if ( $a->base == $b->base ) {
				return 0;
			}
			return ( $a->base < $b->base ) ? -1 : 1;
			return ( $a->base < $b->base ) ? -1 : 1;
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
