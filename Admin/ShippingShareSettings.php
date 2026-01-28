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
		// --- VENDOR ---
		register_setting( 'zh_shipping_share_group', 'zh_vendor_shipping_share_percentage', [
			'type'              => 'number',
			'sanitize_callback' => 'floatval',
			'default'           => 50,
		] );
		register_setting( 'zh_shipping_share_group', 'zh_vendor_hidden_cap_slabs', [
			'sanitize_callback' => [ $this, 'sanitize_slabs' ],
			'default'           => [],
		] );
		register_setting( 'zh_shipping_share_group', 'zh_excluded_vendor_emails', [
			'sanitize_callback' => [ $this, 'sanitize_emails' ],
			'default'           => '',
		] );

		// --- RETAILER ---
		register_setting( 'zh_shipping_share_group', 'zh_retailer_shipping_share_percentage', [
			'type'              => 'number',
			'sanitize_callback' => 'floatval',
			'default'           => 50,
		] );
		register_setting( 'zh_shipping_share_group', 'zh_retailer_hidden_cap_slabs', [
			'sanitize_callback' => [ $this, 'sanitize_slabs' ],
			'default'           => [],
		] );
		register_setting( 'zh_shipping_share_group', 'zh_excluded_retailer_emails', [
			'sanitize_callback' => [ $this, 'sanitize_emails' ],
			'default'           => '',
		] );
	}

	public function sanitize_emails( $input ) {
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
		// Load assets for TomSelect
		echo '<link href="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/css/tom-select.default.min.css" rel="stylesheet">';
		echo '<script src="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/js/tom-select.complete.min.js"></script>';

		// Retrieve Vendor Excluded Emails
		$vendor_emails_str = get_option( 'zh_excluded_vendor_emails', '' );
		$vendor_emails     = array_filter( array_map( 'trim', explode( ',', $vendor_emails_str ) ) );

		// Retrieve Vendor Slabs for rendering
		$vendor_slabs = get_option( 'zh_vendor_hidden_cap_slabs', [] );
		if ( empty( $vendor_slabs ) ) {
			// Fallback to old name if new one is empty
			$vendor_slabs = get_option( 'zh_hidden_cap_slabs', [] );
		}

		// Retrieve Retailer Excluded Emails
		$retailer_emails_str = get_option( 'zh_excluded_retailer_emails', '' );
		$retailer_emails     = array_filter( array_map( 'trim', explode( ',', $retailer_emails_str ) ) );

		?>
		<div class="wrap">
			<h1><?php _e( 'Shipping Deductions & Profit Logic', 'zerohold-shipping' ); ?></h1>
			<p><?php _e( 'Configure the percentage of shipping costs and profit caps for Vendors and Retailers.', 'zerohold-shipping' ); ?></p>
			
			<form method="post" action="options.php">
				<?php settings_fields( 'zh_shipping_share_group' ); ?>
				
				<!-- ========================================================
				     VENDOR SECTION
				     ======================================================== -->
				<div class="zh-settings-section" style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; margin-bottom: 30px; border-radius: 4px;">
					<h2 class="title" style="margin-top:0; color: #d63638; border-bottom: 2px solid #d63638; padding-bottom: 10px;">
						<span class="dashicons dashicons-store" style="vertical-align: middle;"></span> <?php _e( 'Vendor Configuration', 'zerohold-shipping' ); ?>
					</h2>
					
					<h3><?php _e( 'Base Share', 'zerohold-shipping' ); ?></h3>
					<table class="form-table">
						<tr valign="top">
							<th scope="row"><?php _e( 'Vendor Share Percentage (%)', 'zerohold-shipping' ); ?></th>
							<td>
								<input type="number" step="0.5" min="0" max="100" name="zh_vendor_shipping_share_percentage" value="<?php echo esc_attr( get_option( 'zh_vendor_shipping_share_percentage', 50 ) ); ?>" />
								<p class="description"><?php _e( 'The Base percentage deducted from vendor. (Example: 50%)', 'zerohold-shipping' ); ?></p>
							</td>
						</tr>
					</table>

					<h3><?php _e( 'Hidden Profit Cap (ZeroHold Profit)', 'zerohold-shipping' ); ?></h3>
					<p class="description"><?php _e( 'An additional HIDDEN percentage added to the Vendor\'s deduction based on cost slabs.', 'zerohold-shipping' ); ?></p>
					
					<?php $this->render_slabs_table( 'zh_vendor_hidden_cap_slabs' ); ?>

					<hr>

					<h3><?php _e( 'Exclusions', 'zerohold-shipping' ); ?></h3>
					<table class="form-table">
						<tr valign="top">
							<th scope="row"><?php _e( 'Excluded Vendor Emails', 'zerohold-shipping' ); ?></th>
							<td>
								<select class="zh-user-search" name="zh_excluded_vendor_emails[]" multiple placeholder="Search for vendors..." style="width: 100%; max-width: 600px;">
									<?php 
									foreach ( $vendor_emails as $email ) {
										$user = get_user_by( 'email', $email );
										$label = $user ? $user->display_name . ' (' . $email . ')' : $email;
										echo '<option value="' . esc_attr( $email ) . '" selected>' . esc_html( $label ) . '</option>';
									}
									?>
								</select>
								<p class="description"><?php _e( 'Search and select vendors to EXCLUDE from the Hidden Profit Cap logic.', 'zerohold-shipping' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<!-- ========================================================
				     RETAILER SECTION
				     ======================================================== -->
				<div class="zh-settings-section" style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; margin-bottom: 30px; border-radius: 4px;">
					<h2 class="title" style="margin-top:0; color: #2271b1; border-bottom: 2px solid #2271b1; padding-bottom: 10px;">
						<span class="dashicons dashicons-businessman" style="vertical-align: middle;"></span> <?php _e( 'Retailer Configuration (Buyer)', 'zerohold-shipping' ); ?>
					</h2>
					
					<h3><?php _e( 'Base Share', 'zerohold-shipping' ); ?></h3>
					<table class="form-table">
						<tr valign="top">
							<th scope="row"><?php _e( 'Retailer Share Percentage (%)', 'zerohold-shipping' ); ?></th>
							<td>
								<input type="number" step="0.5" min="0" max="100" name="zh_retailer_shipping_share_percentage" value="<?php echo esc_attr( get_option( 'zh_retailer_shipping_share_percentage', 50 ) ); ?>" />
								<p class="description"><?php _e( 'The percentage of the shipping cost shown to the buyer at checkout.', 'zerohold-shipping' ); ?></p>
							</td>
						</tr>
					</table>

					<h3><?php _e( 'Hidden Profit Cap (ZeroHold Profit)', 'zerohold-shipping' ); ?></h3>
					<p class="description"><?php _e( 'An additional HIDDEN percentage added to the Retailer\'s shipping quote based on cost slabs.', 'zerohold-shipping' ); ?></p>
					
					<?php $this->render_slabs_table( 'zh_retailer_hidden_cap_slabs' ); ?>

					<hr>

					<h3><?php _e( 'Exclusions', 'zerohold-shipping' ); ?></h3>
					<table class="form-table">
						<tr valign="top">
							<th scope="row"><?php _e( 'Excluded Retailer Emails', 'zerohold-shipping' ); ?></th>
							<td>
								<select class="zh-user-search" name="zh_excluded_retailer_emails[]" multiple placeholder="Search for customers/retailers..." style="width: 100%; max-width: 600px;">
									<?php 
									foreach ( $retailer_emails as $email ) {
										$user = get_user_by( 'email', $email );
										$label = $user ? $user->display_name . ' (' . $email . ')' : $email;
										echo '<option value="' . esc_attr( $email ) . '" selected>' . esc_html( $label ) . '</option>';
									}
									?>
								</select>
								<p class="description"><?php _e( 'Search and select retailers to EXCLUDE from the Hidden Profit Cap logic.', 'zerohold-shipping' ); ?></p>
							</td>
						</tr>
					</table>
				</div>
				
				<?php submit_button(); ?>
			</form>
		</div>

		<script>
		jQuery(function($){
			// Helper to add rows to slabs tables
			$('.zh-add-slab').on('click', function(){
				const target = $(this).data('target');
				const $body = $('#' + target + '-body');
				const fieldBase = $(this).data('field');
				
				const rowTpl = `
					<tr class="zh-slab-row">
						<td><input type="number" step="0.01" min="0" name="${fieldBase}[min][]" value="" style="width:95%"></td>
						<td><input type="number" step="0.01" min="0" name="${fieldBase}[max][]" value="" placeholder="∞" style="width:95%"></td>
						<td><input type="number" step="0.01" min="0" name="${fieldBase}[percent][]" value="" style="width:95%"></td>
						<td><button type="button" class="button zh-remove-slab" style="color:#dc3232;">&times;</button></td>
					</tr>
				`;
				
				$body.find('.zh-no-slabs-msg').remove();
				$body.append(rowTpl);
			});

			$(document).on('click', '.zh-remove-slab', function(){
				$(this).closest('tr').remove();
			});

			// Initialize TomSelect for all search boxes
			if(window.TomSelect){
				$('.zh-user-search').each(function(){
					new TomSelect(this, {
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
				});
			}
		});
		</script>
		<?php
	}

	/**
	 * Helper to render a slabs table.
	 */
	private function render_slabs_table( $option_name ) {
		$slabs = get_option( $option_name, [] );
		if ( ! is_array( $slabs ) ) $slabs = [];
		$id_base = str_replace( '_', '-', $option_name );
		?>
		<table class="widefat" id="<?php echo $id_base; ?>-table" style="max-width: 800px; margin-bottom: 20px;">
			<thead>
				<tr>
					<th style="width: 30%;">Min Share Cost (₹)</th>
					<th style="width: 30%;">Max Share Cost (₹)</th>
					<th style="width: 30%;">Profit Cap (%)</th>
					<th style="width: 10%;"></th>
				</tr>
			</thead>
			<tbody id="<?php echo $id_base; ?>-body">
				<?php 
				if ( ! empty( $slabs ) ) :
					foreach ( $slabs as $index => $slab ) : ?>
						<tr class="zh-slab-row">
							<td><input type="number" step="0.01" min="0" name="<?php echo $option_name; ?>[min][]" value="<?php echo esc_attr( $slab['min'] ); ?>" style="width:95%"></td>
							<td><input type="number" step="0.01" min="0" name="<?php echo $option_name; ?>[max][]" value="<?php echo esc_attr( $slab['max'] ); ?>" placeholder="∞" style="width:95%"></td>
							<td><input type="number" step="0.01" min="0" name="<?php echo $option_name; ?>[percent][]" value="<?php echo esc_attr( $slab['percent'] ); ?>" style="width:95%"></td>
							<td><button type="button" class="button zh-remove-slab" style="color:#dc3232;">&times;</button></td>
						</tr>
					<?php endforeach; 
				else: ?>
					<tr class="zh-no-slabs-msg">
						<td colspan="4" style="text-align: center; padding: 20px; color: #666;">
							<?php _e( 'No profit cap slabs configured yet.', 'zerohold-shipping' ); ?>
						</td>
					</tr>
				<?php endif; ?>
			</tbody>
			<tfoot>
				<tr>
					<td colspan="4">
						<button type="button" class="button button-secondary zh-add-slab" data-target="<?php echo $id_base; ?>" data-field="<?php echo $option_name; ?>">
							<span class="dashicons dashicons-plus-alt2" style="vertical-align: text-bottom;"></span> <?php _e( 'Add Slab', 'zerohold-shipping' ); ?>
						</button>
					</td>
				</tr>
			</tfoot>
		</table>
		<?php
	}
}
