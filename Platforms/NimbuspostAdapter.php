<?php

namespace Zerohold\Shipping\Platforms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NimbuspostAdapter implements PlatformInterface {
	public function getRates( $order, $vendor ) { return []; }
	public function createOrder( $order, $vendor, $selected_courier ) { return null; }
	public function generateAWB( $shipment_id ) { return null; }
	public function getLabel( $shipment_id ) { return null; }
	public function track( $shipment_id ) { return null; }
}
