<?php

namespace Zerohold\Shipping\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Commission Settings Admin Page
 * 
 * Allows admin to set global commission percentage for all vendors
 */
class CommissionSettings {

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'register_menu' ], 61 );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
	}

	/**
	 * Register admin menu page
	 */
	public function register_menu() {
		add_submenu_page(
			'zerohold-settings',
			__( 'Commission Settings', 'zerohold-shipping' ),
			__( 'Commission Settings', 'zerohold-shipping' ),
			'manage_options',
			'zh-commission-settings',
			[ $this, 'render_page' ]
		);
	}

	/**
	 * Register settings
	 */
	public function register_settings() {
		register_setting( 'zh_commission_settings', 'zh_platform_commission_rate', [
			'type'              => 'number',
			'sanitize_callback' => [ $this, 'sanitize_commission_rate' ],
			'default'           => 0,
		] );
	}

	/**
	 * Sanitize commission rate (0-100)
	 */
	public function sanitize_commission_rate( $value ) {
		$value = floatval( $value );
		if ( $value < 0 ) {
			return 0;
		}
		if ( $value > 100 ) {
			return 100;
		}
		return $value;
	}

	/**
	 * Render admin page
	 */
	public function render_page() {
		// Handle form submission
		if ( isset( $_POST['zh_save_commission_settings'] ) && check_admin_referer( 'zh_commission_settings_nonce' ) ) {
			$commission_rate = isset( $_POST['zh_platform_commission_rate'] ) ? floatval( $_POST['zh_platform_commission_rate'] ) : 0;
			$commission_rate = $this->sanitize_commission_rate( $commission_rate );
			
			update_option( 'zh_platform_commission_rate', $commission_rate );
			echo '<div class="notice notice-success"><p>' . __( 'Commission settings saved successfully.', 'zerohold-shipping' ) . '</p></div>';
		}

		// Get current value
		$commission_rate = get_option( 'zh_platform_commission_rate', 0 );

		?>
		<div class="wrap">
			<h1><?php _e( 'Platform Commission Settings', 'zerohold-shipping' ); ?></h1>
			<p><?php _e( 'Set a global commission percentage that will be deducted from vendor earnings when orders are completed.', 'zerohold-shipping' ); ?></p>

			<form method="post" action="">
				<?php wp_nonce_field( 'zh_commission_settings_nonce' ); ?>

				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="zh_platform_commission_rate"><?php _e( 'Commission Rate (%)', 'zerohold-shipping' ); ?></label>
						</th>
						<td>
							<input type="number" 
								   name="zh_platform_commission_rate" 
								   id="zh_platform_commission_rate" 
								   value="<?php echo esc_attr( $commission_rate ); ?>" 
								   min="0" 
								   max="100" 
								   step="0.01" 
								   class="regular-text">
							<p class="description">
								<?php _e( 'Enter a percentage between 0 and 100. This commission is calculated on product value only (excluding shipping).', 'zerohold-shipping' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<div class="zh-commission-info" style="background: #f0f6fc; border-left: 4px solid #0073aa; padding: 15px; margin: 20px 0;">
					<h3 style="margin-top: 0;"><?php _e( 'How Commission Works', 'zerohold-shipping' ); ?></h3>
					<ul style="margin-left: 20px;">
						<li><?php _e( '<strong>Trigger</strong>: Commission is deducted when order status changes to "Completed"', 'zerohold-shipping' ); ?></li>
						<li><?php _e( '<strong>Calculation</strong>: Commission = Product Value × (Rate / 100)', 'zerohold-shipping' ); ?></li>
						<li><?php _e( '<strong>Permanence</strong>: Once deducted, commission is NEVER refunded (even if return/refund initiated)', 'zerohold-shipping' ); ?></li>
						<li><?php _e( '<strong>Statement</strong>: Appears as separate "Platform Commission" debit entry', 'zerohold-shipping' ); ?></li>
					</ul>
					
					<h4><?php _e( 'Example', 'zerohold-shipping' ); ?></h4>
					<p>
						<?php 
						$example_rate = max( 10, $commission_rate );
						$example_product = 88;
						$example_commission = round( $example_product * ( $example_rate / 100 ), 2 );
						$example_net = $example_product - $example_commission;
						
						printf( 
							__( 'Order with product value ₹%1$s at %2$s%% commission:<br>Vendor receives: ₹%1$s - ₹%3$s = <strong>₹%4$s</strong>', 'zerohold-shipping' ),
							$example_product,
							$example_rate,
							$example_commission,
							$example_net
						);
						?>
					</p>
				</div>

				<?php submit_button( __( 'Save Changes', 'zerohold-shipping' ), 'primary', 'zh_save_commission_settings' ); ?>
			</form>
		</div>
		<?php
	}
}
