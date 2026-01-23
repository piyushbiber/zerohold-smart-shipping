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
		
		// Map Items to "product_details" (inside box_details)
		$product_details = [];
		$total_items_qty = 0;

		$is_return = ! empty( $shipment->direction ) && $shipment->direction === 'return';

		if ( $is_return ) {
			// AGGREGATION FOR RETURNS: BigShip requires exact sum match.
			// Collapsing into 1 line avoids rounding/summation mismatches.
			
			// Build a descriptive name from original items
			$item_names = [];
			foreach ( $shipment->items as $item ) {
				$item_names[] = $item['name'];
			}
			$combined_name = implode( ', ', $item_names );
			$sanitized_name = $this->sanitizeBigShipString( $combined_name );
			
			// Truncate to safety limit (50 chars)
			$final_name = substr( $sanitized_name, 0, 50 );
			if ( empty( $final_name ) ) {
				$final_name = 'Return Order ' . $shipment->order_id;
			}

			$product_details[] = [
				'product_category'                => 'Others',
				'product_sub_category'            => 'General',
				'product_name'                    => $final_name,
				'product_quantity'                => 1,
				'each_product_invoice_amount'     => (float) $shipment->declared_value,
				'each_product_collectable_amount' => 0,
				'hsn'                             => '',
			];
			$total_items_qty = 1;
		} else {
			// Normal Forward Shipment mapping
			foreach ( $shipment->items as $item ) {
				$product_details[] = [
					'product_category'                => 'Others',
					'product_sub_category'            => 'General',
					'product_name'                    => $this->sanitizeBigShipString( $item['name'], 50 ),
					'product_quantity'                => (int) $item['qty'],
					'each_product_invoice_amount'     => (float) $shipment->declared_value / max( 1, $shipment->qty ),
					'each_product_collectable_amount' => 0,
					'hsn'                             => '',
				];
				$total_items_qty += (int) $item['qty'];
			}
		}

        // Logic for Payment Type
        // Docs: Only 'COD' and 'Prepaid' allowed.
        // Assuming Prepaid for now as per previous code.
        $payment_type = 'Prepaid'; 
        // If COD, we need to set collectable amounts.

        // Logic for Name Validation (Min 3 chars, Max 25 chars)
        $full_name_parts = explode( ' ', trim( $shipment->to_contact ) );
        $fname = array_shift( $full_name_parts );
        $lname = implode( ' ', $full_name_parts );

        // Fallback for empty last name
        if ( empty( $lname ) ) {
            $lname = 'Customer'; 
        }

        // Clean and Pad First Name
        $fname = preg_replace( '/[^A-Za-z .]/', '', $fname ); // Alpha + dot + space
        if ( strlen( $fname ) < 3 ) {
            $fname = str_pad( $fname, 3, '.' );
        }
        $fname = substr( $fname, 0, 25 );

        // Clean and Pad Last Name
        $lname = preg_replace( '/[^A-Za-z .]/', '', $lname );
        if ( strlen( $lname ) < 3 ) {
            $lname = str_pad( $lname, 3, '.' );
        }
        $lname = substr( $lname, 0, 25 );


        // Logic for Address Validation (Min 10 chars, Max 50 chars)
        $addr1 = preg_replace( '/[^A-Za-z0-9 .,-\/]/', '', $shipment->to_address1 );
        $addr1 = trim( $addr1 );
        if ( strlen( $addr1 ) < 10 ) {
            // Append generic text to meet minimum length
            $addr1 .= ' Address...'; 
        }
        $addr1 = substr( $addr1, 0, 50 );

        $addr2 = preg_replace( '/[^A-Za-z0-9 .,-\/]/', '', $shipment->to_address2 );
        $addr2 = substr( trim( $addr2 ), 0, 50 );


        // Construct Payload
		$payload = [
            'shipment_category' => 'b2c',
            
            'warehouse_detail' => [
                'pickup_location_id' => (int) $warehouse_id,
                'return_location_id' => (int) $warehouse_id,
            ],

            'consignee_detail' => [
                'first_name' => $fname,
                'last_name'  => $lname,
                'company_name' => $this->sanitizeBigShipString( $shipment->to_store, 40 ),
                'contact_number_primary' => $shipment->to_phone,
                'email_id'   => 'customer@example.com', // Optional per docs?
                'consignee_address' => [
                    'address_line1'    => $addr1,
                    'address_line2'    => $addr2,
                    'address_landmark' => '',
                    'pincode'          => $shipment->to_pincode,
                ]
            ],

            'order_detail' => [
                'invoice_date' => gmdate( 'Y-m-d\TH:i:s.000\Z' ), // UTC Format
                'invoice_id'   => (string) $shipment->order_id,
                'payment_type' => $payment_type,
                'shipment_invoice_amount'  => (float) $shipment->declared_value,
                'total_collectable_amount' => 0, // Prepaid = 0
                'ewaybill_number' => '',
                'document_detail' => [
                    'invoice_document_file'  => '',
                    'ewaybill_document_file' => ''
                ],
                
                // BOX DETAILS (One box for B2C per docs)
                'box_details' => [
                    [
                        'each_box_dead_weight' => (float) $shipment->weight, // Kg
                        'each_box_length'      => (int) $shipment->length,
                        'each_box_width'       => (int) $shipment->width,
                        'each_box_height'      => (int) $shipment->height,
                        'each_box_invoice_amount' => (float) $shipment->declared_value,
                        'each_box_collectable_amount' => 0,
                        'box_count'            => 1, // Mandatory 1 for B2C
                        'product_details'      => $product_details
                    ]
                ]
            ]
        ];
		
		$response = $this->client->post( 'order/add/single', $payload );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

        // Response Pattern: { data: "system_order_id is 1000252960", ... }
        // We need to extract the ID from the string string!
        // Documentation says: "data": "system_order_id is 1000252960"
        
        $data_str = $response['data'] ?? '';
        
        if ( preg_match( '/system_order_id is (\d+)/', $data_str, $matches ) ) {
            return $matches[1];
        }

        // Fallback: Check if success is false
		if ( isset( $response['success'] ) && $response['success'] == false ) {
			// If already exists, we might need to "Search" for it? OR Update?
            // Docs don't mention update. Assuming we just need the ID.
            // If "Order already exists", maybe we can parse ID from message?
            return new \WP_Error( 'bigship_order_error', $response['message'] ?? 'BigShip Order Creation Failed' );
		}

		return null; // Should have been caught by regex
	}

	public function getRates( $shipment ) {
		// 1. Get System ID (Create Draft)
		$system_order_id = $this->createDraftOrder( $shipment );

		if ( ! $system_order_id || is_wp_error( $system_order_id ) ) {
			return [];
		}
	
	// Store system_order_id for later use in createOrder
	update_post_meta( $shipment->order_id, '_zh_bigship_system_order_id', $system_order_id );

		// 2. Fetch Rates
		// GET /api/order/shipping/rates?shipment_category=B2C&system_order_id=...
		$query = [
			'shipment_category' => 'B2C',
			'system_order_id'   => $system_order_id,
		];

		$response = $this->client->get( 'order/shipping/rates', $query );

		if ( is_wp_error( $response ) || empty( $response['data'] ) ) {
			return [];
		}

		// 3. Normalize
		$normalizer = new RateNormalizer();
		$rates      = [];

		foreach ( $response['data'] as $rate_data ) {
			// Normalize
			$rate_obj = $normalizer->normalizeBigShip( $rate_data );
            
            // Skip invalid rates (null returned by normalizer)
            if ( is_null( $rate_obj ) ) {
                continue;
            }

            // Defensive: Ensure we have an Object (user reported Array issues)
            if ( is_array( $rate_obj ) ) {
                $rate_obj = new \Zerohold\Shipping\Models\RateQuote( $rate_obj );
            }
            
            $rates[] = $rate_obj;
		}
		
        // Extra check (optional but safe)
		// $rates = array_filter($rates);


		return $rates;  // Return rates DIRECTLY (VendorActions adds platform key)
	}

	public function createOrder( $shipment ) {
		// BigShip: Order is ALREADY created during getRates (createDraftOrder)
		// We just need to retrieve the system_order_id from order meta
		
		if ( empty( $shipment->courier ) ) {
			return [ 'error' => 'No courier selected for BigShip booking' ];
		}
		
		// Get the system_order_id that was created during getRates
		$system_order_id = get_post_meta( $shipment->order_id, '_zh_bigship_system_order_id', true );
		
		if ( ! $system_order_id ) {
			return [ 'error' => 'BigShip system_order_id not found. Please retry.' ];
		}
		
		
		return [
			'shipment_id'  => $system_order_id,
			'courier_name' => $shipment->courier
		];
	}

	public function manifestOrder( $system_order_id, $courier_id ) {
		// Step 3: Manifest the order with selected courier
		// REQUIRED before calling generateAWB
		
		$payload = [
			'system_order_id' => (int) $system_order_id,
			'courier_id'      => (int) $courier_id
		];
		
		
		$response = $this->client->post( 'order/manifest/single', $payload );
		
		
		if ( isset( $response['success'] ) && $response['success'] === true ) {
			return [ 'status' => 'success', 'message' => $response['message'] ?? 'Manifested' ];
		}
		
		return [ 'error' => $response['message'] ?? 'Manifest failed', 'raw' => $response ];
	}

	public function generateAWB( $shipment_id ) {
		// BigShip Step 1: Generate AWB (shipment_data_id=1)
        // Returns master_awb, courier_id, courier_name
        
		// BigShip uses POST with query params (not GET!)
		$params = [
			'shipment_data_id' => 1,
			'system_order_id'  => $shipment_id
		];
		
		$response = $this->client->post( 'shipment/data', [], $params );
        
        
        if ( ! empty( $response['data']['master_awb'] ) ) {
            return [
                'awb_code'     => $response['data']['master_awb'],
                'courier_name' => $response['data']['courier_name'] ?? '',
                'courier_id'   => $response['data']['courier_id'] ?? '',
                // Identifying success for VendorActions
                'status'       => 'success' 
            ];
        }
        
		return [ 'error' => 'AWB Generation Failed', 'raw' => $response ];
	}

	public function getLabel( $shipment_id ) {
		// BigShip Step 2: Generate Label (shipment_data_id=2)
        // Returns Base64 PDF in res_FileContent
        
		// BigShip uses POST with query params (not GET!)
		$params = [
			'shipment_data_id' => 2,
			'system_order_id'  => $shipment_id
		];
		
		$response = $this->client->post( 'shipment/data', [], $params );
        
        
        if ( isset( $response['data']['res_FileContent'] ) ) {
            $base64_content = $response['data']['res_FileContent'];
            $decoded_pdf    = base64_decode( $base64_content );
            
            if ( $decoded_pdf ) {
                $upload_dir = wp_upload_dir();
                $base_dir   = $upload_dir['basedir'] . '/zh-labels';
                $base_url   = $upload_dir['baseurl'] . '/zh-labels';
                
                if ( ! file_exists( $base_dir ) ) {
                    wp_mkdir_p( $base_dir );
                }
                
                // Filename: zh-label-{shipment_id}-{timestamp}.pdf
                $filename = 'zh-label-' . $shipment_id . '-' . time() . '.pdf';
                $file_path = $base_dir . '/' . $filename;
                
                if ( file_put_contents( $file_path, $decoded_pdf ) ) {
                    return [ 'label_url' => $base_url . '/' . $filename ];
                }
            }
        }
        
        return $response; 
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

		// Naming: Unique per vendor/retailer
		// We use the friendly from_store name for label presentation.
		// For Retailers, WarehouseManager sends a constructed friendly ID.
		$name_source = ! empty( $shipment->warehouse_internal_id ) ? $shipment->warehouse_internal_id : $shipment->from_store;
		$safe_wh_name = $this->sanitizeBigShipString( $name_source, 40 );
		
		if ( empty( $safe_wh_name ) ) {
			$safe_wh_name = 'ZH-WH-' . ( $shipment->vendor_id ?? $shipment->retailer_id );
		}

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
			
			// Using 'warehouse/edit' assume standard endpoint naming
			// Only update if we have the ID to pass (usually required in payload or param)
			// Adding ID to payload for update
			$update_payload = $payload;
			$update_payload['pickup_address_id'] = $existing_id; // Check specific API docs if key differs
			$update_payload['warehouse_id'] = $existing_id;      // Try both common keys

			$update_res = $this->client->post( 'warehouse/edit', $update_payload );

			if ( ! is_wp_error( $update_res ) && ( isset( $update_res['data'] ) || isset( $update_res['status'] ) && $update_res['status'] ) ) {
				// Update Success
				return $existing_id;
			}
			// Fallback to Create if Update fails
		}

		// 3. CREATE LOGIC
		$response = $this->client->post( 'warehouse/add', $payload );

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
			
			$recovered_id = $this->fetchWarehouseIdByName( $safe_wh_name );
			
			if ( $recovered_id ) {
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
		// Endpoint: GET /api/warehouse/get/list (Pagination supported)
		$page_index = 1;
		$page_size  = 200; // Max allowed per docs
		$max_pages  = 10;  // Safety break
		
		do {
			
			$response = $this->client->get( 'warehouse/get/list', [
				'page_index' => $page_index,
				'page_size'  => $page_size
			] );

			if ( is_wp_error( $response ) || empty( $response['data'] ) ) {
				return false;
			}

			// Validate Structure
			$data = $response['data'];
			$result_data = $data['result_data'] ?? [];
			$total_count = $data['result_count'] ?? 0;

			// Iterate Current Page
			foreach ( $result_data as $wh ) {
				$name = $wh['warehouse_name'] ?? '';
				if ( strtolower( trim( $name ) ) === strtolower( trim( $target_name ) ) ) {
					$id = $wh['warehouse_id'] ?? false;
					if ( $id ) {
						return $id;
					}
				}
			}

			// Check if we need next page
			$fetched_so_far = $page_index * $page_size;
			if ( $fetched_so_far >= $total_count ) {
				break; // Done
			}

			$page_index++;

		} while ( $page_index <= $max_pages );

		return false;
	}

	/**
	 * Sanitizes a string for BigShip API (Only Alpha, Num, Space, -, /)
	 * 
	 * @param string $string
	 * @param int|null $limit Truncate limit
	 * @return string
	 */
	private function sanitizeBigShipString( $string, $limit = null ) {
		// Replace common problematic symbols with spaces/empty
		$string = str_replace( [ '(', ')', '[', ']', '{', '}', '#', '*', '+', '=', '!', '?' ], ' ', $string );
		
		// Remove everything except A-Za-z0-9, space, hyphen, forward slash
		$string = preg_replace( '/[^A-Za-z0-9 \-\/]/', '', $string );
		
		// Collapse multiple spaces
		$string = preg_replace( '/\s+/', ' ', trim( $string ) );
		
		if ( $limit ) {
			return substr( $string, 0, $limit );
		}
		return $string;
	}
}
