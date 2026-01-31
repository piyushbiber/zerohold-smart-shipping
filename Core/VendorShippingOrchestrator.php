<?php

namespace Zerohold\Shipping\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * VendorShippingOrchestrator Class
 * 
 * This is the CENTRAL BRAIN for all shipping operations. 
 * It acts as a PRODUCTION GUARD to ensure that shipping logic remains 
 * stable even when other parts of the system (like buyer UI or cancellation) are modified.
 * 
 * CORE RESPONSIBILITIES:
 * 1. Discover quotes from all enabled platforms (Shiprocket, BigShip).
 * 2. Filter out platforms with low balance.
 * 3. Select the cheapest valid courier.
 * 4. Create/Book the order on the winning platform.
 * 5. Handle post-booking steps (AWB, Manifesting, Label Generation).
 * 6. Deduct wallet balance and store order metadata.
 * 
 * PROTECTIVE MEASURES:
 * - Isolation: This logic is separate from UI handlers.
 * - State Guard: Errors during post-booking don't leave the system in an inconsistent state.
 * - Context Awareness: Differentiates between actual "Booking" and "Estimates".
 * 
 * @package Zerohold\Shipping\Core
 */
class VendorShippingOrchestrator {

	/**
	 * Process the entire shipping pipeline for a given order.
	 * 
	 * @param int    $order_id The WooCommerce Order ID.
	 * @param string $context  'booking' (actual label gen) or 'estimate' (dry run).
	 * @return array Result of the operation [success, message, data].
	 */
	public function processOrderShipping( $order_id, $context = 'booking' ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return [ 'success' => false, 'message' => 'Order not found' ];
		}

		error_log( "ZSS DEBUG: === Starting Shipping Pipeline for Order #{$order_id} (Context: {$context}) ===" );

		// Security Guard: Check if order is visible to vendor (cool-off window)
		if ( apply_filters( 'zh_can_vendor_act_on_order', true, $order_id ) === false ) {
			return [ 'success' => false, 'message' => 'Order is in cool-off period.' ];
		}

		// 🛡️ RACE CONDITION GUARD: Fresh check of order status
		$invalid_statuses = [ 'cancelled', 'refunded', 'failed', 'rejected' ];
		if ( in_array( $order->get_status(), $invalid_statuses ) ) {
			return [ 
				'success' => false, 
				'message' => 'Shipping failed: Order is already ' . $order->get_status() . ' by buyer.' 
			];
		}

		// Duplicate Guard
		$label_status = get_post_meta( $order_id, '_zh_shiprocket_label_status', true );
		if ( $label_status == 1 && $context === 'booking' ) {
			return [ 'success' => false, 'message' => 'Label already generated.' ];
		}

		// Map to Shipment
		$mapper   = new \Zerohold\Shipping\Core\OrderMapper();
		$shipment = $mapper->map( $order );

		// Auto-Refresh Warehouse if needed
		\Zerohold\Shipping\Core\WarehouseManager::checkAndRefresh( $shipment );

		// 1. Get Enabled Platforms
		$platforms = \Zerohold\Shipping\Core\PlatformManager::getEnabledPlatforms();
		$quotes    = [];
		$selector  = new \Zerohold\Shipping\Core\RateSelector();
		$excluded_platforms = [];

		// 2. Gather Quotes & Filter by Balance
		foreach ( $platforms as $key => $adapter ) {
			error_log( "ZSS DEBUG: Fetching rates for platform: {$key}" );
			$platform_rates = $adapter->getRates( $shipment );
			
			// GUARD: Standardize adapter return (must be an array)
			if ( ! is_array( $platform_rates ) ) {
				error_log( "ZSS GUARD: Platform {$key} returned invalid rates format (" . gettype( $platform_rates ) . "). Forced to empty array." );
				$platform_rates = [];
			}
			
			$quotes[ $key ] = $platform_rates;
			error_log( "ZSS DEBUG: Found " . count( $platform_rates ) . " rates for {$key}" );

			// Proactive Balance Check
			if ( ! empty( $quotes[ $key ] ) ) {
				$balance = $adapter->getWalletBalance();
				error_log( "ZSS DEBUG: Platform {$key} Balance: ₹{$balance}" );
				
				$local_best = 999999;
				foreach ( (array) $quotes[ $key ] as $q ) {
					$cost = is_object( $q ) ? $q->base : ( $q['base'] ?? 999999 );
					if ( $cost < $local_best ) $local_best = $cost;
				}

				if ( $balance < $local_best ) {
					error_log( "ZSS DEBUG: EXCLUDING platform {$key} due to insufficient balance (Best Rate: ₹{$local_best})" );
					$excluded_platforms[] = $key;
				}
			}
		}

		$retry = true;
		$selected_winner = null;
		$selected_adapter = null;
		$response = null;

