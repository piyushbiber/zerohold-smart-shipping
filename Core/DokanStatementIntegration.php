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
		
		// Hook into Dokan Summary filter to sync top cards
		add_filter( 'dokan_report_statement_summary', [ $this, 'sync_summary_cards' ], 10, 3 );

		// Hook into Global Balance filter to sync withdrawal page and other areas
		add_filter( 'dokan_get_seller_balance', [ $this, 'deduct_shipping_from_global_balance' ], 10, 2 );

		// Hook into Global Earnings filter to ensure dashboard math consistency
		add_filter( 'dokan_get_seller_earnings', [ $this, 'deduct_shipping_from_global_balance' ], 10, 2 );

		// Hook into Dokan Pro Revenue Report filters (Legacy)
		// add_filter( 'dokan_admin_report_data', [ $this, 'sync_revenue_summary_card' ], 10, 1 );
		
		// PRIMARY: Hook into WooCommerce Analytics Revenue Report REST API
		// Disabled in favor of Transaction-Time Mirroring (creating real shipping line items)
		// add_filter( 'woocommerce_rest_prepare_report_revenue_stats', [ $this, 'inject_shipping_into_analytics' ], 10, 3 );
		// add_filter( 'dokan_rest_prepare_report_revenue_stats', [ $this, 'inject_shipping_into_analytics' ], 10, 3 );

		// FALLBACK: Hook into query args to force SQL modification if REST fails
		// add_filter( 'woocommerce_analytics_report_query_args', [ $this, 'force_revenue_report_adjustments' ], 10, 2 );
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

		error_log( "ZSS: Starting robust statement query for Vendor #{$vendor_id}" );

		// 1. Fetch Forward Shipping Charges
		$sql_forward = "
			SELECT 
				pm1.post_id as order_id,
				pm1.meta_value as shipping_cost,
				pm2.meta_value as shipping_date,
				'forward' as shipping_type
			FROM {$wpdb->postmeta} pm1
			INNER JOIN {$wpdb->postmeta} pm2 ON pm1.post_id = pm2.post_id AND pm2.meta_key = '_zh_shipping_date'
			WHERE pm1.meta_key = '_zh_shipping_cost'
			AND DATE(pm2.meta_value) >= %s
			AND DATE(pm2.meta_value) <= %s
		";

		// 2. Fetch Return Shipping Charges
		$sql_return = "
			SELECT 
				pm1.post_id as order_id,
				pm1.meta_value as shipping_cost,
				pm2.meta_value as shipping_date,
				'return' as shipping_type
			FROM {$wpdb->postmeta} pm1
			INNER JOIN {$wpdb->postmeta} pm2 ON pm1.post_id = pm2.post_id AND pm2.meta_key = '_zh_return_shipping_date'
			WHERE pm1.meta_key = '_zh_return_shipping_cost'
			AND DATE(pm2.meta_value) >= %s
			AND DATE(pm2.meta_value) <= %s
		";

		// Combine with UNION
		$sql = "($sql_forward) UNION ($sql_return) ORDER BY shipping_date ASC";
		
		$all_shipping_entries = $wpdb->get_results( $wpdb->prepare( $sql, $start_date, $end_date, $start_date, $end_date ) );
		
		if ( empty( $all_shipping_entries ) ) {
			error_log( "ZSS: No shipping cost meta found in database for these dates." );
			return [];
		}

		error_log( "ZSS: Found " . count( $all_shipping_entries ) . " total shipping entries in DB for this date range. Filtering by vendor..." );

		// Filter by Vendor in PHP
		$filtered_results = [];
		foreach ( $all_shipping_entries as $entry ) {
			$order_id = (int) $entry->order_id;
			$order_vendor_id = function_exists( 'dokan_get_seller_id_by_order' ) ? (int) dokan_get_seller_id_by_order( $order_id ) : (int) get_post_meta( $order_id, '_dokan_vendor_id', true );

			if ( $order_vendor_id === (int) $vendor_id ) {
				$filtered_results[] = $entry;
				error_log( "ZSS: Match! Order #{$order_id} ({$entry->shipping_type}) belongs to Vendor #{$vendor_id}" );
			} else {
				error_log( "ZSS: Skipping! Order #{$order_id} ({$entry->shipping_type}) belongs to Vendor #{$order_vendor_id}" );
			}
		}

		error_log( "ZSS: Final count for statement: " . count( $filtered_results ) );

		return $filtered_results;
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
			$is_return = ( $row->shipping_type === 'return' );

			// Title and Description
			$title = $is_return ? __( 'Return Shipping', 'zerohold-shipping' ) : __( 'Forward Shipping', 'zerohold-shipping' );
			$desc  = $is_return ? sprintf( 'Return Shipping for Order #%d', $order_id ) : sprintf( 'Forward Shipping for Order #%d', $order_id );

			// Shipping charge = vendor PAYS = CREDIT column (deducted from balance)
			$debit  = 0;
			$credit = $shipping_cost;

			// Build Dokan entry
			$transformed[] = [
				'id'           => ($is_return ? 'ZH-RET-' : 'ZH-SHIP-') . $order_id, // Unique ID
				'vendor_id'    => $vendor_id,
				'trn_id'       => $order_id,
				'trn_type'     => 'zh_shipping',
				'perticulars'  => $desc,
				'debit'        => $debit,
				'credit'       => $credit,
				'status'       => '',
				'trn_date'     => $shipping_date,
				'balance_date' => $shipping_date,
				'balance'      => 0, // Will be recalculated
				'trn_title'    => $title,
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

	/**
	 * Sync top summary cards (Total Debit, Total Credit, Balance) with shipping charges.
	 * 
	 * @param array  $summary_data    Original summary data
	 * @param object $results         SQL raw results
	 * @param float  $opening_balance Calculated opening balance
	 * 
	 * @return array Modified summary data
	 */
	public function sync_summary_cards( $summary_data, $results, $opening_balance ) {
		// Use the same date range and vendor from context if possible
		// Since we don't have $args here directly, we try to extract from current request
		$start_date = isset( $_GET['start_date'] ) ? sanitize_text_field( $_GET['start_date'] ) : $this->get_start_date( [] );
		$end_date   = isset( $_GET['end_date'] ) ? sanitize_text_field( $_GET['end_date'] ) : $this->get_end_date( [] );
		$vendor_id  = dokan_get_current_user_id();

		error_log( "ZSS: Syncing summary cards for Vendor #{$vendor_id} ($start_date to $end_date)" );

		// Query our charges
		$shipping_entries = $this->query_shipping_orders( $vendor_id, $start_date, $end_date );
		
		$total_shipping_cost = 0;
		foreach ( $shipping_entries as $entry ) {
			$total_shipping_cost += (float) $entry->shipping_cost;
		}

		if ( $total_shipping_cost > 0 ) {
			error_log( "ZSS: Adjusting summary cards. Total shipping: ₹{$total_shipping_cost}" );
			
			// Total Debit remains SAME (shipping is not an earning)
			// Total Credit INCREASES (shipping is a deduction)
			$summary_data['total_credit'] += $total_shipping_cost;
			
			// Balance matches individual entries recalculation: 
			// Balance = Opening + Total Debit - Total Credit
			$summary_data['balance'] -= $total_shipping_cost;
		}

		return $summary_data;
	}

	/**
	 * Deduct shipping charges from the global vendor balance.
	 * This ensures consistency across the Withdrawal page, Dashboard cards, and validation logic.
	 * 
	 * @param float $balance    The original balance calculated by Dokan
	 * @param int   $vendor_id  The vendor ID
	 * 
	 * @return float Adjusted balance
	 */
	public function deduct_shipping_from_global_balance( $balance, $vendor_id ) {
		// Avoid infinite loops if this is called recursively (though unlikely with this filter)
		static $is_calculating = false;
		if ( $is_calculating ) {
			return $balance;
		}
		$is_calculating = true;

		// We need to query ALL shipping charges for this vendor, not just for a date range,
		// because 'balance' is a lifetime value in Dokan.
		// However, query_shipping_orders requires dates. We'll use a very wide range.
		$start_date = '2000-01-01';
		$end_date   = '2100-12-31';

		$shipping_entries = $this->query_shipping_orders( $vendor_id, $start_date, $end_date );
		
		$total_shipping_cost = 0;
		foreach ( $shipping_entries as $entry ) {
			$total_shipping_cost += (float) $entry->shipping_cost;
		}

		$is_calculating = false;

		if ( $total_shipping_cost > 0 ) {
			error_log( "ZSS: Deducting ₹{$total_shipping_cost} from global balance for Vendor #{$vendor_id}" );
			return $balance - $total_shipping_cost;
		}

		return $balance;
	}

	/**
	 * Inject custom shipping expenses into WooCommerce Analytics Revenue Report.
	 * 
	 * @param WP_REST_Response $response The response object.
	 * @param object           $report   The report object.
	 * @param WP_REST_Request  $request  The request object.
	 * 
	 * @return WP_REST_Response Modified response.
	 */
	public function inject_shipping_into_analytics( $response, $report, $request ) {
		$data = $response->get_data();
		
		// Extract Vendor ID
		// WC Analytics usually passes vendor info via context or we rely on current user
		$vendor_id = dokan_get_current_user_id();
		if ( ! $vendor_id ) {
			return $response;
		}

		// Extract dates from request parameters
		$after  = $request->get_param( 'after' );
		$before = $request->get_param( 'before' );

		// Default to this month if missing (though WC usually provides them)
		if ( empty( $after ) ) {
			$after = dokan_current_datetime()->modify( 'first day of this month' )->format( 'c' );
		}
		if ( empty( $before ) ) {
			$before = dokan_current_datetime()->format( 'c' );
		}

		// Format dates for our DB query
		$start_date = date( 'Y-m-d', strtotime( $after ) );
		$end_date   = date( 'Y-m-d', strtotime( $before ) );

		// Query our custom shipping charges
		$shipping_entries = $this->query_shipping_orders( $vendor_id, $start_date, $end_date );

		if ( empty( $shipping_entries ) ) {
			return $response;
		}

		// Group costs by date (Y-m-d) for interval matching
		$daily_costs = [];
		$total_custom_shipping = 0;

		foreach ( $shipping_entries as $entry ) {
			$date = date( 'Y-m-d', strtotime( $entry->shipping_date ) );
			$cost = (float) $entry->shipping_cost;
			
			if ( ! isset( $daily_costs[ $date ] ) ) {
				$daily_costs[ $date ] = 0;
			}
			$daily_costs[ $date ] += $cost;
			$total_custom_shipping += $cost;
		}

		// 1. UPDATE TOTALS
		if ( isset( $data['totals'] ) ) {
			// Add to shipping total
			$data['totals']['shipping'] = (float) ($data['totals']['shipping'] ?? 0) + $total_custom_shipping;
			
			// Deduct from net_revenue (Net Sales = Gross - Shipping - Tax)
			// Note: WC Analytics might call it 'net_revenue' or 'net_sales' depending on version
			if ( isset( $data['totals']['net_revenue'] ) ) {
				$data['totals']['net_revenue'] = (float) $data['totals']['net_revenue'] - $total_custom_shipping;
			}
		}

		// 2. UPDATE INTERVALS (Chart/Graph Data)
		if ( isset( $data['intervals'] ) && is_array( $data['intervals'] ) ) {
			foreach ( $data['intervals'] as &$interval ) {
				// Interval 'date_start' is usually "2025-01-26 00:00:00"
				$interval_date = date( 'Y-m-d', strtotime( $interval['date_start'] ) );

				if ( isset( $daily_costs[ $interval_date ] ) ) {
					$amount = $daily_costs[ $interval_date ];

					// Update subarray totals
					if ( isset( $interval['subtotals'] ) ) {
						$interval['subtotals']['shipping'] = (float) ($interval['subtotals']['shipping'] ?? 0) + $amount;
						
						if ( isset( $interval['subtotals']['net_revenue'] ) ) {
							$interval['subtotals']['net_revenue'] = (float) $interval['subtotals']['net_revenue'] - $amount;
						}
					}
				}
			}
		}

		$response->set_data( $data );
		return $response;
	}
}
