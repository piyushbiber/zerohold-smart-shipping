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
		$order    = wc_get_order( $order_id );

		if ( ! $order ) {
			wp_die( 'Order not found' );
		}

		// Step 2.6.2: Duplicate Guard - Prevent re-generation
		$label_status = get_post_meta( $order_id, '_zh_shiprocket_label_status', true );
		if ( $label_status == 1 ) {
			wp_die( 'Label already generated for this order. Please use the Download button.' );
		}

		// Map to Shipment
		$mapper   = new OrderMapper();
		$shipment = $mapper->map( $order );

		// 1. Get Enabled Platforms
		$platforms = \Zerohold\Shipping\Core\PlatformManager::getEnabledPlatforms();
		$quotes    = [];

		// 2. Gather Quotes
		foreach ( $platforms as $key => $platform_adapter ) {
			$quotes[ $key ] = $platform_adapter->getRates( $shipment );
		}

		// 3. Select Winner
		$selector = new \Zerohold\Shipping\Core\RateSelector();
		$winner   = $selector->selectBestRate( $quotes );

		if ( ! $winner ) {
			wp_die( 'No shipping rates available from enabled platforms.' );
		}

		$winner_platform = $winner->platform;
		$adapter = $platforms[ $winner_platform ] ?? reset( $platforms );

		// 4. Create Order (Book)
		$shipment->courier  = $winner->courier; 
		$shipment->platform = $winner_platform;
		// Add courier_id if available (BigShip needs it)
		if ( ! empty( $winner->courier_id ) ) {
			$shipment->courier_id = $winner->courier_id;
		}

		$response = $adapter->createOrder( $shipment );

		$label_response = [];
		$awb_response   = [];

		if ( isset( $response['shipment_id'] ) ) {
			// Platform specific AWB/Label logic
			$awb_response = $adapter->generateAWB( $response['shipment_id'] );

			$success = false;
			if ( $winner_platform === 'shiprocket' ) {
				$success = ( isset( $awb_response['awb_assign_status'] ) && $awb_response['awb_assign_status'] == 1 );
			} else {
				// BigShip and others implied success if shipment_id exists
				$success = true;
			}

			if ( $success ) {
				$label_response = $adapter->getLabel( $response['shipment_id'] );

				// Step 2.6.1: Store Meta Data on Success
				if ( isset( $label_response['label_url'] ) ) {
					$awb_code = $awb_response['response']['data']['awb_code'] ?? $response['awb_code'] ?? 'N/A';

					update_post_meta( $order_id, '_zh_shiprocket_shipment_id', $response['shipment_id'] );
					update_post_meta( $order_id, '_zh_shiprocket_awb', $awb_code );
					update_post_meta( $order_id, '_zh_shiprocket_label_url', $label_response['label_url'] );
					update_post_meta( $order_id, '_zh_shiprocket_label_status', 1 );
					update_post_meta( $order_id, '_zh_shipping_platform', $winner_platform );
				}
			}
		}
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
		$order    = wc_get_order( $order_id );

		if ( ! $order ) {
			wp_send_json_error( 'Order not found' );
		}

		// Duplicate Guard
		$label_status = get_post_meta( $order_id, '_zh_shiprocket_label_status', true );
		if ( $label_status == 1 ) {
			wp_send_json_error( 'Label already generated for this order' );
		}

		// Run Pipeline
		$mapper   = new OrderMapper();
		$shipment = $mapper->map( $order );

		error_log( "ZSS DEBUG: Starting Label Generation for Order #{$order_id}" );
		error_log( "ZSS DEBUG: Shipment Data: " . print_r( $shipment, true ) );

		// Step 2.6.6: Auto-Refresh Warehouse if needed
		\Zerohold\Shipping\Core\WarehouseManager::checkAndRefresh( $shipment );

		// 1. Get Enabled Platforms & Initialize
		$platforms = \Zerohold\Shipping\Core\PlatformManager::getEnabledPlatforms();
		$quotes    = [];
		$selector  = new \Zerohold\Shipping\Core\RateSelector();

		// 2. Gather Quotes & Verify Balance
		$retry = true;
		$excluded_platforms = [];
		
		// PROACTIVE: Fetch balances and filter out broke platforms early
		foreach ( $platforms as $key => $adapter ) {
			$quotes[ $key ] = $adapter->getRates( $shipment );
			
			// Optional: Pre-filtering based on balance (if quotes exist)
			if ( ! empty( $quotes[ $key ] ) ) {
				$balance = $adapter->getWalletBalance();
				error_log( "ZSS BALANCE: Platform {$key} has balance of {$balance}" );
				
				// Find cheapest for this platform
				$local_best = 999999;
				foreach ( (array) $quotes[ $key ] as $q ) {
					$cost = is_object($q) ? $q->base : ( $q['base'] ?? 999999 );
					if ( $cost < $local_best ) $local_best = $cost;
				}

				if ( $balance < $local_best ) {
					error_log( "ZSS BALANCE: Proactively excluding platform {$key} due to low balance ({$balance} < {$local_best})" );
					$excluded_platforms[] = $key;
				}
			}
		}

		$response = null;
		$selected_adapter = null;
		$selected_winner = null;

		while ( $retry ) {
			// 1. Filter Quotes
			$active_quotes = [];
			foreach ( $quotes as $key => $q ) {
				if ( ! in_array( $key, $excluded_platforms ) ) {
					$active_quotes[ $key ] = $q;
				}
			}

			// 2. Select Winner
			$winner = $selector->selectBestRate( $active_quotes );

			if ( ! $winner ) {
				error_log( "ZSS ERROR: No valid shipping rates (with balance) found for Order #{$order_id}" );
				wp_send_json_error( 'No shipping rates available from platforms with sufficient balance.' );
				return;
			}

			error_log( "ZSS DEBUG: Winner Selected: " . print_r( $winner, true ) );
			$selected_winner = $winner;
			$winner_platform = $winner->platform;
			$adapter = $platforms[ $winner_platform ] ?? reset( $platforms );
			$selected_adapter = $adapter;

			// 3. Prepare Shipment for specific courier
			$shipment->courier     = $winner->courier; 
			$shipment->platform    = $winner_platform;
			$shipment->courier_id  = $winner->courier_id ?? '';

			// 4. Create Order (Book)
			error_log( "ZSS DEBUG: Attempting createOrder via " . ucfirst($winner_platform) );
			$response = $adapter->createOrder( $shipment );
			error_log( "ZSS DEBUG: createOrder Response: " . print_r( $response, true ) );

			// 5. Balance Check Fallback
			if ( $adapter->isBalanceError( $response ) ) {
				error_log( "ZSS BALANCE: Platform {$winner_platform} has insufficient balance. Excluding and retrying..." );
				$excluded_platforms[] = $winner_platform;
				continue; // Loop again to pick next best
			}

			// Stop loop if success or other fatal error
			$retry = false;
		}

		$winner_platform = $selected_winner->platform;
		$adapter = $selected_adapter;

		// Check for WP_Error (non-balance fatal errors)
		if ( is_wp_error( $response ) ) {
			error_log( "ZSS ERROR: createOrder WP_Error: " . $response->get_error_message() );
			wp_send_json_error( 'Order creation failed: ' . $response->get_error_message() );
			return;
		}

		if ( ! empty( $response['error'] ) && empty( $response['shipment_id'] ) ) {
			wp_send_json_error( 'Order creation failed: ' . $response['error'] );
			return;
		}

		if ( isset( $response['shipment_id'] ) ) {
			
			// Platform-specific follow-up (AWB gen)
			// Shiprocket needs generateAWB. Nimbus might not.
			// BigShip needs manifestOrder BEFORE generateAWB.
			
			if ( $adapter instanceof \Zerohold\Shipping\Platforms\BigShipAdapter && ! empty( $shipment->courier_id ) ) {
				error_log( "ZSS DEBUG: BigShip - Manifesting Order..." );
				$manifest_result = $adapter->manifestOrder( $response['shipment_id'], $shipment->courier_id );
				error_log( "ZSS DEBUG: BigShip Manifest Response: " . print_r( $manifest_result, true ) );

				if ( isset( $manifest_result['error'] ) ) {
					error_log( "ZSS ERROR: BigShip Manifesting failed: " . $manifest_result['error'] );
					wp_send_json_error( 'Manifesting failed: ' . $manifest_result['error'] );
					return;
				}
				
				// Phase-2 Pickup: BigShip manifest = pickup scheduled
				// Store pickup status after successful manifest
				if ( isset( $manifest_result['status'] ) && $manifest_result['status'] === 'success' ) {
					update_post_meta( $order_id, '_zh_bigship_pickup_status', 1 );
					update_post_meta( $order_id, '_zh_bigship_manifest_response', wp_json_encode( $manifest_result ) );
				}
			}

			error_log( "ZSS DEBUG: Generating AWB..." );
			$awb_response = $adapter->generateAWB( $response['shipment_id'] );
			error_log( "ZSS DEBUG: AWB Response: " . print_r( $awb_response, true ) );

			// Check status - standardized or platform specific?
			// Shiprocket uses 'awb_assign_status' == 1.
			// Ideally we standardize this too, but for "Parking" task let's keep SR logic intact.
			// If Adapter is Shiprocket, we check standard SR fields.
			// If Adapter is Nimbus, generateAWB returns [] (noop) or we implement it to return true-like?
			// Currently NimbusAdapter::generateAWB returns [].
			
			// Quick fix for "Parking":
			// If it's Shiprocket, execute strict check.
			// If generic, we need better contract.
			// Since Nimbus is parked, this logic effectively runs only for Shiprocket anyway.
			
			$success = false;
			if ( $winner_platform === 'shiprocket' ) {
				$success = ( isset( $awb_response['awb_assign_status'] ) && $awb_response['awb_assign_status'] == 1 );
			} else {
				// Nimbus or others
				// Assuming createOrder did the job or generateAWB returns success data
				// For now, assume success if we got shipment_id for non-SR platforms?
				// or implement generateAWB in Nimbus properly later.
				$success = true; 
			}

			if ( $success ) {
				error_log( "ZSS DEBUG: Fetching Label..." );
				$label_response = $adapter->getLabel( $response['shipment_id'] );
				error_log( "ZSS DEBUG: Label Response: " . print_r( $label_response, true ) );

				if ( isset( $label_response['label_url'] ) ) {
					// Extract AWB Code (Platform specific or normalized?)
					$awb_code = $awb_response['awb_code'] ?? $awb_response['response']['data']['awb_code'] ?? $response['awb_code'] ?? 'N/A';

					// Store Meta
					update_post_meta( $order_id, '_zh_shiprocket_shipment_id', $response['shipment_id'] );
					update_post_meta( $order_id, '_zh_shiprocket_awb', $awb_code );
					update_post_meta( $order_id, '_zh_shiprocket_label_url', $label_response['label_url'] );
					update_post_meta( $order_id, '_zh_shiprocket_label_status', 1 );
					// Store platform used for this order
					update_post_meta( $order_id, '_zh_shipping_platform', $winner_platform );

                    // BigShip Specific Storage
                    if ( $winner_platform === 'bigship' ) {
                         update_post_meta( $order_id, '_zh_shipment_platform', 'bigship' );
                         update_post_meta( $order_id, '_zh_system_order_id', $response['shipment_id'] );
                         
                         if ( isset( $awb_response['awb_code'] ) ) {
                             update_post_meta( $order_id, '_zh_awb', $awb_response['awb_code'] );
                         }
                         if ( ! empty( $awb_response['lr_number'] ) ) {
                             update_post_meta( $order_id, '_zh_bigship_lr_number', $awb_response['lr_number'] );
                         }
                         if ( isset( $awb_response['courier_name'] ) ) {
                             update_post_meta( $order_id, '_zh_courier', $awb_response['courier_name'] );
                         }
                         if ( isset( $awb_response['courier_id'] ) ) {
                             update_post_meta( $order_id, '_zh_courier_id', $awb_response['courier_id'] );
                         }
                         if ( isset( $label_response['label_url'] ) ) {
                             update_post_meta( $order_id, '_zh_label_pdf_url', $label_response['label_url'] );
                         }
                    }
					

					// Phase-1 Pickup: Schedule pickup for Shiprocket orders
					if ( $winner_platform === 'shiprocket' && method_exists( $adapter, 'generatePickup' ) ) {
						
						if ( ! is_wp_error( $pickup_response ) && isset( $pickup_response['pickup_status'] ) && $pickup_response['pickup_status'] == 1 ) {
							// Pickup scheduled successfully
							update_post_meta( $order_id, '_zh_shiprocket_pickup_status', 1 );
							update_post_meta( $order_id, '_zh_shiprocket_pickup_response', wp_json_encode( $pickup_response ) );
						} elseif ( ! is_wp_error( $pickup_response ) && isset( $pickup_response['message'] ) && stripos( $pickup_response['message'], 'Already in Pickup Queue' ) !== false ) {
							// Pickup was already scheduled (Shiprocket returns 400 for this)
							// We treat this as success for UI purposes
							update_post_meta( $order_id, '_zh_shiprocket_pickup_status', 1 );
							update_post_meta( $order_id, '_zh_shiprocket_pickup_response', wp_json_encode( $pickup_response ) );
						} else {
							// Pickup failed - log but don't block the label generation success
							$error_msg = is_wp_error( $pickup_response ) ? $pickup_response->get_error_message() : ( $pickup_response['message'] ?? 'Unknown error' );
							update_post_meta( $order_id, '_zh_shiprocket_pickup_status', 0 );
							update_post_meta( $order_id, '_zh_shiprocket_pickup_error', $error_msg );
						}
					}

					// Purge LiteSpeed Cache
					if ( function_exists( 'do_action' ) ) {
						do_action( 'litespeed_purge_post', $order_id );
						if ( ! empty( $_SERVER['HTTP_REFERER'] ) ) {
							do_action( 'litespeed_purge_url', $_SERVER['HTTP_REFERER'] );
						}
					}

					// Phase-3: Sync with Dokan Shipment UI
					try {
						$sync_awb = $awb_code;
						$sync_courier = $winner->courier;
						$sync_url = '';

						if ( $winner_platform === 'shiprocket' ) {
							$sync_url = 'https://shiprocket.co/tracking/' . $sync_awb;
						} elseif ( $winner_platform === 'bigship' ) {
							$sync_courier = $awb_response['courier_name'] ?? $winner->courier;
							// Proxy URL for BigShip (since they don't provide a parametric one)
							$sync_url = admin_url( 'admin-post.php?action=zh_track_shipment&order_id=' . $order_id );
						}

						\Zerohold\Shipping\Core\DokanShipmentSync::sync_shipment( $order_id, $sync_awb, $sync_courier, $sync_url );
					} catch ( \Exception $e ) {
					}

					wp_send_json_success( 'Label generated successfully' );
				} else {
				}
			} else {
			}
		} else {
		}

		wp_send_json_error( 'Failed to generate label. Please try again.' );
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
			// Ensure vendor share is calculated for cached results too
			$cached['vendor_min'] = floor( $cached['min_price'] / 2 );
			$cached['vendor_max'] = ceil( $cached['max_price'] / 2 );
			
			wp_send_json_success( array_merge( $cached, [ 'is_cached' => true, 'slab_info' => $slab_data ] ) );
		}

		// 4. API Orchestration (BigShip Primary)
		$resolver = new \Zerohold\Shipping\Core\ZoneResolver();
		$hubs     = $resolver->zoneTable( $origin_pin );
		
		$bigship = new \Zerohold\Shipping\Platforms\BigShipAdapter();
		$rates   = $bigship->estimateRates( $origin_pin, $hubs, $final_slab );

		// Fallback to Shiprocket if no rates found
		if ( empty( $rates ) ) {
			$shiprocket = new \Zerohold\Shipping\Platforms\ShiprocketAdapter();
			$rates      = $shiprocket->estimateRates( $origin_pin, $hubs, $final_slab );
		}

		if ( empty( $rates ) ) {
			wp_send_json_error( 'Unable to fetch estimate right now. Please try again later.' );
		}

		// 5. Aggregate and Format for Modal
		$zone_labels = \Zerohold\Shipping\Core\ZoneResolver::getZoneLabels();
		$zone_breakdown = [];
		$all_prices = [];

		foreach ( $hubs as $zone_key => $pin ) {
			$zone_rates = $rates[ $zone_key ] ?? [];
			$min = 999999;
			$max = 0;

			foreach ( $zone_rates as $r ) {
				$val = floatval( $r->base );
				if ( $val < $min ) $min = $val;
				if ( $val > $max ) $max = $val;
			}

			if ( $min === 999999 ) {
				$min = 0;
				$max = 0;
			}

			// Add range padding to look professional in UI
			$range_min = floor($min);
			$range_max = ceil($max ?: $min * 1.2); 

			// 50/50 Split Rule
			$buyer_min = floor( $range_min / 2 );
			$buyer_max = ceil( $range_max / 2 );
			$you_min   = $range_min - $buyer_min;
			$you_max   = $range_max - $buyer_max;

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

		$vendor_sum_min = floor( $sum_min / 2 );
		$vendor_sum_max = ceil( $sum_max / 2 );

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
}
