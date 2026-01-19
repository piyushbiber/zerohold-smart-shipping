<?php

namespace Zerohold\Shipping\Platforms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Zerohold\Shipping\Integrations\ShiprocketClient;

class ShiprocketAdapter implements PlatformInterface {

	private $client;

	public function __construct() {
		$this->client = new \Zerohold\Shipping\Integrations\ShiprocketClient();
		
		// Temporary Auth Test
		if ( isset( $_GET['zh_test_shiprocket_auth'] ) ) {
			add_action( 'init', [ $this, 'testAuth' ] );
		}
	}

	public function createOrder( $shipment ) {
		// Ensure warehouse exists (Strict)
		$warehouse_id = \Zerohold\Shipping\Core\WarehouseManager::ensureWarehouse( $shipment, 'shiprocket' );
		if ( ! $warehouse_id ) {
			return [ 'error' => 'Shiprocket Warehouse Check Failed. Please check vendor address.' ];
		}

		// Authenticate and Capture Result
		$auth_response = $this->client->login();

		if ( is_wp_error( $auth_response ) ) {
			return [
				'status'  => 'error',
				'message' => 'Authentication Connection Failed',
				'debug'   => $auth_response->get_error_message()
			];
		}

		if ( ! isset( $auth_response['token'] ) ) {
			return [
				'status'  => 'error',
				'message' => 'Authentication Failed: Invalid Credentials or API Error',
				'debug'   => $auth_response
			];
		}

		$order_items = [];
		foreach ( $shipment->items as $item ) {
			$order_items[] = [
				'name'          => $item['name'],
				'sku'           => $item['sku'],
				'units'         => $item['qty'],
				'selling_price' => $shipment->declared_value / max( 1, count( $shipment->items ) ), // Approx share
				'discount'      => '',
				'tax'           => '',
				'hsn'           => ''
			];
		}

		$payload = [
			'order_id'              => $shipment->order_id . '-' . time(), // unique ID
			'order_date'            => current_time( 'Y-m-d H:i' ),
			'pickup_location'       => $warehouse_id,
			'billing_customer_name' => $shipment->to_contact,
			'billing_address'       => $shipment->to_address1,
			'billing_address_2'     => $shipment->to_address2,
			'billing_city'          => $shipment->to_city,
			'billing_pincode'       => $shipment->to_pincode,
			'billing_state'         => $shipment->to_state,
			'billing_country'       => $shipment->to_country,
			'billing_email'         => 'customer@example.com', // fallback
			'billing_phone'         => $shipment->to_phone,
			'shipping_is_billing'   => true,
			'order_items'           => $order_items,
			'payment_method'        => $shipment->payment_mode,
			'shipping_charges'      => 0,
			'giftwrap_charges'      => 0,
			'transaction_charges'   => 0,
			'total_discount'        => 0,
			'sub_total'             => $shipment->declared_value,
			'length'                => $shipment->length,
			'breadth'               => $shipment->width,
			'height'                => $shipment->height,
			'weight'                => $shipment->weight
		];

		// Post to Shiprocket
		$response = $this->client->post( 'orders/create/adhoc', $payload );

		return $response;
	}

