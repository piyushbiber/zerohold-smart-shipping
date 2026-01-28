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

		// STEP 1: Keep Dokan entries and prepare for modification
		$filtered_entries = $entries; 

		// Track existing refunds to avoid double counting
		$existing_refund_ids = [];
		foreach ( $filtered_entries as $entry ) {
			if ( $entry['trn_type'] === 'dokan_refund' || strpos( $entry['trn_type'], 'refund' ) !== false ) {
				$existing_refund_ids[] = (int) $entry['trn_id'];
			}
		}

		// STEP 2: Query orders with shipping charges from meta
		$shipping_orders = $this->query_shipping_orders( $vendor_id, $start_date, $end_date );

		error_log( "ZSS: Found " . count($shipping_orders) . " shipping charges" );

		// STEP 2.5: "GROSS UP" Logic (Prevent Double Deduction)
		// Since we now "Mirror" forward shipping into the WC Order (via Line Item), Dokan natively deducts it from the Order Earnings row.
		// To show a separate "Shipping" row WITHOUT double-deducting, we must add the cost BACK to the Order Row first.
		foreach ( $filtered_entries as &$dokan_entry ) {
			if ( $dokan_entry['trn_type'] === 'dokan_orders' ) {
				$order_id = (int) $dokan_entry['trn_id'];
				
				// Find matching FORWARD shipping charge for this order
				foreach ( $shipping_orders as $ship_entry ) {
					if ( (int) $ship_entry->order_id === $order_id && $ship_entry->shipping_type === 'forward' ) {
						// Add the cost back to the Debit (Earning) column
						$cost = (float) $ship_entry->shipping_cost;
						$dokan_entry['debit'] += $cost; // Gross up
						error_log( "ZSS: Grossed up Order #{$order_id} by ₹{$cost} to allow explicit shipping row." );
					}
				}
			}
		}
		unset( $dokan_entry ); // Break reference
		
		// STEP 3: Handle REJECTION PENALTIES (Following Shipping Pattern)
		// Query rejection penalties from order meta (like shipping charges)
		$penalty_orders = $this->query_rejection_penalties( $vendor_id, $start_date, $end_date );
		$penalty_entries = [];

		if ( ! empty( $penalty_orders ) ) {
			foreach ( $penalty_orders as $penalty ) {
				$order_id = (int) $penalty->order_id;
				$penalty_amount = (float) $penalty->penalty_amount;
				$total_deduction = (float) $penalty->total_deduction;
				$penalty_date = $penalty->penalty_date;
				$order_amount = $total_deduction - $penalty_amount; // The 100% amount

				// ROW A: Order Reversal (-100%)
				$penalty_entries[] = [
					'id'           => 'ZH-REV-' . $order_id,
					'vendor_id'    => $vendor_id,
					'trn_id'       => $order_id,
					'trn_type'     => 'zh_rejection_reversal',
					'perticulars'  => sprintf( __( 'Order #%d Reversal (Refunded to customer)', 'zerohold-shipping' ), $order_id ),
					'debit'        => 0,
					'credit'       => $order_amount,
					'status'       => 'approved',
					'trn_date'     => $penalty_date,
					'balance_date' => $penalty_date,
					'balance'      => 0,
					'trn_title'    => __( 'Order Reversal', 'zerohold-shipping' ),
					'url'          => $this->get_order_url( $order_id ),
				];

				// ROW B: Rejection Penalty (-25%)
				$penalty_entries[] = [
					'id'           => 'ZH-FEE-' . $order_id,
					'vendor_id'    => $vendor_id,
					'trn_id'       => $order_id,
					'trn_type'     => 'zh_rejection_penalty',
					'perticulars'  => sprintf( __( 'Rejection Penalty for Order #%d (25%% Fee)', 'zerohold-shipping' ), $order_id ),
					'debit'        => 0,
					'credit'       => $penalty_amount,
					'status'       => 'approved',
					'trn_date'     => $penalty_date,
					'balance_date' => $penalty_date,
					'balance'      => 0,
					'trn_title'    => __( 'Rejection Penalty', 'zerohold-shipping' ),
					'url'          => $this->get_order_url( $order_id ),
				];
				
				error_log( "ZSS: Injected split rejection entries for Order #{$order_id} (Rev: ₹{$order_amount}, Fee: ₹{$penalty_amount})" );
			}
		}

		// STEP 4: Transform shipping data to Dokan format
		$transformed_shipping = $this->transform_orders_to_dokan( $shipping_orders, $vendor_id );

		// STEP 5: Merge all entries (Original + Shipping + Penalties)
		$merged_entries = array_merge( $filtered_entries, $transformed_shipping, $penalty_entries );

		// STEP 6: Sort by balance_date
		usort( $merged_entries, function( $a, $b ) {
			return strtotime( $a['balance_date'] ) - strtotime( $b['balance_date'] );
		});

		// STEP 7: Recalculate running balance
		$final_entries = $this->recalculate_balance( $merged_entries );

		error_log( sprintf( 
			"ZSS: Final statement - Total: %d (Dokan: %d, Shipping: %d, Penalties: %d)", 
			count( $final_entries ),
			count( $filtered_entries ),
			count( $transformed_shipping ),
			count( $penalty_entries )
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
		if ( isset( $_GET['start_date'] ) && ! empty( $_GET['start_date'] ) ) {
			return sanitize_text_field( $_GET['start_date'] );
		}
		return dokan_current_datetime()->modify( 'first day of this month' )->format( 'Y-m-d' );
	}

	/**
	 * Extract end date from entries or use current date.
	 */
	private function get_end_date( $entries ) {
		if ( isset( $_GET['end_date'] ) && ! empty( $_GET['end_date'] ) ) {
			return sanitize_text_field( $_GET['end_date'] );
		}
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

		// 3. Fetch Shipping Refunds (Buyer Cancellations)
		$sql_refund = "
			SELECT 
				pm1.post_id as order_id,
				pm1.meta_value as shipping_cost,
				pm2.meta_value as shipping_date,
				'refund' as shipping_type
			FROM {$wpdb->postmeta} pm1
			INNER JOIN {$wpdb->postmeta} pm2 ON pm1.post_id = pm2.post_id AND pm2.meta_key = '_zh_shipping_refund_date'
			WHERE pm1.meta_key = '_zh_shipping_refund_amount'
			AND DATE(pm2.meta_value) >= %s
			AND DATE(pm2.meta_value) <= %s
		";

		// Combine with UNION
		$sql = "($sql_forward) UNION ($sql_return) UNION ($sql_refund) ORDER BY shipping_date ASC";
		
		$all_shipping_entries = $wpdb->get_results( $wpdb->prepare( $sql, $start_date, $end_date, $start_date, $end_date, $start_date, $end_date ) );
		
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

	private function transform_orders_to_dokan( $shipping_orders, $vendor_id ) {
		$transformed = [];

		foreach ( $shipping_orders as $row ) {
			$order_id = (int) $row->order_id;
			$shipping_cost = (float) $row->shipping_cost;
			$shipping_date = $row->shipping_date;
			$is_return = ( $row->shipping_type === 'return' );
			$is_refund = ( $row->shipping_type === 'refund' );

			// Title and Description
			if ( $is_refund ) {
				$title = __( 'Shipping Refund', 'zerohold-shipping' );
				$desc  = sprintf( 'Shipping Refund for Order #%d (Buyer Cancellation)', $order_id );
			} elseif ( $is_return ) {
				$title = __( 'Return Shipping', 'zerohold-shipping' );
				$desc  = sprintf( 'Return Shipping for Order #%d', $order_id );
			} else {
				$title = __( 'Forward Shipping', 'zerohold-shipping' );
				$desc  = sprintf( 'Forward Shipping for Order #%d', $order_id );
			}

			// Shipping charge = vendor PAYS = CREDIT column (deducted from balance)
			// Shipping refund = vendor RECEIVES = DEBIT column (added to balance)
			if ( $is_refund ) {
				$debit  = $shipping_cost;
				$credit = 0;
			} else {
				$debit  = 0;
				$credit = $shipping_cost;
			}

			// Build Dokan entry
			$transformed[] = [
				'id'           => ($is_refund ? 'ZH-REFUND-' : ($is_return ? 'ZH-RET-' : 'ZH-SHIP-')) . $order_id, // Unique ID
				'vendor_id'    => $vendor_id,
				'trn_id'       => $order_id,
				'trn_type'     => $is_refund ? 'zh_shipping_refund' : 'zh_shipping',
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
		if ( ! empty( $entries ) && isset( $entries[0]['trn_type'] ) && $entries[0]['trn_type'] === 'opening_balance' ) {
			$opening_balance = (float) $entries[0]['balance'];
			
			// SAFETY: If this is a new vendor (opening balance poisoned by global filter),
			// and we are in a report context, we might need to reset it to 0.
			// However, a more robust context check in the filter itself is better.
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
		// Use the same date range and vendor from context
		$start_date = $this->get_start_date( [] );
		$end_date   = $this->get_end_date( [] );
		
		$vendor_id = 0;
		if ( ! empty( $results ) ) {
			foreach ( $results as $res ) {
				// Dokan results can be objects or arrays depending on version/context
				$res_vendor_id = is_object( $res ) ? ( $res->vendor_id ?? 0 ) : ( $res['vendor_id'] ?? 0 );
				if ( $res_vendor_id ) {
					$vendor_id = (int) $res_vendor_id;
					break;
				}
			}
		}
		
		if ( ! $vendor_id ) {
			$vendor_id = dokan_get_current_user_id();
		}

		error_log( "ZSS: Syncing summary cards for Vendor #{$vendor_id} ($start_date to $end_date)" );

		// 1. SHIPPING CHARGES (Separate Costs and Refunds)
		$shipping_entries = $this->query_shipping_orders( $vendor_id, $start_date, $end_date );
		$total_shipping_cost = 0;
		$total_shipping_refund = 0;
		
		foreach ( $shipping_entries as $entry ) {
			if ( $entry->shipping_type === 'refund' ) {
				$total_shipping_refund += (float) $entry->shipping_cost;
			} else {
				$total_shipping_cost += (float) $entry->shipping_cost;
			}
		}

		// 2. REJECTION PENALTIES
		$penalty_entries = $this->query_rejection_penalties( $vendor_id, $start_date, $end_date );
		$total_penalties = 0;
		foreach ( $penalty_entries as $penalty ) {
			$total_penalties += (float) $penalty->total_deduction;
		}

		// Apply Adjustments
		// Refunds increase DEBIT (Earnings)
		if ( $total_shipping_refund > 0 ) {
			error_log( "ZSS: Adjusting summary cards. Adding Refunds to Debit: ₹{$total_shipping_refund}" );
			$summary_data['total_debit'] += $total_shipping_refund;
		}

		// Costs increase CREDIT (Deductions)
		$total_deductions = $total_shipping_cost + $total_penalties;
		if ( $total_deductions > 0 ) {
			error_log( "ZSS: Adjusting summary cards. Adding Deductions to Credit: ₹{$total_deductions}" );
			$summary_data['total_credit'] += $total_deductions;
		}

		// 3. RECONCILE BALANCE Math
		// Force Balance = Opening + Debit - Credit
		// This prevents double-deduction from the global filter poisoning the starting math.
		$opening = (float)$summary_data['opening_balance'];
		$debit   = (float)$summary_data['total_debit'];
		$credit  = (float)$summary_data['total_credit'];
		
		$summary_data['balance'] = $opening + $debit - $credit;
		
		error_log( "ZSS: Summary reconciled. Opening: {$opening}, Debit: {$debit}, Credit: {$credit}, Final: " . $summary_data['balance'] );

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
		// CRITICAL: Prevent "Poisoning" the Statement Report Opening Balance.
		// If we are handling any request related to analytics, statements, or reports, we return raw balance.
		// This allows the report to calculate its own date-aware ledger correctly.
		$is_report_context = ( 
			( isset( $_GET['path'] ) && strpos( $_GET['path'], 'analytics' ) !== false ) || 
			( isset( $_GET['page'] ) && strpos( $_GET['page'], 'report' ) !== false ) ||
			( isset( $_SERVER['REQUEST_URI'] ) && preg_match( '/(reports|analytics|statement|dokan\/v1\/report)/i', $_SERVER['REQUEST_URI'] ) )
		);

		if ( $is_report_context ) {
			return $balance;
		}

		// Avoid infinite loops if this is called recursively
		static $is_calculating = false;
		if ( $is_calculating ) {
			return $balance;
		}
		$is_calculating = true;

		// We need to query ALL shipping charges for this vendor, not just for a date range,
		// because 'balance' is a lifetime value in Dokan.
		$start_date = '2000-01-01';
		$end_date   = '2100-12-31';

		// 1. SHIPPING
		$shipping_entries = $this->query_shipping_orders( $vendor_id, $start_date, $end_date );
		$total_shipping_cost = 0;
		foreach ( $shipping_entries as $entry ) {
			if ( $entry->shipping_type === 'refund' ) {
				$total_shipping_cost -= (float) $entry->shipping_cost;
			} else {
				$total_shipping_cost += (float) $entry->shipping_cost;
			}
		}

		// 2. REJECTION PENALTIES
		$penalty_entries = $this->query_rejection_penalties( $vendor_id, $start_date, $end_date );
		$total_penalties = 0;
		foreach ( $penalty_entries as $penalty ) {
			$total_penalties += (float) $penalty->total_deduction;
		}

		$is_calculating = false;
		$total_deductions = $total_shipping_cost + $total_penalties;

		if ( $total_deductions > 0 ) {
			error_log( "ZSS: Deducting ₹{$total_deductions} (Shipping: {$total_shipping_cost}, Penalties: {$total_penalties}) from global balance for Vendor #{$vendor_id}" );
			return $balance - $total_deductions;
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
			
			if ( $entry->shipping_type === 'refund' ) {
				$daily_costs[ $date ] -= $cost;
				$total_custom_shipping -= $cost;
			} else {
				$daily_costs[ $date ] += $cost;
				$total_custom_shipping += $cost;
			}
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

	/**
	 * Query rejection penalties from order meta (following shipping pattern).
	 * 
	 * @return array Objects with order_id, penalty_amount, total_deduction, penalty_date
	 */
	private function query_rejection_penalties( $vendor_id, $start_date, $end_date ) {
		global $wpdb;

		error_log( "ZSS: Querying rejection penalties for Vendor #{$vendor_id} between {$start_date} and {$end_date}" );

		// Query orders with rejection penalty meta (similar to shipping query)
		$sql = "
			SELECT 
				pm1.post_id as order_id,
				pm1.meta_value as penalty_amount,
				pm2.meta_value as total_deduction,
				pm3.meta_value as penalty_date
			FROM {$wpdb->postmeta} pm1
			INNER JOIN {$wpdb->postmeta} pm2 ON pm1.post_id = pm2.post_id AND pm2.meta_key = '_zh_rejection_total'
			INNER JOIN {$wpdb->postmeta} pm3 ON pm1.post_id = pm3.post_id AND pm3.meta_key = '_zh_rejection_date'
			WHERE pm1.meta_key = '_zh_rejection_penalty'
			AND DATE(pm3.meta_value) >= %s
			AND DATE(pm3.meta_value) <= %s
		";

		$prepared_sql = $wpdb->prepare( $sql, $start_date, $end_date );
		$all_penalty_entries = $wpdb->get_results( $prepared_sql );

		if ( empty( $all_penalty_entries ) ) {
			error_log( "ZSS: No rejection penalty meta found in database. Checked SQL: " . $prepared_sql );
			return [];
		}

		error_log( "ZSS: Raw DB search found " . count( $all_penalty_entries ) . " penalty entries. Now filtering for Vendor #{$vendor_id}..." );

		// Filter by Vendor in PHP (same as shipping)
		$filtered_results = [];
		foreach ( $all_penalty_entries as $entry ) {
			$order_id = (int) $entry->order_id;
			$order_vendor_id = function_exists( 'dokan_get_seller_id_by_order' ) ? (int) dokan_get_seller_id_by_order( $order_id ) : (int) get_post_meta( $order_id, '_dokan_vendor_id', true );

			if ( $order_vendor_id === (int) $vendor_id ) {
				$filtered_results[] = $entry;
				error_log( "ZSS: SUCCESS Match! Order #{$order_id} belongs to Vendor #{$vendor_id}" );
			} else {
				error_log( "ZSS: MISMATCH! Order #{$order_id} belongs to Vendor #{$order_vendor_id} (Expected #{$vendor_id})" );
			}
		}

		error_log( "ZSS: Final filtered penalty count: " . count( $filtered_results ) );

		return $filtered_results;
	}
}
