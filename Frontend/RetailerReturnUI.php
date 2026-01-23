<?php

namespace Zerohold\Shipping\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

	private static $has_rendered = false;

	public function __construct() {
		// Hook for WP Swings Refund Form Footer
		add_action( 'wps_rma_refund_form_footer', [ $this, 'add_generate_label_button' ] );

		// Extra Fallback Hooks (Only one will render thanks to static flag)
		add_action( 'woocommerce_order_details_after_order_table', [ $this, 'add_generate_label_button' ] );
		add_action( 'woocommerce_after_order_details', [ $this, 'add_generate_label_button' ] );
	}

	/**
	 * Injects the "Generate Return Label" button into the WP Swings form.
	 */
	public function add_generate_label_button() {
		if ( self::$has_rendered ) {
			return;
		}

		// 1. Get Order ID from context
		$order_id = $this->get_current_order_id();
		if ( ! $order_id ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		// 2. Security: Verify current user is the buyer
		$uid = get_current_user_id();
		$cid = (int) $order->get_customer_id();

		if ( $uid !== $cid && ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// 3. Logic Guard: Refund Status must be "Approved" (Trusting Order Status)
		if ( ! $this->is_refund_approved( $order_id ) ) {
			return;
		}

		// Mark as rendered so subsequent hooks exit early
		self::$has_rendered = true;

		// 4. Case A: Label Already Generated?
		$return_shipment_id = get_post_meta( $order_id, '_zh_return_shipment_id', true );
		if ( $return_shipment_id ) {
			$label_url = get_post_meta( $order_id, '_zh_return_label_url', true );
			?>
			<div class="zh-return-success-box" style="margin-top: 20px; padding: 20px; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; color: #166534; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 1px 3px rgba(0,0,0,0.05); font-family: sans-serif;">
				<div>
					<strong style="font-size: 16px; display: block; margin-bottom: 4px;">✅ Return Label Generated</strong>
					<p style="margin: 0; font-size: 14px; color: #15803d;">Shipment ID: <strong><?php echo esc_html( $return_shipment_id ); ?></strong></p>
				</div>
				<div style="display: flex; align-items: center; gap: 10px;">
					<?php if ( $label_url ) : ?>
						<a href="<?php echo esc_url( $label_url ); ?>" 
						   target="_blank" 
						   class="button" 
						   style="background: #166534 !important; color: #fff !important; border: none; padding: 12px 24px; border-radius: 6px; font-weight: 700; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; font-size: 14px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
							📄 PDF DOWNLOAD LABEL
						</a>
					<?php else: ?>
						<button type="button" 
								id="zh-refetch-return-label" 
								class="button" 
								data-order-id="<?php echo esc_attr( $order_id ); ?>"
								style="background: #eab308; color: #fff; border: none; padding: 8px 15px; border-radius: 4px; font-size: 13px; font-weight: 600; cursor: pointer;">
							🔄 REFRESH LABEL URL
						</button>
						<span id="zh-refetch-spinner" style="display:none; font-size: 12px;">⏳</span>
					<?php endif; ?>
				</div>
			</div>
			<script>
			jQuery(function($) {
				$('#zh-refetch-return-label').on('click', function() {
					const btn = $(this);
					const spinner = $('#zh-refetch-spinner');
					btn.prop('disabled', true).css('opacity', '0.5');
					spinner.show();
					$.post('<?php echo admin_url( "admin-ajax.php" ); ?>', {
						action: 'zh_refetch_return_label',
						order_id: btn.data('order-id'),
						security: '<?php echo wp_create_nonce("zh_return_nonce"); ?>'
					}, function(res) {
						location.reload();
					});
				});
			});
			</script>
			<?php
			return;
		}

		// 5. Case B: Render "Initiate" Button
		?>
		<div class="zh-buyer-return-action" style="margin-top: 20px; display: inline-block;">
			<button type="button" 
					id="zh-buyer-generate-return-label" 
					class="button alt" 
					data-order-id="<?php echo esc_attr( $order_id ); ?>"
					style="background: #e67e22; border-color: #d35400; color: #fff; padding: 12px 30px; font-weight: bold; border-radius: 6px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); cursor: pointer; transition: background 0.2s; font-family: sans-serif;">
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
						alert('Return label generated! Reloading page...');
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
