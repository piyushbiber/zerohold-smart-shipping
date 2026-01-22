<?php

namespace Zerohold\Shipping\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ReturnAdminUI {

	public function __construct() {
		// Add button to Admin Order Detail page
		add_action( 'woocommerce_admin_order_data_after_order_details', [ $this, 'add_return_shipping_button' ] );
		
		// Enqueue scripts helper
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
	}

	/**
	 * Adds the "Return Shipping" button to the order details screen.
	 */
	public function add_return_shipping_button( $order ) {
		$order_id = $order->get_id();
		
		// 1. Logic Guard: Only Delivered (Completed)
		if ( $order->get_status() !== 'completed' ) {
			return;
		}

		// 2. Logic Guard: WP Swings Refund Request exists
		if ( ! $this->has_wp_swings_refund_request( $order_id ) ) {
			// Optional: Show nothing or a disabled state if debugging
			return;
		}

		// 3. Logic Guard: Already generated?
		$return_shipment_id = get_post_meta( $order_id, '_zh_return_shipment_id', true );
		if ( $return_shipment_id ) {
			echo '<p class="form-field form-field-wide" style="border-top: 1px solid #eee; padding-top: 10px;">';
			echo '<strong>Return Shipping:</strong> <span class="status-badge" style="background:#27ae60; color:#fff; padding:2px 8px; border-radius:3px;">GENERTED</span>';
			echo '<br><small>ID: ' . esc_html( $return_shipment_id ) . '</small>';
			echo '</p>';
			return;
		}

		// Render the button
		?>
		<p class="form-field form-field-wide" style="border-top: 1px solid #eee; padding-top: 10px;">
			<button type="button" 
					id="zh-initiate-return-shipping" 
					class="button button-primary button-large" 
					data-order-id="<?php echo esc_attr( $order_id ); ?>"
					style="background: #e67e22; border-color: #d35400;">
				<?php _e( 'INITIATE RETURN SHIPPING', 'zerohold-shipping' ); ?>
			</button>
			<span id="zh-return-status-spinner" style="display:none; margin-left:10px;">⏳ Processing...</span>
		</p>
		<script>
		jQuery(function($) {
			$('#zh-initiate-return-shipping').on('click', function(e) {
				e.preventDefault();
				if (!confirm('This will create a NEW shipment from the Retailer to the Vendor. Proceed?')) return;

				const btn = $(this);
				const spinner = $('#zh-return-status-spinner');
				const orderId = btn.data('order-id');

				btn.prop('disabled', true);
				spinner.show();

				$.post(ajaxurl, {
					action: 'zh_initiate_return_shipping',
					order_id: orderId,
					security: '<?php echo wp_create_nonce("zh_return_nonce"); ?>'
				}, function(res) {
					spinner.hide();
					if (res.success) {
						alert(res.data || 'Return shipment created!');
						location.reload();
					} else {
						alert('Error: ' + res.data);
						btn.prop('disabled', false);
					}
				}).fail(function() {
					spinner.hide();
					alert('Connection error initiating return.');
					btn.prop('disabled', false);
				});
			});
		});
		</script>
		<?php
	}

	/**
	 * Checks if a refund request exists in WP Swings for this order.
	 */
	private function has_wp_swings_refund_request( $order_id ) {
		// Based on user feedback: WP Swings uses Refund Request flow.
		// We'll check for meta or existence of RMA request.
		// Standard WP Swings RMA meta is often _wps_rma_refund_request_status or similar.
		// Let's check common keys or a generic 'exists' check.
		
		// If we don't have exact table name, we'll try to find any meta starting with _wps_rma
		$all_meta = get_post_meta( $order_id );
		foreach ( $all_meta as $key => $values ) {
			if ( strpos( $key, '_wps_rma' ) !== false ) {
				return true; 
			}
		}

		// Also check if WP Swings has a specific flag for "Requested"
		// Placeholder for actual implementation if user provides more DB details
		return false; 
	}

	public function enqueue_admin_assets() {
		// We can add CSS here if needed
	}
}
