<?php

namespace Zerohold\Shipping\Models;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RateQuote {
	public $base;
	public $zone;
	public $edd;
	public $courier;
	public $platform; // 'shiprocket' or 'nimbus'
	public $vendor_share;
	public $retailer_share;

	public function __construct( $data = [] ) {
		foreach ( $data as $key => $value ) {
			if ( property_exists( $this, $key ) ) {
				$this->$key = $value;
			}
		}
	}
}

