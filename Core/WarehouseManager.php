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
			$meta_key = 'zh_sr_warehouse_id';
			$adapter  = new ShiprocketAdapter();
		} elseif ( $platform === 'bigship' ) {
			$meta_key = 'zh_bs_warehouse_id';
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
}
