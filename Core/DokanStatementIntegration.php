<?php

namespace Zerohold\Shipping\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dokan Statement Integration Class
 * 
 * Hooks into Dokan Pro's Statement Report to inject wallet-based shipping transactions.
 * Replaces Dokan's volatile status-based order entries with immutable wallet ledger.
 * 
 * @since 1.0.0
 */
class DokanStatementIntegration {

	public function __construct() {
		// Hook into Dokan Statement filter
		add_filter( 'dokan_report_statement_entries', [ $this, 'inject_wallet_entries' ], 10, 3 );
	}

	/**
	 * Inject wallet-based entries into Dokan Statement.
	 * 
	 * @param array  $entries Dokan's original statement entries
	 * @param array  $args    Query arguments (vendor_id, start_date, end_date)
	 * @param string $status  Order status filter
	 * 
	 * @return array Modified entries with wallet transactions injected
	 */
	public function inject_wallet_entries( $entries, $args, $status ) {
		global $wpdb;

		// Extract context from first entry or use current user
		$vendor_id  = $this->get_vendor_id( $entries );
		$start_date = $this->get_start_date( $entries );
		$end_date   = $this->get_end_date( $entries );

		error_log( "ZSS: Dokan Statement Hook Fired - Vendor: {$vendor_id}, Range: {$start_date} to {$end_date}" );

		// STEP 1: Keep Dokan entries as-is for now (Phase 6 will replace order entries with wallet)
		// For now, just ADD shipping entries alongside existing ones
		$filtered_entries = $entries; // Use all entries

		error_log( sprintf( 
			"ZSS: Processing Dokan entries - Total: %d", 
			count( $filtered_entries ) 
		) );

		// STEP 2: Query wallet transactions for shipping
		$wallet_entries = $this->query_wallet_transactions( $vendor_id, $start_date, $end_date );

		error_log( "ZSS: Found " . count($wallet_entries) . " wallet transactions" );
		
		// Debug: Log the actual wallet entries
		if ( ! empty( $wallet_entries ) ) {
			foreach ( $wallet_entries as $idx => $entry ) {
				error_log( sprintf(
					"ZSS: Wallet Entry #%d - ID: %d, Type: %s, Amount: %s, Date: %s",
					$idx,
					$entry->transaction_id,
					$entry->type,
					$entry->amount,
					$entry->date
				) );
			}
		} else {
			error_log( "ZSS: No wallet entries found. Query params - Vendor: {$vendor_id}, Start: {$start_date}, End: {$end_date}" );
		}

		// STEP 3: Transform wallet rows to Dokan format
		$transformed_entries = $this->transform_wallet_to_dokan( $wallet_entries );

		// STEP 4: Merge arrays
		$merged_entries = array_merge( $filtered_entries, $transformed_entries );

		// STEP 5: Sort by balance_date
		usort( $merged_entries, function( $a, $b ) {
			return strtotime( $a['balance_date'] ) - strtotime( $b['balance_date'] );
		});

		// STEP 6: Recalculate running balance
		$final_entries = $this->recalculate_balance( $merged_entries );

		error_log( sprintf( 
			"ZSS: Final statement - Total entries: %d (Dokan: %d, Wallet: %d)", 
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
		// Try to get from Dokan's statement data
		// Fallback to first day of current month
		return dokan_current_datetime()->modify( 'first day of this month' )->format( 'Y-m-d' );
	}

	/**
	 * Extract end date from entries or use current date.
	 */
	private function get_end_date( $entries ) {
		return dokan_current_datetime()->format( 'Y-m-d' );
	}

	/**
	 * Filter out Dokan's volatile order entries (they disappear on status change).
	 * 
	 * Keep: withdrawals, gateway fees, refunds
	 * Remove: dokan_orders (we'll replace with wallet-based entries)
	 */
	private function filter_volatile_entries( $entries ) {
		return array_filter( $entries, function( $entry ) {
			return $entry['trn_type'] !== 'dokan_orders';
		});
	}

	/**
	 * Query wallet transactions for shipping entries.
	 * 
	 * @return array Raw wallet transaction rows
	 */
	private function query_wallet_transactions( $vendor_id, $start_date, $end_date ) {
		global $wpdb;

		$table_transactions = $wpdb->prefix . 'woo_wallet_transactions';
		$table_meta         = $wpdb->prefix . 'woo_wallet_transaction_meta';

		$sql = "
			SELECT t.*, m.meta_value as is_shipping 
			FROM $table_transactions t
			INNER JOIN $table_meta m ON t.transaction_id = m.transaction_id
			WHERE t.user_id = %d
			AND m.meta_key = 'zh_shipping'
			AND m.meta_value = 'yes'
			AND DATE(t.date) >= %s
			AND DATE(t.date) <= %s
			ORDER BY t.date ASC
		";

		$prepared_sql = $wpdb->prepare( $sql, $vendor_id, $start_date, $end_date );
		error_log( "ZSS: Executing wallet query: " . $prepared_sql );

		$results = $wpdb->get_results( $prepared_sql );
		
		error_log( "ZSS: Raw query result count: " . count( $results ) );
		if ( $wpdb->last_error ) {
			error_log( "ZSS: SQL Error: " . $wpdb->last_error );
		}

		return $results;
	}

	/**
	 * Transform wallet transactions to Dokan entry format.
	 * 
	 * CRITICAL: Reverses wallet debit/credit to match Dokan's accounting semantics:
	 * - Wallet 'debit' (vendor pays) → Dokan 'credit'
	 * - Wallet 'credit' (vendor earns) → Dokan 'debit'
	 */
	private function transform_wallet_to_dokan( $wallet_rows ) {
		$transformed = [];

		foreach ( $wallet_rows as $row ) {
			$meta = $this->get_transaction_meta( $row->transaction_id );
			
			// Reverse wallet debit/credit for Dokan semantics
			if ( $row->type === 'debit' ) {
				// Wallet debit = vendor PAYS shipping
				// Dokan: vendor pays = CREDIT column
				$debit  = 0;
				$credit = (float) $row->amount;
			} else {
				// Wallet credit = vendor EARNS (shipping refund)
				// Dokan: vendor earns = DEBIT column
				$debit  = (float) $row->amount;
				$credit = 0;
			}

			// Determine title
			$trn_title = 'Shipping Charge';
			if ( isset( $meta['transaction_type'] ) && $meta['transaction_type'] === 'shipping_refund' ) {
				$trn_title = 'Shipping Refund';
			}

			// Build Dokan entry
			$transformed[] = [
				'id'           => 'W-' . $row->transaction_id, // Prefix to avoid ID conflicts
				'vendor_id'    => (int) $row->user_id,
				'trn_id'       => (int) ( $meta['order_id'] ?? 0 ),
				'trn_type'     => 'zh_shipping',
				'perticulars'  => $row->details,
				'debit'        => $debit,
				'credit'       => $credit,
				'status'       => '',
				'trn_date'     => $row->date,
				'balance_date' => $row->date,
				'balance'      => 0, // Will be recalculated
				'trn_title'    => $trn_title,
				'url'          => $this->get_order_url( $meta['order_id'] ?? 0 ),
			];
		}

		return $transformed;
	}

	/**
	 * Get transaction meta from wallet.
	 */
	private function get_transaction_meta( $transaction_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'woo_wallet_transaction_meta';
		$results = $wpdb->get_results( $wpdb->prepare( 
			"SELECT meta_key, meta_value FROM $table WHERE transaction_id = %d", 
			$transaction_id 
		) );
		
		$meta = [];
		foreach ( $results as $r ) {
			$meta[ $r->meta_key ] = $r->meta_value;
		}
		return $meta;
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
