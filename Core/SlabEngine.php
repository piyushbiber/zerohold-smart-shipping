<?php

namespace Zerohold\Shipping\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SlabEngine
 * 
 * Handles the calculation of chargeable weight based on dead weight 
 * and volumetric dimensions (L x W x H).
 */
class SlabEngine {

	/**
	 * Calculate the final chargeable weight slab.
	 * 
	 * @param float $dead_weight In KG
	 * @param float $length      In CM
	 * @param float $width       In CM
	 * @param float $height      In CM
	 * @return array {
	 *    dead_weight: float,
	 *    volumetric_weight: float,
	 *    chargeable_weight: float,
	 *    slab: float (Rounded to nearest 0.5kg)
	 * }
	 */
	public static function calculate( $dead_weight, $length, $width, $height ) {
		$dead_weight = (float) $dead_weight;
		$length      = (float) $length;
		$width       = (float) $width;
		$height      = (float) $height;

		// 1. Calculate Volumetric Weight (Divisor 5000 is standard for most Indian couriers)
		$volumetric_weight = ( $length * $width * $height ) / 5000;

		// 2. Determine Chargeable Weight (Maximum of both)
		$chargeable_weight = max( $dead_weight, $volumetric_weight );

		// 3. Round up to the nearest 0.5kg Slab
		// Formula: ceil(weight * 2) / 2
		// Examples: 0.2 -> 0.5, 0.6 -> 1.0, 1.1 -> 1.5
		$slab = ceil( $chargeable_weight * 2 ) / 2;

		// Security Guard: Garment rule hard limit at 10kg
		if ( $slab > 10 ) {
			$slab = 10.0;
		}

		return [
			'dead_weight'       => $dead_weight,
			'volumetric_weight' => round( $volumetric_weight, 3 ),
			'chargeable_weight' => round( $chargeable_weight, 3 ),
			'slab'              => (float) $slab
		];
	}

	/**
	 * Get a unique key for caching based on the slab and origin.
	 * 
	 * @param int    $vendor_id
	 * @param string $origin_pincode
	 * @param float  $slab
	 * @return string MD5 hash
	 */
	public static function getCacheKey( $vendor_id, $origin_pincode, $slab ) {
		return md5( $vendor_id . '_' . $origin_pincode . '_' . number_format( (float) $slab, 2 ) );
	}
}
