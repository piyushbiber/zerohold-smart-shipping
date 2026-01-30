<?php

namespace Zerohold\Shipping\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * DokanEarningsFix Class
 * 
 * Ensures that shipping fees from "ZeroHold Smart Shipping" are attributed to the Admin,
 * not the Vendor, keeping the vendor earnings and totals clean.
 */
class DokanEarningsFix {

	public function __construct() {
		add_filter( 'dokan_shipping_fee_recipient', [ $this, 'route_shipping_to_admin' ], 20, 2 );
		add_filter( 'woocommerce_get_formatted_order_total', [ $this, 'filter_vendor_order_total' ], 20, 4 );
		
		// 🛡️ PRIMARY: Intercept earnings with ULTRA-HIGH priority (Overrides Dokan's internal table values)
		add_filter( 'dokan_get_earning_from_order_table', [ $this, 'filter_vendor_earnings_raw' ], 999, 4 );
		add_filter( 'dokan_get_earning_by_order', [ $this, 'filter_vendor_earnings_object' ], 999, 3 );
	}

	/**
	 * Raw DB filter: Intercepts earnings when pulled from wp_dokan_orders.
	 */
	public function filter_vendor_earnings_raw( $earning, $order_id, $context, $raw ) {
		if ( $context !== 'seller' || $raw ) {
			return $earning;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return $earning;
		}

		return $this->process_earnings_logic( $earning, $order );
	}

	/**
	 * Object filter: Intercepts earnings during calculation flow.
	 */
	public function filter_vendor_earnings_object( $earning, $order, $context ) {
		if ( $context !== 'seller' || ! $order ) {
			return $earning;
		}

		return $this->process_earnings_logic( $earning, $order );
	}

	/**
	 * Core Logic: Ensures "Actual Price" (Total - Shipping) is ALWAYS used for ZeroHold orders.
	 * This prevents shipping charges from leaking into vendor earnings after rejection/refund.
	 */
	private function process_earnings_logic( $earning, $order ) {
		// Identify ZSS Orders
		$is_zss = false;
		foreach ( $order->get_shipping_methods() as $method ) {
			if ( strpos( $method->get_method_id(), 'zerohold_shipping' ) !== false ) {
				$is_zss = true;
				break;
			}
		}

		if ( $is_zss ) {
			$total    = (float) $order->get_total();
			$shipping = (float) $order->get_shipping_total();
			
			// For ZeroHold orders, the vendor ALWAYS receives only the item subtotal (minus commission if any).
			// Since ZeroHold shipping belongs to Admin, we must ensure it's never included in vendor's 'Earning' column.
			// Even if Dokan's internal calculation defaults to the full total (e.g., during rejection).
			return $total - $shipping;
		}

		return $earning;
	}

	/**
	 * Force shipping recipient to 'admin' for ZSS orders.
	 * 
	 * @param string $recipient Default recipient ('seller' or 'admin')
	 * @param int    $order_id  The WooCommerce order ID
	 * @return string
	 */
	public function route_shipping_to_admin( $recipient, $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return $recipient;
		}

		// Check if any shipping method in the order is 'zerohold_shipping'
		$shipping_methods = $order->get_shipping_methods();
		foreach ( $shipping_methods as $method ) {
			if ( strpos( $method->get_method_id(), 'zerohold_shipping' ) !== false ) {
				return 'admin';
			}
		}

		return $recipient;
	}

	/**
	 * Filter the formatted order total to exclude shipping for vendors in dashboard.
	 * 
	 * @param string   $formatted_total The formatted order total.
	 * @param WC_Order $order           The order object.
	 * @param string   $tax_display     Tax display type.
	 * @param bool     $display_refunded Whether to display refunded amount.
	 * @return string
	 */
	public function filter_vendor_order_total( $formatted_total, $order, $tax_display, $display_refunded ) {
		// Only run in Dokan Dashboard
		if ( ! function_exists( 'dokan_is_seller_dashboard' ) || ! dokan_is_seller_dashboard() ) {
			return $formatted_total;
		}

		// Check if order uses ZSS shipping
		$is_zss = false;
		foreach ( $order->get_shipping_methods() as $method ) {
			if ( strpos( $method->get_method_id(), 'zerohold_shipping' ) !== false ) {
				$is_zss = true;
				break;
			}
		}

		if ( ! $is_zss ) {
			return $formatted_total;
		}

		// Calculate total minus shipping
		$total    = (float) $order->get_total();
		$shipping = (float) $order->get_shipping_total();
		$new_total = $total - $shipping;

		// Return formatted price
		return wc_price( $new_total, [ 'currency' => $order->get_currency() ] );
	}
}
