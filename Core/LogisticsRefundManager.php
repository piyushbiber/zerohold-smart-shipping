<?php

namespace Zerohold\Shipping\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * LogisticsRefundManager Class
 * 
 * Handles automated wallet settlements when an order reaches RTO Delivered status.
 */
class LogisticsRefundManager {

	/**
	 * Process automated refunds for Vendor and Buyer upon RTO delivery.
	 * 
	 * @param int $order_id The WooCommerce Order ID.
	 * @return bool Success or failure.
	 */
	public static function process_rto_delivered_refund( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return false;
		}

		// Guard: Already processed?
		if ( get_post_meta( $order_id, '_zh_rto_refund_processed', true ) === 'yes' ) {
			return false;
		}

		error_log( "ZSS RTO: Starting Automated Refund for Order #{$order_id}" );

		// 1. VENDOR REFUND (100% Shipping Reversal)
		self::handle_vendor_shipping_reversal( $order, $order_id );

		// 2. BUYER SETTLEMENT (Refund remaining balance after Full Shipping + Cap)
		self::handle_buyer_rto_settlement( $order, $order_id );

		// 3. Mark as Processed
		update_post_meta( $order_id, '_zh_rto_refund_processed', 'yes' );
		update_post_meta( $order_id, '_zh_rto_refund_at', current_time( 'mysql' ) );

		$order->add_order_note( __( 'ZSS: Automated RTO Refund processed for Vendor and Buyer.', 'zerohold-shipping' ) );

		return true;
	}

	/**
	 * Refunds the shipping amount deducted from the vendor during label generation.
	 */
	private static function handle_vendor_shipping_reversal( $order, $order_id ) {
		$vendor_id = 0;
		if ( function_exists( 'dokan_get_seller_id_by_order' ) ) {
			$vendor_id = dokan_get_seller_id_by_order( $order_id );
		} else {
			$vendor_id = $order->get_meta( '_dokan_vendor_id', true );
		}

		if ( ! $vendor_id ) {
			error_log( "ZSS RTO: Vendor ID not found for Order #{$order_id}" );
			return;
		}

		// Amount deducted from vendor during label generation
		$shipping_cost = (float) get_post_meta( $order_id, '_zh_shipping_cost', true );

		if ( $shipping_cost > 0 ) {
			if ( function_exists( 'woo_wallet' ) ) {
				woo_wallet()->wallet->credit( 
					$vendor_id, 
					$shipping_cost, 
					sprintf( __( 'RTO Reversal: Shipping Refund for Order #%d', 'zerohold-shipping' ), $order_id ) 
				);
			}

			// Meta for Dokan Statement Integration (zh_shipping_refund pattern)
			update_post_meta( $order_id, '_zh_shipping_refund_amount', $shipping_cost );
			update_post_meta( $order_id, '_zh_shipping_refund_date', current_time( 'mysql' ) );
			update_post_meta( $order_id, '_zh_rto_vendor_refunded', 'yes' );

			error_log( "ZSS RTO: Refunded ₹{$shipping_cost} to Vendor #{$vendor_id}" );
		}
	}

	/**
	 * Settles the buyer account by charging full carrier cost + profit cap and refunding the rest.
	 */
	private static function handle_buyer_rto_settlement( $order, $order_id ) {
		$customer_id = $order->get_customer_id();
		if ( ! $customer_id ) {
			error_log( "ZSS RTO: Customer ID not found for Order #{$order_id}" );
			return;
		}

		// Full Base Shipping Cost (Carrier Price 100%)
		$base_cost = (float) get_post_meta( $order_id, '_zh_base_shipping_cost', true );
		
		// Retailer Profit Cap applied at Stage C
		$cap_amount = (float) get_post_meta( $order_id, '_zh_retailer_cap_amount', true );
		
		$total_penalty = $base_cost + $cap_amount;
		$order_total   = (float) $order->get_total();
		
		// The buyer paid the full order total at checkout.
		// We refund the order total MINUS the penalty (Full Shipping + Cap).
		$refund_amount = $order_total - $total_penalty;

		if ( $refund_amount > 0 ) {
			if ( function_exists( 'woo_wallet' ) ) {
				woo_wallet()->wallet->credit( 
					$customer_id, 
					$refund_amount, 
					sprintf( __( 'RTO Refund for Order #%d (Shipping Penalty: ₹%s Deducted)', 'zerohold-shipping' ), $order_id, $total_penalty ) 
				);
			}

			// Move order to refunded state (internal)
			update_post_meta( $order_id, '_zh_rto_buyer_refund_amount', $refund_amount );
			update_post_meta( $order_id, '_zh_rto_buyer_penalty_amount', $total_penalty );

			error_log( "ZSS RTO: Refunded ₹{$refund_amount} to Buyer #{$customer_id} (Penalty: ₹{$total_penalty})" );
		} else {
			error_log( "ZSS RTO: Penalty (₹{$total_penalty}) exceeds order total (₹{$order_total}). No refund issued to buyer." );
		}
	}
}
