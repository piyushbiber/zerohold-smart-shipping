<?php

namespace Zerohold\Shipping\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ShippingShareSettings {

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'add_submenu' ], 20 );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'wp_ajax_zh_search_vendors', [ $this, 'search_vendors' ] );
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

		// Repeater Slabs stored as array (Removed 'type' => 'array' to prevent WP casting issues)
		register_setting( 'zh_shipping_share_group', 'zh_hidden_cap_slabs', [
			'sanitize_callback' => [ $this, 'sanitize_slabs' ],
			'default'           => [],
		] );

		// Excluded Emails (comma separated string)
		// We accept an array from the form (Select2), but convert to string for storage
		register_setting( 'zh_shipping_share_group', 'zh_excluded_vendor_emails', [
			'sanitize_callback' => [ $this, 'sanitize_vendor_emails' ],
			'default'           => '',
		] );
	}

	public function sanitize_vendor_emails( $input ) {
		if ( is_array( $input ) ) {
			// Filter empty and sanitize emails
			$clean = array_filter( array_map( 'sanitize_email', $input ) );
			return implode( ',', $clean );
		}
		return sanitize_textarea_field( $input );
	}

	public function search_vendors() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$term = isset( $_GET['q'] ) ? sanitize_text_field( $_GET['q'] ) : '';
		
		$args = [
			'search'         => '*' . $term . '*',
			'search_columns' => [ 'user_login', 'user_email', 'display_name' ],
			'fields'         => [ 'ID', 'user_email', 'display_name' ],
			'number'         => 20,
			// 'role__in'    => [ 'seller', 'administrator', 'dokan_vendor' ] // Optional: Restrict to vendors
		];

		$users = get_users( $args );
		$results = [];

		foreach ( $users as $user ) {
			$results[] = [
				'id'   => $user->user_email, // Use email as value
				'text' => $user->display_name . ' (' . $user->user_email . ')',
			];
		}

		wp_send_json_success( $results );
	}

	public function sanitize_slabs( $input ) {
		// If null or empty, return empty array
		if ( empty( $input ) || ! is_array( $input ) ) {
			return [];
		}

		// Handle case where input is already row-based (rare, but good for safety)
		if ( isset( $input[0]['min'] ) ) {
			return array_values( $input );
		}

		// Process Column-Based Input (from Form: input[min][], input[max][], etc.)
		$output = [];
		if ( isset( $input['min'] ) && is_array( $input['min'] ) ) {
			$count = count( $input['min'] );
			
			for ( $i = 0; $i < $count; $i++ ) {
				$min_val = $input['min'][ $i ]; // Raw
				
				// Skip if Min is empty string (but allow "0")
				if ( $min_val === '' || $min_val === null ) {
					continue;
				}

				$min = floatval( $min_val );
				$max = ( isset( $input['max'][ $i ] ) && $input['max'][ $i ] !== '' ) ? floatval( $input['max'][ $i ] ) : '';
				$pct = isset( $input['percent'][ $i ] ) ? floatval( $input['percent'][ $i ] ) : 0;
				
				$output[] = [ 
					'min'     => $min, 
					'max'     => $max, 
					'percent' => $pct 
				];
			}
		}

		// Sort by min value
		usort( $output, function($a, $b) {
			return $a['min'] <=> $b['min'];
		});

		return $output;
	}

	public function render_page() {
		$slabs = get_option( 'zh_hidden_cap_slabs', [] );
		
		// Ensure it's an array (handle edge case where option might be corrupted string)
		if ( ! is_array( $slabs ) ) {
			$slabs = [];
		}

		// Load assets for TomSelect
		echo '<link href="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/css/tom-select.default.min.css" rel="stylesheet">';
		echo '<script src="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/js/tom-select.complete.min.js"></script>';

		// Retrieve Excluded Emails
		$current_emails_str = get_option( 'zh_excluded_vendor_emails', '' );
		$current_emails = array_filter( array_map( 'trim', explode( ',', $current_emails_str ) ) );
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
						else: ?>
							<tr class="zh-no-slabs-msg">
								<td colspan="4" style="text-align: center; padding: 20px; color: #666;">
									<?php _e( 'No hidden cap slabs configured yet. Click "Add Slab" to start.', 'zerohold-shipping' ); ?>
								</td>
							</tr>
						<?php endif; ?>
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
						// Remove empty message if it exists
						$('.zh-no-slabs-msg').remove();
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
							<select id="zh-vendor-search" name="zh_excluded_vendor_emails[]" multiple placeholder="Search for vendors..." style="width: 100%; max-width: 600px;">
								<?php 
								foreach ( $current_emails as $email ) {
									$user = get_user_by( 'email', $email );
									$label = $user ? $user->display_name . ' (' . $email . ')' : $email;
									echo '<option value="' . esc_attr( $email ) . '" selected>' . esc_html( $label ) . '</option>';
								}
								?>
							</select>
							<p class="description">
								<?php _e( 'Search and select vendors to EXCLUDE from the Hidden Profit Cap logic.', 'zerohold-shipping' ); ?>
							</p>
							<script>
							document.addEventListener('DOMContentLoaded', function() {
								if(window.TomSelect){
									new TomSelect('#zh-vendor-search',{
										valueField: 'id',
										labelField: 'text',
										searchField: 'text',
										load: function(query, callback) {
											var url = ajaxurl + '?action=zh_search_vendors&q=' + encodeURIComponent(query);
											fetch(url)
												.then(response => response.json())
												.then(json => {
													if(json.success) {
														callback(json.data);
													} else {
														callback();
													}
												}).catch(()=>{
													callback();
												});
										},
										create: false
									});
								}
							});
							</script>
						</td>
					</tr>
				</table>
				
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
