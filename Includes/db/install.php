<?php
/**
 * Database Installation Logic
 *
 * Handles the creation of custom tables for ZeroHold Smart Shipping.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Create custom database tables.
 */
function zh_install_tables() {
	global $wpdb;
	$charset_collate = $wpdb->get_charset_collate();

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	// 1. Warehouse Table
	$table_warehouse = $wpdb->prefix . 'zh_warehouse';
	$sql1            = "CREATE TABLE $table_warehouse (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		user_id bigint(20) unsigned NOT NULL,
		role varchar(20) NOT NULL,
		warehouse_code varchar(50) NOT NULL,
		business_name varchar(255) DEFAULT NULL,
		contact_name varchar(255) DEFAULT NULL,
		phone varchar(20) DEFAULT NULL,
		email varchar(255) DEFAULT NULL,
		gst varchar(20) DEFAULT NULL,
		address_line1 text NOT NULL,
		address_line2 text DEFAULT NULL,
		city varchar(100) DEFAULT NULL,
		state varchar(100) DEFAULT NULL,
		pincode varchar(20) DEFAULT NULL,
		opening_time time DEFAULT NULL,
		closing_time time DEFAULT NULL,
		is_primary tinyint(1) DEFAULT 0,
		status varchar(20) DEFAULT 'pending',
		created_at datetime DEFAULT CURRENT_TIMESTAMP,
		updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		PRIMARY KEY  (id),
		KEY user_id (user_id),
		KEY role (role),
		KEY pincode (pincode)
	) $charset_collate;";
	dbDelta( $sql1 );

	// 2. Warehouse Verification Table
	$table_verification = $wpdb->prefix . 'zh_warehouse_verification';
	$sql2               = "CREATE TABLE $table_verification (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		warehouse_id bigint(20) unsigned NOT NULL,
		phone_verified tinyint(1) DEFAULT 0,
		email_verified tinyint(1) DEFAULT 0,
		gst_verified tinyint(1) DEFAULT 0,
		docs_verified tinyint(1) DEFAULT 0,
		admin_verified tinyint(1) DEFAULT 0,
		verified_at datetime DEFAULT NULL,
		rejected_at datetime DEFAULT NULL,
		rejection_reason text DEFAULT NULL,
		created_at datetime DEFAULT CURRENT_TIMESTAMP,
		updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		PRIMARY KEY  (id),
		KEY warehouse_id (warehouse_id)
	) $charset_collate;";
	dbDelta( $sql2 );

	// 3. Carrier Map Table
	$table_carrier_map = $wpdb->prefix . 'zh_carrier_map';
	$sql3              = "CREATE TABLE $table_carrier_map (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		warehouse_id bigint(20) unsigned NOT NULL,
		carrier varchar(50) NOT NULL,
		carrier_wh_id varchar(100) DEFAULT NULL,
		status varchar(20) DEFAULT 'pending',
		last_sync datetime DEFAULT NULL,
		payload longtext DEFAULT NULL,
		created_at datetime DEFAULT CURRENT_TIMESTAMP,
		updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		PRIMARY KEY  (id),
		KEY warehouse_id (warehouse_id),
		KEY carrier (carrier)
	) $charset_collate;";
	dbDelta( $sql3 );

	// 4. Bank Accounts Table
	$table_bank_accounts = $wpdb->prefix . 'zh_bank_accounts';
	$sql4                = "CREATE TABLE $table_bank_accounts (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		user_id bigint(20) unsigned NOT NULL,
		role varchar(20) NOT NULL,
		account_holder varchar(255) DEFAULT NULL,
		account_number varchar(255) DEFAULT NULL,
		ifsc_code varchar(50) DEFAULT NULL,
		bank_name varchar(255) DEFAULT NULL,
		account_type varchar(50) DEFAULT NULL,
		country varchar(50) DEFAULT NULL,
		proof_doc longtext DEFAULT NULL,
		status varchar(20) DEFAULT 'pending',
		verified_at datetime DEFAULT NULL,
		rejected_at datetime DEFAULT NULL,
		created_at datetime DEFAULT CURRENT_TIMESTAMP,
		updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		PRIMARY KEY  (id),
		KEY user_id (user_id)
	) $charset_collate;";
	dbDelta( $sql4 );

	// 5. Onboarding Log Table
	$table_onboarding_log = $wpdb->prefix . 'zh_onboarding_log';
	$sql5                 = "CREATE TABLE $table_onboarding_log (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		user_id bigint(20) unsigned DEFAULT NULL,
		warehouse_id bigint(20) unsigned DEFAULT NULL,
		action varchar(50) NOT NULL,
		data longtext DEFAULT NULL,
		by_actor varchar(50) DEFAULT NULL,
		timestamp datetime DEFAULT CURRENT_TIMESTAMP,
		updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		PRIMARY KEY  (id),
		KEY user_id (user_id),
		KEY warehouse_id (warehouse_id)
	) $charset_collate;";
	dbDelta( $sql5 );
}
