<?php

namespace Zerohold\Shipping\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ZoneResolver
 * 
 * Handles shipping zone logic: vendor pincode -> retailer pincode -> zone.
 */
class ZoneResolver {

	/**
	 * Resolves the shipping zone based on origin and destination pincodes.
	 * Simplified version for internal logic.
	 * 
	 * @param string $origin_pincode
	 * @param string $destination_pincode
	 * @return string (A|B|C|D|E)
	 */
	public function resolve( $origin_pincode, $destination_pincode ) {
		// Rule 1: Same Pincode = A
		if ( $origin_pincode === $destination_pincode ) return 'A';

		// Rule 2: Intra-state vs Inter-state (A/B vs C/D)
		// This usually requires a full state map. For now we use the prefix-based example
		// or return a default 'C' for most cases.
		return 'C'; 
	}

	/**
	 * Returns representative destination pincodes for bulk rate fetching.
	 * These are static, admin-defined, and reused for all vendors.
	 * 
	 * @param string $origin_pin
	 * @return array
	 */
	public function zoneTable( $origin_pin ) {
		return [
			'A' => $origin_pin, // Intra-city
			'B' => '226001',    // Intra-state HUB (Lucknow Example)
			'C' => '110001',    // Nearby Metro (Delhi)
			'D' => '560001',    // Far Metro (Bangalore)
			'E' => '781001',    // Remote (Guwahati)
		];
	}

	/**
	 * Get human readable labels for Zones.
	 */
	public static function getZoneLabels() {
		return [
			'A' => 'Same City',
			'B' => 'Same State',
			'C' => 'Nearby States',
			'D' => 'Far States',
			'E' => 'Remote / NE / J&K'
		];
	}
}

