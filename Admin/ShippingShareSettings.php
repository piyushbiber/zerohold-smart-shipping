<?php

namespace Zerohold\Shipping\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ShippingShareSettings {

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'add_submenu' ], 20 );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
	}

	public function add_submenu() {
		// Add submenu under the existing "ZeroHold" menu (slug: zerohold-settings)
		add_submenu_page(
			'zerohold-settings',
			__( 'Shipping Deductions', 'zerohold-shipping' ),
			__( 'Shipping Deductions', 'zerohold-shipping' ),
			'manage_options',
			'zh-shipping-deductions',
			[ $this, 'render_page' ]
		);
	}

	public function register_settings() {
		register_setting( 'zh_shipping_share_group', 'zh_vendor_shipping_share_percentage', [
			'type'              => 'number',
			'sanitize_callback' => 'floatval',
			'default'           => 50,
		] );

		// Repeater Slabs stored as array
		register_setting( 'zh_shipping_share_group', 'zh_hidden_cap_slabs', [
			'type'              => 'array',
			'sanitize_callback' => [ $this, 'sanitize_slabs' ],
			'default'           => [],
		] );

		// Excluded Emails (comma separated string)
		register_setting( 'zh_shipping_share_group', 'zh_excluded_vendor_emails', [
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_textarea_field',
			'default'           => '',
		] );
	}

	public function sanitize_slabs( $input ) {
		$output = [];
		if ( is_array( $input ) && isset( $input['min'] ) ) {
			$count = count( $input['min'] );
			for ( $i = 0; $i < $count; $i++ ) {
				$min = isset( $input['min'][ $i ] ) ? floatval( $input['min'][ $i ] ) : 0;
				$max = isset( $input['max'][ $i ] ) && $input['max'][ $i ] !== '' ? floatval( $input['max'][ $i ] ) : '';
				$pct = isset( $input['percent'][ $i ] ) ? floatval( $input['percent'][ $i ] ) : 0;
				
				// Only save valid rows
				if ( $min >= 0 ) {
					$output[] = [ 'min' => $min, 'max' => $max, 'percent' => $pct ];
				}
			}
		}
		// Sort by min value to ensure logical processing
		usort( $output, function($a, $b) {
			return $a['min'] <=> $b['min'];
		});
		return $output;
	}

	public function render_page() {
		$slabs = get_option( 'zh_hidden_cap_slabs', [] );
		// Ensure at least one empty row for new installs if empty
		if ( empty( $slabs ) ) {
			$slabs = []; 
		}
		?>
		<div class="wrap">
			<h1><?php _e( 'Vendor Shipping Deductions', 'zerohold-shipping' ); ?></h1>
			<p><?php _e( 'Configure the percentage of shipping costs that will be deducted from the Vendor\'s wallet.', 'zerohold-shipping' ); ?></p>
			
			<form method="post" action="options.php">
				<?php settings_fields( 'zh_shipping_share_group' ); ?>
				<?php do_settings_sections( 'zh_shipping_share_group' ); ?>
				
				<h2 class="title"><?php _e( 'Base Share', 'zerohold-shipping' ); ?></h2>
				<table class="form-table">
					<tr valign="top">
						<th scope="row"><?php _e( 'Vendor Share Percentage (%)', 'zerohold-shipping' ); ?></th>
						<td>
							<input type="number" step="0.5" min="0" max="100" name="zh_vendor_shipping_share_percentage" value="<?php echo esc_attr( get_option( 'zh_vendor_shipping_share_percentage', 50 ) ); ?>" />
							<p class="description">
								<?php _e( 'The Base percentage deducted from vendor. (Example: 50%)', 'zerohold-shipping' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<hr>

				<h2 class="title"><?php _e( 'Hidden Profit Cap (ZeroHold Profit)', 'zerohold-shipping' ); ?></h2>
				<p class="description"><?php _e( 'An additional HIDDEN percentage added to the Vendor\'s deduction based on cost slabs.', 'zerohold-shipping' ); ?><br>
				<?php _e( 'Logic: Min Cost <= Vendor Share < Max Cost. Leave Max empty for infinite.', 'zerohold-shipping' ); ?></p>
				
				<table class="widefat" id="zh-slabs-table" style="max-width: 800px; margin-bottom: 20px;">
					<thead>
						<tr>
							<th style="width: 30%;">Min Share Cost (₹)</th>
							<th style="width: 30%;">Max Share Cost (₹)</th>
							<th style="width: 30%;">Profit Cap (%)</th>
							<th style="width: 10%;"></th>
						</tr>
					</thead>
					<tbody id="zh-slabs-body">
						<?php 
						if ( ! empty( $slabs ) ) :
							foreach ( $slabs as $index => $slab ) : ?>
								<tr class="zh-slab-row">
									<td><input type="number" step="0.01" min="0" name="zh_hidden_cap_slabs[min][]" value="<?php echo esc_attr( $slab['min'] ); ?>" style="width:95%"></td>
									<td><input type="number" step="0.01" min="0" name="zh_hidden_cap_slabs[max][]" value="<?php echo esc_attr( $slab['max'] ); ?>" placeholder="∞" style="width:95%"></td>
									<td><input type="number" step="0.01" min="0" name="zh_hidden_cap_slabs[percent][]" value="<?php echo esc_attr( $slab['percent'] ); ?>" style="width:95%"></td>
									<td><button type="button" class="button zh-remove-slab" style="color:#dc3232;">&times;</button></td>
								</tr>
							<?php endforeach; 
						endif; 
						?>
					</tbody>
					<tfoot>
						<tr>
							<td colspan="4">
								<button type="button" class="button button-secondary" id="zh-add-slab">
									<span class="dashicons dashicons-plus-alt2" style="vertical-align: text-bottom;"></span> <?php _e( 'Add Slab', 'zerohold-shipping' ); ?>
								</button>
							</td>
						</tr>
					</tfoot>
				</table>

				<script>
				jQuery(function($){
					const $body = $('#zh-slabs-body');
					const rowTpl = `
						<tr class="zh-slab-row">
							<td><input type="number" step="0.01" min="0" name="zh_hidden_cap_slabs[min][]" value="" style="width:95%"></td>
							<td><input type="number" step="0.01" min="0" name="zh_hidden_cap_slabs[max][]" value="" placeholder="∞" style="width:95%"></td>
							<td><input type="number" step="0.01" min="0" name="zh_hidden_cap_slabs[percent][]" value="" style="width:95%"></td>
							<td><button type="button" class="button zh-remove-slab" style="color:#dc3232;">&times;</button></td>
						</tr>
					`;

					$('#zh-add-slab').on('click', function(){
						$body.append(rowTpl);
					});

					$body.on('click', '.zh-remove-slab', function(){
						$(this).closest('tr').remove();
					});
				});
				</script>

				<h2 class="title"><?php _e( 'Exclusions', 'zerohold-shipping' ); ?></h2>
				<table class="form-table">
					<tr valign="top">
						<th scope="row"><?php _e( 'Excluded Vendor Emails', 'zerohold-shipping' ); ?></th>
						<td>
							<textarea name="zh_excluded_vendor_emails" rows="5" cols="50" class="large-text code"><?php echo esc_textarea( get_option( 'zh_excluded_vendor_emails', '' ) ); ?></textarea>
							<p class="description">
								<?php _e( 'Enter email addresses of vendors who should be EXCLUDED from the Hidden Profit Cap logic. Separate by comma.', 'zerohold-shipping' ); ?>
							</p>
						</td>
					</tr>
				</table>
				
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
