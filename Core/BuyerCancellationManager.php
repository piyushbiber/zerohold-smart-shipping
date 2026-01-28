<?php

namespace Zerohold\Shipping\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * BuyerCancellationManager Class
 * 
 * Handles order cancellation by buyers during the cool-off window.
 */
class BuyerCancellationManager {

	public function __construct() {
		// 1. Add "Cancel" button to My Account -> Orders
		add_filter( 'woocommerce_my_account_my_orders_actions', [ $this, 'add_cancel_button' ], 10, 2 );

		// 2. Handle the cancellation request
		add_action( 'template_redirect', [ $this, 'handle_cancellation_request' ] );

		// 3. Style and Confirmation
		add_action( 'wp_head', [ $this, 'add_frontend_scripts' ] );
	}

	/**
	 * Add CSS and JS for the button.
	 */
	public function add_frontend_scripts() {
		if ( ! is_account_page() ) return;
		?>
		<style>
			.woocommerce-button.button.zh_cancel {
				background-color: #E74C3C !important;
				color: #fff !important;
				margin-left: 5px;
			}
			.woocommerce-button.button.zh_cancel:hover {
				background-color: #C0392B !important;
			}
			/* Matching Woodmart's flat style */
			.woocommerce-MyAccount-orders .button.zh_cancel {
				padding: 5px 15px;
				text-transform: uppercase;
				font-size: 12px;
				font-weight: 600;
				border-radius: 0;
			}
		</style>
		<script>
		document.addEventListener('DOMContentLoaded', function() {
			document.body.addEventListener('click', function(e) {
				if (e.target && e.target.classList.contains('zh_cancel')) {
					if (!confirm('<?php _e("Are you sure you want to cancel this order? The total amount will be refunded to your wallet immediately.", "zerohold-shipping"); ?>')) {
						e.preventDefault();
					}
				}
			});
		});
		</script>
		<?php
	}

	/**
	 * Add Cancel button to the actions list if order is in cool-off.
	 */
	public function add_cancel_button( $actions, $order ) {
		if ( ! $order ) return $actions;

		$order_id = $order->get_id();

		// Check visibility meta
		$is_visible = get_post_meta( $order_id, '_zh_vendor_visible', true );
		$unlock_at  = (int) get_post_meta( $order_id, '_zh_visibility_unlock_at', true );

		// Only show if invisible and time remains
		if ( $is_visible === 'no' && $unlock_at > time() && $order->has_status( ['on-hold', 'processing', 'pending'] ) ) {
			$actions['zh_cancel'] = [
				'url'  => wp_nonce_url( add_query_arg( 'zh_cancel_order', $order_id ), 'zh_cancel_order_nonce' ),
				'name' => __( 'Cancel', 'zerohold-shipping' ),
			];
		}

		return $actions;
	}

	/**
	 * Handle the actual cancellation and refund.
	 */
	public function handle_cancellation_request() {
		if ( ! isset( $_GET['zh_cancel_order'] ) || ! isset( $_GET['_wpnonce'] ) ) {
			return;
		}

		$order_id = intval( $_GET['zh_cancel_order'] );

		if ( ! wp_verify_nonce( $_GET['_wpnonce'], 'zh_cancel_order_nonce' ) ) {
			wc_add_notice( __( 'Security check failed.', 'zerohold-shipping' ), 'error' );
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order || $order->get_customer_id() !== get_current_user_id() ) {
			wc_add_notice( __( 'Invalid order.', 'zerohold-shipping' ), 'error' );
			return;
		}

		// Re-verify cool-off status
		$is_visible = get_post_meta( $order_id, '_zh_vendor_visible', true );
		$unlock_at  = (int) get_post_meta( $order_id, '_zh_visibility_unlock_at', true );

		if ( $is_visible !== 'no' || $unlock_at <= time() ) {
			wc_add_notice( __( 'Cancellation window has closed. Please contact support.', 'zerohold-shipping' ), 'error' );
			return;
		}

		// Mark as PERMANENTLY hidden from vendor (since it was cancelled during cool-off)
		update_post_meta( $order_id, '_zh_vendor_visible', 'no' ); 
		update_post_meta( $order_id, '_zh_buyer_cancelled_during_cooloff', 'yes' );

		// 1. Perform Refund
		$this->process_immediate_refund( $order );

		// 2. Update Order Status
		$order->update_status( 'cancelled', __( 'Buyer cancelled order during cool-off window.', 'zerohold-shipping' ) );

		// 3. Clear visibility cache (though it shouldn't matter as it's cancelled)
		if ( class_exists( 'Zerohold\Shipping\Core\OrderVisibilityManager' ) ) {
			$ovm = new OrderVisibilityManager();
			$ovm->clear_order_visibility_cache( $order_id );
		}

		wc_add_notice( __( 'Order cancelled and refund processed successfully.', 'zerohold-shipping' ), 'success' );

		wp_safe_redirect( wc_get_endpoint_url( 'orders', '', wc_get_page_permalink( 'myaccount' ) ) );
		exit;
	}

	/**
	 * Process full refund.
	 */
	private function process_immediate_refund( $order ) {
		$amount  = $order->get_total();
		$user_id = $order->get_customer_id();

		// Priority: TerraWallet (as requested in context)
		if ( function_exists( 'woo_wallet' ) ) {
			woo_wallet()->wallet->credit( 
				$user_id, 
				$amount, 
				sprintf( __( 'Refund for Order #%d Cancelled during cool-off.', 'zerohold-shipping' ), $order->get_id() ) 
			);
			$order->add_order_note( sprintf( __( 'Refunded ₹%s to TerraWallet.', 'zerohold-shipping' ), $amount ) );
			return;
		}

		// Fallback: Standard WooCommerce Refund
		wc_create_refund( array(
			'amount'         => $amount,
			'reason'         => __( 'Buyer cancelled during cool-off.', 'zerohold-shipping' ),
			'order_id'       => $order->get_id(),
			'refund_payment' => true,
		) );
	}
}
