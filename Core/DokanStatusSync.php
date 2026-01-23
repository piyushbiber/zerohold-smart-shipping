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

	private $statuses = [];

	public function __construct() {
		$this->statuses = [
			'refund-requested' => __( 'Refund Requested', 'zerohold-shipping' ),
			'return-approved'  => __( 'Return Approved', 'zerohold-shipping' ),
			'refund-approved'  => __( 'Refund Approved', 'zerohold-shipping' ),
			'refund-cancelled' => __( 'Refund Cancelled', 'zerohold-shipping' ),
			'return-rejected'  => __( 'Return Rejected', 'zerohold-shipping' ),
		];

		// 1. Register custom statuses in Dokan's list
		add_filter( 'dokan_get_order_status_list', [ $this, 'register_custom_statuses' ] );
		add_filter( 'dokan_order_statuses', [ $this, 'register_order_statuses' ] );
		add_filter( 'dokan_get_order_status_translated', [ $this, 'translate_status_label' ], 10, 2 );

		// 2. Map status to CSS classes for Dokan dashboard styling
		add_filter( 'dokan_get_order_status_class', [ $this, 'register_status_classes' ], 10, 2 );

		// 3. Ensure parent order status propagates to sub-orders/dashboard table
		add_action( 'woocommerce_order_status_changed', [ $this, 'handle_status_change' ], 10, 3 );
	}

	/**
	 * Adds custom statuses to Dokan's list (often uses non-prefixed)
	 */
	public function register_custom_statuses( $statuses ) {
		return array_merge( $statuses, $this->statuses );
	}

	/**
	 * Some parts of Dokan use this filter for the full list with wc- prefix
	 */
	public function register_order_statuses( $statuses ) {
		foreach ( $this->statuses as $slug => $label ) {
			$statuses[ 'wc-' . $slug ] = $label;
		}
		return $statuses;
	}

	/**
	 * Ensures labels are translated/displayed correctly in the table
	 */
	public function translate_status_label( $label, $status ) {
		$clean_status = str_replace( 'wc-', '', $status );
		if ( isset( $this->statuses[ $clean_status ] ) ) {
			return $this->statuses[ $clean_status ];
		}
		return $label;
	}

	/**
	 * Provides CSS classes for Dokan dashboard badges.
	 */
	public function register_status_classes( $class, $status ) {
		$clean_status = str_replace( 'wc-', '', $status );
		
		switch ( $clean_status ) {
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
	 */
	public function handle_status_change( $order_id, $old_status, $new_status ) {
		$clean_status = str_replace( 'wc-', '', $new_status );

		if ( ! isset( $this->statuses[ $clean_status ] ) ) {
			return;
		}

		$this->log( "Status change detected for Order #{$order_id}: {$old_status} -> {$new_status}" );

		// 1. Update the Dokan table for THIS order (could be a sub-order or a standalone)
		$this->update_dokan_orders_table( $order_id, $clean_status );

		// 2. If it's a parent order (has sub-orders), propagate to children
		$has_suborder = get_post_meta( $order_id, 'has_sub_order', true );
		
		if ( $has_suborder ) {
			global $wpdb;
			
			$sub_orders = $wpdb->get_col( $wpdb->prepare( 
				"SELECT ID FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = 'shop_order'", 
				$order_id 
			) );

			if ( ! empty( $sub_orders ) ) {
				foreach ( $sub_orders as $sub_id ) {
					$sub_order = wc_get_order( $sub_id );
					if ( $sub_order ) {
						$this->log( "Propagating status to Sub-Order #{$sub_id}" );
						// Force status update on sub-order
						$sub_order->update_status( $new_status, __( 'Synced from parent order by ZeroHold.', 'zerohold-shipping' ) );
						
						// Also sync its table entry
						$this->update_dokan_orders_table( $sub_id, $clean_status );
					}
				}
			}
		}
	}

	/**
	 * Manually updates the dokan_orders table to ensure dashboard list sync.
	 */
	private function update_dokan_orders_table( $order_id, $status_slug ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'dokan_orders';
		
		$updated = $wpdb->update(
			$table_name,
			[ 'order_status' => $status_slug ],
			[ 'order_id'     => $order_id ],
			[ '%s' ],
			[ '%d' ]
		);

		if ( $updated ) {
			$this->log( "Successfully updated dokan_orders table for #{$order_id} to {$status_slug}" );
		} else {
			// Check if row actually exists
			$exists = $wpdb->get_var( $wpdb->prepare( "SELECT order_id FROM $table_name WHERE order_id = %d", $order_id ) );
			if ( ! $exists ) {
				$this->log( "Warning: Order #{$order_id} not found in dokan_orders table." );
			}
		}
	}

	/**
	 * Simple Logger for debugging sync issues
	 */
	private function log( $message ) {
		$log_file = WP_CONTENT_DIR . '/dokan_status_sync.log';
		$timestamp = date( 'Y-m-d H:i:s' );
		error_log( "[{$timestamp}] {$message}\n", 3, $log_file );
	}
}
