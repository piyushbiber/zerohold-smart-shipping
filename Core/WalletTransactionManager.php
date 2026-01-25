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

	public function __construct() {
		// Hook to add meta after TeraWallet creates a transaction
		add_action( 'woo_wallet_transaction_recorded', [ $this, 'add_shipping_meta' ], 10, 3 );
	}

	/**
	 * Add shipping meta to wallet transaction after it's created.
	 */
	public function add_shipping_meta( $transaction_id, $user_id, $transaction_data ) {
		// Only process if this is our shipping transaction
		if ( isset( $transaction_data['details'] ) && strpos( $transaction_data['details'], 'Shipping Charge for Order' ) !== false ) {
			// Extract order ID from details
			preg_match( '/#(\d+)/', $transaction_data['details'], $matches );
			$order_id = isset( $matches[1] ) ? $matches[1] : 0;

			// Add meta using TeraWallet's functions
			woo_wallet()->wallet->update_transaction_meta( $transaction_id, 'zh_shipping', 'yes' );
			woo_wallet()->wallet->update_transaction_meta( $transaction_id, 'transaction_type', 'shipping' );
			woo_wallet()->wallet->update_transaction_meta( $transaction_id, 'order_id', $order_id );
			woo_wallet()->wallet->update_transaction_meta( $transaction_id, 'currency', get_woocommerce_currency() );

			error_log( "ZSS WALLET: Added meta to transaction #{$transaction_id} for order #{$order_id}" );
		}
	}

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
			'user_id'  => $vendor_id,
			'amount'   => $amount,
			'type'     => 'debit',
			'details'  => sprintf( __( 'Shipping Charge for Order #%s', 'zerohold-shipping' ), $order_id ),
		];

		error_log( "ZSS WALLET: Transaction data prepared: " . print_r( $transaction_data, true ) );

		// Try direct object method first (Primary TeraWallet API)
		if ( function_exists( 'woo_wallet' ) && isset( woo_wallet()->wallet ) ) {
			$transaction_id = woo_wallet()->wallet->debit( $vendor_id, $amount, $transaction_data['details'] );
			error_log( "ZSS WALLET: Debit result (Transaction ID): " . print_r( $transaction_id, true ) );
			
			// Meta will be added via the hook 'woo_wallet_transaction_recorded'
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
