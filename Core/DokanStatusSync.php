<?php
/**
 * DokanStatusSync Class
 * 
 * Handles synchronization and display of custom order statuses (RMA) in Dokan Vendor Dashboard.
 */

namespace Zerohold\Shipping\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DokanStatusSync {

	public function __construct() {
		// 1. Register custom statuses in Dokan's allowed list
		add_filter( 'dokan_get_order_status_list', [ $this, 'register_custom_statuses' ] );

		// 2. Map status to CSS classes for Dokan dashboard styling
		add_filter( 'dokan_get_order_status_class', [ $this, 'register_status_classes' ], 10, 2 );

		// 3. Ensure parent order status propagates to sub-orders for these custom statuses
		add_action( 'woocommerce_order_status_changed', [ $this, 'sync_suborder_status' ], 10, 3 );
	}

	/**
	 * Adds custom statuses to Dokan's internal list so they appear in the dashboard.
	 */
	public function register_custom_statuses( $statuses ) {
		$custom = [
			'refund-requested' => __( 'Refund Requested', 'zerohold-shipping' ),
			'return-approved'  => __( 'Return Approved', 'zerohold-shipping' ),
			'refund-approved'  => __( 'Refund Approved', 'zerohold-shipping' ),
			'refund-cancelled' => __( 'Refund Cancelled', 'zerohold-shipping' ),
			'return-rejected'  => __( 'Return Rejected', 'zerohold-shipping' ),
		];

		return array_merge( $statuses, $custom );
	}

	/**
	 * Provides CSS classes for Dokan dashboard badges.
	 */
	public function register_status_classes( $class, $status ) {
		switch ( $status ) {
			case 'refund-requested':
				return 'refund-requested alert-warning'; // Yellowish
			case 'return-approved':
			case 'refund-approved':
				return 'refund-approved alert-success'; // Greenish
			case 'refund-cancelled':
			case 'return-rejected':
				return 'refund-cancelled alert-danger'; // Reddish
		}
		return $class;
	}

	/**
	 * Synchronizes the status from parent order to Dokan sub-orders.
	 * Required because Dokan might not automatically sync unknown custom statuses.
	 */
	public function sync_suborder_status( $order_id, $old_status, $new_status ) {
		$target_statuses = [ 
			'refund-requested', 
			'return-approved', 
			'refund-approved', 
			'refund-cancelled', 
			'return-rejected' 
		];

		if ( ! in_array( $new_status, $target_statuses ) ) {
			return;
		}

		// Check if it's a parent order with sub-orders
		$has_suborder = get_post_meta( $order_id, 'has_sub_order', true );
		
		if ( $has_suborder ) {
			global $wpdb;
			
			// Find all sub-orders for this parent
			$sub_orders = $wpdb->get_col( $wpdb->prepare( 
				"SELECT ID FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = 'shop_order'", 
				$order_id 
			) );

			if ( ! empty( $sub_orders ) ) {
				foreach ( $sub_orders as $sub_id ) {
					$sub_order = wc_get_order( $sub_id );
					if ( $sub_order ) {
						// Set the status on sub-order without triggering this hook recursively
						$sub_order->update_status( $new_status, __( 'Synced from parent order.', 'zerohold-shipping' ) );
						
						// Manually update Dokan's internal table for dashboard display consistency
						$this->update_dokan_orders_table( $sub_id, $new_status );
					}
				}
			}
		}
	}

	/**
	 * Manually updates the dokan_orders table to ensure dashboard list sync.
	 */
	private function update_dokan_orders_table( $order_id, $status ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'dokan_orders';
		
		// Dokan stores status without 'wc-' prefix usually in this table
		$status_slug = str_replace( 'wc-', '', $status );
		
		$wpdb->update(
			$table_name,
			[ 'order_status' => $status_slug ],
			[ 'order_id'     => $order_id ],
			[ '%s' ],
			[ '%d' ]
		);
	}
}
