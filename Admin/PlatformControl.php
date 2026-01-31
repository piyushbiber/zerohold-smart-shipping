<?php

namespace Zerohold\Shipping\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Platform Control Admin Page
 * 
 * Allows admins to enable/disable shipping platforms (Shiprocket, BigShip)
 */
class PlatformControl {

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'register_menu' ], 60 );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
	}

	/**
	 * Register admin menu page
	 */
	public function register_menu() {
		add_submenu_page(
			'zerohold-settings',
			__( 'Platform Control', 'zerohold-shipping' ),
			__( 'Platform Control', 'zerohold-shipping' ),
			'manage_options',
			'zh-platform-control',
			[ $this, 'render_page' ]
		);
	}

	/**
	 * Register settings
	 */
	public function register_settings() {
		register_setting( 'zh_platform_control', 'zh_platform_shiprocket_enabled' );
		register_setting( 'zh_platform_control', 'zh_platform_bigship_enabled' );
	}

	/**
	 * Render admin page
	 */
	public function render_page() {
		// Handle form submission
		if ( isset( $_POST['zh_save_platform_control'] ) && check_admin_referer( 'zh_platform_control_nonce' ) ) {
			$shiprocket_enabled = isset( $_POST['zh_platform_shiprocket_enabled'] ) ? 1 : 0;
			$bigship_enabled = isset( $_POST['zh_platform_bigship_enabled'] ) ? 1 : 0;

			// Validation: At least one platform must be enabled
			if ( ! $shiprocket_enabled && ! $bigship_enabled ) {
				echo '<div class="notice notice-error"><p>' . __( 'Error: At least one platform must be enabled.', 'zerohold-shipping' ) . '</p></div>';
			} else {
				update_option( 'zh_platform_shiprocket_enabled', $shiprocket_enabled );
				update_option( 'zh_platform_bigship_enabled', $bigship_enabled );
				echo '<div class="notice notice-success"><p>' . __( 'Platform settings saved successfully.', 'zerohold-shipping' ) . '</p></div>';
			}
		}

		// Get current values
		$shiprocket_enabled = get_option( 'zh_platform_shiprocket_enabled', 1 );
		$bigship_enabled = get_option( 'zh_platform_bigship_enabled', 1 );

		?>
		<div class="wrap">
			<h1><?php _e( 'Platform Control', 'zerohold-shipping' ); ?></h1>
			<p><?php _e( 'Enable or disable shipping platforms for vendor label generation. At least one platform must remain enabled.', 'zerohold-shipping' ); ?></p>

			<form method="post" action="">
				<?php wp_nonce_field( 'zh_platform_control_nonce' ); ?>

				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="zh_platform_shiprocket_enabled"><?php _e( 'Shiprocket', 'zerohold-shipping' ); ?></label>
						</th>
						<td>
							<label class="zh-toggle-switch">
								<input type="checkbox" 
									   name="zh_platform_shiprocket_enabled" 
									   id="zh_platform_shiprocket_enabled" 
									   value="1" 
									   <?php checked( $shiprocket_enabled, 1 ); ?>>
								<span class="zh-toggle-slider"></span>
							</label>
							<span class="zh-platform-status">
								<?php echo $shiprocket_enabled ? '<span style="color: #46b450;">✓ Enabled</span>' : '<span style="color: #dc3232;">⊗ Parked</span>'; ?>
							</span>
							<p class="description">
								<?php _e( 'When parked, Shiprocket will not be used for label generation.', 'zerohold-shipping' ); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="zh_platform_bigship_enabled"><?php _e( 'BigShip', 'zerohold-shipping' ); ?></label>
						</th>
						<td>
							<label class="zh-toggle-switch">
								<input type="checkbox" 
									   name="zh_platform_bigship_enabled" 
									   id="zh_platform_bigship_enabled" 
									   value="1" 
									   <?php checked( $bigship_enabled, 1 ); ?>>
								<span class="zh-toggle-slider"></span>
							</label>
							<span class="zh-platform-status">
								<?php echo $bigship_enabled ? '<span style="color: #46b450;">✓ Enabled</span>' : '<span style="color: #dc3232;">⊗ Parked</span>'; ?>
							</span>
							<p class="description">
								<?php _e( 'When parked, BigShip will not be used for label generation.', 'zerohold-shipping' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save Changes', 'zerohold-shipping' ), 'primary', 'zh_save_platform_control' ); ?>
			</form>
		</div>

		<style>
			/* Toggle Switch Styling */
			.zh-toggle-switch {
				position: relative;
				display: inline-block;
				width: 50px;
				height: 24px;
				margin-right: 10px;
			}

			.zh-toggle-switch input {
				opacity: 0;
				width: 0;
				height: 0;
			}

			.zh-toggle-slider {
				position: absolute;
				cursor: pointer;
				top: 0;
				left: 0;
				right: 0;
				bottom: 0;
				background-color: #ccc;
				transition: .4s;
				border-radius: 24px;
			}

			.zh-toggle-slider:before {
				position: absolute;
				content: "";
				height: 18px;
				width: 18px;
				left: 3px;
				bottom: 3px;
				background-color: white;
				transition: .4s;
				border-radius: 50%;
			}

			input:checked + .zh-toggle-slider {
				background-color: #46b450;
			}

			input:checked + .zh-toggle-slider:before {
				transform: translateX(26px);
			}

			.zh-platform-status {
				font-weight: 600;
				font-size: 14px;
			}
		</style>
		<?php
	}
}
