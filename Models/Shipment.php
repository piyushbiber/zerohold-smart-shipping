<?php

namespace Zerohold\Shipping\Models;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Shipment {
	public $platform;
	public $courier;
	public $awb;
	public $label_url;
	public $tracking_url;
	public $price;
	public $status;

	// ============= Retailer (Delivery) =============
	public $to_contact;
	public $to_store;
	public $to_phone;
	public $to_address1;
	public $to_address2;
	public $to_city;
	public $to_state;
	public $to_pincode;
	public $to_country;

	// ============= Vendor (Pickup) =============
	public $from_store;
	public $from_contact;
	public $from_phone;
	public $from_address1;
	public $from_address2;
	public $from_city;
	public $from_state;
	public $from_pincode;
	public $from_country;

	// ============= Order-Level Info =============
	public $order_id;
	public $declared_value;
	public $payment_mode;

	// ============= Weight & Qty =============
	public $weight;
	public $qty;
	public $length;
	public $width;
	public $height;
	public $volumetric_weight;

	// ============= Future Proofing =============
	public $items = [];
}
