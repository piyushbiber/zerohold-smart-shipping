<?php

namespace Zerohold\Shipping\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Zerohold\Shipping\Models\Shipment;

class OrderMapper {

	public function map( $order ) {

		$shipment = new Shipment();

		// ============= Retailer (Delivery) =============
		$shipment->to_first_name = $order->get_shipping_first_name();
		$shipment->to_last_name  = $order->get_shipping_last_name();
		$shipment->to_contact  = trim( $shipment->to_first_name . ' ' . $shipment->to_last_name );
		$shipment->to_store    = $order->get_meta( '_shipping_store_name' ); // optional
		$shipment->to_phone    = $order->get_billing_phone() ?: $order->get_shipping_phone();
		$shipment->to_address1 = $order->get_shipping_address_1();
		$shipment->to_address2 = $order->get_shipping_address_2();
		$shipment->to_city     = $order->get_shipping_city();
		$shipment->to_state    = $order->get_shipping_state();
		$shipment->to_pincode  = $order->get_shipping_postcode();
		$shipment->to_country  = $order->get_shipping_country();

		// ============= Vendor (Pickup) =============
		$vendor_id = 0;
		foreach ( $order->get_items() as $item ) {
			$product_id = $item->get_product_id();
			$vendor_id  = \get_post_field( 'post_author', $product_id );
			if ( $vendor_id ) {
				break;
			}
		}

		$vendor = function_exists( 'dokan' ) ? \dokan()->vendor->get( $vendor_id ) : null;
		$store  = $vendor ? $vendor->get_shop_info() : [];

		$shipment->from_store    = $store['store_name'] ?? '';
		$shipment->from_contact  = $store['store_name'] ?? '';
		$shipment->from_phone    = $store['phone'] ?? '';
		$shipment->from_address1 = $store['address']['street_1'] ?? '';
		$shipment->from_address2 = $store['address']['street_2'] ?? '';
		$shipment->from_city     = $store['address']['city'] ?? '';
		$shipment->from_state    = $store['address']['state'] ?? '';
		$shipment->from_pincode  = $store['address']['zip'] ?? '';
		$shipment->from_state    = $store['address']['state'] ?? '';
		$shipment->from_pincode  = $store['address']['zip'] ?? '';
		$shipment->from_country  = $store['address']['country'] ?? 'IN';
		$shipment->vendor_id     = $vendor_id;

		// ============= Order-Level Info =============
		$shipment->order_id       = $order->get_id();
		$shipment->order_date     = $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d H:i' ) : current_time( 'Y-m-d H:i' );
		$shipment->declared_value = $order->get_total();
		$shipment->payment_mode   = 'Prepaid';

		// ============= Weight & Qty =============
		$total_weight = 0;
		$total_qty    = 0;
		$items_data   = [];
		$max_length   = 0;
		$max_width    = 0;
		$max_height   = 0;

		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			$qty     = (int) $item->get_quantity();
			$weight  = (float) $product->get_weight();

			$total_qty    += $qty;
			$total_weight += ( $weight > 0 ? $weight : 0.5 ) * $qty; // fallback per item 0.5 KG

			// Max dimensions from products as a simple heuristic for Phase-1
			$max_length = max( $max_length, (float) $product->get_length() );
			$max_width  = max( $max_width, (float) $product->get_width() );
			$max_height = max( $max_height, (float) $product->get_height() );

			$items_data[] = [
				'name' => $item->get_name(),
				'sku'  => $product->get_sku(),
				'qty'  => $qty,
			];
		}

		// Fallback dimensions if none provided
		$shipment->length = $max_length > 0 ? $max_length : 10;
		$shipment->width  = $max_width > 0 ? $max_width : 10;
		$shipment->height = $max_height > 0 ? $max_height : 10;

		$shipment->weight = $total_weight > 0 ? $total_weight : 0.5; // final fallback
		$shipment->qty    = $total_qty;
		$shipment->items  = $items_data;

		// Volumetric Weight calculation: (L * W * H) / 5000
		$shipment->volumetric_weight = ( $shipment->length * $shipment->width * $shipment->height ) / 5000;

		return $shipment;
	}
}
