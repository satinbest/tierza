<?php

declare(strict_types=1);

namespace Tierza\Infrastructure\Database;

use Tierza\Domain\Exceptions\SchemaMigrationException;
use wpdb;

/**
 * Manages database table creation, version tracking, and schema migrations.
 */
final class Schema {

	public const CURRENT_DB_VERSION = '1.0.0';
	public const DB_VERSION_OPTION  = 'tierza_db_version';

	public const TABLE_GROUPS      = 'tierza_groups';
	public const TABLE_RULES       = 'tierza_rules';
	public const TABLE_USER_GROUPS = 'tierza_user_groups';

	/**
	 * Get full table name with prefix.
	 */
	public static function get_table_name( string $table, ?wpdb $wpdb = null ): string {
		global $wpdb;
		$db = $wpdb ?? $GLOBALS['wpdb'];
		return $db->prefix . $table;
	}

	/**
	 * Get full table name for groups.
	 */
	public static function groups_table( ?wpdb $wpdb = null ): string {
		return self::get_table_name( self::TABLE_GROUPS, $wpdb );
	}

	/**
	 * Get full table name for rules.
	 */
	public static function rules_table( ?wpdb $wpdb = null ): string {
		return self::get_table_name( self::TABLE_RULES, $wpdb );
	}

	/**
	 * Get full table name for user group assignments.
	 */
	public static function user_groups_table( ?wpdb $wpdb = null ): string {
		return self::get_table_name( self::TABLE_USER_GROUPS, $wpdb );
	}

	/**
	 * Run table creation and idempotent migrations.
	 */
	public static function install( ?wpdb $db_instance = null ): void {
		$db = $db_instance ?? ( isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof wpdb ? $GLOBALS['wpdb'] : null );

		if ( ! $db instanceof wpdb ) {
			return;
		}

		$charset_collate = $db->get_charset_collate();

		$table_groups      = self::groups_table( $db );
		$table_rules       = self::rules_table( $db );
		$table_user_groups = self::user_groups_table( $db );

		// dbDelta requires specific syntax:
		// - Each field on its own line
		// - Two spaces after PRIMARY KEY
		// - KEY instead of INDEX
		$sql_groups = "CREATE TABLE {$table_groups} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(190) NOT NULL,
  slug varchar(190) NOT NULL,
  description text NULL,
  status varchar(20) NOT NULL DEFAULT 'active',
  sort_order int(11) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uq_slug (slug),
  KEY idx_status_sort (status, sort_order)
) {$charset_collate};";

