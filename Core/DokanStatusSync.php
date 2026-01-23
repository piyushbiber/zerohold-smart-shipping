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

		// 1. Register with WooCommerce Core (Crucial for visibility)
		add_filter( 'woocommerce_register_shop_order_post_statuses', [ $this, 'register_wc_post_statuses' ] );
		add_filter( 'wc_order_statuses', [ $this, 'add_to_wc_order_statuses' ] );

		// 2. Register custom statuses in Dokan's list
		add_filter( 'dokan_get_order_status_list', [ $this, 'register_custom_statuses' ] );
		add_filter( 'dokan_order_statuses', [ $this, 'register_order_statuses' ] );
		add_filter( 'dokan_get_order_status_translated', [ $this, 'translate_status_label' ], 10, 2 );

		// 3. Map status to CSS classes for Dokan dashboard styling
		add_filter( 'dokan_get_order_status_class', [ $this, 'register_status_classes' ], 10, 2 );

		// 4. Inject Custom CSS to fix "white-on-white" issue
		add_action( 'wp_head', [ $this, 'inject_status_css' ], 100 );
		add_action( 'admin_head', [ $this, 'inject_status_css' ], 100 );

		// 5. Ensure parent order status propagates to sub-orders/dashboard table
		add_action( 'woocommerce_order_status_changed', [ $this, 'handle_status_change' ], 20, 3 );
	}

	/**
	 * Register statuses in WordPress/WC core
	 */
	public function register_wc_post_statuses( $post_statuses ) {
		foreach ( $this->statuses as $slug => $label ) {
			$post_statuses[ 'wc-' . $slug ] = [
				'label'                     => $label,
				'public'                    => true,
				'exclude_from_search'       => false,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
				/* translators: %s: number of orders */
				'label_count'               => _n_noop( $label . ' <span class="count">(%s)</span>', $label . ' <span class="count">(%s)</span>', 'zerohold-shipping' ),
			];
		}
		return $post_statuses;
	}

	/**
	 * Add to WC Order Statuses list
	 */
	public function add_to_wc_order_statuses( $order_statuses ) {
		foreach ( $this->statuses as $slug => $label ) {
			$order_statuses[ 'wc-' . $slug ] = $label;
		}
		return $order_statuses;
	}

	/**
	 * Adds custom statuses to Dokan's list
	 */
	public function register_custom_statuses( $statuses ) {
		return array_merge( $statuses, $this->statuses );
	}

	public function register_order_statuses( $statuses ) {
		foreach ( $this->statuses as $slug => $label ) {
			$statuses[ 'wc-' . $slug ] = $label;
		}
		return $statuses;
	}

	public function translate_status_label( $label, $status ) {
		$clean_status = str_replace( 'wc-', '', $status );
		if ( isset( $this->statuses[ $clean_status ] ) ) {
			return $this->statuses[ $clean_status ];
		}
		return $label;
	}

	public function register_status_classes( $class, $status ) {
		$clean_status = str_replace( 'wc-', '', $status );
		return $class . ' status-' . $clean_status;
	}

	/**
	 * Injects CSS to style the status badges in Dokan Dashboard
	 */
	public function inject_status_css() {
		?>
		<style id="zh-dokan-status-css">
			/* Specific Dokan Dashboard Badge Styles */
			.dokan-dashboard .dokan-orders-content .status-refund-requested,
			.dokan-dashboard .dokan-orders-content .refund-requested { background-color: #fef9c3 !important; color: #854d0e !important; border: 1px solid #fde047 !important; padding: 2px 8px; border-radius: 4px; font-weight: 600; }
			
			.dokan-dashboard .dokan-orders-content .status-refund-approved,
			.dokan-dashboard .dokan-orders-content .refund-approved,
			.dokan-dashboard .dokan-orders-content .status-return-approved,
			.dokan-dashboard .dokan-orders-content .return-approved { background-color: #dcfce7 !important; color: #166534 !important; border: 1px solid #86efac !important; padding: 2px 8px; border-radius: 4px; font-weight: 600; }
			
			.dokan-dashboard .dokan-orders-content .status-refund-cancelled,
			.dokan-dashboard .dokan-orders-content .refund-cancelled,
			.dokan-dashboard .dokan-orders-content .status-return-rejected,
			.dokan-dashboard .dokan-orders-content .return-rejected { background-color: #fee2e2 !important; color: #991b1b !important; border: 1px solid #fecaca !important; padding: 2px 8px; border-radius: 4px; font-weight: 600; }
			
			/* Admin List Styles */
			mark.refund-requested { background-color: #fef9c3; color: #854d0e; }
			mark.refund-approved, mark.return-approved { background-color: #dcfce7; color: #166534; }
			mark.refund-cancelled, mark.return-rejected { background-color: #fee2e2; color: #991b1b; }
		</style>
		<?php
	}

	/**
	 * Synchronizes status changes
	 */
	public function handle_status_change( $order_id, $old_status, $new_status ) {
		$clean_status = str_replace( 'wc-', '', $new_status );

		if ( ! isset( $this->statuses[ $clean_status ] ) ) {
			return;
		}

		$this->log( "SYNC: Order #{$order_id} changed to {$new_status}" );

		// Update Dokan Table
		$this->update_dokan_orders_table( $order_id, $clean_status );

		// Handle Sub-orders
		$has_suborder = get_post_meta( $order_id, 'has_sub_order', true );
		if ( $has_suborder ) {
			global $wpdb;
			$sub_orders = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = 'shop_order'", $order_id ) );
			foreach ( $sub_orders as $sub_id ) {
				$sub_order = wc_get_order( $sub_id );
				if ( $sub_order ) {
					$sub_order->update_status( $new_status, 'Synced from parent by ZeroHold.' );
					$this->update_dokan_orders_table( $sub_id, $clean_status );
				}
			}
		}
	}

	private function update_dokan_orders_table( $order_id, $status_slug ) {
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'dokan_orders', [ 'order_status' => $status_slug ], [ 'order_id' => $order_id ] );
	}

	private function log( $message ) {
		$log_file = WP_CONTENT_DIR . '/dokan_status_sync.log';
		error_log( "[" . date('Y-m-d H:i:s') . "] " . $message . "\n", 3, $log_file );
	}
}