	public function getRateQuote( $origin_pincode, $destination_pincode, $weight, $cod = 0 ) {
		// Authenticate first
		$auth_response = $this->client->login();

		if ( is_wp_error( $auth_response ) || ! isset( $auth_response['token'] ) ) {
			return $auth_response;
		}

		$query_args = [
			'pickup_postcode'   => $origin_pincode,
			'delivery_postcode' => $destination_pincode,
			'weight'            => $weight,
			'cod'               => $cod,
		];

		$response = $this->client->get( 'courier/serviceability/', $query_args );
		
		error_log( 'ZSS DEBUG: Shiprocket raw serviceability response: ' . print_r( $response, true ) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// Use the normalizer to return a unified model
		$normalizer = new \Zerohold\Shipping\Core\RateNormalizer();
		return $normalizer->normalizeShiprocket( $response );
	}

	public function getRates( $shipment ) {
		// Ensure warehouse exists (Phase-2)
		\Zerohold\Shipping\Core\WarehouseManager::ensureWarehouse( $shipment, 'shiprocket' );

		$quote = $this->getRateQuote(
			$shipment->from_pincode,
			$shipment->to_pincode,
			$shipment->weight,
			( $shipment->payment_mode === 'COD' ? 1 : 0 )
		);
		
		error_log( 'ZSS DEBUG: Shiprocket getRateQuote result: ' . print_r( $quote, true ) );

		return [ 'shiprocket' => $quote ];
	}

	public function generateAWB( $shipment_id ) {
		$payload = [
			'shipment_id' => $shipment_id,
			'courier_id'  => null, // Auto-assign cheapest
		];

		// Post to Shiprocket
		$response = $this->client->post( 'courier/assign/awb', $payload );

		return $response;
	}
	public function getLabel( $shipment_id ) {
		// POST /courier/generate/label
		return $this->client->post(
			'courier/generate/label',
			[
				'shipment_id' => [ $shipment_id ]
			],
			true
		);
	}
	public function track( $shipment_id ) { return null; }

	/**
	 * Creates a pickup location (warehouse) on Shiprocket.
	 * 
	 * @param \Zerohold\Shipping\Models\Shipment $shipment
	 * @return string|WP_Error Pickup Location Name (ID)
	 */
	/**
	 * Creates a pickup location (warehouse) on Shiprocket.
	 * 
	 * @param \Zerohold\Shipping\Models\Shipment $shipment
	 * @return string|WP_Error Pickup Location Name (ID)
	 */
	public function createWarehouse( $shipment ) {
		// Endpoint: POST /settings/company/addpickup
		// Phase-1: Use correct endpoint
		
		// Phase-2: Payload Mapping
		// Generate a unique code if possible, or let SR handle nickname?
		// "pickup_location" field is the Nickname/ID.
		$pickup_code = 'Vendor_' . $shipment->vendor_id;
		
		// Phase-7: Normalize Pincode (6 digits, int)
		$pin_code = preg_replace( '/[^0-9]/', '', $shipment->from_pincode );
		$pin_code = substr( $pin_code, 0, 6 ); // Ensure max 6

		if ( strlen( $pin_code ) < 6 ) {
			return new \WP_Error( 'sr_warehouse_error', 'Invalid Pincode for Shiprocket Warehouse: ' . $shipment->from_pincode );
		}

		$payload = [
			'pickup_location' => $pickup_code,
			'name'            => $shipment->from_store ?: 'Vendor Store ' . $shipment->vendor_id,
			'email'           => 'vendor' . $shipment->vendor_id . '@example.com', // Unique email if possible?
			'phone'           => $shipment->from_phone ?: '9876543210',
			'address'         => $shipment->from_address1,
			'address_2'       => $shipment->from_address2,
			'city'            => $shipment->from_city,
			'state'           => $shipment->from_state,
			'country'         => 'India',
			'pin_code'        => intval( $pin_code ), // Phase-7
		];

		// POST settings/company/addpickup
		$response = $this->client->post( 'settings/company/addpickup', $payload );

		error_log( 'ZSS DEBUG: Shiprocket AddPickup Response: ' . print_r( $response, true ) );

		if ( is_wp_error( $response ) ) {
			// Check if error is "already exists"
			// SR might return 422 with message.
			// Ideally we assume failure unless we can recover code.
			return $response;
		}

		// Phase-3: Extract ID
		// Check for success address object
		if ( isset( $response['success'] ) && $response['success'] ) {
			// Valid creation
			// Response format check: { "address": { "pickup_location": "..." } } ?
			if ( isset( $response['address']['pickup_location'] ) ) {
				return $response['address']['pickup_location'];
			}
			// Sometimes just in root?
			if ( isset( $response['pickup_location'] ) ) {
				return $response['pickup_location'];
			}
		}

		// Error Handling / Existence Check
		// If "already exists", we might want to assume $pickup_code is valid?
		// BUT User Phase-4 says: "If warehouse not created -> fail and log error, NOT fallback."
		
		// However, if we tried to create 'Vendor_123' and it says "Already exists", 
		// that implies 'Vendor_123' IS the valid ID.
		// So we should return it safe.
		if ( isset( $response['message'] ) ) {
			if ( stripos( $response['message'], 'exists' ) !== false || stripos( $response['message'], 'taken' ) !== false ) {
				return $pickup_code;
			}
		}

		return new \WP_Error( 'sr_warehouse_failed', 'Failed to create Shiprocket Warehouse: ' . print_r( $response, true ) );
	}
}
