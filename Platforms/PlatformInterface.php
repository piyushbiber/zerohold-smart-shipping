<?php

namespace Zerohold\Shipping\Platforms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface PlatformInterface {
	public function getRates( $shipment );
	public function createOrder( $shipment );
	public function generateAWB( $shipment_id );
	public function getLabel( $shipment_id );
	public function track( $shipment_id );
}
