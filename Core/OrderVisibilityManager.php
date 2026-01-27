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
		// 1. Initialize visibility meta on order creation
		add_action( 'woocommerce_checkout_order_processed', [ $this, 'initialize_visibility' ], 20, 3 );

		// 2. Filter Dokan order queries to hide invisible orders
		add_filter( 'dokan_get_vendor_orders_args', [ $this, 'filter_dokan_orders' ], 20 );

		// 3. Unlocking mechanisms
		add_action( $this->cron_hook, [ $this, 'unlock_expired_orders' ] );
		add_action( 'dokan_dashboard_content_before', [ $this, 'lazy_unlock_vendor_orders' ] );

		// 4. Safety Guards
		add_filter( 'zh_can_vendor_act_on_order', [ $this, 'is_order_visible' ], 10, 2 );
	}

	/**
	 * Mark new orders as invisible and set unlock timestamp.
	 */
	public function initialize_visibility( $order_id, $posted_data, $order ) {
		$delay_value = (int) get_option( 'zh_order_visibility_delay_value', 2 );
		$delay_unit  = get_option( 'zh_order_visibility_delay_unit', 'hours' );

		$delay_seconds = ( $delay_unit === 'minutes' ) ? $delay_value * MINUTE_IN_SECONDS : $delay_value * HOUR_IN_SECONDS;

		update_post_meta( $order_id, '_zh_vendor_visible', 'no' );
		update_post_meta( $order_id, '_zh_visibility_unlock_at', time() + $delay_seconds );

		// Schedule cron if not already scheduled
		if ( ! wp_next_scheduled( $this->cron_hook ) ) {
			wp_schedule_event( time(), 'five_minutes', $this->cron_hook );
		}
	}

	/**
	 * Filter Dokan orders to exclude those not yet visible.
	 */
	public function filter_dokan_orders( $args ) {
		// Ensure we don't hide from admins in backend if they are somehow using this filter
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return $args;
		}

		// Only apply logic if we are in Dokan context (vendor dashboard)
		if ( ! function_exists( 'dokan_is_seller_dashboard' ) || ! dokan_is_seller_dashboard() ) {
			// If it's an AJAX call from vendor dashboard, we still want to filter
			if ( ! wp_doing_ajax() ) {
				return $args;
			}
		}

		if ( ! isset( $args['meta_query'] ) ) {
			$args['meta_query'] = [];
		}

		$args['meta_query'][] = [
			'relation' => 'OR',
			[
				'key'     => '_zh_vendor_visible',
				'value'   => 'yes',
				'compare' => '='
			],
			[
				'key'     => '_zh_vendor_visible',
				'compare' => 'NOT EXISTS'
			]
		];

		return $args;
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
