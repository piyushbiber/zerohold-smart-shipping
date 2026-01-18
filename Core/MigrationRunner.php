<?php

namespace Zerohold\Shipping\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Migration Runner System
 * Handles database schema updates incrementally.
 */
class MigrationRunner {

	protected static $table = 'zh_migrations';

	/**
	 * Run the migration system.
	 */
	public static function run() {
		self::createMigrationsTable();
		self::runPendingMigrations();
	}

	/**
	 * Create the migrations tracking table if it doesn't exist.
	 */
	protected static function createMigrationsTable() {
		global $wpdb;
		$table = $wpdb->prefix . self::$table;

		$sql = "CREATE TABLE IF NOT EXISTS `$table` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `migration` varchar(190) NOT NULL,
            `ran_at` datetime NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `migration` (`migration`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Scan and run all pending migrations in the Migrations directory.
	 */
	protected static function runPendingMigrations() {
		$migrations = self::getMigrationFiles();

		if ( empty( $migrations ) ) {
			return;
		}

		foreach ( $migrations as $file ) {
			if ( ! self::hasRun( $file ) ) {
				// Include the migration file
				include_once $file;

				// Tracking the migration
				self::markAsRun( $file );
			}
		}
	}

	/**
	 * Get all migration files from the Migrations subdirectory.
	 *
	 * @return array
	 */
	protected static function getMigrationFiles() {
		$dir = plugin_dir_path( __FILE__ ) . 'Migrations/*.php';
		$files = glob( $dir );
		
		if ( false === $files ) {
			return [];
		}

		// Sort migrations alphabetically to ensure order
		sort( $files );
		
		return $files;
	}

	/**
	 * Check if a migration has already been executed.
	 *
	 * @param string $migration Path to the migration file.
	 * @return bool
	 */
	protected static function hasRun( $migration ) {
		global $wpdb;
		$table = $wpdb->prefix . self::$table;
		$name  = basename( $migration );

		$result = $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM `$table` WHERE migration = %s", $name )
		);

		return (bool) $result;
	}

	/**
	 * Record a migration as successfully executed.
	 *
	 * @param string $migration Path to the migration file.
	 */
	protected static function markAsRun( $migration ) {
		global $wpdb;
		$table = $wpdb->prefix . self::$table;
		$name  = basename( $migration );

		$wpdb->insert(
			$table,
			[
				'migration' => $name,
				'ran_at'    => current_time( 'mysql' ),
			]
		);
	}
}
