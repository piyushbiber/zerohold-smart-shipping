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

		// 2. Filter order queries everywhere (Failsafe)
		add_filter( 'dokan_get_vendor_orders_args', [ $this, 'filter_dokan_orders' ], 999 );
		add_filter( 'dokan_get_vendor_orders', [ $this, 'filter_order_results' ], 999 );
		add_filter( 'woocommerce_order_data_store_cpt_get_orders_query', [ $this, 'filter_wc_order_query' ], 999 );

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
	 * Mark new orders as invisible and set unlock timestamp.
	 */
	public function initialize_visibility_new( $order_id ) {
		if ( ! $order_id ) return;

		// Extract ID if object is passed
		if ( is_object( $order_id ) && method_exists( $order_id, 'get_id' ) ) {
			$order_id = $order_id->get_id();
		}

		if ( ! is_numeric( $order_id ) ) return;
		
		// Guard: If it's already set to 'no' or 'yes', don't re-init
		$existing = get_post_meta( $order_id, '_zh_vendor_visible', true );
		if ( $existing === 'no' || $existing === 'yes' ) {
			return;
		}

		$delay_value = (int) get_option( 'zh_order_visibility_delay_value', 2 );
		$delay_unit  = get_option( 'zh_order_visibility_delay_unit', 'hours' );

		$delay_seconds = ( $delay_unit === 'minutes' ) ? $delay_value * MINUTE_IN_SECONDS : $delay_value * HOUR_IN_SECONDS;
		$unlock_at = time() + $delay_seconds;

		update_post_meta( $order_id, '_zh_vendor_visible', 'no' );
		update_post_meta( $order_id, '_zh_visibility_unlock_at', $unlock_at );

		// Schedule cron if not already scheduled
		if ( ! wp_next_scheduled( $this->cron_hook ) ) {
			wp_schedule_event( time(), 'five_minutes', $this->cron_hook );
		}
	}

	/**
	 * Filter Dokan orders to exclude those not yet visible.
	 */
	public function filter_dokan_orders( $args ) {
		// Ensure we don't hide from admins in backend
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return $args;
		}

		if ( ! isset( $args['meta_query'] ) ) {
			$args['meta_query'] = [];
		}

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
	 * Direct WooCommerce order query filter (Used by Dokan and others)
	 */
	public function filter_wc_order_query( $query_vars ) {
		// Only run on frontend or AJAX
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return $query_vars;
		}

		if ( ! isset( $query_vars['meta_query'] ) ) {
			$query_vars['meta_query'] = [];
		}

		$query_vars['meta_query'][] = [
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

		return $query_vars;
	}

	/**
	 * Secondary filter for the final order objects/IDs returned by Dokan.
	 */
	public function filter_order_results( $orders ) {
		if ( ! is_array( $orders ) ) {
			return $orders;
		}

		// Relaxing context check for debugging
		// error_log( "ZSS VISIBILITY DEBUG: filter_order_results processing " . count($orders) . " orders." );

		foreach ( $orders as $key => $order_item ) {
			$id = 0;
			if ( is_object( $order_item ) ) {
				$id = isset( $order_item->ID ) ? $order_item->ID : ( method_exists( $order_item, 'get_id' ) ? $order_item->get_id() : 0 );
			} elseif ( is_numeric( $order_item ) ) {
				$id = $order_item;
			}

			if ( $id && ! $this->is_order_visible( true, $id ) ) {
				// error_log( "ZSS VISIBILITY DEBUG: Hiding order #$id from results." );
				unset( $orders[ $key ] );
			}
		}

		return array_values( $orders );
	}

	/**
	 * Background task to unlock orders whose cool-off has expired.
	 */
	public function unlock_expired_orders() {
		global $wpdb;

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
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return $is_visible;
		}

		$status = get_post_meta( $order_id, '_zh_vendor_visible', true );
		if ( $status === 'no' ) {
			return false;
		}
		return $is_visible;
	}
}