		// 3. Selection & Fallback Loop
		while ( $retry ) {
			$active_quotes = [];
			foreach ( $quotes as $key => $q ) {
				if ( ! in_array( $key, $excluded_platforms ) ) {
					$active_quotes[ $key ] = $q;
				}
			}

			$winner = $selector->selectBestRate( $active_quotes );

			if ( ! $winner ) {
				error_log( "ZSS DEBUG: No valid winner found across active platforms." );
				$retry = false;
				continue;
			}

			$selected_winner = $winner;
			$winner_platform = $winner->platform;
			$adapter = $platforms[ $winner_platform ];
			$selected_adapter = $adapter;

			error_log( "ZSS DEBUG: WINNER SELECTED: {$winner->courier} on {$winner_platform} (Cost: ₹{$winner->base})" );

			// Prepare Shipment
			$shipment->courier     = $winner->courier; 
			$shipment->platform    = $winner_platform;
			$shipment->courier_id  = $winner->courier_id ?? '';

			if ( $context === 'estimate' ) {
				return [ 'success' => true, 'data' => $winner ];
			}

			// 4. Create Order (Book)
			error_log( "ZSS DEBUG: Calling createOrder on {$winner_platform} adapter..." );
			$response = $adapter->createOrder( $shipment );
			error_log( "ZSS DEBUG: createOrder Response: " . print_r( $response, true ) );

			// 5. Balance Check Fallback
			if ( $adapter->isBalanceError( $response ) ) {
				$excluded_platforms[] = $winner_platform;
				continue; 
			}

			$retry = false;
		}

		if ( ! $selected_winner || is_wp_error( $response ) || ( ! empty( $response['error'] ) && empty( $response['shipment_id'] ) ) ) {
			$err = is_wp_error( $response ) ? $response->get_error_message() : ( $response['error'] ?? 'No rates with balance found' );
			return [ 'success' => false, 'message' => 'Shipping failed: ' . $err ];
		}

		$shipment_id = $response['shipment_id'];
		$winner_platform = $selected_winner->platform;
		$adapter = $selected_adapter;

		// 6. Post-Booking Steps (AWB, Manifesting, Label)
		
		// BigShip specific: Manifest order with courier BEFORE AWB
		if ( $adapter instanceof \Zerohold\Shipping\Platforms\BigShipAdapter && ! empty( $shipment->courier_id ) ) {
			error_log( "ZSS DEBUG: BigShip detected. Manifesting order {$shipment_id} with Courier ID: {$shipment->courier_id}" );
			$manifest_result = $adapter->manifestOrder( $shipment_id, $shipment->courier_id );
			error_log( "ZSS DEBUG: Manifest Result: " . print_r( $manifest_result, true ) );
			if ( isset( $manifest_result['error'] ) ) {
				return [ 'success' => false, 'message' => 'Manifest failed: ' . $manifest_result['error'] ];
			}
			update_post_meta( $order_id, '_zh_bigship_manifest_status', 1 );
		}

		// AWB Assignment
		error_log( "ZSS DEBUG: Requesting AWB from {$winner_platform} with Courier ID: " . ( $shipment->courier_id ?: 'null' ) );
		$awb_response = $adapter->generateAWB( $shipment_id, $shipment->courier_id );
		error_log( "ZSS DEBUG: AWB Response: " . print_r( $awb_response, true ) );
		$awb_success = false;

		if ( $winner_platform === 'shiprocket' ) {
			$awb_success = ( isset( $awb_response['awb_assign_status'] ) && $awb_response['awb_assign_status'] == 1 );
		} else {
			$awb_success = true; // BigShip/Others assume success if we reach here
		}

		if ( ! $awb_success ) {
			return [ 'success' => false, 'message' => 'AWB assignment failed.' ];
		}

		// Label Generation
		$label_response = $adapter->getLabel( $shipment_id );
		if ( empty( $label_response['label_url'] ) ) {
			return [ 'success' => false, 'message' => 'Label URL not found in response.' ];
		}

		// 7. Success State & Storage
		$awb_code = $awb_response['awb_code'] ?? $awb_response['response']['data']['awb_code'] ?? $response['awb_code'] ?? 'N/A';

		$this->storeMeta( $order_id, $shipment_id, $awb_code, $label_response['label_url'], $winner_platform );
		$this->handleWalletDeduction( $order, $order_id, $selected_winner );
		$this->handleShiprocketPickup( $order_id, $shipment_id, $winner_platform, $adapter );
		$this->handleBigShipExtras( $order_id, $shipment_id, $winner_platform, $awb_response, $label_response );
		$this->syncDokanShipment( $order_id, $awb_code, $selected_winner, $winner_platform, $awb_response );
		$this->purgeCache( $order_id );

