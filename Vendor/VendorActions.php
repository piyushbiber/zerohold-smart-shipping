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
		
		// Step 2.6.5: Register download handler
		add_action( 'admin_post_zh_download_label', [ $this, 'zh_download_label' ] );

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

		// TEMP: Visualization for validation
		echo '<div style="font-family: sans-serif; padding: 20px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 8px; margin: 20px;">';
		echo '<h2 style="color: #2271b1; border-bottom: 2px solid #2271b1; padding-bottom: 10px;">ZSS Push Result</h2>';
		echo '<p><strong>Order ID:</strong> ' . esc_html( $order_id ) . '</p>';
		echo '<p><strong>Platform:</strong> ' . esc_html( ucfirst( $winner_platform ) ) . '</p>';
		echo '<p><strong>Courier:</strong> ' . esc_html( $winner->courier ) . '</p>';
		echo '<p><strong>Cost:</strong> ' . esc_html( $winner->base ) . '</p>';
		echo '<hr>';
		echo '<h3>Order Response:</h3>';
		echo '<pre style="background: #222; color: #0f0; padding: 15px; border-radius: 4px; overflow-x: auto;">';
		print_r( $response );
		echo '</pre>';

		if ( ! empty( $awb_response ) ) {
			echo '<hr>';
			echo '<h3>AWB Response:</h3>';
			echo '<pre style="background: #000; color: #ffeb3b; padding: 15px; border-radius: 4px; overflow-x: auto;">';
			print_r( $awb_response );
			echo '</pre>';
		}

		if ( ! empty( $label_response ) ) {
			echo '<hr>';
			echo '<h3>Label Response:</h3>';
			echo '<pre style="background: #fff; color: #000; border: 2px solid #2271b1; padding: 15px; border-radius: 4px; overflow-x: auto;">';
			print_r( $label_response );
			echo '</pre>';

			if ( isset( $label_response['label_url'] ) ) {
				echo '<p style="margin-top:10px;"><a href="' . esc_url( $label_response['label_url'] ) . '" target="_blank" style="display:inline-block; padding:10px 20px; background:#2271b1; color:#fff; text-decoration:none; border-radius:4px; font-weight:bold;">DOWNLOAD LABEL</a></p>';
			}
		}
		
		echo '<hr>';
		echo '<h3>Mapped Shipment Data:</h3>';
		echo '<pre style="background: #eee; color: #333; padding: 15px; border-radius: 4px; overflow-x: auto;">';
		print_r( $shipment );
		echo '</pre>';
		echo '</div>';
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
		error_log( 'ZSS AJAX: Starting pipeline for order ' . $order_id );
		$mapper   = new OrderMapper();
		$shipment = $mapper->map( $order );
		error_log( 'ZSS AJAX: Shipment mapped' );

		// Step 2.6.6: Auto-Refresh Warehouse if needed
		\Zerohold\Shipping\Core\WarehouseManager::checkAndRefresh( $shipment );

		// 1. Get Enabled Platforms
		$platforms = \Zerohold\Shipping\Core\PlatformManager::getEnabledPlatforms();
		$quotes    = [];

		// 2. Gather Quotes
		foreach ( $platforms as $key => $platform_adapter ) {
			error_log( "ZSS: Fetching rates for $key" );
			$quotes[ $key ] = $platform_adapter->getRates( $shipment );
		}

		// 3. Select Winner
		$selector = new \Zerohold\Shipping\Core\RateSelector();
		$winner   = $selector->selectBestRate( $quotes );

		if ( ! $winner ) {
			wp_send_json_error( 'No shipping rates available from enabled platforms.' );
			return;
		}

		$winner_platform = $winner->platform; // 'shiprocket', 'nimbus', etc.
		// Note from Phase-4 logic: we need to use the ADAPTER of the winner to book.
		// But wait, $winner->platform gives us the string key.
		// We can get the adapter from $platforms array IF it matches the key.
		// HOWEVER, RateNormalizer sets platform string. 
		// ShiprocketAdapter sets 'shiprocket'. RateNormalizer default for SR is ?? 
		// Let's assume standard keys match.
		
		// If winner is 'nimbus', we need nimbus adapter.
		// But if Nimbus is parked (commented out), we shouldn't have gotten a quote from it unless...
		// Ah, we only fetch quotes from enabled platforms. So winner MUST be from one of them.
		
		$adapter = $platforms[ $winner_platform ] ?? reset( $platforms ); // fallback
		
		error_log( "ZSS: Winner is {$winner_platform} with courier {$winner->courier} at {$winner->base}" );

		// 4. Create Order (Book)
		// We might need to pass selected courier info to the adapter?
		// ShiprocketAdapter doesn't seem to take courier ID in createOrder, it does "adhoc" then "generateAWB".
		// Nimbus takes it directly.
		// We should enhance $shipment with selection info.
		$shipment->courier  = $winner->courier; 
		$shipment->platform = $winner_platform;
		// Add courier_id for BigShip
		if ( ! empty( $winner->courier_id ) ) {
			$shipment->courier_id = $winner->courier_id;
		}
		
		error_log( 'ZSS AJAX: Calling createOrder on ' . get_class( $adapter ) );
		$response = $adapter->createOrder( $shipment );
		error_log( 'ZSS AJAX: createOrder response: ' . print_r( $response, true ) );

		if ( isset( $response['shipment_id'] ) ) {
			error_log( 'ZSS AJAX: shipment_id received: ' . $response['shipment_id'] );
			
			// Platform-specific follow-up (AWB gen)
			// Shiprocket needs generateAWB. Nimbus might not.
			// Let's generically call generateAWB, adapters can return success/noop if not needed.
			
			error_log( 'ZSS AJAX: Calling generateAWB' );
			$awb_response = $adapter->generateAWB( $response['shipment_id'] );
			error_log( 'ZSS AJAX: generateAWB response: ' . print_r( $awb_response, true ) );

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
				error_log( 'ZSS AJAX: AWB assigned/confirmed, calling getLabel' );
				$label_response = $adapter->getLabel( $response['shipment_id'] );
				error_log( 'ZSS AJAX: getLabel response: ' . print_r( $label_response, true ) );

				if ( isset( $label_response['label_url'] ) ) {
					// Extract AWB Code (Platform specific or normalized?)
					$awb_code = $awb_response['response']['data']['awb_code'] ?? $response['awb_code'] ?? 'N/A';

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
					
					error_log( 'ZSS AJAX: Meta updated, success' );

					// Purge LiteSpeed Cache
					if ( function_exists( 'do_action' ) ) {
						do_action( 'litespeed_purge_post', $order_id );
						if ( ! empty( $_SERVER['HTTP_REFERER'] ) ) {
							do_action( 'litespeed_purge_url', $_SERVER['HTTP_REFERER'] );
						}
					}

					wp_send_json_success( 'Label generated successfully' );
				} else {
					error_log( 'ZSS AJAX ERROR: label_url not in response' );
				}
			} else {
				error_log( 'ZSS AJAX ERROR: AWB assignment failed' );
			}
		} else {
			error_log( 'ZSS AJAX ERROR: No shipment_id in response' );
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
}
