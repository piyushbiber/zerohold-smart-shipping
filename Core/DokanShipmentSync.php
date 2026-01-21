<?php

namespace Zerohold\Shipping\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class DokanShipmentSync
 * Handles synchronization of tracking data with Dokan Pro's shipment system.
 */
class DokanShipmentSync {

	/**
	 * Sync shipment data to Dokan.
	 *
	 * @param int    $order_id     The WooCommerce Order ID.
	 * @param string $awb          Tracking number (AWB).
	 * @param string $courier      Courier name (e.g. Shiprocket, BigShip).
	 * @param string $tracking_url Tracking URL.
	 * @return int|bool The shipment ID on success, false on failure.
	 */
	public static function sync_shipment( $order_id, $awb, $courier, $tracking_url ) {
		global $wpdb;

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			error_log( "ZSS ERROR: Order not found for sync ($order_id)" );
			return false;
		}

		// 1. Get Vendor ID
		$vendor_id = dokan_get_seller_id_by_order( $order_id );
		if ( ! $vendor_id ) {
			// Fallback: Try to get from order items if it's a sub-order
			$vendor_id = $order->get_meta( '_dokan_vendor_id', true );
		}

		if ( ! $vendor_id ) {
			error_log( "ZSS ERROR: Vendor ID not found for order $order_id" );
			return false;
		}

		// 2. Prepare Item Qty Mapping (All items in order)
		$item_qty_map = [];
		foreach ( $order->get_items() as $item_id => $item ) {
			$item_qty_map[ $item_id ] = $item->get_quantity();
		}

		// 3. Prepare Data for Insertion
		// The table name discovered via investigation
		$table_name = $wpdb->prefix . 'dokan_shipping_tracking';
		
		// Defensive: Check if table exists before inserting
		if ( $wpdb->get_var( "SHOW TABLES LIKE '$table_name'" ) != $table_name ) {
			error_log( "ZSS WARNING: Dokan Shipping Tracking table ($table_name) does not exist. Sync skipped." );
			return false;
		}

		$data = [
			'order_id'        => (int) $order_id,
			'seller_id'       => (int) $vendor_id,
			'provider'        => 'sp-other',
			'provider_label'  => $courier,
			'provider_url'    => $tracking_url,
			'number'          => $awb,
			'date'            => current_time( 'M j, Y' ), // Human readable usually for this table
			'shipping_status' => 'ss_on_the_way',
			'status_label'    => __( 'On the way', 'dokan' ),
			'is_notify'       => 'no',
			'item_qty'        => wp_json_encode( $item_qty_map ),
			'status'          => 1,
		];

		$format = [
			'%d', // order_id
			'%d', // seller_id
			'%s', // provider
			'%s', // provider_label
			'%s', // provider_url
			'%s', // number
			'%s', // date
			'%s', // shipping_status
			'%s', // status_label
			'%s', // is_notify
			'%s', // item_qty
			'%d', // status
		];

		error_log( "ZSS DEBUG: Syncing shipment to Dokan ($table_name) for order $order_id" );
		
		$result = $wpdb->insert( $table_name, $data, $format );

		error_log( "ZSS DEBUG: wpdb->insert result: " . var_export( $result, true ) );

		if ( $result === false ) {
			error_log( "ZSS ERROR: Failed to insert shipment into $table_name." );
			error_log( "ZSS ERROR: Last Error: " . $wpdb->last_error );
			error_log( "ZSS ERROR: Table attempted: $table_name" );
			return false;
		}

		$shipment_id = $wpdb->insert_id;
		error_log( "ZSS SUCCESS: Dokan shipment created (ID: $shipment_id) for order $order_id" );

		return $shipment_id;
	}
}
