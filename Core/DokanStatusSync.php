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
		// Define all possible variations to catch plugin differences
		$this->statuses = [
			'refund-requested' => __( 'Refund Requested', 'zerohold-shipping' ),
			'refund-request'   => __( 'Refund Requested', 'zerohold-shipping' ),
			'return-requested' => __( 'Return Requested', 'zerohold-shipping' ),
			'return-request'   => __( 'Return Requested', 'zerohold-shipping' ),
			'return-approved'  => __( 'Return Approved', 'zerohold-shipping' ),
			'refund-approved'  => __( 'Refund Approved', 'zerohold-shipping' ),
			'refund-cancelled' => __( 'Refund Cancelled', 'zerohold-shipping' ),
			'return-rejected'  => __( 'Return Rejected', 'zerohold-shipping' ),
			'return-cancelled' => __( 'Return Cancelled', 'zerohold-shipping' ),
		];

		// 1. Register with WooCommerce Core
		add_filter( 'woocommerce_register_shop_order_post_statuses', [ $this, 'register_wc_post_statuses' ] );
		add_filter( 'wc_order_statuses', [ $this, 'add_to_wc_order_statuses' ] );

		// 2. Register with Dokan
		add_filter( 'dokan_get_order_status_list', [ $this, 'register_custom_statuses' ], 999 );
		add_filter( 'dokan_order_statuses', [ $this, 'register_order_statuses' ], 999 );
		add_filter( 'dokan_get_order_status_translated', [ $this, 'translate_status_label' ], 999, 2 );

		// 3. Styling
		add_filter( 'dokan_get_order_status_class', [ $this, 'register_status_classes' ], 999, 2 );
		add_action( 'wp_head', [ $this, 'inject_status_css' ], 100 );
		add_action( 'admin_head', [ $this, 'inject_status_css' ], 100 );

		// 4. Aggressive Sync
		add_action( 'woocommerce_order_status_changed', [ $this, 'handle_status_change' ], 1, 3 ); // Early priority
		
		// Fallback repair: if someone views the dashboard, try to sync known problematic statuses
		add_action( 'dokan_dashboard_content_before', [ $this, 'repair_visible_orders' ] );
	}

	public function register_wc_post_statuses( $post_statuses ) {
		foreach ( $this->statuses as $slug => $label ) {
			if ( isset( $post_statuses[ 'wc-' . $slug ] ) ) continue;
			$post_statuses[ 'wc-' . $slug ] = [
				'label'                     => $label,
				'public'                    => true,
				'exclude_from_search'       => false,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
				'label_count'               => _n_noop( $label . ' <span class="count">(%s)</span>', $label . ' <span class="count">(%s)</span>', 'zerohold-shipping' ),
			];
		}
		return $post_statuses;
	}

	public function add_to_wc_order_statuses( $order_statuses ) {
		foreach ( $this->statuses as $slug => $label ) {
			$order_statuses[ 'wc-' . $slug ] = $label;
		}
		return $order_statuses;
	}

	public function register_custom_statuses( $statuses ) {
		return array_merge( $statuses, $this->statuses );
	}

	public function register_order_statuses( $statuses ) {
		foreach ( $this->statuses as $slug => $label ) {
			$statuses[ 'wc-' . $slug ] = $label;
			$statuses[ $slug ] = $label; // Also add without wc- for Dokan table matching
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

	public function inject_status_css() {
		?>
		<style id="zh-dokan-status-css">
			.dokan-dashboard .dokan-orders-content .status-refund-requested,
			.dokan-dashboard .dokan-orders-content .status-refund-request,
			.dokan-dashboard .dokan-orders-content .status-return-requested,
			.dokan-dashboard .dokan-orders-content .status-return-request,
			.dokan-dashboard .dokan-orders-content .refund-requested { background-color: #fef9c3 !important; color: #854d0e !important; border: 1px solid #fde047 !important; padding: 2px 8px; border-radius: 4px; font-weight: 600; display: inline-block; white-space: nowrap; }
			
			.dokan-dashboard .dokan-orders-content .status-refund-approved,
			.dokan-dashboard .dokan-orders-content .refund-approved,
			.dokan-dashboard .dokan-orders-content .status-return-approved,
			.dokan-dashboard .dokan-orders-content .return-approved { background-color: #dcfce7 !important; color: #166534 !important; border: 1px solid #86efac !important; padding: 2px 8px; border-radius: 4px; font-weight: 600; display: inline-block; white-space: nowrap; }
			
			.dokan-dashboard .dokan-orders-content .status-refund-cancelled,
			.dokan-dashboard .dokan-orders-content .refund-cancelled,
			.dokan-dashboard .dokan-orders-content .status-return-rejected,
			.dokan-dashboard .dokan-orders-content .return-rejected,
			.dokan-dashboard .dokan-orders-content .status-return-cancelled { background-color: #fee2e2 !important; color: #991b1b !important; border: 1px solid #fecaca !important; padding: 2px 8px; border-radius: 4px; font-weight: 600; display: inline-block; white-space: nowrap; }
			
			/* Fallback for empty badges */
			.dokan-dashboard .dokan-orders-content .dokan-label:empty::before { content: "Unknown Status"; color: #999; font-style: italic; }
		</style>
		<?php
	}

	public function handle_status_change( $order_id, $old_status, $new_status ) {
		$clean_status = str_replace( 'wc-', '', $new_status );

		// Always log to main error log so we can see it in user output
		error_log( "ZSS DEBUG: Status Change for #{$order_id} from {$old_status} to {$new_status}" );

		if ( ! isset( $this->statuses[ $clean_status ] ) ) {
			return;
		}

		$this->update_dokan_orders_table( $order_id, $clean_status );

		$has_suborder = get_post_meta( $order_id, 'has_sub_order', true );
		if ( $has_suborder ) {
			global $wpdb;
			$sub_orders = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = 'shop_order'", $order_id ) );
			foreach ( $sub_orders as $sub_id ) {
				$sub_order = wc_get_order( $sub_id );
				if ( $sub_order ) {
					$sub_order->update_status( $new_status, 'Synced from parent by ZSS.' );
					$this->update_dokan_orders_table( $sub_id, $clean_status );
				}
			}
		}
	}

	/**
	 * Aggressively repairs Dokan table for any RMA orders that might be broken
	 */
	public function repair_visible_orders() {
		// Only run occasionally or on specific requests to avoid overhead
		if ( ! is_user_logged_in() ) return;
		
		global $wpdb;
		$custom_slugs = array_keys( $this->statuses );
		$in_query = "'" . implode( "','wc-", $custom_slugs ) . "'"; // Simple hack to get both
		$in_query = str_replace( "'','", "'wc-", $in_query ); // Fix first element
		$in_query = "'" . implode( "','wc-", $custom_slugs ) . "','refund-requested','wc-refund-requested'"; 

		// Find orders in wp_posts that have our custom statuses but might not be synced
		$broken_orders = $wpdb->get_results( "
			SELECT p.ID, p.post_status 
			FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->prefix}dokan_orders do ON p.ID = do.order_id
			WHERE p.post_type = 'shop_order' 
			AND p.post_status IN ($in_query)
			AND (do.order_status != REPLACE(p.post_status, 'wc-', '') OR do.order_status IS NULL)
			LIMIT 20
		" );

		if ( $broken_orders ) {
			foreach ( $broken_orders as $order ) {
				$clean = str_replace( 'wc-', '', $order->post_status );
				$this->update_dokan_orders_table( $order->ID, $clean );
				error_log( "ZSS DEBUG: Repaired Dokan status for #{$order->ID} to {$clean}" );
			}
		}
	}

	private function update_dokan_orders_table( $order_id, $status_slug ) {
		global $wpdb;
		$table = $wpdb->prefix . 'dokan_orders';
		$wpdb->update( $table, [ 'order_status' => $status_slug ], [ 'order_id' => $order_id ] );
		
		// If update failed (row doesn't exist), we might need to insert, but Dokan should handle that.
		// However, let's be safe.
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE order_id = %d", $order_id ) );
		if ( ! $exists ) {
			error_log( "ZSS CRITICAL: Order #{$order_id} missing from dokan_orders table!" );
		}
	}
}
