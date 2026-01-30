<?php

namespace Zerohold\Shipping\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Zerohold\Shipping\Core\StatelessShipping;
use Zerohold\Shipping\Models\Shipment;

/**
 * ZSS_Shipping_Method Class
 * 
 * Provides live quotes at checkout via StatelessShipping logic.
 */
class ZSS_Shipping_Method extends \WC_Shipping_Method {

	/**
	 * Constructor
	 */
	public function __construct( $instance_id = 0 ) {
		$this->id                 = 'zerohold_shipping';
		$this->instance_id        = absint( $instance_id );
		$this->method_title       = __( 'ZeroHold Smart Shipping', 'zss' );
		$this->method_description = __( 'Real-time shipping rates from Shiprocket and BigShip.', 'zss' );
		$this->supports           = array(
			'shipping-zones',
			'instance-settings',
			'instance-settings-modal',
		);

		$this->init();
	}

	/**
	 * Initialize settings
	 */
	public function init() {
		$this->init_form_fields();
		$this->init_settings();

		$this->title = $this->get_option( 'title', 'Smart Shipping' );

		add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	/**
	 * Form fields for admin display
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'title' => array(
				'title'       => __( 'Method Title', 'zss' ),
				'type'        => 'text',
				'description' => __( 'This controls the title which the user sees during checkout.', 'zss' ),
				'default'     => __( 'Smart Shipping', 'zss' ),
				'desc_tip'    => true,
			),
		);
	}

	/**
	 * Calculate shipping rates
	 * 
	 * @param array $package
	 */
	public function calculate_shipping( $package = array() ) {
		// 1. Get Destination Info
		$dest_pincode = $package['destination']['postcode'] ?? '';
		$dest_city    = $package['destination']['city'] ?? '';
		$dest_state   = $package['destination']['state'] ?? '';
		$dest_country = $package['destination']['country'] ?? 'IN';

		if ( empty( $dest_pincode ) ) {
			return;
		}

		// 2. Identify Vendor and Calculate Weight/Dimensions
		$vendor_id    = 0;
		$total_weight = 0;
		$max_length   = 0;
		$max_width    = 0;
		$max_height   = 0;
		$items_data   = [];

		foreach ( $package['contents'] as $item_id => $values ) {
			$product = $values['data'];
			$qty     = $values['quantity'];

			// Get Vendor ID (Author of product)
			if ( ! $vendor_id ) {
				$vendor_id = get_post_field( 'post_author', $product->get_id() );
			}

			// Weight logic
			$weight = (float) $product->get_weight();
			$total_weight += ( $weight > 0 ? $weight : 0.5 ) * $qty;

			// Dimensions logic
			$max_length = max( $max_length, (float) $product->get_length() );
			$max_width  = max( $max_width, (float) $product->get_width() );
			$max_height = max( $max_height, (float) $product->get_height() );

			$items_data[] = [
				'name' => $product->get_name(),
				'sku'  => $product->get_sku(),
				'qty'  => $qty,
			];
		}

		if ( ! $vendor_id ) {
			return; // No vendor found
		}

		// 3. Get Vendor (Pickup) Info
		$vendor = function_exists( 'dokan' ) ? dokan()->vendor->get( $vendor_id ) : null;
		$store  = $vendor ? $vendor->get_shop_info() : [];

		if ( empty( $store['address']['zip'] ) ) {
			return; // Vendor lacks pincode
		}

		// 4. Construct Shipment Object
		$shipment = new Shipment();
		$shipment->from_pincode = $store['address']['zip'];
		$shipment->to_pincode   = $dest_pincode;
		$shipment->to_city      = $dest_city;
		$shipment->to_state     = $dest_state;
		$shipment->to_country   = $dest_country;
		
		$shipment->weight       = $total_weight > 0 ? $total_weight : 0.5;
		$shipment->length       = $max_length > 0 ? $max_length : 10;
		$shipment->width        = $max_width > 0 ? $max_width : 10;
		$shipment->height       = $max_height > 0 ? $max_height : 10;
		
		$shipment->declared_value = $package['contents_cost'] ?? 0;
		$shipment->payment_mode   = 'Prepaid'; // Checkout quotes are typically for calculated costs
		$shipment->items          = $items_data;
		$shipment->vendor_id      = $vendor_id;

		// 5. Fetch Rate via Stateless Logic
		$stateless = new StatelessShipping();
		$best_rate = $stateless->getBestPriorityRate( $shipment );

		if ( $best_rate && $best_rate->base > 0 ) {
			// Apply Retailer (Buyer) Share and Cap logic
			$buyer_id    = get_current_user_id();
			$final_cost  = \Zerohold\Shipping\Core\PriceEngine::calculate_share_and_cap( $best_rate->base, 'retailer', $buyer_id );

			$rate = array(
				'id'      => $this->id . '_' . $best_rate->platform,
				'label'   => $this->title, 
				'cost'    => $final_cost,
				'package' => $package,
				'meta_data' => array(
					'is_zss' => 'yes',
					'platform' => $best_rate->platform
				)
			);

			$this->add_rate( $rate );
		}
	}
}
