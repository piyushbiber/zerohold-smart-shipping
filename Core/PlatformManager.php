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
		// Check which platforms are enabled via admin settings
		$shiprocket_enabled = get_option( 'zh_platform_shiprocket_enabled', 1 );
		$bigship_enabled = get_option( 'zh_platform_bigship_enabled', 1 );
		
		$platforms = [];
		
		// Only include enabled platforms
		if ( $shiprocket_enabled ) {
			$platforms['shiprocket'] = new ShiprocketAdapter();
		}
		
		if ( $bigship_enabled ) {
			$platforms['bigship'] = new \Zerohold\Shipping\Platforms\BigShipAdapter();
		}
		
		// Fallback: If both are disabled (shouldn't happen due to validation), enable both
		if ( empty( $platforms ) ) {
			error_log( 'ZSS WARNING: All platforms disabled. Enabling both as fallback.' );
			$platforms['shiprocket'] = new ShiprocketAdapter();
			$platforms['bigship'] = new \Zerohold\Shipping\Platforms\BigShipAdapter();
		}

		return $platforms;
	}
}
