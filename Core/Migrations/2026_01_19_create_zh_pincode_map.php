<?php

/**
 * Migration: Create Zh Pincode Map Table
 * Filename: 2026_01_19_create_zh_pincode_map.php
 */

class CreateZhPincodeMap {

	/**
	 * Run the migration.
	 */
	public static function up() {
		global $wpdb;

		$table = $wpdb->prefix . 'zh_pincode_map';

		$sql = "CREATE TABLE `$table` (
            `pincode` VARCHAR(10) NOT NULL,
            `state` VARCHAR(50) NOT NULL,
            `district` VARCHAR(80) NULL,
            `circle` VARCHAR(50) NULL,
            `region` VARCHAR(50) NULL,
            PRIMARY KEY (`pincode`),
            KEY `state_idx` (`state`),
            KEY `district_idx` (`district`),
            KEY `circle_idx` (`circle`),
            KEY `region_idx` (`region`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}
}

// Execute the migration
CreateZhPincodeMap::up();
