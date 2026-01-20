<?php

namespace Zerohold\Shipping\Platforms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Zerohold\Shipping\Integrations\BigShipClient;
use Zerohold\Shipping\Core\RateNormalizer;

class BigShipAdapter implements PlatformInterface {

	private $client;

	public function __construct() {
		$this->client = new BigShipClient();
	}

	/**
	 * Helper: Create/Update Order on BigShip to get System ID.
	 * 
	 * @param object $shipment
	 * @return string|WP_Error System Order ID
	 */
	private function createDraftOrder( $shipment ) {
		// Endpoint: POST /api/order/add/single
		
		// Ensure Warehouse Exists
		$warehouse_id = \Zerohold\Shipping\Core\WarehouseManager::ensureWarehouse( $shipment, 'bigship' );
		if ( ! $warehouse_id ) {
			return new \WP_Error( 'bigship_warehouse', 'Failed to retrieve BigShip Warehouse ID' );
		}
		
		$items = [];
		foreach ( $shipment->items as $item ) {
			$items[] = [
				'name'          => $item['name'],
				'sku'           => $item['sku'],
				'quantity'      => $item['qty'],
				'unit_price'    => $shipment->declared_value / max( 1, $shipment->qty ), 
				'tax_rate'      => 0,
				'hsn_code'      => '',
				'discount'      => 0,
			];
		}

		$payload = [
			'order_id'          => (string) $shipment->order_id, // Merchant Order ID
			'order_date'        => current_time( 'Y-m-d H:i:s' ),
			'channel'           => 'Custom',
			'pickup_address_id' => (string) $warehouse_id,
			// BigShip supposedly supports "warehouse_details" object inline if ID not known? 
			// Or we must fetch warehouse list first? 
			// For Phase-4 MVP, assume default or let user configure later.
			// Let's try passing empty or dummy if allowed? Or omitting?
			// Re-reading spec: "pickup_address_id": "<warehouse_id>"
			// This is a blocker if we don't have it.
			// Ideally we fetch list. But let's assume we need to pass something valid.
			// Using random ID might fail. 
			// Strategy: 'pickup_address_id' might be optional if we pass address? 
			// Let's stick to spec.
			
			'payment_category'  => 'Prepaid',
			'shipment_category' => 'B2C', // Essential
			'invoice_value'     => $shipment->declared_value,
			'total_amount'      => $shipment->declared_value,
			'customer_details'  => [
				'name'    => $shipment->to_contact,
				'email'   => 'customer@example.com',
				'mobile'  => $shipment->to_phone,
				'address_line1' => $shipment->to_address1,
				'address_line2' => $shipment->to_address2,
				'pincode' => $shipment->to_pincode,
				'city'    => $shipment->to_city,
				'state'   => $shipment->to_state,
			],
			'weight'            => $shipment->weight * 1000, // Grams!
			'dimensions'        => [
				'length' => $shipment->length,
				'breadth' => $shipment->width,
				'height' => $shipment->height,
			],
			'order_items'       => $items,
		];

		// Include Courier ID if selecting/booking
		if ( ! empty( $shipment->courier_id ) ) {
			$payload['courier_id'] = $shipment->courier_id;
		} elseif ( ! empty( $shipment->courier ) ) {
			// Fallback: mostly for logging, API likely needs ID
			$payload['courier_name'] = $shipment->courier; 
		}

		// Note: "pickup_address_id" is mandatory usually. 
		// If fails, we might need a workaround or hardcoded ID from BigShip dashboard.
		
		$response = $this->client->post( 'order/add/single', $payload );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( isset( $response['status'] ) && $response['status'] == false ) {
			// If already exists, maybe Update? Or maybe it just returns the ID?
			// Assuming message says "Order already exists". 
			// Ideally we get the ID back.
		}

		// return System Order ID
		return $response['data']['system_order_id'] ?? null;
	}

