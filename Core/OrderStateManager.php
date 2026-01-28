<?php
/**
 * Order State Manager Class
 * 
 * THE SOURCE OF TRUTH.
 * This class centralizes all ZeroHold-specific order metadata keys and provides
 * a unified API for reading/writing state. No other class should use direct
 * update_post_meta calls for these keys.
 */

namespace Zerohold\Shipping\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OrderStateManager {

	// 1. VISIBILITY CONSTANTS
	const META_VISIBILITY           = '_zh_vendor_visible';
	const META_UNLOCK_AT            = '_zh_visibility_unlock_at';
	
	const STATE_VISIBLE             = 'yes';
	const STATE_HIDDEN              = 'no';
	const STATE_PERMANENTLY_HIDDEN  = 'cancelled_permanent'; // Specialized state for hardening

	// 2. CANCELLATION FLAGS
	const META_CANCELLED_COOLOFF    = '_zh_buyer_cancelled_during_cooloff';
	const META_CANCELLED_POST_COOL  = '_zh_buyer_cancelled_post_cooloff';
	const META_CANCELLED_POST_LABEL = '_zh_buyer_cancelled_post_label';

	// 3. FINANCIAL KEYS
	const META_SHIPPING_COST        = '_zh_shipping_cost';
	const META_SHIPPING_DATE        = '_zh_shipping_date';
	
	const META_SHIPPING_REFUND_AMT  = '_zh_shipping_refund_amount';
	const META_SHIPPING_REFUND_DATE = '_zh_shipping_refund_date';
	const META_SHIPPING_REFUNDED    = '_zh_shipping_refunded_to_vendor';

	const META_REJECTION_PENALTY    = '_zh_rejection_penalty';
	const META_REJECTION_TOTAL      = '_zh_rejection_total';
	const META_REJECTION_DATE       = '_zh_rejection_date';

	// 4. SHIPPING STATE KEYS
	const META_PLATFORM             = '_zh_shipping_platform';
	const META_AWB                  = '_zh_shiprocket_awb';
	const META_LABEL_URL            = '_zh_shiprocket_label_url';
	const META_LABEL_STATUS         = '_zh_shiprocket_label_status';
	const META_PICKUP_STATUS        = '_zh_shiprocket_pickup_status';
	const META_SHIPMENT_ID          = '_zh_shiprocket_shipment_id';

	/**
	 * Get Visibility State
	 */
	public static function get_visibility( $order_id ) {
		$state = get_post_meta( $order_id, self::META_VISIBILITY, true );
		$is_cancelled = get_post_meta( $order_id, self::META_CANCELLED_COOLOFF, true );
		
		if ( $is_cancelled === 'yes' ) {
			return self::STATE_PERMANENTLY_HIDDEN;
		}
		
		return $state ?: self::STATE_HIDDEN;
	}

	/**
	 * Set Visibility State with Hardening Guard
	 */
	public static function set_visibility( $order_id, $state ) {
		// GUARD: Cannot unlock an order that was permanently hidden (cancelled)
		$current = self::get_visibility( $order_id );
		if ( $current === self::STATE_PERMANENTLY_HIDDEN ) {
			error_log( "ZSS GUARD: Blocked attempt to change visibility of PERMANENTLY_HIDDEN order #{$order_id}" );
			return false;
		}

		return update_post_meta( $order_id, self::META_VISIBILITY, $state );
	}

	/**
	 * Mark order as Permanently Hidden (Cancellation during cool-off)
	 */
	public static function mark_as_cancelled_cooloff( $order_id ) {
		update_post_meta( $order_id, self::META_VISIBILITY, self::STATE_HIDDEN );
		update_post_meta( $order_id, self::META_CANCELLED_COOLOFF, 'yes' );
	}

	/**
	 * Check if order is ready for vendor action
	 */
	public static function is_visible_to_vendor( $order_id ) {
		return self::get_visibility( $order_id ) === self::STATE_VISIBLE;
	}

	/**
	 * Record Shipping Cost
	 */
	public static function record_shipping_cost( $order_id, $amount ) {
		update_post_meta( $order_id, self::META_SHIPPING_COST, $amount );
		update_post_meta( $order_id, self::META_SHIPPING_DATE, current_time( 'mysql' ) );
	}

	/**
	 * Record Shipping Refund
	 */
	public static function record_shipping_refund( $order_id, $amount ) {
		update_post_meta( $order_id, self::META_SHIPPING_REFUND_AMT, $amount );
		update_post_meta( $order_id, self::META_SHIPPING_REFUND_DATE, current_time( 'mysql' ) );
		update_post_meta( $order_id, self::META_SHIPPING_REFUNDED, 'yes' );
	}
}
