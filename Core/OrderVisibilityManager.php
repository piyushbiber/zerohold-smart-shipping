<?php

namespace Zerohold\Shipping\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * OrderVisibilityManager Class
 * 
 * Handles the logic for delaying order visibility to vendors.
 */
class OrderVisibilityManager {

	private $cron_hook = 'zh_unlock_vendor_visibility_event';

	public function __construct() {
		// 1. Initialize visibility meta on order creation (Broad hooks)
		add_action( 'woocommerce_new_order', [ $this, 'initialize_visibility_new' ], 5 );
		add_action( 'woocommerce_checkout_order_processed', [ $this, 'initialize_visibility_new' ], 5 );
		add_action( 'save_post_shop_order', [ $this, 'initialize_visibility_new' ], 5 );
		add_action( 'dokan_checkout_update_order_meta', [ $this, 'initialize_visibility_new' ], 5 );
		add_action( 'dokan_checkout_update_sub_order_meta', [ $this, 'initialize_visibility_new' ], 5 );

		// 2. Filter order queries everywhere (Aggressive Failsafe)
		add_action( 'pre_get_posts', [ $this, 'pre_get_posts_filter' ], 999 );
		add_filter( 'dokan_get_vendor_orders_args', [ $this, 'filter_dokan_orders' ], 999 );
		add_filter( 'dokan_vendor_orders', [ $this, 'filter_order_results' ], 999 );

		// 3. Unlocking mechanisms
		add_action( $this->cron_hook, [ $this, 'unlock_expired_orders' ] );
		add_action( 'dokan_dashboard_content_before', [ $this, 'lazy_unlock_vendor_orders' ] );

		// 4. Safety Guards
		add_filter( 'zh_can_vendor_act_on_order', [ $this, 'is_order_visible' ], 10, 2 );

		// 5. Debug
		add_action( 'admin_notices', [ $this, 'debug_admin_notice' ] );
	}

	public function debug_admin_notice() {
		if ( ! current_user_can( 'manage_options' ) ) return;
		global $wpdb;
		$no = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_zh_vendor_visible' AND meta_value = 'no'" );
		$yes = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_zh_vendor_visible' AND meta_value = 'yes'" );
		echo "<div class='notice notice-warning is-dismissible'><p><strong>ZSS Visibility Debug:</strong> Hidden (no): $no | Visible (yes): $yes</p></div>";
	}

	/**
	 * Failsafe: Filter ANY shop_order query in the frontend.
	 */
	public function pre_get_posts_filter( $query ) {
		// Only run on frontend queries
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}

		// Only target shop_order
		$post_types = (array) $query->get( 'post_type' );
		if ( ! in_array( 'shop_order', $post_types ) ) {
			return;
		}

		// Get current meta query
		$meta_query = $query->get( 'meta_query' ) ?: [];

		// Add visibility guard
		$meta_query[] = [
			'relation' => 'OR',
			[
				'key'     => '_zh_vendor_visible',
				'compare' => 'NOT EXISTS'
			],
			[
				'key'     => '_zh_vendor_visible',
				'value'   => 'no',
				'compare' => '!='
			]
		];

