<?php

namespace Zerohold\Shipping\Vendor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VendorUI {
	public function __construct() {
		// Inject shipping buttons into order list (priority 10000 to run AFTER Vendor Pack's 9999)
		add_filter( 'woocommerce_admin_order_actions', [ $this, 'add_shipping_buttons' ], 10000, 2 );
		
		// Enqueue styles and scripts
		add_action( 'wp_footer', [ $this, 'enqueue_shipping_ui' ] );
		add_action( 'wp_footer', [ $this, 'inject_product_estimate_ui' ] );
		
		// Add nonce field to order list
		add_action( 'dokan_order_listing_before_table', [ $this, 'add_nonce_field' ] );
	}

	/**
	 * Add shipping buttons to order row actions
	 */
	public function add_shipping_buttons( $actions, $order ) {
		$order_id = $order->get_id();
		$status = $order->get_status();
		
		// Only show shipping buttons for processing orders
		if ( $status !== 'processing' ) {
			return $actions;
		}

		// Check label status
		$label_status = (int) get_post_meta( $order_id, '_zh_shiprocket_label_status', true );
		
		if ( $label_status !== 1 ) {
			// Show "GENERATE LABEL" button
			$actions['generate_label'] = [
				'url'    => '#',
				'name'   => __( 'Generate Label', 'zerohold-shipping' ),
				'action' => 'generate-label',
				'icon'   => '<span class="zss-generate-label zss-action-btn">GENERATE LABEL</span>',
			];
		} else {
			// Show "DOWNLOAD LABEL" button
			$actions['download_label'] = [
				'url'    => admin_url( 'admin-post.php?action=zh_download_label&order_id=' . $order_id ),
				'name'   => __( 'Download Label', 'zerohold-shipping' ),
				'action' => 'download-label',
				'icon'   => '<span class="zss-download-label zss-action-btn">DOWNLOAD LABEL</span>',
			];
		
			// Check pickup status and add badge (supports both Shiprocket and BigShip)
			$shiprocket_pickup = get_post_meta( $order_id, '_zh_shiprocket_pickup_status', true );
			$bigship_pickup = get_post_meta( $order_id, '_zh_bigship_pickup_status', true );
			
			if ( (int) $shiprocket_pickup === 1 || (int) $bigship_pickup === 1 ) {
				// Pickup scheduled successfully for either platform
				$actions['pickup_status'] = [
					'url'    => '#',
					'name'   => __( 'Pickup Scheduled', 'zerohold-shipping' ),
					'action' => 'pickup-status',
					'icon'   => '<span class="zss-pickup-scheduled zss-status-badge">✓ PICKUP SCHEDULED</span>',
				];
			}

			// Add "CONFIRM HANDOVER" for return shipments in transit
			$return_ship_id = get_post_meta( $order_id, '_zh_return_shipment_id', true );
			if ( $return_ship_id ) {
				$handover_done = (int) get_post_meta( $order_id, '_zh_return_handover_confirmed', true );
				if ( ! $handover_done ) {
					$actions['confirm_handover'] = [
						'url'    => '#',
						'name'   => __( 'Confirm Handover', 'zerohold-shipping' ),
						'action' => 'confirm-handover',
						'icon'   => '<span class="zss-confirm-handover zss-action-btn" style="background:#e67e22!important; color:white!important; border:0!important;">CONFIRM HANDOVER</span>',
					];
				} else {
					$actions['handover_done'] = [
						'url'    => '#',
						'name'   => __( 'Received', 'zerohold-shipping' ),
						'action' => 'handover-done',
						'icon'   => '<span class="zss-handover-done zss-status-badge">✓ RECEIVED AT WH</span>',
					];
				}
			}
		}

		return $actions;
	}

	/**
	 * Add nonce field for AJAX security
	 */
	public function add_nonce_field() {
		wp_nonce_field( 'zh_order_action_nonce', 'zh_order_nonce' );
	}

