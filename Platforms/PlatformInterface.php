<?php

namespace Zerohold\Shipping\Platforms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface PlatformInterface {
	/**
	 * @param \Zerohold\Shipping\Models\Shipment $shipment
	 * @return \Zerohold\Shipping\Models\RateQuote[]
	 */
	public function getRates( $shipment );

	/**
	 * @param \Zerohold\Shipping\Models\Shipment $shipment
	 * @return array{shipment_id: string, awb_code?: string}
	 */
	public function createOrder( $shipment );

	public function generateAWB( $shipment_id );
	public function getLabel( $shipment_id );
	public function track( $shipment_id );
	public function estimateRates( $origin_pincode, $destination_pincodes, $slab );
	public function getWalletBalance();
	public function isBalanceError( $response );

	/**
	 * Cancel a shipment/order on the platform.
	 * 
	 * @param int $order_id WooCommerce Order ID
	 * @return array API Response
	 */
	public function cancelOrder( $order_id );
}
