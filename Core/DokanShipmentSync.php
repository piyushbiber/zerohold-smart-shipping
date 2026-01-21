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
		$table_name = $wpdb->prefix . 'dokan_vendor_order_shipment';
		
		$data = [
			'order_id'        => $order_id,
			'vendor_id'       => $vendor_id,
			'provider'        => 'sp-other', // Always "Other"
			'provider_label'  => $courier,
			'number'          => $awb,
			'date'            => current_time( 'mysql' ),
			'shipping_status' => 'ss_on_the_way', // "On the way"
			'status_label'    => __( 'On the way', 'dokan' ),
			'provider_url'    => $tracking_url,
			'item_qty'        => wp_json_encode( $item_qty_map ),
			'comments'        => '',
		];

		$format = [
			'%d', // order_id
			'%d', // vendor_id
			'%s', // provider
			'%s', // provider_label
			'%s', // number
			'%s', // date
			'%s', // shipping_status
			'%s', // status_label
			'%s', // provider_url
			'%s', // item_qty
			'%s', // comments
		];

		error_log( "ZSS DEBUG: Syncing shipment to Dokan for order $order_id" );
		
		$result = $wpdb->insert( $table_name, $data, $format );

		if ( $result === false ) {
			error_log( "ZSS ERROR: Failed to insert shipment into $table_name. Error: " . $wpdb->last_error );
			return false;
		}

		$shipment_id = $wpdb->insert_id;
		error_log( "ZSS SUCCESS: Dokan shipment created (ID: $shipment_id) for order $order_id" );

		// 4. Add Shipment Timeline Update (Optional but better for UI consistency)
		self::add_timeline_update( $order_id, $shipment_id, $courier, $awb );

		return $shipment_id;
	}

	/**
	 * Add an entry to the shipment timeline.
	 */
	private static function add_timeline_update( $order_id, $shipment_id, $courier, $awb ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'dokan_vendor_order_shipment_info';
		
		// Check if table exists (Dokan Pro timeline table)
		if ( $wpdb->get_var( "SHOW TABLES LIKE '$table_name'" ) != $table_name ) {
			return;
		}

		$data = [
			'order_id'    => $order_id,
			'shipment_id' => $shipment_id,
			'comment_id'  => 0, // Not linked to a standard comment
			'status'      => 'ss_on_the_way',
			'date'        => current_time( 'mysql' ),
		];

		$wpdb->insert( $table_name, $data, [ '%d', '%d', '%d', '%s', '%s' ] );
	}
}