	/**
	 * Enqueue CSS and JavaScript for shipping UI
	 */
	public function enqueue_shipping_ui() {
		if ( ! function_exists( 'dokan_is_seller_dashboard' ) || ! dokan_is_seller_dashboard() ) {
			return;
		}

		// Only on orders page
		$is_orders_page = isset( $_GET['orders'] ) || get_query_var( 'orders' );
		
		// Fallback for clean URLs
		if ( ! $is_orders_page && strpos( $_SERVER['REQUEST_URI'], '/orders' ) !== false ) {
			$is_orders_page = true;
		}

		if ( ! $is_orders_page || isset( $_GET['order_id'] ) ) {
			return;
		}

		?>
		<style>
			/* ZSS Shipping Button Styles */
			.dokan-order-action a span.zss-action-btn {
				display: inline-block;
				padding: 4px 10px;
				border-radius: 4px;
				font-weight: 600;
				font-size: 12px;
				border: 1px solid #ddd;
				background: #f8f9fa;
				color: #333;
				margin: 2px;
				text-decoration: none;
				line-height: 1.4;
			}

			.zss-generate-label {
				background: #6c5ce7 !important;
				color: white !important;
				border: 0 !important;
				padding: 5px 12px !important;
			}

			.zss-generate-label:hover {
				background: #5b4bc4 !important;
				color: #fff !important;
			}

			.zss-download-label {
				background: #3498db !important;
				color: white !important;
				border: 0 !important;
				padding: 5px 12px !important;
			}

			.zss-download-label:hover {
				background: #2980b9 !important;
				color: #fff !important;
			}

			.zss-pickup-scheduled {
				background: #27ae60 !important;
				color: white !important;
				border: 0 !important;
				padding: 5px 12px !important;
				cursor: default !important;
				font-size: 11px !important;
			}

			.zss-pickup-scheduled:hover {
				background: #229954 !important;
				color: #fff !important;
			}

			.zss-spinner {
				color: #6c5ce7;
				font-weight: 600;
				font-size: 12px;
			}

			/* Hide Dokan Pro Manual Shipment UI */
			#dokan-order-shipping-status-tracking, 
			#create-tracking-status-action,
			#update-tracking-status-details,
			.shippments-tracking-footer-button,
			.shippments-tracking-footer-status select,
			.shippments-tracking-footer-status input {
				display: none !important;
			}
			
			/* Make existing shipment info look read-only */
			.shippments-tracking-footer-status {
				pointer-events: none;
				opacity: 0.9;
			}
		</style>

		<script>
		jQuery(function($) {
			// Helper function to get order ID
			function getOrderId(btn) {
				let $row = btn.closest('tr');
				let $idCell = $row.find('.dokan-order-id a');
				if ($idCell.length) {
					let href = $idCell.attr('href');
					let match = href.match(/order_id=(\d+)/);
					return match ? match[1] : null;
				}
				return null;
			}

			// GENERATE LABEL (Using delegation on document for better compatibility)
			$(document).on('click', '.zss-generate-label, .dokan-order-action [action="generate-label"], .generate-label', function(e){
				e.preventDefault();
				
				let btn = $(this);
				let id = getOrderId(btn);
				let nonce = $('#zh_order_nonce').val();
				let $container = btn.closest('.dokan-order-action');
				
				if (!id || btn.hasClass('disabled')) {
					return;
				}
				
				// Show spinner
				$container.children().hide();
				let $spinner = $('<span class="zss-spinner">⏳ Generating...</span>');
				$container.append($spinner);
				
				$.post('/wp-admin/admin-ajax.php', {
					action: 'zh_generate_label',
					order_id: id,
					security: nonce
				}, function(res) {
					console.log('ZSS AJAX Response:', res);
					if (res.success) {
						// Small delay to ensure LiteSpeed purge finishes, then cache-busting reload
						setTimeout(function() {
							let url = new URL(window.location.href);
							url.searchParams.set('zss_refresh', Date.now());
							window.location.href = url.toString();
						}, 1000);
					} else {
						// If already generated, we still want to reload to show the Download button
						if (res.data && res.data.includes('already generated')) {
							setTimeout(function() {
								let url = new URL(window.location.href);
								url.searchParams.set('zss_refresh', Date.now());
								window.location.href = url.toString();
							}, 1000);
						} else {
							alert(res.data || 'Error generating label');
							$spinner.remove();
							$container.children().show();
						}
					}
				}).fail(function(xhr) {
					console.error('ZSS AJAX FAIL:', xhr.responseText);
					alert('Connection error');
					$spinner.remove();
					$container.children().show();
				});
			});

			// CONFIRM HANDOVER
			$(document).on('click', '.zss-confirm-handover, .dokan-order-action [action="confirm-handover"], .confirm-handover', function(e){
				e.preventDefault();
				
				let btn = $(this);
				let id = getOrderId(btn);
				let nonce = $('#zh_order_nonce').val();
				let $container = btn.closest('.dokan-order-action');
				
				if (!id || !confirm('Are you sure you have received this return at your warehouse?')) {
					return;
				}
				
				// Show spinner
				$container.children().hide();
				let $spinner = $('<span class="zss-spinner">⏳ Updating...</span>');
				$container.append($spinner);
				
				$.post('/wp-admin/admin-ajax.php', {
					action: 'zh_confirm_return_handover',
					order_id: id,
					security: nonce
				}, function(res) {
					if (res.success) {
						location.reload();
					} else {
						alert(res.data || 'Error updating status');
						$spinner.remove();
						$container.children().show();
					}
				}).fail(function() {
					alert('Connection error');
					$spinner.remove();
					$container.children().show();
				});
			});
		});
		</script>
		<?php
	}