		$query->set( 'meta_query', $meta_query );
	}

	/**
	 * Mark new orders as invisible and set unlock timestamp.
	 */
	public function initialize_visibility_new( $order_id ) {
		if ( ! $order_id ) return;
		
		// error_log( "ZSS VISIBILITY DEBUG: initialize_visibility_new called for #$order_id" );

		// Guard: If it's already set to 'no' or 'yes', don't re-init
		$existing = get_post_meta( $order_id, '_zh_vendor_visible', true );
		if ( $existing === 'no' || $existing === 'yes' ) {
			// error_log( "ZSS VISIBILITY DEBUG: Already has meta ($existing) for #$order_id" );
			return;
		}

		$delay_value = (int) get_option( 'zh_order_visibility_delay_value', 2 );
		$delay_unit  = get_option( 'zh_order_visibility_delay_unit', 'hours' );

		$delay_seconds = ( $delay_unit === 'minutes' ) ? $delay_value * MINUTE_IN_SECONDS : $delay_value * HOUR_IN_SECONDS;
		$unlock_at = time() + $delay_seconds;

		// error_log( "ZSS VISIBILITY DEBUG: Setting #$order_id to 'no'. Unlock at: " . date('H:i:s', $unlock_at) );

		update_post_meta( $order_id, '_zh_vendor_visible', 'no' );
		update_post_meta( $order_id, '_zh_visibility_unlock_at', $unlock_at );

		// Schedule cron if not already scheduled
		if ( ! wp_next_scheduled( $this->cron_hook ) ) {
			wp_schedule_event( time(), 'five_minutes', $this->cron_hook );
		}
	}

	private function apply_meta_to_order( $id, $unlock_at ) {
		update_post_meta( $id, '_zh_vendor_visible', 'no' );
		update_post_meta( $id, '_zh_visibility_unlock_at', $unlock_at );
	}

	/**
	 * Filter Dokan orders to exclude those not yet visible.
	 */
	public function filter_dokan_orders( $args ) {
		// Ensure we don't hide from admins in backend
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return $args;
		}

		// Robust dashboard check: Dokan dashboard is often on a page or via AJAX
		$is_dashboard = ( function_exists( 'dokan_is_seller_dashboard' ) && dokan_is_seller_dashboard() );
		$is_ajax_order_list = ( wp_doing_ajax() && isset( $_REQUEST['action'] ) && $_REQUEST['action'] === 'dokan_get_orders' );
		
		if ( ! $is_dashboard && ! $is_ajax_order_list && ! wp_doing_ajax() ) {
			// return $args; // Temporary: keep aggressive for debugging
		}

		if ( ! isset( $args['meta_query'] ) ) {
			$args['meta_query'] = [];
		}

		// Add visibility filter
		$args['meta_query'][] = [
			'relation' => 'OR',
			[
				'key'     => '_zh_vendor_visible',
				'compare' => 'NOT EXISTS'
			],
			[
				'key'     => '_zh_vendor_visible',
				'value'   => 'yes',
				'compare' => '='
			]
		];

		return $args;
	}

	/**
	 * Secondary filter for the final order objects/IDs.
	 */
	public function filter_order_results( $orders ) {
		if ( ! is_array( $orders ) ) {
			return $orders;
		}

		// Only apply logic if we are in Dokan context (vendor dashboard)
		if ( ! function_exists( 'dokan_is_seller_dashboard' ) || ! dokan_is_seller_dashboard() ) {
			if ( ! wp_doing_ajax() ) {
				return $orders;
			}
		}

		foreach ( $orders as $key => $order_item ) {
			$id = 0;
			if ( is_object( $order_item ) ) {
				$id = isset( $order_item->ID ) ? $order_item->ID : ( isset( $order_item->order_id ) ? $order_item->order_id : 0 );
			} elseif ( is_numeric( $order_item ) ) {
				$id = $order_item;
			}

			if ( $id && ! $this->is_order_visible( true, $id ) ) {
				// error_log( "ZSS VISIBILITY DEBUG: Hiding order #$id from results list." );
				unset( $orders[ $key ] );
			}
		}

		return array_values( $orders ); // Re-index for safety
	}

	/**
	 * Background task to unlock orders whose cool-off has expired.
	 */
	public function unlock_expired_orders() {
		global $wpdb;

		// Find orders with _zh_vendor_visible = 'no' and expired timestamp
		$order_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta} 
			 WHERE meta_key = '_zh_visibility_unlock_at' 
			 AND meta_value <= %d 
			 AND post_id IN (
				SELECT post_id FROM {$wpdb->postmeta} 
				WHERE meta_key = '_zh_vendor_visible' AND meta_value = 'no'
			 )",
			time()
		) );

		if ( ! empty( $order_ids ) ) {
			foreach ( $order_ids as $order_id ) {
				update_post_meta( $order_id, '_zh_vendor_visible', 'yes' );
			}
		}
	}

	/**
	 * Lazy unlock for the current vendor when they view their dashboard.
	 */
	public function lazy_unlock_vendor_orders() {
		if ( ! function_exists( 'dokan_get_current_user_id' ) ) {
			return;
		}

		$vendor_id = dokan_get_current_user_id();
		if ( ! $vendor_id ) {
			return;
		}

		global $wpdb;

		// Find candidate orders for this vendor
		$order_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p
			 JOIN {$wpdb->postmeta} pm_v ON p.ID = pm_v.post_id AND pm_v.meta_key = '_zh_vendor_visible' AND pm_v.meta_value = 'no'
			 JOIN {$wpdb->postmeta} pm_t ON p.ID = pm_t.post_id AND pm_t.meta_key = '_zh_visibility_unlock_at' AND pm_t.meta_value <= %d
			 JOIN {$wpdb->prefix}dokan_orders do ON p.ID = do.order_id AND do.seller_id = %d
			 WHERE p.post_type = 'shop_order'",
			time(),
			$vendor_id
		) );

		if ( ! empty( $order_ids ) ) {
			foreach ( $order_ids as $order_id ) {
				update_post_meta( $order_id, '_zh_vendor_visible', 'yes' );
			}
		}
	}

	/**
	 * Check if an order is visible to the vendor.
	 */
	public function is_order_visible( $is_visible, $order_id ) {
		$status = get_post_meta( $order_id, '_zh_vendor_visible', true );
		if ( $status === 'no' ) {
			return false;
		}
		return $is_visible;
	}
}
