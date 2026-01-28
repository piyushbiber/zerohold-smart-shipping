<?php

namespace Zerohold\Shipping\Vendor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Zerohold\Shipping\Core\OrderMapper;

class VendorActions {
	public function __construct() {
		// Register admin-post handlers
		add_action( 'admin_post_zh_generate_label', [ $this, 'zh_handle_generate_label' ] );
		add_action( 'admin_post_nopriv_zh_generate_label', [ $this, 'zh_handle_generate_label' ] );
		
		// Tracking Proxy Handler
		add_action( 'admin_post_zh_track_shipment', [ $this, 'zh_track_shipment' ] );
		add_action( 'admin_post_nopriv_zh_track_shipment', [ $this, 'zh_track_shipment' ] );

		// Step 2.6.4: Register AJAX handler
		add_action( 'wp_ajax_zh_generate_label', [ $this, 'zh_handle_generate_label_ajax' ] );
		add_action( 'wp_ajax_zh_get_on_demand_rates', [ $this, 'zh_get_on_demand_rates' ] );
		add_action( 'wp_ajax_zh_confirm_return_handover', [ $this, 'zh_confirm_return_handover' ] );
		
		// Step 2.6.5: Register download handler
		add_action( 'admin_post_zh_download_label', [ $this, 'zh_download_label' ] );
		add_action( 'admin_post_zh_download_return_label', [ $this, 'zh_download_return_label' ] );
		add_action( 'admin_post_nopriv_zh_download_return_label', [ $this, 'zh_download_return_label' ] );

		// Tracking Proxy Handler
		add_action( 'admin_post_zh_track_shipment', [ $this, 'zh_track_shipment' ] );
		add_action( 'admin_post_nopriv_zh_track_shipment', [ $this, 'zh_track_shipment' ] );

		// Hook: Flag vendor for warehouse refresh on profile update
		add_action( 'dokan_store_profile_saved', [ '\Zerohold\Shipping\Core\WarehouseManager', 'flagVendorForRefresh' ] );
	}

	/**
	 * Handle the print label request from the vendor UI.
	 */
	public function zh_handle_generate_label() {
		if ( empty( $_GET['order_id'] ) ) {
			wp_die( 'Missing Order ID' );
		}

		$order_id = intval( $_GET['order_id'] );
		
		$orchestrator = new \Zerohold\Shipping\Core\VendorShippingOrchestrator();
		$result = $orchestrator->processOrderShipping( $order_id, 'booking' );

		if ( ! $result['success'] ) {
			wp_die( esc_html( $result['message'] ) );
		}

		// Success: Redirect back or show success (though usually triggered via UI buttons)
		$referrer = wp_get_referer();
		if ( $referrer ) {
			wp_safe_redirect( $referrer );
		} else {
			echo 'Label generated successfully. You can now download it from the order list.';
		}
		exit;
	}

	/**
	 * Step 2.6.4: AJAX Handler for Label Generation
	 * Returns JSON response for smooth UI updates
	 */
	public function zh_handle_generate_label_ajax() {
		// Security check
		check_ajax_referer( 'zh_order_action_nonce', 'security' );

		if ( empty( $_POST['order_id'] ) ) {
			wp_send_json_error( 'Missing Order ID' );
		}

		$order_id = intval( $_POST['order_id'] );
		
		$orchestrator = new \Zerohold\Shipping\Core\VendorShippingOrchestrator();
		$result = $orchestrator->processOrderShipping( $order_id, 'booking' );

		if ( $result['success'] ) {
			wp_send_json_success( $result['message'] );
		} else {
			wp_send_json_error( $result['message'] );
		}
	}

