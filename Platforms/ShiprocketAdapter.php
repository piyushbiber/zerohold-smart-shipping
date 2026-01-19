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
			'pickup_location'       => 'Primary', // fallback or mapping needed
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
}
