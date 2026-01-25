<?php

namespace Zerohold\Shipping\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles interactions with TeraWallet (WooWallet).
 * Ensures all shipping-related transactions are correctly tagged with metadata.
 */
class WalletTransactionManager {

	/**
	 * Debit shipping charge from vendor wallet.
	 *
	 * @param int    $order_id  The Order ID.
	 * @param float  $amount    The amount to debit.
	 * @param int    $vendor_id The Vendor ID (user ID).
	 * @return int|bool Transaction ID on success, false on failure.
	 */
	public static function debit_shipping_charge( $order_id, $amount, $vendor_id ) {
		error_log( "ZSS WALLET: Attempting to debit ₹{$amount} from vendor #{$vendor_id} for order #{$order_id}" );

		if ( ! function_exists( 'woo_wallet' ) && ! has_action( 'woo_wallet_debit_balance' ) ) {
			error_log( 'ZSS WALLET ERROR: TeraWallet not active. Cannot debit shipping charge.' );
			return false;
		}

		if ( $amount <= 0 ) {
			error_log( "ZSS WALLET ERROR: Invalid amount: {$amount}" );
			return false;
		}

		$transaction_data = [
			'blog_id'      => get_current_blog_id(),
			'user_id'      => $vendor_id,
			'amount'       => $amount,
			'type'         => 'debit',
			'details'      => sprintf( __( 'Shipping Charge for Order #%s', 'zerohold-shipping' ), $order_id ),
			'users_mapped' => [],
			'meta'         => [
				'zh_shipping'      => 'yes',
				'transaction_type' => 'shipping',
				'order_id'         => $order_id,
				'currency'         => get_woocommerce_currency(),
			]
		];

		error_log( "ZSS WALLET: Transaction data prepared: " . print_r( $transaction_data, true ) );

		// Try direct object method first (Primary TeraWallet API)
		if ( function_exists( 'woo_wallet' ) && isset( woo_wallet()->wallet ) ) {
			$transaction_id = woo_wallet()->wallet->debit( $transaction_data );
			error_log( "ZSS WALLET: Debit result (Transaction ID): " . print_r( $transaction_id, true ) );
			return $transaction_id;
		}
		
		// Fallback to action hook (Standard Hook API)
		error_log( "ZSS WALLET: Using fallback hook method" );
		do_action( 'woo_wallet_debit_balance', $transaction_data );
		return true;
	}

	/**
	 * Credit shipping refund to vendor wallet.
	 *
	 * @param int    $order_id  The Order ID.
	 * @param float  $amount    The amount to credit.
	 * @param int    $vendor_id The Vendor ID (user ID).
	 * @return int|bool Transaction ID on success, false on failure.
	 */
	public static function credit_shipping_refund( $order_id, $amount, $vendor_id ) {
		if ( ! function_exists( 'woo_wallet' ) && ! has_action( 'woo_wallet_credit_balance' ) ) {
			return false;
		}

		if ( $amount <= 0 ) {
			return false;
		}

		$transaction_data = [
			'blog_id'      => get_current_blog_id(),
			'user_id'      => $vendor_id,
			'amount'       => $amount,
			'type'         => 'credit',
			'details'      => sprintf( __( 'Shipping Refund for Order #%s', 'zerohold-shipping' ), $order_id ),
			'users_mapped' => [],
			'meta'         => [
				'zh_shipping'      => 'yes',
				'transaction_type' => 'shipping_refund',
				'order_id'         => $order_id,
				'currency'         => get_woocommerce_currency(),
			]
		];

		if ( function_exists( 'woo_wallet' ) && isset( woo_wallet()->wallet ) ) {
			return woo_wallet()->wallet->credit( $transaction_data );
		}

		do_action( 'woo_wallet_credit_balance', $transaction_data );
		return true;
	}
}
