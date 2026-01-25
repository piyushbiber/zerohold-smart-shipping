<?php

namespace Zerohold\Shipping\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dokan Statement Integration Class
 * 
 * Injects shipping charges from order meta into Dokan vendor statement.
 * 
 * @since 1.0.0
 */
class DokanStatementIntegration {

	public function __construct() {
		// Hook into Dokan Statement filter
		add_filter( 'dokan_report_statement_entries', [ $this, 'inject_shipping_entries' ], 10, 3 );
	}

	/**
	 * Inject shipping entries into Dokan Statement.
	 * 
	 * @param array  $entries Dokan's original statement entries
	 * @param array  $args    Query arguments (vendor_id, start_date, end_date)
	 * @param string $status  Order status filter
	 * 
	 * @return array Modified entries with shipping charges injected
	 */
	public function inject_shipping_entries( $entries, $args, $status ) {
		global $wpdb;

		// Extract context from first entry or use current user
		$vendor_id  = $this->get_vendor_id( $entries );
		$start_date = $this->get_start_date( $entries );
		$end_date   = $this->get_end_date( $entries );

		error_log( "ZSS: Dokan Statement Hook Fired - Vendor: {$vendor_id}, Range: {$start_date} to {$end_date}" );

		// STEP 1: Keep Dokan entries as-is for now
		$filtered_entries = $entries; // Use all entries

		error_log( sprintf( 
			"ZSS: Processing Dokan entries - Total: %d", 
			count( $filtered_entries ) 
		) );

		// STEP 2: Query orders with shipping charges from meta
		$shipping_orders = $this->query_shipping_orders( $vendor_id, $start_date, $end_date );

		error_log( "ZSS: Found " . count($shipping_orders) . " shipping charges" );
		
		// Debug: Log the shipping orders
		if ( ! empty( $shipping_orders ) ) {
			foreach ( $shipping_orders as $idx => $order ) {
				error_log( sprintf(
					"ZSS: Shipping Entry #%d - Order: %d, Cost: %s, Date: %s",
					$idx,
					$order->order_id,
					$order->shipping_cost,
					$order->shipping_date
				) );
			}
		} else {
			error_log( "ZSS: No shipping charges found. Query params - Vendor: {$vendor_id}, Start: {$start_date}, End: {$end_date}" );
		}

		// STEP 3: Transform order data to Dokan format
		$transformed_entries = $this->transform_orders_to_dokan( $shipping_orders, $vendor_id );

		// STEP 4: Merge arrays
		$merged_entries = array_merge( $filtered_entries, $transformed_entries );

		// STEP 5: Sort by balance_date
		usort( $merged_entries, function( $a, $b ) {
			return strtotime( $a['balance_date'] ) - strtotime( $b['balance_date'] );
		});

		// STEP 6: Recalculate running balance
		$final_entries = $this->recalculate_balance( $merged_entries );

		error_log( sprintf( 
			"ZSS: Final statement - Total entries: %d (Dokan: %d, Shipping: %d)", 
			count( $final_entries ),
			count( $filtered_entries ),
			count( $transformed_entries )
		) );

		return $final_entries;
	}

	/**
	 * Extract vendor ID from context.
	 */
	private function get_vendor_id( $entries ) {
		if ( ! empty( $entries ) && isset( $entries[0]['vendor_id'] ) ) {
			return (int) $entries[0]['vendor_id'];
		}
		return dokan_get_current_user_id();
	}

	/**
	 * Extract start date from entries or use current month start.
	 */
	private function get_start_date( $entries ) {
		return dokan_current_datetime()->modify( 'first day of this month' )->format( 'Y-m-d' );
	}

	/**
	 * Extract end date from entries or use current date.
	 */
	private function get_end_date( $entries ) {
		return dokan_current_datetime()->format( 'Y-m-d' );
	}

