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

	// 4. SHIPPING STATE KEYS (Standardized)
	const META_PLATFORM             = '_zh_shipping_platform';
	const META_AWB                  = '_zh_awb';
	const META_SYSTEM_ORDER_ID      = '_zh_system_order_id';
	const META_COURIER              = '_zh_courier';
	const META_COURIER_ID           = '_zh_courier_id';
	const META_LABEL_URL            = '_zh_label_pdf_url';
	const META_LR_NUMBER            = '_zh_bigship_lr_number';
	const META_PICKUP_STATUS        = '_zh_shiprocket_pickup_status'; // Legacy name but kept for stability
	
	// Legacy Aliases (pointing to Standardized keys)
	const LEGACY_SHIPROCKET_AWB       = '_zh_shiprocket_awb';
	const LEGACY_SHIPROCKET_LABEL_URL = '_zh_shiprocket_label_url';

	// 5. RETURN SHIPPING KEYS
	const META_RETURN_SHIPMENT_ID    = '_zh_return_shipment_id';
	const META_RETURN_PLATFORM       = '_zh_return_platform';
	const META_RETURN_COURIER        = '_zh_return_courier';
	const META_RETURN_AWB            = '_zh_return_awb';
	const META_RETURN_LABEL_URL      = '_zh_return_label_url';
	const META_RETURN_COST           = '_zh_return_shipping_cost';
	const META_RETURN_DATE           = '_zh_return_shipping_date';
	const META_RETURN_HANDOVER       = '_zh_return_handover_confirmed';
	const META_BIGSHIP_SYSTEM_ID     = '_zh_bigship_system_order_id';

	/**
	 * Mark Return Handover as Confirmed (Hardened)
	 */
	public static function confirm_return_handover( $order_id ) {
		update_post_meta( $order_id, self::META_RETURN_HANDOVER, 1 );
	}

	/**
	 * Store BigShip System ID (Platform Internal)
	 */
	public static function record_bigship_id( $order_id, $system_id ) {
		update_post_meta( $order_id, self::META_BIGSHIP_SYSTEM_ID, $system_id );
	}

	/**
	 * Record Outbound Shipment Data (Hardened)
	 */
	public static function record_shipment_data( $order_id, $data ) {
		if ( ! empty( $data['platform'] ) )    update_post_meta( $order_id, self::META_PLATFORM, $data['platform'] );
		if ( ! empty( $data['system_id'] ) )   update_post_meta( $order_id, self::META_SYSTEM_ORDER_ID, $data['system_id'] );
		if ( ! empty( $data['awb'] ) )         update_post_meta( $order_id, self::META_AWB, $data['awb'] );
		if ( ! empty( $data['courier'] ) )     update_post_meta( $order_id, self::META_COURIER, $data['courier'] );
		if ( ! empty( $data['courier_id'] ) )  update_post_meta( $order_id, self::META_COURIER_ID, $data['courier_id'] );
		if ( ! empty( $data['label_url'] ) )   update_post_meta( $order_id, self::META_LABEL_URL, $data['label_url'] );
		if ( ! empty( $data['lr_number'] ) )   update_post_meta( $order_id, self::META_LR_NUMBER, $data['lr_number'] );
		
		// Compatibility for legacy keys
		if ( ! empty( $data['awb'] ) )         update_post_meta( $order_id, self::LEGACY_SHIPROCKET_AWB, $data['awb'] );
		if ( ! empty( $data['label_url'] ) )   update_post_meta( $order_id, self::LEGACY_SHIPROCKET_LABEL_URL, $data['label_url'] );
	}

	/**
	 * Record Return Shipment Data (Hardened)
	 */
	public static function record_return_data( $order_id, $data ) {
		if ( ! empty( $data['shipment_id'] ) ) update_post_meta( $order_id, self::META_RETURN_SHIPMENT_ID, $data['shipment_id'] );
		if ( ! empty( $data['platform'] ) )    update_post_meta( $order_id, self::META_RETURN_PLATFORM, $data['platform'] );
		if ( ! empty( $data['courier'] ) )     update_post_meta( $order_id, self::META_RETURN_COURIER, $data['courier'] );
		if ( ! empty( $data['awb'] ) )         update_post_meta( $order_id, self::META_RETURN_AWB, $data['awb'] );
		if ( ! empty( $data['label_url'] ) )   update_post_meta( $order_id, self::META_RETURN_LABEL_URL, $data['label_url'] );
		if ( ! empty( $data['cost'] ) )        update_post_meta( $order_id, self::META_RETURN_COST, $data['cost'] );
		if ( ! empty( $data['cost'] ) )        update_post_meta( $order_id, self::META_RETURN_DATE, current_time('mysql') );
	}

	/**
	 * Get Visibility State
	 */
	public static function get_visibility( $order_id ) {
		$state = get_post_meta( $order_id, self::META_VISIBILITY, true );
		$is_cancelled = get_post_meta( $order_id, self::META_CANCELLED_COOLOFF, true );
		
		if ( $is_cancelled === 'yes' ) {
			return self::STATE_PERMANENTLY_HIDDEN;
		}
		
		return $state;
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

	/**
	 * Calculate Rejection Financials (Hardened)
	 * 
	 * RULE: Reversal amount must EXCLUDE ZeroHold shipping fees.
	 * Penalty is calculated based on this reversal amount.
	 */
	public static function calculate_rejection_data( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) return null;

		$full_total    = (float) $order->get_total();
		$shipping_cost = (float) get_post_meta( $order_id, self::META_SHIPPING_COST, true );
		
		// If shipping cost isn't in meta, check WC shipping methods (Fallback)
		if ( $shipping_cost <= 0 ) {
			foreach ( $order->get_shipping_methods() as $method ) {
				$shipping_cost += (float) $method->get_cost();
			}
		}

		$reversal_base = $full_total - $shipping_cost;
		
		// Penalty Math (Standard 25%)
		$fixed_fee = (float) get_option( 'zh_rejection_penalty_fixed', 0 );
		$percent   = (float) get_option( 'zh_rejection_penalty_percent', 25 );
		
		$penalty = $fixed_fee + ( $reversal_base * ( $percent / 100 ) );
		$total_deduction = $reversal_base + $penalty;

		return [
			'reversal_base'   => $reversal_base,   // The correctly isolated order value
			'penalty_amount'  => $penalty,        // The 25% fee based on isolated value
			'total_deduction' => $total_deduction  // The total amount removed from vendor (125%)
		];
	}

	/**
	 * Mark Rejection with Centralized Data
	 */
	public static function record_rejection( $order_id, $reason = '' ) {
		$data = self::calculate_rejection_data( $order_id );
		if ( ! $data ) return;

		update_post_meta( $order_id, self::META_REJECTION_PENALTY, $data['penalty_amount'] );
		update_post_meta( $order_id, self::META_REJECTION_TOTAL, $data['total_deduction'] );
		update_post_meta( $order_id, self::META_REJECTION_DATE, current_time( 'mysql' ) );
		
		update_post_meta( $order_id, '_zh_vendor_rejected', 'yes' );
		if ( $reason ) {
			update_post_meta( $order_id, '_zh_vendor_reject_reason', $reason );
		}
	}

	/**
	 * Calculates the final share for a specific user type (vendor or retailer).
	 * THE 10th SHIELD: Isolated Financial Formula.
	 * 
	 * @param float  $base_price Original carrier price.
	 * @param string $type       'vendor' or 'retailer'
	 * @param int    $user_id    The user ID to check for exclusions.
	 * @return float Adjusted price (share + cap).
	 */
	public static function calculate_share_and_cap( $base_price, $type = 'vendor', $user_id = 0 ) {
		if ( $base_price <= 0 ) return 0;

		// 1. Calculate Base Share
		$share_percent = (float) get_option( "zh_{$type}_shipping_share_percentage", 50 );
		$share_amount  = $base_price * ( $share_percent / 100 );

		// 2. Check Exclusions
		$excluded_emails_str = get_option( "zh_excluded_{$type}_emails", "" );
		if ( ! empty( $excluded_emails_str ) && $user_id ) {
			$user = get_user_by( "id", $user_id );
			if ( $user ) {
				$user_email = strtolower( trim( $user->user_email ) );
				$excluded_list = array_map( "trim", explode( ",", strtolower( $excluded_emails_str ) ) );
				if ( in_array( $user_email, $excluded_list ) ) {
					return $share_amount; // Return base share without cap
				}
			}
		}

		// 3. Apply Hidden Profit Cap
		$option_name = "zh_{$type}_hidden_cap_slabs";
		
		// Fallback for legacy vendor naming (zh_hidden_cap_slabs)
		if ( $type === 'vendor' && ! get_option( $option_name ) ) {
			$option_name = "zh_hidden_cap_slabs";
		}

		$slabs = get_option( $option_name, [] );
		if ( empty( $slabs ) ) {
			return $share_amount;
		}

		foreach ( $slabs as $slab ) {
			$min = isset( $slab['min'] ) ? floatval( $slab['min'] ) : 0;
			$max = ( isset( $slab['max'] ) && $slab['max'] !== '' ) ? floatval( $slab['max'] ) : PHP_FLOAT_MAX;
			$pct = isset( $slab['percent'] ) ? floatval( $slab['percent'] ) : 0;

			if ( $share_amount >= $min && $share_amount <= $max ) {
				$cap = $share_amount * ( $pct / 100 );
				return $share_amount + $cap;
			}
		}

		return $share_amount;
	}
}
