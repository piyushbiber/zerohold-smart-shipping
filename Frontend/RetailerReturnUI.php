<?php

namespace Zerohold\Shipping\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RetailerReturnUI {

	public function __construct() {
		error_log( "ZSS DEBUG: RetailerReturnUI initialized." );
		// Hook for WP Swings Refund Form Footer
		// Shows button to the customer on the refund request view
		add_action( 'wps_rma_refund_form_footer', [ $this, 'add_generate_label_button' ] );

		// Extra Hook: Just in case the specific one doesn't fire
		add_action( 'woocommerce_order_details_after_order_table', [ $this, 'add_generate_label_button' ] );
	}

	/**
	 * Injects the "Generate Return Label" button into the WP Swings form.
	 */
	public function add_generate_label_button() {
		error_log( "ZSS DEBUG: RetailerReturnUI::add_generate_label_button hook fired!" );
		
		// 1. Get Order ID from context
		$order_id = $this->get_current_order_id();
		error_log( "ZSS DEBUG: Frontend Order Detection: " . ($order_id ?: 'FAILED') );

		if ( ! $order_id ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			error_log( "ZSS DEBUG: Order Object FAILED for #$order_id" );
			return;
		}

		// 2. Security: Verify current user is the buyer
		$uid = get_current_user_id();
		$cid = (int) $order->get_customer_id();
		error_log( "ZSS DEBUG: Security Check - Current User: $uid, Order Customer: $cid" );

		if ( $uid !== $cid && ! current_user_can( 'manage_options' ) ) {
			error_log( "ZSS DEBUG: Permission Denied" );
			return;
		}

		// 3. Logic Guard: Refund Status must be "Approved" (Trusting Order Status)
		$status = $order->get_status();
		$is_approved = $this->is_refund_approved( $order_id );
		error_log( "ZSS DEBUG: Status Check - '$status', Approved: " . ($is_approved ? 'YES' : 'NO') );

		if ( ! $is_approved ) {
			return;
		}

		// 4. Logic Guard: Already generated?
		$return_shipment_id = get_post_meta( $order_id, '_zh_return_shipment_id', true );
		if ( $return_shipment_id ) {
			?>
			<div style="margin-top: 20px; padding: 15px; background: #e8f5e9; border: 1px solid #c8e6c9; border-radius: 5px; color: #2e7d32;">
				<strong>✅ Return Label Generated</strong>
				<p style="margin: 5px 0 0 0; font-size: 14px;">Your return shipment (ID: <?php echo esc_html( $return_shipment_id ); ?>) has been created. The pickup is being scheduled.</p>
			</div>
			<?php
			return;
		}

		// 5. Render Button
		?>
		<div class="zh-buyer-return-action" style="margin-top: 20px; display: inline-block;">
			<button type="button" 
					id="zh-buyer-generate-return-label" 
					class="button alt" 
					data-order-id="<?php echo esc_attr( $order_id ); ?>"
					style="background: #e67e22; border-color: #d35400; color: #fff; padding: 10px 25px; font-weight: bold; border-radius: 4px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); cursor: pointer; transition: background 0.2s;">
				<?php _e( 'GENERATE RETURN LABEL', 'zerohold-shipping' ); ?>
			</button>
			<span id="zh-buyer-return-status-spinner" style="display:none; margin-left:10px; font-size: 14px;">⏳ Processing...</span>
		</div>

		<script>
		jQuery(function($) {
			const ajaxUrl = '<?php echo admin_url( "admin-ajax.php" ); ?>';
			
			$('#zh-buyer-generate-return-label').on('click', function(e) {
				e.preventDefault();
				const btn = $(this);
				const spinner = $('#zh-buyer-return-status-spinner');
				const orderId = btn.data('order-id');

				if (!confirm('This will generate your return shipping label. Proceed?')) return;

				btn.prop('disabled', true).css({ 'opacity': '0.5', 'cursor': 'not-allowed' });
				spinner.show();

				$.post(ajaxUrl, {
					action: 'zh_initiate_return_shipping',
					order_id: orderId,
					security: '<?php echo wp_create_nonce("zh_return_nonce"); ?>'
				}, function(res) {
					spinner.hide();
					if (res.success) {
						alert(res.data || 'Return label generated! Page will reload.');
						location.reload();
					} else {
						alert('Error: ' + (res.data || 'Unknown error occurred.'));
						btn.prop('disabled', false).css({ 'opacity': '1', 'cursor': 'pointer' });
					}
				}).fail(function() {
					spinner.hide();
					alert('Connection error. Please contact support.');
					btn.prop('disabled', false).css({ 'opacity': '1', 'cursor': 'pointer' });
				});
			});
		});
		</script>
		<?php
	}

	/**
	 * Attempts to find the current Order ID from the request context.
	 */
	private function get_current_order_id() {
		// Method A: Query Parameter
		if ( ! empty( $_GET['order_id'] ) ) {
			return intval( $_GET['order_id'] );
		}
		
		// Method B: WP Swings specific URL param
		if ( ! empty( $_GET['order'] ) ) {
			return intval( $_GET['order'] );
		}

		// Method C: WooCommerce My Account View Order endpoint
		global $wp;
		if ( isset( $wp->query_vars['view-order'] ) ) {
			return intval( $wp->query_vars['view-order'] );
		}

		// Method D: Parse from URL if path is /view-order/123/
		$path = trim( $_SERVER['REQUEST_URI'], '/' );
		$parts = explode( '/', $path );
		foreach ( $parts as $part ) {
			if ( is_numeric( $part ) && intval( $part ) > 1000 ) { // Basic sanity check
				return intval( $part );
			}
		}

		return 0;
	}

	/**
	 * Checks if the refund request is approved for the given order.
	 */
	private function is_refund_approved( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return false;
		}

		$status = $order->get_status();
		
		// Map of statuses that imply the return has been approved/validated
		// WP Swings uses 'return-approved' or 'refund-requested'
		$approved_statuses = [ 
			'return-approved', 
			'wc-return-approved', 
			'refund-requested', 
			'wc-refund-requested'
		];

		return in_array( $status, $approved_statuses );
	}
}
