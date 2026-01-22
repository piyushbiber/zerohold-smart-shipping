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
		$status   = $order->get_status();
		
		error_log( "ZSS DEBUG: Checking Return Button for Order #$order_id with Status: $status" );

		// 1. Logic Guard: Delivered (Completed) OR Refund Requested
		// WP Swings typical custom status is 'refund-requested'
		$allowed_statuses = [ 'completed', 'processing', 'refund-requested', 'wc-refund-requested' ];
		
		if ( ! in_array( $status, $allowed_statuses ) ) {
			error_log( "ZSS DEBUG: Status '$status' not in allowed list." );
			return;
		}

		// 2. Logic Guard: WP Swings Refund Request exists
		if ( ! $this->has_wp_swings_refund_request( $order_id ) ) {
			error_log( "ZSS DEBUG: No WP Swings Refund Request found for Order #$order_id" );
			return;
		}

		error_log( "ZSS DEBUG: Logic guards passed for Order #$order_id. Rendering button." );

		// 3. Logic Guard: Already generated?
		$return_shipment_id = get_post_meta( $order_id, '_zh_return_shipment_id', true );
		if ( $return_shipment_id ) {
			echo '<p class="form-field form-field-wide" style="border-top: 1px solid #eee; padding-top: 10px;">';
			echo '<strong>Return Shipping:</strong> <span class="status-badge" style="background:#27ae60; color:#fff; padding:2px 8px; border-radius:3px;">GENERATED</span>';
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
		$all_meta = get_post_meta( $order_id );
		foreach ( $all_meta as $key => $values ) {
			if ( strpos( $key, '_wps_' ) !== false ) {
				error_log( "ZSS DEBUG: Found WP Swings meta key: $key" );
				return true; 
			}
		}

		error_log( "ZSS DEBUG: No meta keys starting with _wps_ found for Order #$order_id" );
		return false; 
	}

	public function enqueue_admin_assets() {
		// We can add CSS here if needed
	}
}
