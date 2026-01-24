<?php

/**
 * Migration: Create Zh Rate Estimate Cache Table
 * Filename: 2026_01_24_create_zh_rate_estimate_cache.php
 */

class CreateZhRateEstimateCache {

	/**
	 * Run the migration.
	 */
	public static function up() {
		global $wpdb;

		$table = $wpdb->prefix . 'zh_rate_estimate_cache';

		$sql = "CREATE TABLE `$table` (
            `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            `vendor_id` BIGINT(20) UNSIGNED NOT NULL,
            `origin_pincode` VARCHAR(10) NOT NULL,
            `slab_key` VARCHAR(32) NOT NULL,
            `min_price` DECIMAL(10,2) DEFAULT 0.00,
            `max_price` DECIMAL(10,2) DEFAULT 0.00,
            `zone_data_json` LONGTEXT NOT NULL,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `vendor_slab_origin` (`vendor_id`, `origin_pincode`, `slab_key`),
            KEY `created_at_idx` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}
}

// Execute the migration
CreateZhRateEstimateCache::up();
