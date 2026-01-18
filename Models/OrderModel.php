<?php

namespace Zerohold\Shipping\Models;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OrderModel {
	public $id;
	public $weight;
	public $dimensions;
	public $value;
	public $delivery_address;
	public $payment_type = 'prepaid';
}
