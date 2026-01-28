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
				const btn = e.target.closest('.zh_cancel');
				if (btn) {
					const stage = btn.getAttribute('data-stage');
					let msg = '<?php _e("Are you sure you want to cancel this order? The total amount will be refunded to your wallet immediately.", "zerohold-shipping"); ?>';
					
					if (stage === 'post-label') {
						msg = '<?php _e("Your order is already packed and a shipping label has been generated. If you cancel now, shipping charges will NOT be refunded. Do you still want to proceed?", "zerohold-shipping"); ?>';
					}

					if (!confirm(msg)) {
						e.preventDefault();
					}
				}
			});
		});
		</script>
		<?php
	}

	/**
	 * Add Cancel button to the actions list if order is in cool-off OR label generated but not shipped.
	 * Also keeps button visible in the "gap" between cool-off and label generation.
	 */
	public function add_cancel_button( $actions, $order ) {
		if ( ! $order ) return $actions;

		$order_id = $order->get_id();

		// Guard: Don't show if already shipped or completed
		if ( ! $order->has_status( ['on-hold', 'processing', 'pending'] ) ) {
			return $actions;
		}

		$is_visible   = get_post_meta( $order_id, '_zh_vendor_visible', true );
		$unlock_at    = (int) get_post_meta( $order_id, '_zh_visibility_unlock_at', true );
		$label_status = get_post_meta( $order_id, '_zh_shiprocket_label_status', true );

		// Determine Stage for Frontend
		$stage = 'none';

		if ( $label_status == 1 ) {
			// Stage 2: Label exists (Partial Refund)
			$stage = 'post-label';
		} else {
			// Stage 1: No Label yet (Full Refund)
			// This covers: 
			// 1. Cool-off window (invisible)
			// 2. The Gap (visible but no label yet)
			$stage = 'cool-off'; 
		}

		if ( $stage !== 'none' ) {
			$actions['zh_cancel'] = [
				'url'  => wp_nonce_url( add_query_arg( 'zh_cancel_order', $order_id ), 'zh_cancel_order_nonce' ),
				'name' => __( 'Cancel', 'zerohold-shipping' ),
			];

			// Inject JS to set the stage on the button
			echo '<script>document.addEventListener("DOMContentLoaded", function(){ 
				const btns = document.querySelectorAll(".zh_cancel[href*=\'zh_cancel_order=' . $order_id . '\']");
				btns.forEach(b => b.setAttribute("data-stage", "' . $stage . '"));
			});</script>';
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

		// Re-verify order status
		if ( ! $order->has_status( ['on-hold', 'processing', 'pending'] ) ) {
			wc_add_notice( __( 'Order cannot be cancelled at this stage.', 'zerohold-shipping' ), 'error' );
			return;
		}

		// Determine Stage
		$is_visible   = get_post_meta( $order_id, '_zh_vendor_visible', true );
		$unlock_at    = (int) get_post_meta( $order_id, '_zh_visibility_unlock_at', true );
		$label_status = get_post_meta( $order_id, '_zh_shiprocket_label_status', true );

		$stage = 'none';
		if ( $label_status == 1 ) {
			$stage = 'post-label';
		} else {
			// No label exists - check if order is currently visible to vendor
			if ( $is_visible === 'yes' ) {
				// Cool-off ended, order is visible - treat as post-cooloff for visibility purposes
				$stage = 'post-cooloff-no-label';
			} else {
				// Still in cool-off window (hidden from vendor)
				$stage = 'cool-off';
			}
		}

		// 1. Calculate Refund Amount
		$order_total = (float) $order->get_total();
		$refund_amount = $order_total;
		$note = '';

		if ( $stage === 'cool-off' ) {
			$note = __( 'Buyer cancelled order during cool-off window. Full refund processed.', 'zerohold-shipping' );
			
			// Mark as PERMANENTLY hidden from vendor (since it was cancelled during cool-off)
			update_post_meta( $order_id, '_zh_vendor_visible', 'no' ); 
			update_post_meta( $order_id, '_zh_buyer_cancelled_during_cooloff', 'yes' );
		} elseif ( $stage === 'post-cooloff-no-label' ) {
			// Cancelled after cool-off ended but before label generation
			$note = __( 'Buyer cancelled order. Full refund processed (no label was generated).', 'zerohold-shipping' );
			
			// Keep order visible to vendor so they see the cancellation
			update_post_meta( $order_id, '_zh_vendor_visible', 'yes' );
		} else {
			// Stage: post-label
			// Get actual shipping amount paid by buyer (not vendor cost)
			$shipping_total = (float) $order->get_shipping_total();
			$refund_amount  = $order_total - $shipping_total;
			$note = sprintf( __( 'Buyer cancelled order after label generation. Shipping charges (₹%s) were NOT refunded. Partial refund processed.', 'zerohold-shipping' ), $shipping_total );
			
			// Ensure it remains visible to vendor so they see the cancellation
			update_post_meta( $order_id, '_zh_vendor_visible', 'yes' );
		}

		// 2. Perform Refund
		$this->process_immediate_refund( $order, $refund_amount, $note );

		// 3. Update Order Status
		$order->update_status( 'cancelled', $note );

		// 4. Clear visibility cache
		if ( class_exists( 'Zerohold\Shipping\Core\OrderVisibilityManager' ) ) {
			$ovm = new OrderVisibilityManager();
			$ovm->clear_order_visibility_cache( $order_id );
		}

		wc_add_notice( __( 'Order cancelled successfully.', 'zerohold-shipping' ), 'success' );

		wp_safe_redirect( wc_get_endpoint_url( 'orders', '', wc_get_page_permalink( 'myaccount' ) ) );
		exit;
	}

	/**
	 * Process partial or full refund.
	 */
	private function process_immediate_refund( $order, $amount, $reason ) {
		$user_id = $order->get_customer_id();

		// Priority: TerraWallet
		if ( function_exists( 'woo_wallet' ) ) {
			woo_wallet()->wallet->credit( 
				$user_id, 
				$amount, 
				sprintf( __( 'Refund for Order #%d: %s', 'zerohold-shipping' ), $order->get_id(), $reason ) 
			);
			$order->add_order_note( sprintf( __( 'Refunded ₹%s to TerraWallet. Reason: %s', 'zerohold-shipping' ), $amount, $reason ) );
			return;
		}

		// Fallback: Standard WooCommerce Refund
		wc_create_refund( array(
			'amount'         => $amount,
			'reason'         => $reason,
			'order_id'       => $order->get_id(),
			'refund_payment' => true,
		) );
	}
}
