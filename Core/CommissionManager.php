<?php

namespace Zerohold\Shipping\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Commission Manager
 * 
 * Handles platform commission deduction when orders are completed
 */
class CommissionManager {

	public function __construct() {
		// Hook into order status change to "completed"
		add_action( 'woocommerce_order_status_completed', [ $this, 'deduct_commission' ], 10, 1 );
	}

	/**
	 * Deduct commission when order is completed
	 * 
	 * @param int $order_id WooCommerce order ID
	 */
	public function deduct_commission( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		// Check if commission already deducted
		$already_deducted = get_post_meta( $order_id, '_zh_commission_deducted', true );
		if ( $already_deducted === '1' ) {
			error_log( "ZSS: Commission already deducted for Order #{$order_id}" );
			return;
		}

		// Get commission rate
		$commission_rate = get_option( 'zh_platform_commission_rate', 0 );
		if ( $commission_rate <= 0 ) {
			error_log( "ZSS: Commission rate is 0%, skipping for Order #{$order_id}" );
			return;
		}

		// Get vendor ID
		$vendor_id = dokan_get_seller_id_by_order( $order_id );
		if ( ! $vendor_id ) {
			error_log( "ZSS: No vendor found for Order #{$order_id}" );
			return;
		}

		// Calculate commission on product value only (exclude shipping)
		$product_total = (float) $order->get_subtotal(); // Product subtotal (before discounts)
		$commission_amount = round( $product_total * ( $commission_rate / 100 ), 2 );

		if ( $commission_amount <= 0 ) {
			error_log( "ZSS: Commission amount is ₹0, skipping for Order #{$order_id}" );
			return;
		}

		error_log( "ZSS: Deducting commission for Order #{$order_id}: ₹{$commission_amount} ({$commission_rate}% of ₹{$product_total})" );

		// Deduct from vendor wallet using Dokan's method
		$this->deduct_from_vendor_wallet( $vendor_id, $order_id, $commission_amount );

		// Mark as deducted
		update_post_meta( $order_id, '_zh_commission_deducted', '1' );
		update_post_meta( $order_id, '_zh_commission_amount', $commission_amount );
		update_post_meta( $order_id, '_zh_commission_rate', $commission_rate );

		// Fire action for ZeroHold Finance plugin
		do_action( 'zerohold_commission_charged', [
			'vendor_id'         => $vendor_id,
			'order_id'          => $order_id,
			'commission_amount' => $commission_amount,
			'commission_rate'   => $commission_rate,
			'product_total'     => $product_total
		] );

		error_log( "ZSS: Commission deducted successfully for Order #{$order_id}" );
	}

	/**
	 * Deduct commission from vendor wallet
	 * 
	 * @param int   $vendor_id         Vendor user ID
	 * @param int   $order_id          Order ID
	 * @param float $commission_amount Commission amount to deduct
	 */
	private function deduct_from_vendor_wallet( $vendor_id, $order_id, $commission_amount ) {
		global $wpdb;

		// Insert debit entry into Dokan vendor balance table
		$wpdb->insert(
			$wpdb->prefix . 'dokan_vendor_balance',
			[
				'vendor_id'    => $vendor_id,
				'trn_id'       => $order_id,
				'trn_type'     => 'platform_commission',
				'perticulars'  => sprintf( 'Platform Commission (Order #%d)', $order_id ),
				'debit'        => $commission_amount,
				'credit'       => 0,
				'status'       => 'approved',
				'trn_date'     => current_time( 'mysql' ),
				'balance_date' => current_time( 'mysql' ),
			],
			[ '%d', '%d', '%s', '%s', '%f', '%f', '%s', '%s', '%s' ]
		);

		error_log( "ZSS: Commission entry added to vendor balance table for Vendor #{$vendor_id}" );
	}
}
