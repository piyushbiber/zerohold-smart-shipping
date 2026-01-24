<?php

namespace Zerohold\Shipping\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class EstimateCache
 * 
 * Manages database-level caching for on-demand shipping estimates.
 * Implements 24-hour TTL and slab-based keying.
 */
class EstimateCache {

	private static $table_name = 'zh_rate_estimate_cache';

	/**
	 * Get the full table name with prefix.
	 */
	private static function getTableName() {
		global $wpdb;
		return $wpdb->prefix . self::$table_name;
	}

	/**
	 * Retrieve a valid estimate from the cache.
	 * 
	 * @param int    $vendor_id
	 * @param string $origin_pincode
	 * @param float  $slab
	 * @return array|null Returns estimate data or null if not found/expired
	 */
	public static function get( $vendor_id, $origin_pincode, $slab ) {
		global $wpdb;
		
		$slab_key = SlabEngine::getCacheKey( $vendor_id, $origin_pincode, $slab );
		$table    = self::getTableName();

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM $table WHERE vendor_id = %d AND origin_pincode = %s AND slab_key = %s LIMIT 1",
			$vendor_id,
			$origin_pincode,
			$slab_key
		), ARRAY_A );

		if ( ! $row ) {
			return null;
		}

		// TTL Check: 24 Hours
		$created_at = strtotime( $row['created_at'] );
		if ( ( time() - $created_at ) > ( 24 * HOUR_IN_SECONDS ) ) {
			return null; // Expired
		}

		$row['zone_data'] = json_decode( $row['zone_data_json'], true );
		return $row;
	}

	/**
	 * Store or refresh an estimate in the cache.
	 * 
	 * @param int    $vendor_id
	 * @param string $origin_pincode
	 * @param float  $slab
	 * @param float  $min_price
	 * @param float  $max_price
	 * @param array  $zone_data
	 * @return bool
	 */
	public static function set( $vendor_id, $origin_pincode, $slab, $min_price, $max_price, $zone_data ) {
		global $wpdb;

		$slab_key = SlabEngine::getCacheKey( $vendor_id, $origin_pincode, $slab );
		$table    = self::getTableName();

		$data = [
			'vendor_id'      => $vendor_id,
			'origin_pincode' => $origin_pincode,
			'slab_key'       => $slab_key,
			'min_price'      => $min_price,
			'max_price'      => $max_price,
			'zone_data_json' => wp_json_encode( $zone_data ),
			'created_at'     => current_time( 'mysql' )
		];

		// Use REPLACE INTO to handle unique key updates automatically
		return $wpdb->replace( $table, $data );
	}

	/**
	 * Force clear cache for a specific vendor or origin.
	 */
	public static function clear( $vendor_id = null ) {
		global $wpdb;
		$table = self::getTableName();

		if ( $vendor_id ) {
			$wpdb->delete( $table, [ 'vendor_id' => $vendor_id ] );
		} else {
			$wpdb->query( "TRUNCATE TABLE $table" );
		}
	}
}
