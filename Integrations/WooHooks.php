<?php

namespace Zerohold\Shipping\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Zerohold\Shipping\Core\Orchestrator;
use Zerohold\Shipping\Core\OrderMapper;

/**
 * WooHooks Class
 * 
 * Handles WooCommerce event synchronization.
 */
class WooHooks {

	protected $orchestrator;
	protected $mapper;

	public function __construct( Orchestrator $orchestrator, OrderMapper $mapper ) {
		$this->orchestrator = $orchestrator;
		$this->mapper       = $mapper;

		add_action( 'woocommerce_order_status_processing', [ $this, 'onOrderProcessing' ], 10, 1 );
	}

	public function onOrderProcessing( $order_id ) {
		// Step 1: Load Woo order (no ZSS logic yet)
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return;
		}

		// Step 2: Map order -> Shipment (logic comes later)
		$shipment = $this->mapper->map( $order );

		// Step 3: Send to Orchestrator (logic added later)
		$this->orchestrator->mapOrder( $shipment );
	}
}