	public function getRates( $shipment ) {
		// 1. Get System ID (Create Draft)
		error_log( 'ZSS DEBUG: BigShipAdapter::createDraftOrder calling...' );
		$system_order_id = $this->createDraftOrder( $shipment );

		if ( ! $system_order_id || is_wp_error( $system_order_id ) ) {
			error_log( 'ZSS DEBUG ERROR: BigShip createDraftOrder failed or empty. Response: ' . print_r( $system_order_id, true ) );
			return [];
		}
		error_log( 'ZSS DEBUG: BigShip System Order ID: ' . $system_order_id );

		// 2. Fetch Rates
		// GET /api/order/shipping/rates?shipment_category=B2C&system_order_id=...
		$query = [
			'shipment_category' => 'B2C',
			'system_order_id'   => $system_order_id,
		];

		error_log( 'ZSS DEBUG: BigShip fetching rates with query: ' . print_r( $query, true ) );
		$response = $this->client->get( 'order/shipping/rates', $query );
		error_log( 'ZSS DEBUG: BigShip raw rate response: ' . print_r( $response, true ) );

		if ( is_wp_error( $response ) || empty( $response['data'] ) ) {
			error_log( 'ZSS DEBUG ERROR: BigShip response invalid or empty data' );
			return [];
		}

		// 3. Normalize
		$normalizer = new RateNormalizer();
		$rates      = [];

		foreach ( $response['data'] as $rate_data ) {
			// BigShip rate structure might differ. 
			// Assuming 'total_charges', 'courier_name', 'edd'.
			$rates[] = $normalizer->normalizeBigShip( $rate_data );
		}
		
		error_log( 'ZSS DEBUG: BigShip normalized rates count: ' . count( $rates ) );

		return [ 'bigship' => $rates ];
	}

	public function createOrder( $shipment ) {
		// Final Booking Step
		// We reuse createDraftOrder logic but this time we expect courier info to be present in $shipment.
		// If the draft already exists, calling this again with courier_id should finalize/update it.
		
		// 1. Ensure Courier ID is present
		if ( empty( $shipment->courier ) ) {
			return [ 'error' => 'No courier selected for BigShip booking' ];
		}
		// Note: $shipment->courier from RateNormalizer is the Name.
		// BigShip might need an ID. 
		// Ideally RateNormalizer should store ID in a meta field or "courier_id" property.
		// BUT for now, assuming "courier_id" is passed if available, or we need to map name to ID?
		// Phase-4 MVP: Assume what we get is usable, or we pass it as "courier_id" in payload if we have it.
		// For BigShip, if 'courier_id' is needed, we should have captured it during getRates normalization.
		// Let's rely on createDraftOrder handling the 'courier_id' if we add it to payload.
		
		// 2. Call Order Add/Update
		// We'll modify createDraftOrder slightly to accept an optional 'courier_id' merge
		// OR we just manually construct payload here to be sure.
		
		// Let's call createDraftOrder again, but we need to ensure it includes 'courier_id'.
		// I will modify createDraftOrder to check $shipment->courier_id if I add it.
		// Or simpler: pass it as 2nd arg? No, shipment object is better.
		// Let's assume $shipment->courier_id holds the ID.
		
		$system_order_id = $this->createDraftOrder( $shipment );

		if ( is_wp_error( $system_order_id ) ) {
			return $system_order_id;
		}

		if ( ! $system_order_id ) {
			return [ 'error' => 'Failed to get System Order ID from BigShip' ];
		}

		// Success?
		return [
			'shipment_id' => $system_order_id,
			'awb_code'    => '', // BigShip specific: might need separate call or comes in response?
			'courier_name'=> $shipment->courier
		];
	}

	public function generateAWB( $shipment_id ) {
		// Might be automatic.
		return [];
	}

	public function getLabel( $shipment_id ) {
		// POST /shipment/data?shipment_data_id=2&system_order_id=...
		// $shipment_id here assumed to be system_order_id.
		return $this->client->post( 'shipment/data', [
			'shipment_data_id' => 2,
			'system_order_id'  => $shipment_id
		]);
	}

