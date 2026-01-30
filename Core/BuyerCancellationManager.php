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
		
		// 4. Show cancellation notice on vendor order detail page
		add_action( 'dokan_order_detail_after_order_items', [ $this, 'show_vendor_cancellation_notice' ] );
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

					if (stage === 'transit') {
						msg = '<?php _e("Your parcel is already in transit. Cancellation now will incur a full shipping penalty (Base Price + Profit Cap). Do you still want to proceed?", "zerohold-shipping"); ?>';
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
			// Check for transit (Lightweight Check for UI)
			$is_shipped = get_post_meta( $order_id, '_zh_shiprocket_pickup_status', true ) == 1 || get_post_meta( $order_id, '_zh_bigship_pickup_status', true ) == 1;
			
			if ( $is_shipped ) {
				$stage = 'transit';
			} else {
				$stage = 'post-label';
			}
		} else {
			// Stage 1: No Label yet (Full Refund)
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
			// REAL-TIME TRANSIT CHECK (API Based)
			if ( $this->is_order_in_transit_realtime( $order_id ) ) {
				$stage = 'transit';
			} else {
				$stage = 'post-label';
			}
		} else {
			// No label exists - check if order is currently visible to vendor
			if ( $is_visible === 'yes' ) {
				$stage = 'post-cooloff-no-label';
			} else {
				$stage = 'cool-off';
			}
		}

		// 1. Calculate Refund Amount
		$order_total = (float) $order->get_total();
		$refund_amount = $order_total;
		$note = '';

		if ( $stage === 'cool-off' ) {
			$note = __( 'Buyer cancelled order during cool-off window.', 'zerohold-shipping' );
			
			// Mark as PERMANENTLY hidden from vendor (since it was cancelled during cool-off)
			update_post_meta( $order_id, '_zh_vendor_visible', 'no' ); 
			update_post_meta( $order_id, '_zh_buyer_cancelled_during_cooloff', 'yes' );
		} elseif ( $stage === 'post-cooloff-no-label' ) {
			// Cancelled after cool-off ended but before label generation
			$note = __( 'Buyer cancelled order (No label generated).', 'zerohold-shipping' );
			
			// Keep order visible to vendor so they see the cancellation
			update_post_meta( $order_id, '_zh_vendor_visible', 'yes' );
			update_post_meta( $order_id, '_zh_buyer_cancelled_post_cooloff', 'yes' );
		} elseif ( $stage === 'post-label' ) {
			// Stage: post-label (Packed but NOT in transit)
			// Get actual shipping amount paid by buyer (not vendor cost)
			$shipping_total = (float) $order->get_shipping_total();
			$refund_amount  = $order_total - $shipping_total;
			$note = __( 'Buyer cancelled order after label generation.', 'zerohold-shipping' );
			
			// Ensure it remains visible to vendor so they see the cancellation
			update_post_meta( $order_id, '_zh_vendor_visible', 'yes' );
			update_post_meta( $order_id, '_zh_buyer_cancelled_post_label', 'yes' );
			
			// REFUND VENDOR SHIPPING COST
			$this->refund_vendor_shipping_cost( $order_id );
		} else {
			// Stage: transit (Scenario D)
			// Penalty = Base Freight + Retailer Cap
			$base_freight = (float) get_post_meta( $order_id, '_zh_base_shipping_cost', true );
			$retailer_cap = (float) get_post_meta( $order_id, '_zh_retailer_cap_amount', true );
			
			// If meta is missing (old order), fallback to buyer's shipping total
			if ( $base_freight <= 0 ) {
				$base_freight = (float) $order->get_shipping_total();
			}

			$total_penalty = $base_freight + $retailer_cap;
			$refund_amount  = max( 0, $order_total - $total_penalty );
			$note = sprintf( 
				__( 'Buyer cancelled order DURING TRANSIT. Penalty Applied: ₹%s (Base ₹%s + Cap ₹%s).', 'zerohold-shipping' ), 
				$total_penalty, $base_freight, $retailer_cap 
			);
			
			update_post_meta( $order_id, '_zh_vendor_visible', 'yes' );
			update_post_meta( $order_id, '_zh_buyer_cancelled_transit', 'yes' );
			update_post_meta( $order_id, '_zh_transit_penalty_amount', $total_penalty );

			// VENDOR REFUND: Still refund vendor their share, as they have no fault.
			$this->refund_vendor_shipping_cost( $order_id );
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

	/**
	 * Show a prominent notice on vendor order detail page if order was cancelled by buyer.
	 */
	public function show_vendor_cancellation_notice( $order ) {
		if ( ! $order ) return;
		
		$order_id = is_numeric( $order ) ? $order : $order->get_id();
		$order_obj = wc_get_order( $order_id );
		
		if ( ! $order_obj || $order_obj->get_status() !== 'cancelled' ) {
			return;
		}
		
		// 1. Check if this was a buyer cancellation via meta flags or notes
		$is_cooloff_cancel = get_post_meta( $order_id, '_zh_buyer_cancelled_during_cooloff', true ) === 'yes';
		$is_post_cooloff   = get_post_meta( $order_id, '_zh_buyer_cancelled_post_cooloff', true ) === 'yes';
		$is_post_label     = get_post_meta( $order_id, '_zh_buyer_cancelled_post_label', true ) === 'yes';
		
		if ( ! $is_cooloff_cancel && ! $is_post_cooloff && ! $is_post_label ) {
			// Fallback: Check notes if meta isn't set (for older orders during transition)
			$notes = wc_get_order_notes( [ 'order_id' => $order_id, 'limit' => 5 ] );
			foreach ( $notes as $note ) {
				if ( stripos( $note->content, 'Buyer cancelled' ) !== false ) {
					if ( stripos( $note->content, 'label generation' ) !== false ) $is_post_label = true;
					else $is_post_cooloff = true;
					break;
				}
			}
		}
		
		if ( ! $is_cooloff_cancel && ! $is_post_cooloff && ! $is_post_label ) {
			return;
		}
		
		// 2. Determine titles and messages (Vendor Facing)
		$title = __( '⚠️ This order was cancelled by the retailer.', 'zerohold-shipping' );
		$body  = '';

		if ( $is_post_label ) {
			$body = __( 'Your shipping charges (which were deducted) have been refunded. Please check your statement for the shipping refund entry.', 'zerohold-shipping' );
		} elseif ( $is_post_cooloff ) {
			$body = __( 'No shipping label was generated for this order, so no shipping charges were deducted.', 'zerohold-shipping' );
		} else {
			// Hidden usually, but for completeness:
			$body = __( 'This order was cancelled during the cool-off window.', 'zerohold-shipping' );
		}
		
		?>
		<div class="zh-buyer-cancellation-notice" style="
			background: #fff3cd;
			border-left: 4px solid #ff9800;
			padding: 15px 20px;
			margin: 20px 0;
			border-radius: 4px;
			box-shadow: 0 2px 4px rgba(0,0,0,0.1);
		">
			<h4 style="margin: 0 0 8px 0; color: #856404; font-size: 16px;">
				<?php echo esc_html( $title ); ?>
			</h4>
			<p style="margin: 0; color: #856404; font-size: 14px;">
				<?php echo esc_html( $body ); ?>
			</p>
		</div>
		<?php
	}

	private function refund_vendor_shipping_cost( $order_id ) {
		// Get the vendor ID
		$vendor_id = 0;
		if ( function_exists( 'dokan_get_seller_id_by_order' ) ) {
			$vendor_id = dokan_get_seller_id_by_order( $order_id );
		} else {
			$order = wc_get_order( $order_id );
			if ( $order ) {
				$vendor_id = $order->get_meta( '_dokan_vendor_id', true );
			}
		}

		if ( ! $vendor_id ) {
			error_log( "ZSS: Cannot refund vendor shipping - vendor ID not found for order #{$order_id}" );
			return;
		}

		// Get the shipping cost that was deducted from vendor
		$shipping_cost = (float) get_post_meta( $order_id, '_zh_shipping_cost', true );

		if ( $shipping_cost <= 0 ) {
			error_log( "ZSS: No shipping cost to refund for order #{$order_id}" );
			return;
		}

		// Store refund data in order meta - DokanStatementIntegration will pick it up automatically
		update_post_meta( $order_id, '_zh_shipping_refund_amount', $shipping_cost );
		update_post_meta( $order_id, '_zh_shipping_refund_date', current_time( 'mysql' ) );
		update_post_meta( $order_id, '_zh_shipping_refunded_to_vendor', 'yes' );

		// Add order note
		$order = wc_get_order( $order_id );
		if ( $order ) {
			$order->add_order_note( 
				sprintf( __( 'Vendor shipping cost (₹%s) will be refunded via Dokan statement due to buyer cancellation.', 'zerohold-shipping' ), $shipping_cost )
			);
		}

		error_log( "ZSS: Scheduled ₹{$shipping_cost} shipping refund for vendor #{$vendor_id} for order #{$order_id}" );
	}

	/**
	 * Real-time check if an order is in transit based on API scan histories.
	 * 
	 * @param int $order_id
	 * @return bool
	 */
	private function is_order_in_transit_realtime( $order_id ) {
		$platform    = get_post_meta( $order_id, '_zh_shipping_platform', true );
		$awb         = get_post_meta( $order_id, '_zh_shiprocket_awb', true ) ?: get_post_meta( $order_id, '_zh_awb', true );
		$lrn         = get_post_meta( $order_id, '_zh_bigship_lr_number', true );
		$tracking_id = ( $platform === 'bigship' && ! empty( $lrn ) ) ? $lrn : $awb;
		$type        = ( $platform === 'bigship' && ! empty( $lrn ) ) ? 'lrn' : 'awb';

		if ( ! $tracking_id ) return false;

		// Get Adapter
		$adapter = null;
		if ( $platform === 'shiprocket' ) {
			$adapter = new \Zerohold\Shipping\Platforms\ShiprocketAdapter();
		} elseif ( $platform === 'bigship' ) {
			$adapter = new \Zerohold\Shipping\Platforms\BigShipAdapter();
		}

		if ( ! $adapter ) return false;

		// Fetch Real-time Tracking
		$tracking = ( $platform === 'bigship' ) ? $adapter->track( $tracking_id, $type ) : $adapter->track( $tracking_id );

		// logic: If scan_histories has ANY entries, it means it's with the courier
		if ( ! empty( $tracking['data']['scan_histories'] ) ) {
			return true;
		}

		return false;
	}
}
