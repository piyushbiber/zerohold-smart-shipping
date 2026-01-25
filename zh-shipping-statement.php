<?php

namespace Zerohold\Shipping;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Root Level Shipping Page to bypass Vendor folder issues.
 * Logic migrated from Vendor/ShippingStatementPage.php
 */
class RootShippingStatementPage {

	const ENDPOINT = 'shipping-statement';

	public function __construct() {
		// 1. Add Menu Item
		add_filter( 'dokan_get_dashboard_nav', [ $this, 'add_menu_item' ], 20 );

		// 2. Register Endpoint
		add_filter( 'dokan_query_var_filter', [ $this, 'register_endpoint' ] );
		add_action( 'init', [ $this, 'add_rewrite_rule' ] );

		// 3. Render Content
		add_action( 'dokan_load_custom_template', [ $this, 'render_content' ] );
	}

	/**
	 * Add "Shipping Charges" to Dokan Dashboard Menu.
	 */
	public function add_menu_item( $urls ) {
        // Safe URL generation
		$url = function_exists( 'dokan_get_navigation_url' ) ? dokan_get_navigation_url( self::ENDPOINT ) : home_url( '/dashboard/' . self::ENDPOINT );

		$urls['shipping_statement'] = [
			'title' => __( 'Shipping Charges', 'zerohold-shipping' ),
			'icon'  => '<i class="fas fa-shipping-fast"></i>',
			'url'   => $url,
			'pos'   => 55
		];
		return $urls;
	}

	/**
	 * Register the query var.
	 */
	public function register_endpoint( $query_vars ) {
		$query_vars[] = self::ENDPOINT;
		return $query_vars;
	}

	/**
	 * Add rewrite rule for the endpoint.
	 */
	public function add_rewrite_rule() {
		add_rewrite_endpoint( self::ENDPOINT, EP_PAGES );
	}

	/**
	 * Render the page content if the endpoint matches.
	 */
	public function render_content( $query_vars ) {
		if ( isset( $query_vars[ self::ENDPOINT ] ) ) {
			$this->display_statement_page();
		}
	}

