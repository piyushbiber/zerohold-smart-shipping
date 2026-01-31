<?php
/**
 * Plugin Name: ZeroHold Smart Shipping (ZSS)
 * Description: A powerful multi-platform shipping adapter for ZeroHold.
 * Version: 1.0.0
 * Author: ZeroHold
 * Namespace: Zerohold\Shipping
 */

namespace Zerohold\Shipping;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Basic Autoloader for Zerohold\Shipping Namespace
spl_autoload_register( function ( $class ) {
	$prefix = 'Zerohold\\Shipping\\';
	$base_dir = plugin_dir_path( __FILE__ );

	$len = strlen( $prefix );
	if ( strncmp( $prefix, $class, $len ) !== 0 ) {
		return;
	}

	$relative_class = substr( $class, $len );
	$file = $base_dir . str_replace( '\\', '/', $relative_class ) . '.php';

	if ( file_exists( $file ) ) {
		require $file;
	}
} );

// Database Installation Logic
include_once plugin_dir_path( __FILE__ ) . 'Includes/db/install.php';

// Register activation hook
register_activation_hook( __FILE__, 'zh_install_tables' );

// Debug Test Hook
add_action( 'admin_init', function() {
	if ( isset( $_GET['zh_test_shipment'] ) ) {

		$order_id = intval( $_GET['zh_test_shipment'] );
		$order    = wc_get_order( $order_id );

		if ( ! $order ) {
			echo "Order not found";
			exit;
		}

		try {
			$mapper   = new \Zerohold\Shipping\Core\OrderMapper();
			$shipment = $mapper->map( $order );

			echo "<h3>Shipment Test Output</h3>";
			echo "<pre>";
			print_r( $shipment );
			echo "</pre>";

		} catch ( \Throwable $e ) {
			echo "<h3>Mapper Exception</h3>";
			echo "<pre>{$e->getMessage()}</pre>";
			echo "<pre>{$e->getFile()}</pre>";
			echo "<pre>{$e->getLine()}</pre>";
		}

		exit;
	}
} );

// Main Plugin Entry Point
class ZeroHoldSmartShipping {
	public function __construct() {
		// Initialize Vendor Actions
		new Vendor\VendorActions();

		// Initialize Vendor UI (shipping buttons)
		new Vendor\VendorUI();




        // Initialize Dokan Statement Integration (Immutable Ledger)
        new Core\DokanStatementIntegration();

		// Initialize Return Shipping MVP
		new Admin\ReturnAdminUI();
		new Core\ReturnManager();
		new Frontend\RetailerReturnUI();
		new Core\DokanStatusSync();

		// New: Order Visibility Delay Logic
		new Core\OrderVisibilityManager();

		// New: Buyer Cancellation Logic
		new Core\BuyerCancellationManager();

		// Bug Fix: Isolate Shipping from Vendor Total
		new Core\DokanEarningsFix();

		// Initialize Logistics Background Sync (Phase 2)
		new Core\LogisticsSynchronizer();

		// Initialize Platforms (for testing auth)
		new Platforms\ShiprocketAdapter();

		// Initialize Admin Pages
		if ( is_admin() ) {
			new Admin\ShippingShareSettings();
			require_once __DIR__ . '/Core/Admin/PincodeImportPage.php';
			add_action( 'admin_menu', function() {
				\Zerohold\Shipping\Admin\PincodeImportPage::register();
			} );
			new Admin\OrderVisibilitySettings();
			new Admin\LogisticsUI();
		}

		// Register Refund Cleanup Hook
		add_action( 'woocommerce_order_status_refunded', [ $this, 'cleanup_on_refund' ], 10, 1 );

		// 🚀 RTO Refund Trigger: Fired whenever the status is set to 'RTO Delivered'
		// This works for both automatic carrier updates and manual admin status changes.
		add_action( 'woocommerce_order_status_rto-delivered', [ '\Zerohold\Shipping\Core\LogisticsRefundManager', 'process_rto_delivered_refund' ] );

		// Register Shipping Method
		add_filter( 'woocommerce_shipping_methods', [ $this, 'register_shipping_method' ] );
		add_action( 'woocommerce_shipping_init', [ $this, 'init_shipping_method' ] );

		// Register Custom Cron Schedules
		add_filter( 'cron_schedules', [ $this, 'register_custom_cron_schedules' ] );

		// Schedule Background Sync
		if ( ! wp_next_scheduled( 'zh_logistics_sync_cron' ) ) {
			wp_schedule_event( time(), 'twicedaily', 'zh_logistics_sync_cron' );
		}
	}

	/**
	 * Define custom cron intervals.
	 */
	public function register_custom_cron_schedules( $schedules ) {
		$schedules['five_minutes'] = [
			'interval' => 300,
			'display'  => esc_html__( 'Every 5 Minutes' ),
		];
		return $schedules;
	}

	/**
	 * Register the shipping method class.
	 */
	public function register_shipping_method( $methods ) {
		$methods['zerohold_shipping'] = \Zerohold\Shipping\Frontend\ZSS_Shipping_Method::class;
		return $methods;
	}

	/**
	 * Initialize the shipping method class.
	 */
	public function init_shipping_method() {
		if ( class_exists( 'WC_Shipping_Method' ) ) {
			require_once __DIR__ . '/Frontend/ZSS_Shipping_Method.php';
		}
	}

	/**
	 * Triggered when order is refunded.
	 */
	public function cleanup_on_refund( $order_id ) {
		\Zerohold\Shipping\Core\DokanShipmentSync::cleanup_return_tracking( $order_id );
	}

	public function run() {
		// Run logic
	}
}

function zss_init() {
	// Initialize Migration Runner
	Core\MigrationRunner::run();
	
	// Initialize Safe Debug Listener (Removable)
	if ( class_exists( 'Zerohold\Shipping\Core\DebugListener' ) ) {
		new Core\DebugListener();
	}

	$plugin = new ZeroHoldSmartShipping();
	$plugin->run();
}

add_action( 'plugins_loaded', 'Zerohold\Shipping\zss_init' );
