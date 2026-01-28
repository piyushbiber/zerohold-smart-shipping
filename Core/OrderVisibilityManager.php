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

		// Force unlock trigger
		if ( isset( $_GET['zh_force_unlock_visibility'] ) ) {
			$this->unlock_expired_orders();
			echo "<div class='notice notice-success'><p>Manual visibility unlock executed.</p></div>";
		}

		global $wpdb;
		$no = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_zh_vendor_visible' AND meta_value = 'no' AND post_id NOT IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_zh_buyer_cancelled_during_cooloff' AND meta_value = 'yes')" );
		$yes = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_zh_vendor_visible' AND meta_value = 'yes'" );
		
		// Find the oldest unlock only for orders that are NOT buyer-cancelled
		$oldest_unlock = $wpdb->get_var( "
			SELECT pm2.meta_value 
			FROM {$wpdb->postmeta} pm1
			JOIN {$wpdb->postmeta} pm2 ON pm1.post_id = pm2.post_id
			WHERE pm1.meta_key = '_zh_vendor_visible' AND pm1.meta_value = 'no'
			AND pm2.meta_key = '_zh_visibility_unlock_at'
			AND pm1.post_id NOT IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_zh_buyer_cancelled_during_cooloff' AND meta_value = 'yes')
			ORDER BY (pm2.meta_value + 0) ASC LIMIT 1
		" );
		
		$hidden_ids = $wpdb->get_col( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_zh_vendor_visible' AND meta_value = 'no' AND post_id NOT IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_zh_buyer_cancelled_during_cooloff' AND meta_value = 'yes') LIMIT 10" );
		$ids_str = !empty($hidden_ids) ? implode(', ', $hidden_ids) : 'None';

		$status_msg = "<strong>ZSS Visibility Debug:</strong> Hidden (no): $no | Visible (yes): $yes | Hidden IDs: $ids_str";
		if ( $oldest_unlock ) {
			$diff = (int)$oldest_unlock - time();
			$time_str = date( 'Y-m-d H:i:s', $oldest_unlock );
			$status_msg .= " | Next unlock at: $time_str (in $diff seconds)";
			if ( $diff < 0 ) {
				$status_msg .= " <span style='color:red;'>[OVERDUE - Auto-unlock failing]</span>";
			}
		}

		echo "<div class='notice notice-warning is-dismissible'><p>$status_msg | <a href='" . add_query_arg( 'zh_force_unlock_visibility', '1' ) . "'>Force Unlock Now</a></p></div>";
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
		// If in Admin Backend, allow seeing everything
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return $args;
		}

		// On Seller Dashboard, we MUST hide orders marked as 'no'
		if ( ! isset( $args['meta_query'] ) ) {
			$args['meta_query'] = [];
		}

		$args['meta_query'][] = [
			'relation' => 'AND',
			[
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
			],
			[
				'key'     => '_zh_buyer_cancelled_during_cooloff',
				'compare' => 'NOT EXISTS'
			]
		];

		return $args;
	}

	/**
	 * Direct WooCommerce order query filter (Used by Dokan and others)
	 */
	public function filter_wc_order_query( $query_vars ) {
		// If in Admin Backend, allow seeing everything
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return $query_vars;
		}

		if ( ! isset( $query_vars['meta_query'] ) ) {
			$query_vars['meta_query'] = [];
		}

		$query_vars['meta_query'][] = [
			'relation' => 'AND',
			[
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
			],
			[
				'key'     => '_zh_buyer_cancelled_during_cooloff',
				'compare' => 'NOT EXISTS'
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

		// 1. Fetch only a manageable batch
		$batch_limit = apply_filters( 'zh_order_visibility_unlock_batch_size', 50 ); // Smaller batch for stability
		$now = time();

		// Use + 0 to force numeric comparison in SQL
		$order_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT pm1.post_id 
			 FROM {$wpdb->postmeta} pm1
			 JOIN {$wpdb->postmeta} pm2 ON pm1.post_id = pm2.post_id
			 WHERE pm1.meta_key = '_zh_vendor_visible' AND pm1.meta_value = 'no'
			 AND pm2.meta_key = '_zh_visibility_unlock_at' AND (pm2.meta_value + 0) <= %d
			 AND pm1.post_id NOT IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_zh_buyer_cancelled_during_cooloff' AND meta_value = 'yes')
			 LIMIT %d",
			$now,
			$batch_limit
		) );

		if ( ! empty( $order_ids ) ) {
			foreach ( $order_ids as $order_id ) {
				// 1. Direct Meta Update
				update_post_meta( $order_id, '_zh_vendor_visible', 'yes' );
				
				// 2. Minimal Cache Clear (Post only)
				clean_post_cache( $order_id );
				
				// 3. Dokan Cache (Surgical)
				if ( class_exists( '\WeDevs\Dokan\Order\OrderCache' ) && function_exists( 'dokan_get_seller_id_by_order' ) ) {
					$seller_id = dokan_get_seller_id_by_order( $order_id );
					if ( $seller_id ) \WeDevs\Dokan\Order\OrderCache::delete( $seller_id, $order_id );
				}
			}
			
			// 4. Global LiteSpeed Purge (Only once per batch)
			if ( class_exists( 'LiteSpeed\Purge' ) && method_exists( 'LiteSpeed\Purge', 'purge_all' ) ) {
				\LiteSpeed\Purge::purge_all();
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

		// Find overdue orders for this vendor specifically
		$order_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT pm1.post_id 
			 FROM {$wpdb->postmeta} pm1
			 JOIN {$wpdb->postmeta} pm2 ON pm1.post_id = pm2.post_id
			 LEFT JOIN {$wpdb->prefix}dokan_orders do ON pm1.post_id = do.order_id
			 LEFT JOIN {$wpdb->postmeta} pm3 ON pm1.post_id = pm3.post_id AND pm3.meta_key = '_dokan_vendor_id'
			 WHERE pm1.meta_key = '_zh_vendor_visible' AND pm1.meta_value = 'no'
			 AND pm2.meta_key = '_zh_visibility_unlock_at' AND (pm2.meta_value + 0) <= %d
			 AND (do.seller_id = %d OR pm3.meta_value = %s)",
			time(),
			$vendor_id,
			(string)$vendor_id
		) );

		if ( ! empty( $order_ids ) ) {
			foreach ( $order_ids as $order_id ) {
				try {
					update_post_meta( $order_id, '_zh_vendor_visible', 'yes' );
					
					// Only do lightweight post-level cache clearing in the loop
					clean_post_cache( $order_id );
					if ( class_exists( '\WeDevs\Dokan\Order\OrderCache' ) && function_exists( 'dokan_get_seller_id_by_order' ) ) {
						$seller_id = dokan_get_seller_id_by_order( $order_id );
						if ( $seller_id ) \WeDevs\Dokan\Order\OrderCache::delete( $seller_id, $order_id );
					}
				} catch ( \Throwable $e ) {
					// Be silent, don't crash the dashboard
				}
			}

			// Perform surgical purge for this specific vendor only
			$this->purge_vendor_pages_surgical( $vendor_id );
		}
	}

	/**
	 * Check if an order is visible to the vendor.
	 */
	public function is_order_visible( $is_visible, $order_id ) {
		// Admin BACKEND sees all. 
		// Frontend (Dashboard) follows the vendor's view for verification.
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return $is_visible;
		}

		// 1. Permanent Hidden: Buyer cancelled during cool-off
		$is_buyer_cancelled = get_post_meta( $order_id, '_zh_buyer_cancelled_during_cooloff', true );
		if ( $is_buyer_cancelled === 'yes' ) {
			return false;
		}

		// 2. Normal Delayed Visibility
		$status = get_post_meta( $order_id, '_zh_vendor_visible', true );
		if ( $status === 'no' ) {
			return false;
		}
		return $is_visible;
	}

	/**
	 * Surgical purging for LiteSpeed and other caches
	 */
	public function purge_vendor_pages_surgical( $vendor_id ) {
		if ( ! $vendor_id ) return;

		// 1. Clear generic page cache if LiteSpeed is present
		if ( class_exists( 'LiteSpeed\Purge' ) ) {
			// Purge the vendor's specific order dashboard
			if ( function_exists( 'dokan_get_navigation_url' ) ) {
				$order_url = dokan_get_navigation_url( 'orders' );
				\LiteSpeed\Purge::purge_url( $order_url );
				
				$dashboard_url = dokan_get_navigation_url( 'dashboard' );
				\LiteSpeed\Purge::purge_url( $dashboard_url );
			}
		}

		// 2. Trigger Dokan hooks that might be watched by cache plugins
		do_action( 'dokan_vendor_orders_updated', $vendor_id );
	}

	/**
	 * Forcefully clear Dokan and WordPress caches for an order.
	 */
	public function clear_order_visibility_cache( $order_id ) {
		try {
			$seller_id = function_exists( 'dokan_get_seller_id_by_order' ) ? dokan_get_seller_id_by_order( $order_id ) : 0;

			// 1. Clear Dokan's internal order/count caches
			if ( $seller_id && class_exists( '\WeDevs\Dokan\Order\OrderCache' ) ) {
				\WeDevs\Dokan\Order\OrderCache::delete( $seller_id, $order_id );
			}

			// 2. Clear WordPress Core Post/Meta cache
			clean_post_cache( $order_id );

			// 3. Surgical LiteSpeed Purge
			if ( $seller_id ) {
				$this->purge_vendor_pages_surgical( $seller_id );
			}
		} catch ( \Throwable $e ) {
			// Fail silently
		}
	}
}
