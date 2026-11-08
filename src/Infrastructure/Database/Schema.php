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

		$table_groups      = self::groups_table( $db );
		$table_rules       = self::rules_table( $db );
		$table_user_groups = self::user_groups_table( $db );

		// Step 1: Pre-migration reconciliation and backfilling on existing rules table.
		self::prepare_and_backfill_rules( $db );

		$charset_collate = $db->get_charset_collate();

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

		// Step 2: Ensure unique index constraints exist even if dbDelta bypassed them.
		self::ensure_unique_indexes( $db );

		// Step 3: Verify schema before updating version.
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
	 * Prepare existing tierza_rules table for migration, detect conflicts, and backfill active_key.
	 *
	 * @throws SchemaMigrationException If active rule conflicts exist in legacy data.
	 */
	public static function prepare_and_backfill_rules( wpdb $db ): void {
		$table_rules = self::rules_table( $db );

		// If table does not exist yet, this is a fresh install.
		$query = $db->prepare( 'SHOW TABLES LIKE %s', $table_rules );
		if ( $db->get_var( $query ) !== $table_rules ) {
			return;
		}

		// Step A: Ensure active_key column exists before conflict detection and backfill.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$cols = $db->get_results( "SHOW FULL COLUMNS FROM {$table_rules}", ARRAY_A );
		if ( is_array( $cols ) && ! empty( $cols ) ) {
			$existing_fields = array_column( $cols, 'Field' );
			if ( ! in_array( 'active_key', $existing_fields, true ) ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$db->query( "ALTER TABLE {$table_rules} ADD COLUMN active_key varchar(100) NULL AFTER status" );
			}
		}

		// Step B: Detect duplicate active logical rules (target_type, target_id, scope_type, scope_id).
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$conflicts = $db->get_results(
			'SELECT target_type, target_id, scope_type, scope_id, COUNT(*) AS conflict_count, GROUP_CONCAT(id ORDER BY id ASC) AS conflicting_ids ' .
			"FROM {$table_rules} " .
			"WHERE status = 'active' " .
			'GROUP BY target_type, target_id, scope_type, scope_id ' .
			'HAVING conflict_count > 1',
			ARRAY_A
		);

		if ( ! empty( $conflicts ) && is_array( $conflicts ) ) {
			$details = [];
			foreach ( $conflicts as $row ) {
				$details[] = sprintf(
					'[target=%s:%d, scope=%s:%d, rule_ids=%s]',
					(string) ( $row['target_type'] ?? '' ),
					(int) ( $row['target_id'] ?? 0 ),
					(string) ( $row['scope_type'] ?? '' ),
					(int) ( $row['scope_id'] ?? 0 ),
					(string) ( $row['conflicting_ids'] ?? '' )
				);
			}

			throw new SchemaMigrationException(
				sprintf(
					'Tierza migration halted: detected %d conflicting active pricing rule(s). In Tierza v1, only one active rule is permitted per target and scope regardless of pricing type. Conflicting sets: %s. No records were modified or deleted. Please deactivate or adjust the duplicate active rules before retrying the migration.',
					count( $conflicts ),
					implode( '; ', $details )
				)
			);
		}

		// Step C: Backfill active rules missing active_key.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$db->query(
			"UPDATE {$table_rules} " .
			"SET active_key = CONCAT(target_type, ':', target_id, '|', scope_type, ':', scope_id) " .
			"WHERE status = 'active' AND (active_key IS NULL OR active_key = '')"
		);

		// Step D: Ensure inactive rules have active_key = NULL.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$db->query(
			"UPDATE {$table_rules} " .
			'SET active_key = NULL ' .
			"WHERE status != 'active' AND active_key IS NOT NULL"
		);
	}

	/**
	 * Guarantee that essential UNIQUE indexes exist on all tables.
	 */
	public static function ensure_unique_indexes( wpdb $db ): void {
		$table_rules       = self::rules_table( $db );
		$table_groups      = self::groups_table( $db );
		$table_user_groups = self::user_groups_table( $db );

		self::ensure_unique_index_on_table( $db, $table_rules, 'uq_active_rule', 'active_key' );
		self::ensure_unique_index_on_table( $db, $table_groups, 'uq_slug', 'slug' );
		self::ensure_unique_index_on_table( $db, $table_user_groups, 'uq_user_id', 'user_id' );
	}

	/**
	 * Ensure specific column has a UNIQUE index on the specified table.
	 */
	private static function ensure_unique_index_on_table( wpdb $db, string $table, string $index_name, string $column ): void {
		$query = $db->prepare( 'SHOW TABLES LIKE %s', $table );
		if ( $db->get_var( $query ) !== $table ) {
			return;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$index_rows = $db->get_results( "SHOW INDEX FROM {$table}", ARRAY_A );
		if ( ! is_array( $index_rows ) ) {
			return;
		}

		$found     = false;
		$is_unique = false;
		foreach ( $index_rows as $row ) {
			if ( ( $row['Key_name'] ?? '' ) === $index_name ) {
				$found = true;
				if ( 0 === (int) ( $row['Non_unique'] ?? 1 ) ) {
					$is_unique = true;
				}
			}
		}

		if ( ! $found ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$db->query( "ALTER TABLE {$table} ADD UNIQUE KEY {$index_name} ({$column})" );
		} elseif ( ! $is_unique ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$db->query( "ALTER TABLE {$table} DROP INDEX {$index_name}, ADD UNIQUE KEY {$index_name} ({$column})" );
		}
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
				'indexes' => [
					'PRIMARY'         => [
						'unique'  => true,
						'columns' => [ 'id' ],
					],
					'uq_slug'         => [
						'unique'  => true,
						'columns' => [ 'slug' ],
					],
					'idx_status_sort' => [
						'unique'  => false,
						'columns' => [ 'status', 'sort_order' ],
					],
				],
			],
			self::rules_table( $db )       => [
				'columns' => [ 'id', 'target_type', 'target_id', 'scope_type', 'scope_id', 'pricing_type', 'value', 'status', 'active_key', 'created_at', 'updated_at' ],
				'indexes' => [
					'PRIMARY'        => [
						'unique'  => true,
						'columns' => [ 'id' ],
					],
					'uq_active_rule' => [
						'unique'  => true,
						'columns' => [ 'active_key' ],
					],
					'idx_target'     => [
						'unique'  => false,
						'columns' => [ 'target_type', 'target_id', 'status' ],
					],
					'idx_scope'      => [
						'unique'  => false,
						'columns' => [ 'scope_type', 'scope_id', 'status' ],
					],
					'idx_lookup'     => [
						'unique'  => false,
						'columns' => [ 'target_type', 'target_id', 'scope_type', 'scope_id', 'status' ],
					],
				],
			],
			self::user_groups_table( $db ) => [
				'columns' => [ 'id', 'user_id', 'group_id', 'created_at', 'updated_at' ],
				'indexes' => [
					'PRIMARY'      => [
						'unique'  => true,
						'columns' => [ 'id' ],
					],
					'uq_user_id'   => [
						'unique'  => true,
						'columns' => [ 'user_id' ],
					],
					'idx_group_id' => [
						'unique'  => false,
						'columns' => [ 'group_id' ],
					],
				],
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
			if ( ! is_array( $cols_data ) || empty( $cols_data ) ) {
				$issues[] = sprintf( 'Failed to query columns metadata for table "%s".', $table );
			} else {
				$existing_cols = array_column( $cols_data, 'Field' );
				foreach ( $specs['columns'] as $col ) {
					if ( ! in_array( $col, $existing_cols, true ) ) {
						$issues[] = sprintf( 'Table "%s" is missing column "%s".', $table, $col );
					}
				}
			}

			// Check essential indexes, their uniqueness, and ordered columns.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$index_data = $db->get_results( "SHOW INDEX FROM {$table}", ARRAY_A );
			if ( ! is_array( $index_data ) || empty( $index_data ) ) {
				$issues[] = sprintf( 'Failed to query indexes metadata for table "%s".', $table );
			} else {
				$actual_indexes = [];
				foreach ( $index_data as $row ) {
					$key_name = (string) ( $row['Key_name'] ?? '' );
					if ( '' === $key_name ) {
						continue;
					}
					if ( ! isset( $actual_indexes[ $key_name ] ) ) {
						$actual_indexes[ $key_name ] = [
							'unique'  => 0 === (int) ( $row['Non_unique'] ?? 1 ),
							'columns' => [],
						];
					}
					$seq = (int) ( $row['Seq_in_index'] ?? 1 );
					$actual_indexes[ $key_name ]['columns'][ $seq ] = (string) ( $row['Column_name'] ?? '' );
				}

				foreach ( $actual_indexes as $k => $info ) {
					ksort( $actual_indexes[ $k ]['columns'] );
					$actual_indexes[ $k ]['columns'] = array_values( $actual_indexes[ $k ]['columns'] );
				}

				foreach ( $specs['indexes'] as $expected_idx_name => $expected_spec ) {
					if ( ! isset( $actual_indexes[ $expected_idx_name ] ) ) {
						$issues[] = sprintf( 'Table "%s" is missing index "%s".', $table, $expected_idx_name );
						continue;
					}

					$actual = $actual_indexes[ $expected_idx_name ];

					// Verify index is actually UNIQUE when required.
					if ( $expected_spec['unique'] && ! $actual['unique'] ) {
						$issues[] = sprintf( 'Index "%s" on table "%s" must be UNIQUE.', $expected_idx_name, $table );
					}

					// Verify indexed columns match expected order.
					if ( $actual['columns'] !== $expected_spec['columns'] ) {
						$issues[] = sprintf(
							'Index "%s" on table "%s" has columns [%s], expected [%s].',
							$expected_idx_name,
							$table,
							implode( ', ', $actual['columns'] ),
							implode( ', ', $expected_spec['columns'] )
						);
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