		$sql_rules = "CREATE TABLE {$table_rules} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  target_type varchar(20) NOT NULL,
  target_id bigint(20) unsigned NOT NULL,
  scope_type varchar(20) NOT NULL,
  scope_id bigint(20) unsigned NOT NULL DEFAULT 0,
  pricing_type varchar(20) NOT NULL,
  value decimal(20,6) NOT NULL DEFAULT 0.000000,
  status varchar(20) NOT NULL DEFAULT 'active',
  active_key varchar(100) NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uq_active_rule (active_key),
  KEY idx_target (target_type, target_id, status),
  KEY idx_scope (scope_type, scope_id, status),
  KEY idx_lookup (target_type, target_id, scope_type, scope_id, status)
) {$charset_collate};";

		$sql_user_groups = "CREATE TABLE {$table_user_groups} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  group_id bigint(20) unsigned NOT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uq_user_id (user_id),
  KEY idx_group_id (group_id)
) {$charset_collate};";

		if ( ! function_exists( 'dbDelta' ) && defined( 'ABSPATH' ) ) {
			$upgrade_path = ABSPATH . 'wp-admin/includes/upgrade.php';
			if ( file_exists( $upgrade_path ) ) {
				require_once $upgrade_path;
			}
		}

		if ( function_exists( 'dbDelta' ) ) {
			dbDelta( $sql_groups );
			dbDelta( $sql_rules );
			dbDelta( $sql_user_groups );
		}

		// Verify schema before updating version.
		$issues = self::verify_schema( $db );
		if ( ! empty( $issues ) ) {
			throw new SchemaMigrationException(
				sprintf(
					'Tierza schema migration failed verification: %s',
					implode( '; ', $issues )
				)
			);
		}

		update_option( self::DB_VERSION_OPTION, self::CURRENT_DB_VERSION );
	}

	/**
	 * Verify that all required tables, essential columns, and indexes exist.
	 *
	 * @return string[] List of issues, or empty array if healthy.
	 */
	public static function verify_schema( ?wpdb $db_instance = null ): array {
		$db = $db_instance ?? ( isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof wpdb ? $GLOBALS['wpdb'] : null );

		if ( ! $db instanceof wpdb ) {
			return [ 'Database connection is unavailable.' ];
		}

		$issues = [];
		$tables = [
			self::groups_table( $db )      => [
				'columns' => [ 'id', 'name', 'slug', 'status', 'sort_order', 'created_at', 'updated_at' ],
				'indexes' => [ 'PRIMARY', 'uq_slug' ],
			],
			self::rules_table( $db )       => [
				'columns' => [ 'id', 'target_type', 'target_id', 'scope_type', 'scope_id', 'pricing_type', 'value', 'status', 'active_key', 'created_at', 'updated_at' ],
				'indexes' => [ 'PRIMARY', 'uq_active_rule' ],
			],
			self::user_groups_table( $db ) => [
				'columns' => [ 'id', 'user_id', 'group_id', 'created_at', 'updated_at' ],
				'indexes' => [ 'PRIMARY', 'uq_user_id' ],
			],
		];

		foreach ( $tables as $table => $specs ) {
			$query       = $db->prepare( 'SHOW TABLES LIKE %s', $table );
			$found_table = $db->get_var( $query );

			if ( $found_table !== $table ) {
				$issues[] = sprintf( 'Missing table "%s".', $table );
				continue;
			}

			// Check essential columns.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$cols_data = $db->get_results( "SHOW FULL COLUMNS FROM {$table}", ARRAY_A );
			if ( is_array( $cols_data ) ) {
				$existing_cols = array_column( $cols_data, 'Field' );
				foreach ( $specs['columns'] as $col ) {
					if ( ! in_array( $col, $existing_cols, true ) ) {
						$issues[] = sprintf( 'Table "%s" is missing column "%s".', $table, $col );
					}
				}
			}

			// Check essential indexes.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$index_data = $db->get_results( "SHOW INDEX FROM {$table}", ARRAY_A );
			if ( is_array( $index_data ) ) {
				$existing_indexes = array_column( $index_data, 'Key_name' );
				foreach ( $specs['indexes'] as $idx ) {
					if ( ! in_array( $idx, $existing_indexes, true ) ) {
						$issues[] = sprintf( 'Table "%s" is missing index "%s".', $table, $idx );
					}
				}
			}
		}

		return $issues;
	}

	/**
	 * Check whether schema needs to be upgraded.
	 */
	public static function maybe_upgrade( ?wpdb $db_instance = null ): void {
		$installed_version = get_option( self::DB_VERSION_OPTION, '0.0.0' );

		if ( version_compare( (string) $installed_version, self::CURRENT_DB_VERSION, '<' ) ) {
			self::install( $db_instance );
		}
	}

	/**
	 * Check whether all required tables exist in the database.
	 */
	public static function tables_exist( ?wpdb $wpdb = null ): bool {
		global $wpdb;
		$db = $wpdb ?? $GLOBALS['wpdb'];

		if ( ! $db instanceof wpdb ) {
			return false;
		}

		$tables = [
			self::groups_table( $db ),
			self::rules_table( $db ),
			self::user_groups_table( $db ),
		];

		foreach ( $tables as $table ) {
			$query = $db->prepare( 'SHOW TABLES LIKE %s', $table );
			$found = $db->get_var( $query );
			if ( $found !== $table ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Drop all tables (exclusively for isolated test fixtures or uninstallation).
	 */
	public static function drop_tables( ?wpdb $wpdb = null ): void {
		global $wpdb;
		$db = $wpdb ?? $GLOBALS['wpdb'];

		if ( ! $db instanceof wpdb ) {
			return;
		}

		$tables = [
			self::user_groups_table( $db ),
			self::rules_table( $db ),
			self::groups_table( $db ),
		];

		foreach ( $tables as $table ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names cannot be bound via placeholders.
			$db->query( "DROP TABLE IF EXISTS {$table}" );
		}

		delete_option( self::DB_VERSION_OPTION );
	}
}
