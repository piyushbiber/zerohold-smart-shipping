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

        // Initialize Shipping Statement Page (New)
        // require_once __DIR__ . '/Vendor/ShippingStatementPage.php';
        // new \ZH_Shipping_Statement_Unique();

        // ROOT FILE TEST
        require_once __DIR__ . '/zh-shipping-statement.php';
        new \Zerohold\Shipping\RootShippingStatementPage();

        // Initialize Wallet Transaction Manager (New)
        new Core\WalletTransactionManager();

		// Initialize Return Shipping MVP
		new Admin\ReturnAdminUI();
		new Core\ReturnManager();
		new Frontend\RetailerReturnUI();
		new Core\DokanStatusSync();

		// Initialize Platforms (for testing auth)
		new Platforms\ShiprocketAdapter();

		// Initialize Admin Pages
		if ( is_admin() ) {
			require_once __DIR__ . '/Core/Admin/PincodeImportPage.php';
			add_action( 'admin_menu', function() {
				\Zerohold\Shipping\Admin\PincodeImportPage::register();
			} );
		}

		// Register Refund Cleanup Hook
		add_action( 'woocommerce_order_status_refunded', [ $this, 'cleanup_on_refund' ], 10, 1 );
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
