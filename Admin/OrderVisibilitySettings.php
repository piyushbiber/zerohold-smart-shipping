<?php

namespace Zerohold\Shipping\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * OrderVisibilitySettings Class
 * 
 * Manages the "Order Visibility" settings page under the ZeroHold menu.
 */
class OrderVisibilitySettings {

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'add_submenu' ], 30 );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
	}

	public function add_submenu() {
		add_submenu_page(
			'zerohold-settings',
			__( 'Order Visibility', 'zerohold-shipping' ),
			__( 'Order Visibility', 'zerohold-shipping' ),
			'manage_options',
			'zh-order-visibility',
			[ $this, 'render_page' ]
		);
	}

	public function register_settings() {
		register_setting( 'zh_order_visibility_group', 'zh_order_visibility_delay_value', [
			'type'              => 'integer',
			'sanitize_callback' => 'intval',
			'default'           => 2,
		] );

		register_setting( 'zh_order_visibility_group', 'zh_order_visibility_delay_unit', [
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => 'hours',
		] );
	}

	public function render_page() {
		?>
		<div class="wrap">
			<h1><?php _e( 'Order Visibility Configuration', 'zerohold-shipping' ); ?></h1>
			<p><?php _e( 'Configure the delay before orders become visible to vendors on their dashboard.', 'zerohold-shipping' ); ?></p>
			
			<form method="post" action="options.php">
				<?php settings_fields( 'zh_order_visibility_group' ); ?>
				
				<div class="zh-settings-section" style="background: #fff; padding: 25px; border: 1px solid #ccd0d4; border-radius: 4px; max-width: 800px;">
					<h2 class="title" style="margin-top:0; color: #2271b1; border-bottom: 2px solid #2271b1; padding-bottom: 10px; margin-bottom: 20px;">
						<span class="dashicons dashicons-clock" style="vertical-align: middle; margin-right: 5px;"></span> <?php _e( 'Cool-off Window', 'zerohold-shipping' ); ?>
					</h2>
					
					<table class="form-table">
						<tr valign="top">
							<th scope="row"><?php _e( 'Delay Duration', 'zerohold-shipping' ); ?></th>
							<td>
								<div style="display: flex; align-items: center; gap: 10px;">
									<input type="number" min="0" name="zh_order_visibility_delay_value" value="<?php echo esc_attr( get_option( 'zh_order_visibility_delay_value', 2 ) ); ?>" style="width: 80px;" />
									
									<select name="zh_order_visibility_delay_unit">
										<option value="minutes" <?php selected( get_option( 'zh_order_visibility_delay_unit', 'hours' ), 'minutes' ); ?>><?php _e( 'Minutes', 'zerohold-shipping' ); ?></option>
										<option value="hours" <?php selected( get_option( 'zh_order_visibility_delay_unit', 'hours' ), 'hours' ); ?>><?php _e( 'Hours', 'zerohold-shipping' ); ?></option>
									</select>
								</div>
								<p class="description"><?php _e( 'The amount of time to wait after an order is placed before showing it to the vendor.', 'zerohold-shipping' ); ?></p>
							</td>
						</tr>
					</table>

                    <div style="margin-top: 30px; padding: 15px; background: #f0f6fb; border-left: 4px solid #2271b1;">
                        <strong><?php _e( 'How it works:', 'zerohold-shipping' ); ?></strong>
                        <ul style="margin: 10px 0 0 20px; list-style-type: disc;">
                            <li><?php _e( 'Orders are created immediately but marked as invisible to vendors.', 'zerohold-shipping' ); ?></li>
                            <li><?php _e( 'A background process checks every 5 minutes to unlock expired orders.', 'zerohold-shipping' ); ?></li>
                            <li><?php _e( 'Vendors cannot generate labels or act on orders until they are visible.', 'zerohold-shipping' ); ?></li>
                            <li><?php _e( 'Admins can always see all orders in the backend.', 'zerohold-shipping' ); ?></li>
                        </ul>
                    </div>
				</div>
				
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