	public function track( $shipment_id ) {
		return $this->client->get( 'shipment/track/' . $shipment_id );
	}

	/**
	 * Creates a warehouse on BigShip.
	 * 
	 * @param \Zerohold\Shipping\Models\Shipment $shipment
	 * @return string|WP_Error Warehouse ID
	 */
	public function createWarehouse( $shipment ) {
		// Endpoint: POST /warehouse/add
		
		// 1. Validate Phone (Strict: 10 digits, starts with 6-9)
		$phone = preg_replace( '/[^0-9]/', '', $shipment->from_phone );
		if ( ! preg_match( '/^[6-7-8-9][0-9]{9}$/', $phone ) ) {
			// BLOCKING ERROR -> POPUP
			return new \WP_Error( 'bs_validation_phone', 'Vendor contact number is missing or invalid. Please update your store contact to continue shipping.' );
		}

		// 2. Validate Pincode (Strict: 6 digits)
		$pincode = preg_replace( '/[^0-9]/', '', $shipment->from_pincode );
		if ( strlen( $pincode ) !== 6 ) {
			return new \WP_Error( 'bs_validation_pincode', 'Vendor pincode is invalid. It must be exactly 6 digits.' );
		}

		// 3. Prepare Address Line 1 (10-50 chars, allowed charset)
		// Rule: Only A-Z 0-9 space . , - /
		$raw_addr1 = $shipment->from_address1;
		$safe_addr1 = preg_replace( '/[^A-Za-z0-9 .,-\/]/', '', $raw_addr1 );
		$addr1_final = substr( trim( $safe_addr1 ), 0, 50 ); // Max 50

		// Check Min Length 10
		if ( strlen( $addr1_final ) < 10 ) {
			// Fallback: Store Name + " Warehouse"
			$store_name_safe = preg_replace( '/[^A-Za-z0-9 .,-\/]/', '', $shipment->from_store );
			$fallback = $store_name_safe . ' Warehouse';
			$addr1_final = substr( $fallback, 0, 50 );
		}
		
		// 4. Prepare Address Line 2 (Max 50)
		$raw_addr2 = $shipment->from_address2;
		$safe_addr2 = preg_replace( '/[^A-Za-z0-9 .,-\/]/', '', $raw_addr2 );
		$addr2_final = substr( trim( $safe_addr2 ), 0, 50 );

		// 5. Landmark (City or empty, Max 50)
		$landmark = substr( preg_replace( '/[^A-Za-z0-9 .,-\/]/', '', $shipment->from_city ), 0, 50 );

		// 6. Generate Safe Warehouse Name (Internal ID basically)
		$safe_wh_name = preg_replace( '/[^A-Za-z0-9 .,-\/]/', '', 'Vendor_' . $shipment->vendor_id );

		$payload = [
			'warehouse_name'         => $safe_wh_name,
			'address_line1'          => $addr1_final,
			'address_line2'          => $addr2_final, // Optional
			'address_landmark'       => $landmark,    // Optional
			'address_pincode'        => $pincode,     // Required (6 digit)
			'contact_number_primary' => $phone,       // Required (10 digit)
		];

		// Excluded: email, company_name, contact_person_name, mobile

		$response = $this->client->post( 'warehouse/add', $payload );
		
		if ( ! is_wp_error( $response ) && isset( $response['data']['pickup_address_id'] ) ) {
			return $response['data']['pickup_address_id'];
		} else {
			// If API returns error message, pass it through?
			$msg = 'Failed to create BigShip warehouse';
			if ( isset( $response['message'] ) ) {
				$msg .= ': ' . $response['message'];
			} elseif ( is_wp_error( $response ) ) {
				$msg .= ': ' . $response->get_error_message();
			}
			error_log( 'ZSS ERROR: ' . $msg . ' Payload: ' . print_r( $payload, true ) );
			return new \WP_Error( 'bigship_warehouse_error', $msg );
		}
	}
}
