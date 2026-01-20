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
	/**
	 * Creates (or Updates) a warehouse on BigShip.
	 * Implements Option D: Smart Refresh + Duplication Recovery.
	 * 
	 * @param \Zerohold\Shipping\Models\Shipment $shipment
	 * @return string|WP_Error Warehouse ID
	 */
	public function createWarehouse( $shipment ) {
		// 1. Strict Validation
		// Phone: 10 digit, starts 6/7/8/9
		$phone = preg_replace( '/[^0-9]/', '', $shipment->from_phone );
		if ( ! preg_match( '/^[6-7-8-9][0-9]{9}$/', $phone ) ) {
			return new \WP_Error( 'bs_validation_phone', 'Vendor contact number is missing or invalid (Must be 10 digits starting with 6-9).' );
		}

		// Pincode: Exactly 6 digits
		$pincode = preg_replace( '/[^0-9]/', '', $shipment->from_pincode );
		if ( strlen( $pincode ) !== 6 ) {
			return new \WP_Error( 'bs_validation_pincode', 'Vendor pincode is invalid. It must be exactly 6 digits.' );
		}

		// Address: 10-50 chars, safe chars only
		$raw_addr1 = $shipment->from_address1;
		$safe_addr1 = preg_replace( '/[^A-Za-z0-9 .,-\/]/', '', $raw_addr1 );
		$addr1_final = substr( trim( $safe_addr1 ), 0, 50 );

		if ( strlen( $addr1_final ) < 10 ) {
			$store_name_safe = preg_replace( '/[^A-Za-z0-9 .,-\/]/', '', $shipment->from_store );
			$fallback = $store_name_safe . ' Warehouse';
			$addr1_final = substr( $fallback, 0, 50 );
		}
		
		$raw_addr2 = $shipment->from_address2;
		$safe_addr2 = preg_replace( '/[^A-Za-z0-9 .,-\/]/', '', $raw_addr2 );
		$addr2_final = substr( trim( $safe_addr2 ), 0, 50 );

		$landmark = substr( preg_replace( '/[^A-Za-z0-9 .,-\/]/', '', $shipment->from_city ), 0, 50 );

		// Naming: Unique per vendor
		$safe_wh_name = 'ZH-WH-' . $shipment->vendor_id;

		// Payload (Cleaned)
		$payload = [
			'warehouse_name'         => $safe_wh_name,
			'address_line1'          => $addr1_final,
			'address_line2'          => $addr2_final,
			'address_landmark'       => $landmark,
			'address_pincode'        => $pincode,
			'contact_number_primary' => $phone,
		];
		// Removed: email, company_name, contact_person_name, mobile as requested

		// 2. REFRESH LOGIC (Update instead of Create)
		$existing_id = get_user_meta( $shipment->vendor_id, '_bs_warehouse_id', true );
		$status      = get_user_meta( $shipment->vendor_id, '_zh_warehouse_status', true );

		if ( $existing_id && $status === 'NEED_REFRESH' ) {
			error_log( "ZSS DEBUG: Attempting BigShip Warehouse UPDATE for ID: $existing_id" );
			
			// Using 'warehouse/edit' assume standard endpoint naming
			// Only update if we have the ID to pass (usually required in payload or param)
			// Adding ID to payload for update
			$update_payload = $payload;
			$update_payload['pickup_address_id'] = $existing_id; // Check specific API docs if key differs
			$update_payload['warehouse_id'] = $existing_id;      // Try both common keys

			$update_res = $this->client->post( 'warehouse/edit', $update_payload );
			error_log( 'ZSS DEBUG: BigShip Update Response: ' . print_r( $update_res, true ) );

			if ( ! is_wp_error( $update_res ) && ( isset( $update_res['data'] ) || isset( $update_res['status'] ) && $update_res['status'] ) ) {
				// Update Success
				return $existing_id;
			}
			// Fallback to Create if Update fails
			error_log( "ZSS DEBUG: Update failed, falling back to CREATE/RECOVER flow." );
		}

		// 3. CREATE LOGIC
		$response = $this->client->post( 'warehouse/add', $payload );
		error_log( 'ZSS DEBUG: BigShip Raw Create Response: ' . print_r( $response, true ) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// Success Pattern 1: { data: { pickup_address_id: ... } }
		if ( isset( $response['data']['pickup_address_id'] ) ) {
			return $response['data']['pickup_address_id'];
		}
		
		// Success Pattern 2: { data: { warehouse_id: ... } }
		if ( isset( $response['data']['warehouse_id'] ) ) {
			return $response['data']['warehouse_id'];
		}

		// 4. DUPLICATION RECOVERY
		$msg = $response['message'] ?? '';
		
		// If "already exist" or similar message
		if ( stripos( $msg, 'exist' ) !== false || ( isset( $response['status'] ) && $response['status'] == false ) ) {
			error_log( "ZSS DEBUG: Warehouse duplication detected ('$safe_wh_name'). Attempting recovery..." );
			
			$recovered_id = $this->fetchWarehouseIdByName( $safe_wh_name );
			
			if ( $recovered_id ) {
				error_log( "ZSS DEBUG: RECOVERED BigShip Warehouse ID: $recovered_id" );
				return $recovered_id;
			}
			$msg .= ' (Recovery by Name Failed)';
		}
		
		// Failure
		return new \WP_Error( 'bigship_warehouse_error', 'Failed to create/recover warehouse: ' . $msg );
	}

	/**
	 * Helper: Fetch warehouse ID by name using GetAll endpoint.
	 * 
	 * @param string $target_name
	 * @return string|false
	 */
	private function fetchWarehouseIdByName( $target_name ) {
		// Endpoint: GET /api/warehouse/get/all
		$response = $this->client->get( 'warehouse/get/all' );

		// Debug Log
		error_log( 'ZSS DEBUG: BigShip GetAll Warehouses Response Count: ' . ( isset($response['data']) ? count($response['data']) : 0 ) );

		if ( is_wp_error( $response ) || empty( $response['data'] ) ) {
			return false;
		}

		foreach ( $response['data'] as $wh ) {
			// Check Name Match
			$name = $wh['warehouse_name'] ?? $wh['name'] ?? '';
			
			if ( strtolower( trim( $name ) ) === strtolower( trim( $target_name ) ) ) {
				// Found it! Return strict ID.
				// BigShip usually returns 'pickup_address_id' or 'warehouse_id'
				$id = $wh['pickup_address_id'] ?? $wh['warehouse_id'] ?? $wh['id'] ?? false;
				
				if ( $id ) {
					// Log structure for confirmation
					error_log( "ZSS DEBUG: Match Found! ID: $id" );
					return $id;
				}
			}
		}

		return false;
	}
}
