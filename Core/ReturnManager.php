<?php

namespace Zerohold\Shipping\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Zerohold\Shipping\Core\OrderMapper;
use Zerohold\Shipping\Core\PlatformManager;
use Zerohold\Shipping\Core\RateSelector;

class ReturnManager {

	public function __construct() {
		// AJAX handler for manual admin button
		add_action( 'wp_ajax_zh_initiate_return_shipping', [ $this, 'handle_initiate_return_ajax' ] );
		add_action( 'wp_ajax_zh_refetch_return_label', [ $this, 'handle_refetch_label_ajax' ] );

		// Automated trigger on WP Swings Refund Approval
		// Based on user feedback: "Refund approved -> initiate return shipping"
		// Hook name synthesized from user's screenshot showing "Action Hooks"
		add_action( 'wps_rma_refund_request_approved', [ $this, 'create_return_shipment' ], 10, 1 );
	}

	/**
	 * AJAX Wrapper for manual trigger.
	 */
	public function handle_initiate_return_ajax() {
		check_ajax_referer( 'zh_return_nonce', 'security' );

		if ( empty( $_POST['order_id'] ) ) {
			wp_send_json_error( 'Missing Order ID' );
		}

		$order_id = intval( $_POST['order_id'] );
		$result   = $this->create_return_shipment( $order_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX handler to refetch a missing label URL.
	 */
	public function handle_refetch_label_ajax() {
		check_ajax_referer( 'zh_return_nonce', 'security' );

		$order_id = intval( $_POST['order_id'] );
		$ship_id  = get_post_meta( $order_id, '_zh_return_shipment_id', true );
		$platform = get_post_meta( $order_id, '_zh_return_platform', true );

		if ( ! $ship_id || ! $platform ) {
			wp_send_json_error( 'Shipment not found' );
		}

		$platforms = PlatformManager::getEnabledPlatforms();
		if ( ! isset( $platforms[ $platform ] ) ) {
			wp_send_json_error( 'Platform adapter not found' );
		}

		$adapter   = $platforms[ $platform ];
		$label_res = $adapter->getLabel( $ship_id );

		if ( isset( $label_res['label_url'] ) ) {
			update_post_meta( $order_id, '_zh_return_label_url', $label_res['label_url'] );
			wp_send_json_success( 'Label URL updated' );
		}

		wp_send_json_error( 'Label URL still not available from carrier' );
	}

	/**
	 * Core Logic: Creates a return shipment (Manual or Automated).
	 * 
	 * @param int $order_id
	 * @return string|WP_Error Success message or error
	 */
	public function create_return_shipment( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return new \WP_Error( 'order_not_found', 'Order not found' );
		}

		// Guard: Prevent duplicate return shipment
		$existing = get_post_meta( $order_id, '_zh_return_shipment_id', true );
		if ( $existing ) {
			return new \WP_Error( 'already_exists', 'Return shipment already exists for this order: ' . $existing );
		}

		// 1. Map Order for Return
		$mapper   = new OrderMapper();
		$shipment = $mapper->map( $order, 'return' );
		
		error_log( 'ZSS RETURN: Processing return for order ' . $order_id );

		// 2. Fetch Original Shipment Data for Priority
		$orig_platform = get_post_meta( $order_id, 'zh_shipping_platform', true );
		$orig_courier  = get_post_meta( $order_id, 'zh_courier_name', true );

		$platforms = PlatformManager::getEnabledPlatforms();
		$winner    = null;

		// 3. PRIORITY LOGIC: Same Platform + Same Courier
		if ( $orig_platform && $orig_courier && isset( $platforms[ $orig_platform ] ) ) {
			error_log( "ZSS RETURN: Attempting Priority (Same Platform: $orig_platform, Courier: $orig_courier)" );
			
			$adapter = $platforms[ $orig_platform ];
			$rates   = $adapter->getRates( $shipment ); 
			$platform_rates = isset( $rates[ $orig_platform ] ) ? $rates[ $orig_platform ] : $rates;

			foreach ( (array) $platform_rates as $rate ) {
				if ( ! is_object( $rate ) ) continue;
				
				if ( strtolower( trim( $rate->courier ) ) === strtolower( trim( $orig_courier ) ) ) {
					error_log( "ZSS RETURN: Priority Match Found" );
					$winner = $rate;
					break;
				}
			}
		}

		// 4. FALLBACK LOGIC: Standard ZSS Rate Logic
		if ( ! $winner ) {
			error_log( "ZSS RETURN: Priority failed. Using fallback ZSS logic." );
			$quotes = [];
			foreach ( $platforms as $key => $adapter ) {
				$quotes[ $key ] = $adapter->getRates( $shipment );
			}
			$selector = new RateSelector();
			$winner   = $selector->selectBestRate( $quotes );
		}

		if ( ! $winner ) {
			return new \WP_Error( 'no_rates', 'No shipping routes available for return.' );
		}

		// 5. Create Order (Book)
		$shipment->courier     = $winner->courier;
		$shipment->platform    = $winner->platform;
		$shipment->courier_id  = $winner->courier_id ?? '';

		$adapter = $platforms[ $winner->platform ];
		$response = $adapter->createOrder( $shipment );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// 6. Finalize (AWB + Label)
		if ( isset( $response['shipment_id'] ) ) {
			if ( $adapter instanceof \Zerohold\Shipping\Platforms\BigShipAdapter && ! empty( $shipment->courier_id ) ) {
				$adapter->manifestOrder( $response['shipment_id'], $shipment->courier_id );
			}

			$awb_response = $adapter->generateAWB( $response['shipment_id'] );
			$label_res    = $adapter->getLabel( $response['shipment_id'] );

			update_post_meta( $order_id, '_zh_return_shipment_id', $response['shipment_id'] );
			update_post_meta( $order_id, '_zh_return_platform', $winner->platform );
			update_post_meta( $order_id, '_zh_return_courier', $winner->courier );
			
			if ( isset( $awb_response['awb_code'] ) ) {
				update_post_meta( $order_id, '_zh_return_awb', $awb_response['awb_code'] );
			}
			if ( isset( $label_res['label_url'] ) ) {
				update_post_meta( $order_id, '_zh_return_label_url', $label_res['label_url'] );
			}

			return 'Return Shipment Created via ' . ucfirst($winner->platform) . ' (' . $winner->courier . ')';
		}

		return new \WP_Error( 'finalize_failed', 'Failed to finalize return shipment.' );
	}
}
