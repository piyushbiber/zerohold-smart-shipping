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
			'order_id'              => $shipment->order_id . '-' . time(), // unique ID
			'order_date'            => current_time( 'Y-m-d H:i' ),
			'pickup_location'       => \Zerohold\Shipping\Core\WarehouseManager::ensureWarehouse( $shipment, 'shiprocket' ) ?: 'Primary',
			'billing_customer_name' => $shipment->to_contact,
			'billing_customer_name' => $shipment->to_contact,
			'billing_last_name'     => '',
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
	public function createWarehouse( $shipment ) {
		// Endpoint: /settings/pickup-locations/add
		// Required: pickup_location, name, email, phone, address, city, state, country, pin_code
		
		// Unique name for this vendor to avoid collision?
		// SR Pickup Locations are identified by "pickup_location" (nickname).
		// We can use "Vendor_{ID}_{ShortName}" or just "Vendor_{ID}".
		$pickup_code = 'Vendor_' . $shipment->vendor_id;

		$payload = [
			'pickup_location' => $pickup_code,
			'name'            => $shipment->from_store ?: 'Vendor Store',
			'email'           => 'vendor@example.com', // Dokan might store this, mapping from user needed if not in address
			'phone'           => $shipment->from_phone ?: '9876543210',
			'address'         => $shipment->from_address1,
			'address_2'       => $shipment->from_address2,
			'city'            => $shipment->from_city,
			'state'           => $shipment->from_state,
			'country'         => 'India', // SR specific
			'pin_code'        => $shipment->from_pincode,
		];

		$response = $this->client->post( 'settings/pickup-locations/add', $payload );

		error_log( 'ZSS DEBUG: Shiprocket createWarehouse response: ' . print_r( $response, true ) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}
		
		// If success, it might return success: true. 
		// Or if exists, it returns error but we can use the code.
		// Docs say: 422 if exists.
		
		// If exists: "pickup_location already exists".
		if ( isset( $response['success'] ) && $response['success'] ) {
			return $pickup_code;
		}

		// Handle "already exists" logic if needed, but for now user logic is:
		// "if (!warehouseExistsForVendor) -> create".
		// Since we check user_meta first in Manager, we only call this if DB is empty.
		// If SR says exists, we should probably save it to DB anyway.
		if ( isset( $response['message'] ) && stripos( $response['message'], 'already exists' ) !== false ) {
			return $pickup_code;
		}

		return $pickup_code; // Best effort return code if we think it worked or existed
	}
}
