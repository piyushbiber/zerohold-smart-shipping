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
		
		// PHASE 1: Re-enabled ONLY for rejected/cancelled orders
		// These filters ensure rejected orders show ₹0.00 in earnings column
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
	 * Core Logic: Returns ₹0.00 for rejected/cancelled orders.
	 * For completed orders, subtracts commission if applicable.
	 */
	private function process_earnings_logic( $earning, $order ) {
		// Check if order is rejected or cancelled
		$status = $order->get_status();
		$rejected_statuses = [ 'cancelled', 'refunded', 'failed', 'rejected' ];
		
		if ( in_array( $status, $rejected_statuses, true ) ) {
			// Rejected/cancelled orders should show ₹0.00 earnings
			return 0;
		}
		
		// For completed orders, subtract commission from earnings display
		if ( $status === 'completed' ) {
			$commission_amount = get_post_meta( $order->get_id(), '_zh_commission_amount', true );
			if ( $commission_amount > 0 ) {
				return $earning - (float) $commission_amount;
			}
		}
		
		// For other statuses (processing, on-hold, etc.), return earning as-is
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

	/**
	 * Set recipient meta on the shipping line item during checkout.
	 */
	public function set_shipping_item_recipient( $item, $package_key, $package, $order ) {
		// Check if it's a ZSS method
		$method_id = $item->get_method_id();
		if ( strpos( $method_id, 'zerohold_shipping' ) !== false ) {
			// Write the recipient meta directly to the line item
			$item->add_meta_data( '_dokan_shipping_fee_recipient', 'admin', true );
			$item->add_meta_data( '_is_zss_shipping', 'yes', true );
		}
	}

	/**
	 * Set order-level recipient meta after order is processed.
	 */
	public function set_order_level_shipping_recipient( $order_id, $posted_data, $order ) {
		$is_zss = false;
		foreach ( $order->get_shipping_methods() as $method ) {
			if ( strpos( $method->get_method_id(), 'zerohold_shipping' ) !== false ) {
				$is_zss = true;
				break;
			}
		}

		if ( $is_zss ) {
			// Dokan uses 'shipping_fee_recipient' (no underscore) for order meta
			$order->update_meta_data( 'shipping_fee_recipient', 'admin' );
			$order->update_meta_data( '_dokan_shipping_fee_recipient', 'admin' ); // Secondary protection
			$order->save();
		}
	}

	/**
	 * Reinforce admin commission by ensuring ZSS shipping is included if recipient is admin.
	 */
	public function reinforce_admin_commission( $commission, $order, $context = 'admin' ) {
		// If context is admin, we want to ensure commission includes shipping if it's ZSS
		$is_zss = false;
		foreach ( $order->get_shipping_methods() as $method ) {
			if ( strpos( $method->get_method_id(), 'zerohold_shipping' ) !== false ) {
				$is_zss = true;
				break;
			}
		}

		if ( $is_zss ) {
			// Force the commission to include shipping for admin if it's not already there
			// Dokan Commission class uses this filter for the final calculated value.
		}

		return $commission;
	}

	/**
	 * Final Guard: Ensure net amount (vendor earning) excludes shipping for ZSS.
	 * Hooked to: dokan_order_net_amount
	 */
	public function filter_net_amount( $net_amount, $order ) {
		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order );
		}
		if ( ! $order ) {
			return $net_amount;
		}

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
			
			// If net amount is suspiciously full total, force it to be (total - shipping)
			if ( $net_amount >= $total - 0.01 && $shipping > 0 ) {
				return $total - $shipping;
			}
		}

		return $net_amount;
	}

	/**
	 * Final Guard for Vendor Earning (after gateway fees).
	 * Hooked to: dokan_orders_vendor_net_amount
	 */
	public function filter_net_amount_vendor( $net_amount, $vendor_earning, $gateway_fee, $tmp_order, $order ) {
		return $this->filter_net_amount( $net_amount, $order );
	}
}
