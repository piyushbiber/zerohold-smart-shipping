<?php

namespace Zerohold\Shipping;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Root Level Shipping Page to bypass Vendor folder issues.
 */
class RootShippingStatementPage {

	const ENDPOINT = 'shipping-statement';

	public function __construct() {
		add_filter( 'dokan_get_dashboard_nav', [ $this, 'add_menu_item' ], 20 );
		add_filter( 'dokan_query_var_filter', [ $this, 'register_endpoint' ] );
		add_action( 'init', [ $this, 'add_rewrite_rule' ] );
		add_action( 'dokan_load_custom_template', [ $this, 'render_content' ] );
	}

	public function add_menu_item( $urls ) {
        // Safe URL
		$url = home_url( '/dashboard/' . self::ENDPOINT );

		$urls['shipping_statement'] = [
			'title' => __( 'Shipping Charges', 'zerohold-shipping' ),
			'icon'  => '<i class="fas fa-shipping-fast"></i>',
			'url'   => $url,
			'pos'   => 55
		];
		return $urls;
	}

	public function register_endpoint( $query_vars ) {
		$query_vars[] = self::ENDPOINT;
		return $query_vars;
	}

	public function add_rewrite_rule() {
		add_rewrite_endpoint( self::ENDPOINT, EP_PAGES );
	}

	public function render_content( $query_vars ) {
		if ( isset( $query_vars[ self::ENDPOINT ] ) ) {
            echo '<div class="dokan-dashboard-wrap"><h1>Works from Root!</h1><p>The "Vendor" folder was the problem.</p></div>';
		}
	}
}