	/**
	 * Query orders with shipping charges from order meta.
	 * 
	 * @return array Array of shipping charge data from orders
	 */
	private function query_shipping_orders( $vendor_id, $start_date, $end_date ) {
		global $wpdb;

		// Query orders that have shipping cost meta
		$sql = "
			SELECT 
				p.ID as order_id,
				pm1.meta_value as shipping_cost,
				pm2.meta_value as shipping_date
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} pm1 ON p.ID = pm1.post_id AND pm1.meta_key = '_zh_shipping_cost'
			INNER JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_zh_shipping_date'
			INNER JOIN {$wpdb->postmeta} pm3 ON p.ID = pm3.post_id AND pm3.meta_key = '_dokan_vendor_id'
			WHERE p.post_type = 'shop_order'
			AND pm3.meta_value = %d
			AND DATE(pm2.meta_value) >= %s
			AND DATE(pm2.meta_value) <= %s
			ORDER BY pm2.meta_value ASC
		";

		$prepared_sql = $wpdb->prepare( $sql, $vendor_id, $start_date, $end_date );
		error_log( "ZSS: Executing shipping meta query: " . $prepared_sql );

		$results = $wpdb->get_results( $prepared_sql );
		
		if ( $wpdb->last_error ) {
			error_log( "ZSS: SQL Error: " . $wpdb->last_error );
		}

		return $results;
	}

	/**
	 * Transform order shipping data to Dokan entry format.
	 * 
	 * Shipping is a COST to vendor, so it goes in CREDIT column (vendor pays).
	 */
	private function transform_orders_to_dokan( $shipping_orders, $vendor_id ) {
		$transformed = [];

		foreach ( $shipping_orders as $row ) {
			$order_id = (int) $row->order_id;
			$shipping_cost = (float) $row->shipping_cost;
			$shipping_date = $row->shipping_date;

			// Shipping charge = vendor PAYS = CREDIT column (deducted from balance)
			$debit  = 0;
			$credit = $shipping_cost;

			// Build Dokan entry
			$transformed[] = [
				'id'           => 'ZH-SHIP-' . $order_id, // Unique ID
				'vendor_id'    => $vendor_id,
				'trn_id'       => $order_id,
				'trn_type'     => 'zh_shipping',
				'perticulars'  => sprintf( 'Shipping Charge for Order #%d', $order_id ),
				'debit'        => $debit,
				'credit'       => $credit,
				'status'       => '',
				'trn_date'     => $shipping_date,
				'balance_date' => $shipping_date,
				'balance'      => 0, // Will be recalculated
				'trn_title'    => __( 'Shipping Charge', 'zerohold-shipping' ),
				'url'          => $this->get_order_url( $order_id ),
			];
		}

		return $transformed;
	}

	/**
	 * Get order URL for Dokan navigation.
	 */
	private function get_order_url( $order_id ) {
		if ( empty( $order_id ) || ! function_exists( 'dokan_get_navigation_url' ) ) {
			return '';
		}

		return wp_nonce_url(
			add_query_arg( [ 'order_id' => $order_id ], dokan_get_navigation_url( 'orders' ) ),
			'dokan_view_order'
		);
	}

	/**
	 * Recalculate running balance for all entries.
	 * 
	 * CRITICAL: Must run after merging to ensure accurate balance column.
	 */
	private function recalculate_balance( $entries ) {
		$opening_balance = 0;
		
		// Extract opening balance from first entry if it exists
		if ( ! empty( $entries ) && isset( $entries[0]['balance'] ) && $entries[0]['trn_type'] === 'opening_balance' ) {
			$opening_balance = (float) $entries[0]['balance'];
		}

		$running_balance = $opening_balance;

		foreach ( $entries as &$entry ) {
			if ( $entry['trn_type'] === 'opening_balance' ) {
				continue; // Keep original balance
			}

			$running_balance += ( (float) $entry['debit'] - (float) $entry['credit'] );
			$entry['balance'] = $running_balance;
		}

		return $entries;
	}
}
