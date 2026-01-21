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
		});
		</script>
		<?php
	}
}