	/**
	 * Step 2.6.5: Download Label Handler
	 * Forces PDF download from stored label URL
	 */
	public function zh_download_label() {
		if ( empty( $_GET['order_id'] ) ) {
			wp_die( 'Missing Order ID' );
		}

		$order_id  = intval( $_GET['order_id'] );
		$label_url = get_post_meta( $order_id, '_zh_shiprocket_label_url', true );
        
        // Fallback or override for BigShip
        if ( ! $label_url ) {
            $label_url = get_post_meta( $order_id, '_zh_label_pdf_url', true );
        }

		if ( ! $label_url ) {
			wp_die( 'Label not found. Please generate the label first.' );
		}

		// Force PDF download
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="shipping-label-' . $order_id . '.pdf"' );
		
		// Fetch and output the PDF
		$pdf_content = wp_remote_get( $label_url, [ 'timeout' => 30 ] );
		
		if ( is_wp_error( $pdf_content ) ) {
			wp_die( 'Failed to download label: ' . $pdf_content->get_error_message() );
		}

		echo wp_remote_retrieve_body( $pdf_content );
		exit;
	}

	/**
	 * Proxy handler for return label downloads.
	 * Tracks "Return Initiated" and redirects to actual PDF.
	 */
	public function zh_download_return_label() {
		if ( empty( $_GET['order_id'] ) ) {
			wp_die( 'Missing Order ID' );
		}

		$order_id  = intval( $_GET['order_id'] );
		$label_url = get_post_meta( $order_id, '_zh_return_label_url', true );

		if ( ! $label_url ) {
			wp_die( 'Return label not found.' );
		}

		// Track Event
		\Zerohold\Shipping\Core\DokanShipmentSync::add_return_update( 
			$order_id, 
			'initiated' 
		);

		// Redirect to actual label
		wp_redirect( $label_url );
		exit;
	}

	/**
	 * AJAX Handler for manual Return Handover confirmation by Vendor.
	 */
	public function zh_confirm_return_handover() {
		check_ajax_referer( 'zh_order_action_nonce', 'security' );

		if ( empty( $_POST['order_id'] ) ) {
			wp_send_json_error( 'Missing Order ID' );
		}

		$order_id = intval( $_POST['order_id'] );
		
		// Update meta
		update_post_meta( $order_id, '_zh_return_handover_confirmed', 1 );

		// Track Event
		\Zerohold\Shipping\Core\DokanShipmentSync::add_return_update( 
			$order_id, 
			'handover' 
		);

		wp_send_json_success( 'Handover confirmed successfully' );
	}

