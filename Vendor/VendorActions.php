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
		
		// Step 2.6.4: Register AJAX handler
		add_action( 'wp_ajax_zh_generate_label', [ $this, 'zh_handle_generate_label_ajax' ] );
		add_action( 'wp_ajax_zh_confirm_return_handover', [ $this, 'zh_confirm_return_handover' ] );
		
		// Step 2.6.5: Register download handler
		add_action( 'admin_post_zh_download_label', [ $this, 'zh_download_label' ] );
		add_action( 'admin_post_zh_download_return_label', [ $this, 'zh_download_return_label' ] );
		add_action( 'admin_post_nopriv_zh_download_return_label', [ $this, 'zh_download_return_label' ] );

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
							$sync_url = 'https://bigship.in/tracking?tracking_number=' . $sync_awb;
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
}
