<?php

namespace Zerohold\Shipping\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Zerohold\Shipping\Models\Shipment;
use Zerohold\Shipping\Platforms\ShiprocketAdapter;
use Zerohold\Shipping\Platforms\BigShipAdapter;
use Zerohold\Shipping\Core\RateSelector;

/**
 * Class StatelessShipping
 * 
 * Handles real-time shipping quotes without persisting data (Phase-1).
 */
class StatelessShipping {

	/**
	 * Fetches the best rate based on priority: Shiprocket -> BigShip.
	 * 
	 * @param Shipment $shipment
	 * @return \Zerohold\Shipping\Models\RateQuote|null
	 */
	public function getBestPriorityRate( Shipment $shipment ) {
		$selector = new RateSelector();

		// 1. Try Shiprocket (Primary)
		$sr_adapter = new ShiprocketAdapter();
		$sr_rates   = $sr_adapter->getRates( $shipment );

		if ( ! empty( $sr_rates['shiprocket'] ) ) {
			$winner = $selector->selectBestRate( $sr_rates );
			if ( $winner && $winner->base > 0 ) {
				return $winner;
			}
		}

		// 2. Try BigShip (Fallback)
		$bs_adapter = new BigShipAdapter();
		$bs_rates   = $bs_adapter->getRates( $shipment );

		if ( ! empty( $bs_rates ) ) {
			$winner = $selector->selectBestRate( [ 'bigship' => $bs_rates ] );
			if ( $winner && $winner->base > 0 ) {
				return $winner;
			}
		}

		return null;
	}
}
