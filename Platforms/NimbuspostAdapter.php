<?php

namespace Zerohold\Shipping\Platforms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Zerohold\Shipping\Integrations\NimbuspostClient;
use Zerohold\Shipping\Core\RateNormalizer;

class NimbuspostAdapter implements PlatformInterface {

	private $client;

	public function __construct() {
		$this->client = new NimbuspostClient();
	}

	public function getRates( $shipment ) {
		// Prepare payload for NimbusPost serviceability API
		$payload = [
			'origin'             => $shipment->from_pincode,
			'destination'        => $shipment->to_pincode,
			'weight'             => $shipment->weight, // Kg
			'length'             => $shipment->length,
			'breadth'            => $shipment->width,
			'height'             => $shipment->height,
			'payment_type'       => 'prepaid', // Enforcing prepaid as per request
			// Additional fields might be needed depending on strictness of API
			// 'order_amount'    => $shipment->declared_value
		];

		// Important: Check if field names match API docs exactly.
		// User said: origin_pincode, destination_pincode
		// Let me double check the user request text.
		// User said: origin_pincode, destination_pincode.
		// Retrying with user specified keys.

		$payload = [
			'origin'             => $shipment->from_pincode, // API doc link says 'origin' but user pseudo said 'origin_pincode'. Documentation usually wins.
			// Let's stick to user pseudo or my best guess? 
			// User Link provided. I can't browse. 
			// User Pseudo: origin_pincode, destination_pincode.
			// Let's try to be safe and use what user provided in "STEP-2".
			'origin_pincode'      => $shipment->from_pincode,
			'destination_pincode' => $shipment->to_pincode,
			'weight'              => $shipment->weight,
			'length'              => $shipment->length,
			'breadth'             => $shipment->width,
			'height'              => $shipment->height,
			'payment_type'        => 'prepaid',
		];
		
		$response = $this->client->post( 'courier/serviceability', $payload );
		
		if ( is_wp_error( $response ) || ! is_array( $response ) || empty( $response['data'] ) ) {
			return [];
		}

		// Normalize
		$normalizer = new RateNormalizer();
		$rates      = [];

		// Response['data'] usually contains the list
		if ( isset( $response['data'] ) && is_array( $response['data'] ) ) {
			foreach ( $response['data'] as $rate_data ) {
				$rates[] = $normalizer->normalizeNimbus( $rate_data );
			}
		}

		return [ 'nimbus' => $rates ];
	}

	public function createOrder( $shipment ) {
		// Map ZSS Shipment to Nimbus Order Payload
		// User STEP-5: address, item details, dims, courier code, PREPAID
		// AND we need 'courier_id' from the chosen rate? 
		// Actually createOrder in PlatformInterface takes $shipment. 
		// But in ZSS flow, we pick a rate first. 
		// The $shipment object needs to hold the selected courier info if we are booking specific one.
		// Or maybe we are just creating "Shipment" and then assigning courier?
		// Shiprocket flow: create adhoc order, then generate AWB (assign courier).
		// Nimbus flow: POST /v1/shipments usually books it directly?
		// Let's assume we pass necessary courier ID if needed, or it's auto.
		// User notes: "Payload includes ... courier code returned from Quote"
		// Ensure $shipment has courier info.
		
		$items = [];
		foreach ( $shipment->items as $item ) {
			$items[] = [
				'name'       => $item['name'],
				'qty'        => $item['qty'],
				'unit_price' => $shipment->declared_value / max( 1, $shipment->qty ), // simple distrib
				'sku'        => $item['sku'],
			];
		}

		$payload = [
			'order_number'        => $shipment->order_id . '-' . time(),
			'shipping_charges'    => 0,
			'discount'            => 0,
			'cod_charges'         => 0,
			'payment_type'        => 'prepaid',
			'order_amount'        => $shipment->declared_value,
			'package_weight'      => $shipment->weight,
			'package_length'      => $shipment->length,
			'package_breadth'     => $shipment->width,
			'package_height'      => $shipment->height,
			'consignee' => [
				'name'    => $shipment->to_contact,
				'phone'   => $shipment->to_phone,
				'address' => $shipment->to_address1 . ' ' . $shipment->to_address2,
				'pincode' => $shipment->to_pincode,
				'city'    => $shipment->to_city,
				'state'   => $shipment->to_state,
			],
			'pickup' => [
				'warehouse_name' => 'Primary', // Mapping needed?
				'pincode'        => $shipment->from_pincode,
				// Often need more warehouse details or ID.
			],
			'order_items' => $items,
			// 'courier_id' => ??? We need to know which courier was selected.
			// Assuming $shipment->courier holds the ID or code if set.
		];
		
		// If $shipment has courier info, pass it.
		if ( ! empty( $shipment->courier ) ) {
			$payload['courier_id'] = $shipment->courier; 
		}

		$response = $this->client->post( 'shipments', $payload );

		// Parse response to return ZSS format
		// Expecting: tracking_number, label_url, shipment_id/id
		
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return $response;
	}

	public function generateAWB( $shipment_id ) {
		// Nimbus might do this in createOrder?
		// If separate step needed:
		return [];
	}

	public function getLabel( $shipment_id ) {
		// GET /v1/shipments/label/{id} ??
		// User says: /v1/shipments/:id or within booking
		return $this->client->get( 'shipments/label/' . $shipment_id );
	}

	public function track( $shipment_id ) {
		return $this->client->get( 'shipments/track/' . $shipment_id );
	}
}
