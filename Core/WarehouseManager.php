<?php

namespace Zerohold\Shipping\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Zerohold\Shipping\Platforms\ShiprocketAdapter;
use Zerohold\Shipping\Platforms\BigShipAdapter;

class WarehouseManager {

	/**
	 * Ensures a warehouse exists for the vendor on the specified platform.
	 * 
	 * @param \Zerohold\Shipping\Models\Shipment $shipment
	 * @param string $platform 'shiprocket' or 'bigship'
	 * @return string|false Warehouse ID or false on failure
	 */
	public static function ensureWarehouse( $shipment, $platform ) {
		if ( empty( $shipment->vendor_id ) ) {
			return false; // Cannot associate
		}

		$meta_key = '';
		$adapter  = null;

		if ( $platform === 'shiprocket' ) {
			$meta_key = '_sr_pickup_location';
			$adapter  = new ShiprocketAdapter();
		} elseif ( $platform === 'bigship' ) {
			$meta_key = '_bs_warehouse_id'; // User requested this key
			$adapter  = new BigShipAdapter();
		} else {
			return false;
		}

		// 1. Check Freezer (DB)
		$warehouse_id = get_user_meta( $shipment->vendor_id, $meta_key, true );
		if ( ! empty( $warehouse_id ) ) {
			return $warehouse_id;
		}

		// 2. Create Warehouse
		// We expect the adapter to have a createWarehouse method.
		// If not, we log error.
		if ( ! method_exists( $adapter, 'createWarehouse' ) ) {
			error_log( "ZSS ERROR: Adapter for $platform does not have createWarehouse method." );
			return false;
		}

		$new_id = $adapter->createWarehouse( $shipment );

		if ( $new_id && ! is_wp_error( $new_id ) ) {
			// 3. Freeze (Store)
			update_user_meta( $shipment->vendor_id, $meta_key, $new_id );
			return $new_id;
		}

		return false;
	}

	/**
	 * Hook Target: Mark vendor for warehouse refresh on profile update.
	 * 
	 * @param int $vendor_id
	 */
	public static function flagVendorForRefresh( $vendor_id ) {
		update_user_meta( $vendor_id, '_zh_warehouse_status', 'NEED_REFRESH' );
	}

	/**
	 * Checks if refresh is needed and executes it for all platforms.
	 * 
	 * @param \Zerohold\Shipping\Models\Shipment $shipment
	 */
	public static function checkAndRefresh( $shipment ) {
		if ( empty( $shipment->vendor_id ) ) {
			return;
		}

		$status = get_user_meta( $shipment->vendor_id, '_zh_warehouse_status', true );

		if ( $status === 'NEED_REFRESH' ) {
			error_log( "ZSS: Refreshing Warehouses for Vendor " . $shipment->vendor_id );
			
			// 1. Refresh Shiprocket
			$sr_adapter = new ShiprocketAdapter();
			$sr_id      = $sr_adapter->createWarehouse( $shipment );
			if ( $sr_id && ! is_wp_error( $sr_id ) ) {
				update_user_meta( $shipment->vendor_id, '_sr_pickup_location', $sr_id );
				error_log( "ZSS: Shiprocket Warehouse Refreshed: $sr_id" );
			}

			// 2. Refresh BigShip
			$bs_adapter = new BigShipAdapter();
			$bs_id      = $bs_adapter->createWarehouse( $shipment );
			if ( $bs_id && ! is_wp_error( $bs_id ) ) {
				update_user_meta( $shipment->vendor_id, '_bs_warehouse_id', $bs_id );
				error_log( "ZSS: BigShip Warehouse Refreshed: $bs_id" );
			}

			// 3. Freeze
			update_user_meta( $shipment->vendor_id, '_zh_warehouse_status', 'FROZEN' );
		}
	}
}