	/**
	 * Proxy handler for real-time shipment tracking.
	 * Fetches data via API and displays a simple tracking UI.
	 */
	public function zh_track_shipment() {
		if ( empty( $_GET['order_id'] ) ) {
			wp_die( 'Missing Order ID' );
		}

		$order_id = intval( $_GET['order_id'] );
		$platform = get_post_meta( $order_id, '_zh_shipping_platform', true );
		$awb      = get_post_meta( $order_id, '_zh_shiprocket_awb', true ) ?: get_post_meta( $order_id, '_zh_awb', true );
		$lrn      = get_post_meta( $order_id, '_zh_bigship_lr_number', true );

		if ( ! $awb && ! $lrn ) {
			wp_die( 'Tracking information not found for this order.' );
		}

		// Use LRN if available for BigShip, otherwise AWB
		$tracking_id = ( $platform === 'bigship' && ! empty( $lrn ) ) ? $lrn : $awb;
		$tracking_type = ( $platform === 'bigship' && ! empty( $lrn ) ) ? 'lrn' : 'awb';

		// Get Adapter
		$adapter = null;
		if ( $platform === 'shiprocket' ) {
			$adapter = new \Zerohold\Shipping\Platforms\ShiprocketAdapter();
		} elseif ( $platform === 'bigship' ) {
			$adapter = new \Zerohold\Shipping\Platforms\BigShipAdapter();
		}

		if ( ! $adapter ) {
			wp_die( 'Shipping platform not supported for tracking.' );
		}

		// Fetch Data
		$tracking_data = $platform === 'bigship' ? $adapter->track( $tracking_id, $tracking_type ) : $adapter->track( $tracking_id );

		// Display Tracking Page
		?>
		<html>
		<head>
			<title>Track Order #<?php echo $order_id; ?></title>
			<style>
				body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; padding: 20px; background: #f0f2f5; color: #1c1e21; }
				.track-card { max-width: 600px; margin: 0 auto; background: #fff; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); padding: 25px; }
				h1 { font-size: 24px; margin-bottom: 20px; border-bottom: 1px solid #eee; padding-bottom: 10px; }
				.status-badge { display: inline-block; padding: 6px 12px; border-radius: 20px; background: #e7f3ff; color: #1877f2; font-weight: bold; font-size: 14px; margin-bottom: 20px; text-transform: uppercase; }
				.history-item { border-left: 2px solid #e4e6eb; padding-left: 20px; position: relative; padding-bottom: 20px; }
				.history-item::before { content: ""; position: absolute; left: -7px; top: 0; width: 12px; height: 12px; border-radius: 50%; background: #bcc0c4; }
				.history-item.latest::before { background: #1877f2; }
				.history-date { font-size: 12px; color: #65676b; margin-bottom: 4px; }
				.history-status { font-weight: bold; font-size: 15px; margin-bottom: 2px; }
				.history-remark { font-size: 14px; color: #4b4f56; }
				.history-location { font-size: 12px; font-style: italic; color: #65676b; }
                .no-history { text-align: center; color: #65676b; padding: 40px 0; }
                .header-info { margin-bottom: 25px; font-size: 15px; }
                .header-info span { font-weight: bold; margin-right: 15px; }
			</style>
		</head>
		<body>
			<div class="track-card">
				<h1>Tracking Information</h1>
                
                <div class="header-info">
                    <div><span>Order ID:</span> #<?php echo $order_id; ?></div>
                    <div><span>Carrier:</span> <?php echo ucfirst($platform); ?></div>
                    <div><span>Tracking ID:</span> <?php echo $tracking_id; ?> (<?php echo strtoupper($tracking_type); ?>)</div>
                </div>

				<?php if ( empty( $tracking_data['data']['scan_histories'] ) ): ?>
					<div class="status-badge"><?php echo $tracking_data['data']['order_detail']['current_tracking_status'] ?? 'Processing'; ?></div>
                    <div class="no-history">No tracking history found yet. Please check back later.</div>
				<?php else: 
					$histories = $tracking_data['data']['scan_histories'];
					$latest = reset($histories);
					?>
					<div class="status-badge"><?php echo $latest['scan_status'] ?? 'In Transit'; ?></div>

					<div class="history-container">
						<?php foreach ( $histories as $index => $scan ): ?>
							<div class="history-item <?php echo $index === 0 ? 'latest' : ''; ?>">
								<div class="history-date"><?php echo $scan['scan_datetime']; ?></div>
								<div class="history-status"><?php echo $scan['scan_status']; ?></div>
								<?php if ( ! empty( $scan['scan_remarks'] ) ): ?>
									<div class="history-remark"><?php echo $scan['scan_remarks']; ?></div>
								<?php endif; ?>
								<?php if ( ! empty( $scan['scan_location'] ) ): ?>
									<div class="history-location"><?php echo $scan['scan_location']; ?></div>
								<?php endif; ?>
							</div>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</div>
		</body>
		</html>
		<?php
		exit;
	}

	/**
	 * AJAX Handler for On-Demand Shipping Estimates during Product Upload.
	 */
	public function zh_get_on_demand_rates() {
		check_ajax_referer( 'zh_order_action_nonce', 'security' );

		$weight = floatval( $_POST['weight'] ?? 0 );
		$length = floatval( $_POST['length'] ?? 0 );
		$width  = floatval( $_POST['width'] ?? 0 );
		$height = floatval( $_POST['height'] ?? 0 );

		if ( ! $weight ) {
			wp_send_json_error( 'Weight is required for estimation.' );
		}

		// Garment Rule: Hard limit 10kg
		if ( $weight > 10 ) {
			$weight = 10;
		}

		$vendor_id = dokan_get_current_user_id();
		if ( ! $vendor_id ) {
			wp_send_json_error( 'Authentication failed.' );
		}

		// 1. Calculate Slab
		$slab_data = \Zerohold\Shipping\Core\SlabEngine::calculate( $weight, $length, $width, $height );
		$final_slab = $slab_data['slab'];

		// 2. Identify Origin Pincode (Robust Search)
		$vendor_data = dokan_get_seller_address( $vendor_id );
		$origin_pin  = $vendor_data['zip'] ?? '';

		// Log for internal tracing if zip is missing from primary Dokan function
		if ( empty( $origin_pin ) ) {
			// Fallback: Check standard User Meta if Dokan helper fails
			$origin_pin = get_user_meta( $vendor_id, 'billing_postcode', true );
		}

		if ( empty( $origin_pin ) ) {
			wp_send_json_error( 'Store pincode not found. Please ensure your Store Address (ZIP code) is set in Settings > Store.' );
		}

		// 3. Cache Check
		$cached = \Zerohold\Shipping\Core\EstimateCache::get( $vendor_id, $origin_pin, $final_slab );
		if ( $cached ) {
			$cached['vendor_min'] = \Zerohold\Shipping\Core\PriceEngine::calculate_share_and_cap( $cached['min_price'], 'vendor', $vendor_id );
			$cached['vendor_max'] = \Zerohold\Shipping\Core\PriceEngine::calculate_share_and_cap( $cached['max_price'], 'vendor', $vendor_id );
			
			// Add Retailer (Buyer) info to cache display if needed
			$cached['buyer_min'] = \Zerohold\Shipping\Core\PriceEngine::calculate_share_and_cap( $cached['min_price'], 'retailer' );
			$cached['buyer_max'] = \Zerohold\Shipping\Core\PriceEngine::calculate_share_and_cap( $cached['max_price'], 'retailer' );
			
			wp_send_json_success( array_merge( $cached, [ 'is_cached' => true, 'slab_info' => $slab_data ] ) );
		}

		// 1. Get Zone Representative Pincodes (DYNAMIC)
		$resolver = new \Zerohold\Shipping\Core\ZoneResolver();
		$zone_pins = $resolver->zoneTable( $origin_pin );

		// Dynamic Zone B: Find a pincode in the Vendor's own state
		$vendor_state = $vendor_data['address']['state'] ?? '';
		if ( ! empty( $vendor_state ) ) {
			global $wpdb;
			$table_map = $wpdb->prefix . 'zh_pincode_map';
			
			// Try to find a random pincode in the same state (excluding origin)
			$dynamic_b = $wpdb->get_var( $wpdb->prepare(
				"SELECT pincode FROM $table_map WHERE state = %s AND pincode != %s LIMIT 1",
				$vendor_state,
				$origin_pin
			));

			if ( $dynamic_b ) {
				$zone_pins['B'] = $dynamic_b;
				error_log( "ZSS DEBUG: Dynamic Zone B for $vendor_state: $dynamic_b" );
			}
		}
		
		$bigship = new \Zerohold\Shipping\Platforms\BigShipAdapter();
		$rates   = $bigship->estimateRates( $origin_pin, $zone_pins, $final_slab );

		// Fallback to Shiprocket if no rates found
		if ( empty( $rates ) ) {
			$shiprocket = new \Zerohold\Shipping\Platforms\ShiprocketAdapter();
			$rates      = $shiprocket->estimateRates( $origin_pin, $zone_pins, $final_slab );
		}

		if ( empty( $rates ) ) {
			wp_send_json_error( 'Unable to fetch estimate right now. Please try again later.' );
		}

		// 5. Aggregate and Format for Modal
		$zone_labels = \Zerohold\Shipping\Core\ZoneResolver::getZoneLabels();
		$zone_breakdown = [];
		$all_prices = [];

		foreach ( $zone_pins as $zone_key => $pin ) {
			$zone_rates = $rates[ $zone_key ] ?? [];
			$min = 999999;
			$max = 0;

			foreach ( $zone_rates as $r ) {
				// Handle both object and array style, just in case
				$val = is_object($r) ? floatval( $r->base ) : floatval( $r['base'] ?? 0 );
				
				if ( $val < $min ) $min = $val;
				if ( $val > $max ) $max = $val;
			}

			if ( $min === 999999 ) {
				$min = 0;
				$max = 0;
			}
			
			// error_log( "ZSS DEBUG: Zone $zone_key Final: Min $min, Max $max" );

			// Add range padding to look professional in UI
			$range_min = floor($min);
			$range_max = ceil($max ?: $min * 1.2); 

			$you_min   = \Zerohold\Shipping\Core\PriceEngine::calculate_share_and_cap( $range_min, 'vendor', $vendor_id );
			$you_max   = \Zerohold\Shipping\Core\PriceEngine::calculate_share_and_cap( $range_max, 'vendor', $vendor_id );

			$buyer_min = \Zerohold\Shipping\Core\PriceEngine::calculate_share_and_cap( $range_min, 'retailer' );
			$buyer_max = \Zerohold\Shipping\Core\PriceEngine::calculate_share_and_cap( $range_max, 'retailer' );

			$zone_breakdown[ $zone_key ] = [
				'label'     => $zone_labels[ $zone_key ] ?? $zone_key,
				'total_min' => $range_min,
				'total_max' => $range_max,
				'buyer_min' => $buyer_min,
				'buyer_max' => $buyer_max,
				'you_min'   => $you_min,
				'you_max'   => $you_max,
			];

			if ( $range_min > 0 ) $all_prices[] = $range_min;
			if ( $range_max > 0 ) $all_prices[] = $range_max;
		}

		// Overall Summary (Total vs Vendor Share)
		$sum_min = ! empty( $all_prices ) ? min( $all_prices ) : 0;
		$sum_max = ! empty( $all_prices ) ? max( $all_prices ) : 0;

		$vendor_sum_min = \Zerohold\Shipping\Core\PriceEngine::calculate_share_and_cap( $sum_min, 'vendor', $vendor_id );
		$vendor_sum_max = \Zerohold\Shipping\Core\PriceEngine::calculate_share_and_cap( $sum_max, 'vendor', $vendor_id );

		// 6. Save to Cache
		\Zerohold\Shipping\Core\EstimateCache::set( 
			$vendor_id, 
			$origin_pin, 
			$final_slab, 
			$sum_min, 
			$sum_max, 
			$zone_breakdown 
		);

		wp_send_json_success([
			'min_price'      => $sum_min,
			'max_price'      => $sum_max,
			'vendor_min'     => $vendor_sum_min,
			'vendor_max'     => $vendor_sum_max,
			'zone_data'      => $zone_breakdown,
			'slab_info'      => $slab_data,
			'origin_pincode' => $origin_pin,
			'is_cached'      => false
		]);
	}




	private function inject_shipping_line_item( $order_id, $cost ) {
		if ( ! $order_id || ! $cost ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		// Remove existing shipping lines to prevent duplication
		foreach ( $order->get_items( 'shipping' ) as $item_id => $item ) {
			$order->remove_item( $item_id );
		}

		// Create standard WooCommerce shipping line item
		$shipping_item = new \WC_Order_Item_Shipping();
		$shipping_item->set_method_title( 'ZeroHold Shipping' );
		$shipping_item->set_method_id( 'zerohold_shipping' );
		$shipping_item->set_total( (float) $cost );
		
		// Add to order
		$order->add_item( $shipping_item );
		
		// Force recalculation so totals update in WC tables
		$order->calculate_totals();
		$order->save();

		error_log( "ZSS: Injected shipping line item ₹{$cost} into Order #{$order_id} for Analytics." );
	}
}