		return [ 'success' => true, 'message' => 'Label generated successfully' ];
	}

	private function storeMeta( $order_id, $shipment_id, $awb, $url, $platform ) {
		update_post_meta( $order_id, '_zh_shiprocket_shipment_id', $shipment_id );
		update_post_meta( $order_id, '_zh_shiprocket_awb', $awb );
		update_post_meta( $order_id, '_zh_shiprocket_label_url', $url );
		update_post_meta( $order_id, '_zh_shiprocket_label_status', 1 );
		update_post_meta( $order_id, '_zh_shipping_platform', $platform );
		update_post_meta( $order_id, '_zh_shipping_date', current_time( 'mysql' ) );
	}

	private function handleWalletDeduction( $order, $order_id, $winner ) {
		$vendor_id = 0;
		if ( function_exists( 'dokan_get_seller_id_by_order' ) ) {
			$vendor_id = dokan_get_seller_id_by_order( $order_id );
		} else {
			$vendor_id = $order->get_meta( '_dokan_vendor_id', true );
		}

		if ( $vendor_id ) {
			$total_cost = (float) $winner->base;
			$vendor_share_final = \Zerohold\Shipping\Core\PriceEngine::calculate_share_and_cap( $total_cost, 'vendor', $vendor_id );
			
			update_post_meta( $order_id, '_zh_shipping_cost', $vendor_share_final );

			// Store Additional Meta for Scenario D Cancellation Penalty
			update_post_meta( $order_id, '_zh_base_shipping_cost', $total_cost );
			
			// Calculate and store the Retailer Cap specifically
			$retailer_price = \Zerohold\Shipping\Core\PriceEngine::calculate_share_and_cap( $total_cost, 'retailer' );
			$retailer_share = $total_cost * ( (float) get_option( "zh_retailer_shipping_share_percentage", 50 ) / 100 );
			$retailer_cap   = max( 0, $retailer_price - $retailer_share );
			update_post_meta( $order_id, '_zh_retailer_cap_amount', $retailer_cap );
			
			// Note: Wallet deduction is handled by DokanStatementIntegration reading _zh_shipping_cost
		}
	}

	private function handleShiprocketPickup( $order_id, $shipment_id, $platform, $adapter ) {
		if ( $platform === 'shiprocket' && method_exists( $adapter, 'generatePickup' ) ) {
			$pickup = $adapter->generatePickup( $shipment_id );
			if ( ! is_wp_error( $pickup ) && ( ( isset($pickup['pickup_status']) && $pickup['pickup_status'] == 1 ) || ( isset($pickup['message']) && stripos($pickup['message'], 'Queue') !== false ) ) ) {
				update_post_meta( $order_id, '_zh_shiprocket_pickup_status', 1 );
			}
		}
	}

	private function handleBigShipExtras( $order_id, $shipment_id, $platform, $awb_res, $label_res ) {
		if ( $platform === 'bigship' ) {
			update_post_meta( $order_id, '_zh_shipment_platform', 'bigship' );
			update_post_meta( $order_id, '_zh_system_order_id', $shipment_id );
			if ( ! empty( $awb_res['awb_code'] ) ) update_post_meta( $order_id, '_zh_awb', $awb_res['awb_code'] );
			if ( ! empty( $awb_res['lr_number'] ) ) update_post_meta( $order_id, '_zh_bigship_lr_number', $awb_res['lr_number'] );
			if ( ! empty( $awb_res['courier_name'] ) ) update_post_meta( $order_id, '_zh_courier', $awb_res['courier_name'] );
			if ( ! empty( $awb_res['courier_id'] ) ) update_post_meta( $order_id, '_zh_courier_id', $awb_res['courier_id'] );
			if ( ! empty( $label_res['label_url'] ) ) update_post_meta( $order_id, '_zh_label_pdf_url', $label_res['label_url'] );
		}
	}

	private function syncDokanShipment( $order_id, $awb, $winner, $platform, $awb_res ) {
		try {
			$sync_courier = $winner->courier;
			$sync_url = '';

			if ( $platform === 'shiprocket' ) {
				$sync_url = 'https://shiprocket.co/tracking/' . $awb;
			} elseif ( $platform === 'bigship' ) {
				$sync_courier = $awb_res['courier_name'] ?? $winner->courier;
				$sync_url = admin_url( 'admin-post.php?action=zh_track_shipment&order_id=' . $order_id );
			}

			\Zerohold\Shipping\Core\DokanShipmentSync::sync_shipment( $order_id, $awb, $sync_courier, $sync_url );
		} catch ( \Exception $e ) {
			// Fail silently for sync
		}
	}

	private function purgeCache( $order_id ) {
		if ( function_exists( 'do_action' ) ) {
			do_action( 'litespeed_purge_post', $order_id );
			if ( ! empty( $_SERVER['HTTP_REFERER'] ) ) {
				do_action( 'litespeed_purge_url', $_SERVER['HTTP_REFERER'] );
			}
		}
	}
}
