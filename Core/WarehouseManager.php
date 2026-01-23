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
		$meta_key = '';
		$adapter  = null;
		$user_id  = $shipment->vendor_id;
		$is_retailer = ! empty( $shipment->is_retailer_pickup );

		if ( $platform === 'shiprocket' ) {
			$meta_key = $is_retailer ? '_zh_rt_sr_pickup_location' : '_sr_pickup_location';
			$adapter  = new ShiprocketAdapter();
		} elseif ( $platform === 'bigship' ) {
			$meta_key = $is_retailer ? '_zh_rt_bs_warehouse_id' : '_bs_warehouse_id';
			$adapter  = new BigShipAdapter();
		} else {
			return false;
		}

		// If retailer, we might not have a vendor_id. We use a unique key for the retailer.
		// Use phone number or customer user id.
		$storage_id = $user_id;
		if ( $is_retailer ) {
			$storage_id = $shipment->retailer_id ?: 'RT_' . preg_replace('/[^0-9]/', '', $shipment->retailer_phone);
			// For Retailers, we might want to store in a global option or order meta if no user ID.
			// Implementing a search for user by phone for better reuse.
			if ( is_string($storage_id) && strpos($storage_id, 'RT_') === 0 ) {
				$warehouse_id = get_option( 'zh_rt_wh_' . $storage_id . '_' . $platform );
				if ( ! empty( $warehouse_id ) ) return $warehouse_id;
			}
		}

		// 1. Check Freezer (DB/User Meta)
		if ( is_numeric( $storage_id ) ) {
			$warehouse_id = get_user_meta( $storage_id, $meta_key, true );
			if ( ! empty( $warehouse_id ) ) {
				return $warehouse_id;
			}
		}

		// 2. Prepare Retailer Naming for Adapter
		if ( $is_retailer ) {
			$phone = preg_replace( '/[^0-9]/', '', $shipment->retailer_phone );
			$shipment->warehouse_internal_id = 'RT_CUST_' . $phone . '_WH';
		}

		// 3. Create Warehouse
		if ( ! method_exists( $adapter, 'createWarehouse' ) ) {
			error_log( "ZSS ERROR: Adapter for $platform does not have createWarehouse method." );
			return false;
		}

		$new_id = $adapter->createWarehouse( $shipment );

		if ( $new_id && ! is_wp_error( $new_id ) ) {
			// 4. Freeze (Store)
			if ( is_numeric( $storage_id ) ) {
				update_user_meta( $storage_id, $meta_key, $new_id );
			} elseif ( is_string($storage_id) && strpos($storage_id, 'RT_') === 0 ) {
				update_option( 'zh_rt_wh_' . $storage_id . '_' . $platform, $new_id );
			}
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
