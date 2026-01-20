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
	public $freight;
	public $cod;
	public $courier_id;

	public function __construct( $data = [] ) {
        // ZSS DEBUG: logging constructor input
        // error_log( "ZSS DEBUG: RateQuote Consturct Input: " . print_r($data, true) );
        
        if ( is_object( $data ) ) {
            $data = (array) $data;
        }
		$this->base           = isset( $data['base'] ) ? $data['base'] : 0;
		$this->freight        = isset( $data['freight'] ) ? $data['freight'] : 0;
		$this->cod            = isset( $data['cod'] ) ? $data['cod'] : 0;
		$this->zone           = isset( $data['zone'] ) ? $data['zone'] : '';
		$this->edd            = isset( $data['edd'] ) ? $data['edd'] : '';
		$this->courier        = isset( $data['courier'] ) ? $data['courier'] : '';
		$this->platform       = isset( $data['platform'] ) ? $data['platform'] : '';
		$this->courier_id     = isset( $data['courier_id'] ) ? $data['courier_id'] : ''; // New field
		$this->vendor_share   = isset( $data['vendor_share'] ) ? $data['vendor_share'] : 0;
		$this->retailer_share = isset( $data['retailer_share'] ) ? $data['retailer_share'] : 0;
        
        // ZSS DEBUG: logging result
        if ( $this->base > 0 || !empty($this->platform) ) {
             // error_log( "ZSS DEBUG: RateQuote Created -> Base: {$this->base}, Platform: {$this->platform}" );
        } else {
             error_log( "ZSS DEBUG WARNING: RateQuote Created EMPTY -> Input was: " . print_r($data, true) );
        }
	}
}