	/**
	 * Inject on-demand shipping estimate button and modal into the Product Edit page.
	 */
	public function inject_product_estimate_ui() {
		if ( ! function_exists( 'dokan_is_seller_dashboard' ) || ! dokan_is_seller_dashboard() ) {
			return;
		}

		// Only on product edit/new page
		$is_product_page = isset( $_GET['product_id'] ) || strpos( $_SERVER['REQUEST_URI'], '/products' ) !== false;
		if ( ! $is_product_page ) return;

		?>
		<style>
			/* Estimate Button & Summary Styles */
			.zh-estimate-container { margin-top: 15px; padding: 15px; border: 1px dashed #6c5ce7; border-radius: 8px; background: #f8f9ff; }
			.zh-check-estimate-btn { background: #6c5ce7; color: #fff; border: 0; padding: 8px 16px; border-radius: 4px; font-weight: 600; cursor: pointer; transition: 0.2s; }
			.zh-check-estimate-btn:hover { background: #5b4bc4; box-shadow: 0 4px 8px rgba(108, 92, 231, 0.2); }
			.zh-check-estimate-btn:disabled { background: #bcc0c4; cursor: not-allowed; }
			.zh-estimate-summary { margin-top: 10px; display: none; font-size: 14px; }
			.zh-price-range { font-weight: bold; color: #1877f2; font-size: 16px; }
			.zh-view-breakdown { color: #6c5ce7; text-decoration: underline; cursor: pointer; margin-left: 10px; font-weight: 500; }

			/* Estimate Modal (Modern Popup) */
			#zh-estimate-modal { display:none; position:fixed; z-index:999999; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.6); backdrop-filter: blur(4px); }
			.zh-modal-content { background:#fff; margin:5% auto; padding:0; border-radius:12px; width:90%; max-width:750px; overflow:hidden; box-shadow:0 10px 30px rgba(0,0,0,0.25); animation: zhSlideDown 0.3s ease-out; }
			@keyframes zhSlideDown { from { transform: translateY(-50px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
			
			.zh-modal-header { background:#6c5ce7; color:white; padding:20px; position:relative; }
			.zh-modal-header h2 { margin:0; font-size:20px; color:white; }
			.zh-modal-close { position:absolute; right:20px; top:20px; color:white; font-size:28px; font-weight:bold; cursor:pointer; opacity:0.8; }
			.zh-modal-close:hover { opacity:1; }

			.zh-modal-body { padding:25px; max-height:80vh; overflow-y:auto; }
			.zh-section-title { font-size:16px; font-weight:700; margin: 25px 0 12px 0; color:#2c3e50; border-left:4px solid #6c5ce7; padding-left:10px; }
			.zh-section-title:first-child { margin-top:0; }

			.zh-modal-table { width:100%; border-collapse:collapse; margin-bottom:20px; }
			.zh-modal-table th { background:#f8f9fa; text-align:left; padding:12px; border-bottom:2px solid #eee; color:#65676b; font-size:13px; text-transform:uppercase; }
			.zh-modal-table td { padding:12px; border-bottom:1px solid #eee; font-size:14px; color:#1c1e21; }
			.zh-modal-table tr:last-child td { border-bottom:0; }
			.zh-modal-table .highlight { font-weight:700; }
			.zh-zone-tag { font-weight:bold; color:#6c5ce7; }
			
			.zh-split-col { background: #fdfcfe; }
			.zh-disclaimer { margin-top:20px; padding:12px; background:#fff9e6; border-radius:6px; font-size:12px; color:#856404; line-height:1.5; }
			
			.zh-loading-dots::after { content: '.'; animation: dots 1.5s steps(5, end) infinite; }
			@keyframes dots { 0%, 20% { content: '.'; } 40% { content: '..'; } 60% { content: '...'; } 80%, 100% { content: ''; } }
		</style>

		<!-- Estimate Modal HTML -->
		<div id="zh-estimate-modal">
			<div class="zh-modal-content">
				<div class="zh-modal-header">
					<h2>Shipping Estimate Breakdown</h2>
					<span class="zh-modal-close">&times;</span>
				</div>
				<div class="zh-modal-body" id="zh-modal-body-content">
					<!-- Dynamic Content Injected Here -->
				</div>
			</div>
		</div>

		<script>
		jQuery(function($) {
			console.log("ZSS DEBUG: Shipping Estimate JS Loaded");
			
			// Support both standard Dokan (#_length) AND ZeroHold custom Pack Setup (#zh_field_box_length)
			// Also support different parent containers (.dokan-form-group vs .zh-dimension-row)
			const $target = $('#_length, #zh_field_box_length').first().closest('.dokan-form-group, .zh-dimension-row');
			console.log("ZSS DEBUG: Target dimension field found:", $target.length);
			
			if ($target.length) {
				console.log("ZSS DEBUG: Injecting Estimate UI");
				const estimateHtml = `
					<div class="zh-estimate-container">
						<button type="button" id="zh-check-estimate" class="zh-check-estimate-btn">Check estimated delivery price</button>
						<div id="zh-estimate-summary" class="zh-estimate-summary">
							Estimated delivery: <span class="zh-price-range">₹0 – ₹0</span> / box
							<span id="zh-view-breakdown" class="zh-view-breakdown">View zone-wise breakdown →</span>
						</div>
					</div>
				`;
				$target.after(estimateHtml);
			}

			let lastData = null;

			$(document).on('click', '#zh-check-estimate', function(e) {
				e.preventDefault();
				const btn = $(this);
				
				// Dynamically find inputs based on current form type (Standard vs Pack Setup)
				const isPack = $('#zh_field_box_weight').length > 0;
				const weight = isPack ? $('#zh_field_box_weight').val() : $('#_weight').val();
				const l = isPack ? $('#zh_field_box_length').val() : $('#_length').val();
				const w = isPack ? $('#zh_field_box_width').val() : $('#_width').val();
				const h = isPack ? $('#zh_field_box_height').val() : $('#_height').val();

				if (!weight || weight == 0) {
					alert('Please enter box weight first.');
					return;
				}

				if (weight > 10) {
					alert('Weight limit: Garment boxes must not exceed 10kg.');
					$('#_weight').val(10);
					return;
				}

				btn.prop('disabled', true).html('Fetching rates <span class="zh-loading-dots"></span>');
				$('#zh-estimate-summary').hide();

				$.post('/wp-admin/admin-ajax.php', {
					action: 'zh_get_on_demand_rates',
					weight: weight,
					length: l, width: w, height: h,
					security: $('#zh_order_nonce').val()
				}, function(res) {
					btn.prop('disabled', false).text('Check estimated delivery price');
					if (res.success) {
						lastData = res.data;
						$('.zh-price-range').text('₹' + res.data.min_price + ' – ₹' + res.data.max_price);
						$('#zh-estimate-summary').fadeIn();
					} else {
						alert(res.data || 'Could not fetch estimate. Try again.');
					}
				}).fail(function(){
					btn.prop('disabled', false).text('Check estimated delivery price');
					alert('Connection error. Please try again.');
				});
			});

			$(document).on('click', '#zh-view-breakdown', function(){
				if (!lastData) return;
				
				const slab = lastData.slab_info;
				
				const isPack = $('#zh_field_box_length').length > 0;
				const boxL = isPack ? $('#zh_field_box_length').val() : $('#_length').val();
				const boxW = isPack ? $('#zh_field_box_width').val() : $('#_width').val();
				const boxH = isPack ? $('#zh_field_box_height').val() : $('#_height').val();

				let modalHtml = `
					<div class="zh-section-title">BOX SUMMARY</div>
					<table class="zh-modal-table">
						<tr><td>Origin Pincode</td><td class="highlight">${lastData.origin_pincode} (Your Store)</td></tr>
						<tr><td>Box Size</td><td class="highlight">${boxL || 0} × ${boxW || 0} × ${boxH || 0} cm</td></tr>
						<tr><td>Chargeable Weight</td><td class="highlight">${slab.slab.toFixed(1)} kg</td></tr>
						<tr><td>Calculation Basis</td><td class="highlight">Weight / Volumetric (whichever higher)</td></tr>
					</table>

					<div class="zh-section-title">ZONE-WISE ESTIMATE TABLE</div>
					<table class="zh-modal-table">
						<thead>
							<tr>
								<th>Zone</th>
								<th>Delivery Coverage</th>
								<th>Estimate</th>
								<th class="zh-split-col">Buyer Pays</th>
								<th class="zh-split-col">You Pay</th>
							</tr>
						</thead>
						<tbody>
				`;

				// Map Zones
				const zones = lastData.zone_data;
				Object.keys(zones).sort().forEach(key => {
					const z = zones[key];
					modalHtml += `
						<tr>
							<td><span class="zh-zone-tag">Zone ${key}</span></td>
							<td>${z.label}</td>
							<td class="highlight">₹${z.total_min} – ₹${z.total_max}</td>
							<td class="zh-split-col">₹${z.buyer_min} – ₹${z.buyer_max}</td>
							<td class="zh-split-col">₹${z.you_min} – ₹${z.you_max}</td>
						</tr>
					`;
				});

				modalHtml += `
						</tbody>
					</table>
					<div class="zh-disclaimer">
						<strong>Note:</strong> Buyer share shown is indicative. Final buyer shipping may vary based on checkout rules. Total estimate is for informational purposes; final billing occurs at order-time based on buyer pincode.
					</div>
				`;

				$('#zh-modal-body-content').html(modalHtml);
				$('#zh-estimate-modal').fadeIn(200);
			});

			$('.zh-modal-close, #zh-estimate-modal').click(function(e){
				if(e.target == this || $(e.target).hasClass('zh-modal-close')) {
					$('#zh-estimate-modal').fadeOut(200);
				}
			});
		});
		</script>
		<?php
	}
}