	/**
	 * Main Logic: Fetch and Display Table.
	 */
	private function display_statement_page() {
		if ( ! is_user_logged_in() ) {
			return;
		}

		$user_id = get_current_user_id();
		global $wpdb;

		// IMPORTANT: Read directly from TeraWallet tables
		$table_transactions = $wpdb->prefix . 'woo_wallet_transactions';
		$table_meta         = $wpdb->prefix . 'woo_wallet_transaction_meta';

		// Query logic
		$sql = "
			SELECT t.*, m.meta_value as is_shipping 
			FROM $table_transactions t
			INNER JOIN $table_meta m ON t.transaction_id = m.transaction_id
			WHERE t.user_id = %d
			AND m.meta_key = 'zh_shipping'
			AND m.meta_value = 'yes'
			ORDER BY t.date DESC
			LIMIT 100
		";

		$results = $wpdb->get_results( $wpdb->prepare( $sql, $user_id ) );

		// Calculate Current Balance (Safe Check)
		$current_balance = 0;
		if ( function_exists('woo_wallet') ) {
			$wallet_instance = woo_wallet();
			if ( is_object( $wallet_instance ) && isset( $wallet_instance->wallet ) && method_exists( $wallet_instance->wallet, 'get_wallet_balance' ) ) {
				$current_balance = $wallet_instance->wallet->get_wallet_balance( $user_id );
			}
		}

		?>
		<div class="dokan-dashboard-wrap">
			<?php 
			if ( function_exists( 'dokan_get_template' ) ) {
				dokan_get_template( 'dashboard/nav.php', [ 'active_menu' => 'shipping_statement' ] ); 
			}
			?>

			<div class="dokan-dashboard-content">
				<header class="dokan-dashboard-header">
					<h1 class="entry-title"><?php _e( 'Shipping Charges Statement', 'zerohold-shipping' ); ?></h1>
				</header>

				<div class="dokan-panel dokan-panel-default">
					<div class="dokan-panel-heading">
						<strong><?php _e( 'Wallet Shipping History', 'zerohold-shipping' ); ?></strong>
						<span style="float:right">Current Wallet Balance: <strong><?php echo wc_price( $current_balance ); ?></strong></span>
					</div>
					<div class="dokan-panel-body">
						
						<?php if ( empty( $results ) ) : ?>
							<div class="dokan-alert dokan-alert-info">
								<?php _e( 'No shipping transactions found yet.', 'zerohold-shipping' ); ?>
							</div>
						<?php else : ?>
							<table class="dokan-table dokan-table-striped">
								<thead>
									<tr>
										<th><?php _e( 'Date', 'zerohold-shipping' ); ?></th>
										<th><?php _e( 'Order ID', 'zerohold-shipping' ); ?></th>
										<th><?php _e( 'Type', 'zerohold-shipping' ); ?></th>
										<th><?php _e( 'Debit', 'zerohold-shipping' ); ?></th>
										<th><?php _e( 'Credit', 'zerohold-shipping' ); ?></th>
										<th><?php _e( 'Details', 'zerohold-shipping' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $results as $row ) : 
										$meta = $this->get_transaction_meta( $row->transaction_id );
										$order_id = $meta['order_id'] ?? '-';
										$type = $row->type; // debit or credit
										$amount = floatval( $row->amount );
										
										// Formatting
										$date = date_i18n( get_option( 'date_format' ), strtotime( $row->date ) );
										
										$debit_display = ($type === 'debit') ? wc_price( $amount ) : '-';
										$credit_display = ($type === 'credit') ? wc_price( $amount ) : '-';
										
										// Human Readable Type
										$type_label = ($type === 'debit') ? 'Shipping Charge' : 'Shipping Refund';
										if ( isset($meta['transaction_type']) && $meta['transaction_type'] === 'shipping_refund' ) {
											$type_label = 'Shipping Refund';
										}
									?>
									<tr>
										<td><?php echo esc_html( $date ); ?></td>
										<td>
											<?php if ( is_numeric( $order_id ) ) : ?>
												<a href="<?php echo esc_url( dokan_get_navigation_url( 'orders' ) . '?order_id=' . $order_id ); ?>">
													#<?php echo esc_html( $order_id ); ?>
												</a>
											<?php else : ?>
												<?php echo esc_html( $order_id ); ?>
											<?php endif; ?>
										</td>
										<td>
											<span class="zss-type-badge <?php echo $type; ?>">
												<?php echo esc_html( $type_label ); ?>
											</span>
										</td>
										<td class="zss-debit"><?php echo $debit_display; ?></td>
										<td class="zss-credit"><?php echo $credit_display; ?></td>
										<td><?php echo esc_html( $row->details ); ?></td>
									</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						<?php endif; ?>

					</div>
				</div>
			</div>
		</div>

		<style>
			.zss-type-badge { padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: bold; text-transform: uppercase; }
			.zss-type-badge.debit { background: #ffe0e0; color: #c0392b; border: 1px solid #fab1a0; }
			.zss-type-badge.credit { background: #e0f9e0; color: #27ae60; border: 1px solid #a9dfbf; }
			.zss-debit { color: #c0392b; font-weight: bold; }
			.zss-credit { color: #27ae60; font-weight: bold; }
		</style>
		<?php
	}

	/**
	 * Helper to get all meta for a transaction.
	 */
	private function get_transaction_meta( $transaction_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'woo_wallet_transaction_meta';
		$results = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM $table WHERE transaction_id = %d", $transaction_id ) );
		
		$meta = [];
		foreach ( $results as $r ) {
			$meta[ $r->meta_key ] = $r->meta_value;
		}
		return $meta;
	}
}
