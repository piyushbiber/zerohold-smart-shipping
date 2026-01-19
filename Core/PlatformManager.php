<?php

namespace Zerohold\Shipping\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Zerohold\Shipping\Platforms\ShiprocketAdapter;
use Zerohold\Shipping\Platforms\NimbuspostAdapter;

class PlatformManager {

	/**
	 * Returns an array of enabled platform adapters.
	 * 
	 * @return array
	 */
	public static function getEnabledPlatforms() {
		// Option A: Park Nimbus by excluding it from this list.
		// Future: enabledPlatforms += Nimbus
		
		$platforms = [
			'shiprocket' => new ShiprocketAdapter(),
			'bigship'    => new \Zerohold\Shipping\Platforms\BigShipAdapter(),
			// 'nimbus'     => new NimbuspostAdapter(), // Parked
		];

		return $platforms;
	}
}
