<?php

declare(strict_types=1);

namespace Tierza\Tests\Unit\Database;

use PHPUnit\Framework\TestCase;
use Tierza\Domain\Exceptions\SchemaMigrationException;
use Tierza\Infrastructure\Database\Schema;

/**
 * Unit tests verifying failure-safe schema migrations.
 */
class SchemaMigrationTest extends TestCase {

	public function test_verify_schema_detects_missing_tables(): void {
		$mock_db = $this->createMock( \wpdb::class );
		$mock_db->prefix = 'wp_';
		// Return null for table check -> missing tables.
		$mock_db->method( 'get_var' )->willReturn( null );

		$issues = Schema::verify_schema( $mock_db );
		$this->assertNotEmpty( $issues );
		$this->assertStringContainsString( 'Missing table', $issues[0] );
	}

	public function test_verify_schema_detects_missing_columns(): void {
		$mock_db = $this->createMock( \wpdb::class );
		$mock_db->prefix = 'wp_';
		$mock_db->method( 'prepare' )->willReturnCallback(
			function ( string $query, ...$args ) {
				$val = $args[0] ?? '';
				return str_replace( '%s', "'" . (string) $val . "'", $query );
			}
		);

		// All tables exist.
		$mock_db->method( 'get_var' )->willReturnCallback(
			function ( string $query ) {
				if ( str_contains( $query, 'SHOW TABLES LIKE' ) ) {
					preg_match( "/'([^']+)'/", $query, $matches );
					return $matches[1] ?? 'wp_tierza_groups';
				}
				return null;
			}
		);

		// Return incomplete columns list missing 'active_key'.
		$mock_db->method( 'get_results' )->willReturnCallback(
			function ( string $query ) {
				if ( str_contains( $query, 'SHOW FULL COLUMNS' ) ) {
					return [
						[ 'Field' => 'id' ],
						[ 'Field' => 'name' ],
						[ 'Field' => 'slug' ],
						[ 'Field' => 'status' ],
						[ 'Field' => 'sort_order' ],
						[ 'Field' => 'created_at' ],
						[ 'Field' => 'updated_at' ],
					];
				}
				if ( str_contains( $query, 'SHOW INDEX' ) ) {
					return [
						[ 'Key_name' => 'PRIMARY' ],
						[ 'Key_name' => 'uq_slug' ],
						[ 'Key_name' => 'uq_active_rule' ],
						[ 'Key_name' => 'uq_user_id' ],
					];
				}
				return [];
			}
		);

		$issues = Schema::verify_schema( $mock_db );
		$this->assertNotEmpty( $issues );
		$found_missing_active_key = false;
		foreach ( $issues as $issue ) {
			if ( str_contains( $issue, 'missing column "active_key"' ) ) {
				$found_missing_active_key = true;
				break;
			}
		}
		$this->assertTrue( $found_missing_active_key, 'verify_schema must flag missing active_key column.' );
	}

	public function test_install_throws_exception_on_verification_failure_without_updating_version(): void {
		$mock_db = $this->createMock( \wpdb::class );
		$mock_db->prefix = 'wp_';
		$mock_db->method( 'get_charset_collate' )->willReturn( 'utf8mb4' );
		$mock_db->method( 'get_var' )->willReturn( null ); // Tables missing.

		update_option( Schema::DB_VERSION_OPTION, '0.0.5' );

		$this->expectException( SchemaMigrationException::class );
		$this->expectExceptionMessage( 'Tierza schema migration failed verification' );

		try {
			Schema::install( $mock_db );
		} finally {
			// Version option must NOT be updated to 1.0.0.
			$this->assertSame( '0.0.5', get_option( Schema::DB_VERSION_OPTION ) );
		}
	}

	public function test_verify_schema_detects_non_unique_index(): void {
		$mock_db         = $this->createMock( \wpdb::class );
		$mock_db->prefix = 'wp_';
		$mock_db->method( 'prepare' )->willReturnCallback(
			function ( string $query, ...$args ) {
				$val = $args[0] ?? '';
				return str_replace( '%s', "'" . (string) $val . "'", $query );
			}
		);
		$mock_db->method( 'get_var' )->willReturnCallback(
			function ( string $query ) {
				if ( str_contains( $query, 'SHOW TABLES LIKE' ) ) {
					preg_match( "/'([^']+)'/", $query, $matches );
					return $matches[1] ?? 'wp_tierza_groups';
				}
				return null;
			}
		);

		// Return complete columns.
		$mock_db->method( 'get_results' )->willReturnCallback(
			function ( string $query ) {
				if ( str_contains( $query, 'SHOW FULL COLUMNS' ) ) {
					return [
						[ 'Field' => 'id' ],
						[ 'Field' => 'target_type' ],
						[ 'Field' => 'target_id' ],
						[ 'Field' => 'scope_type' ],
						[ 'Field' => 'scope_id' ],
						[ 'Field' => 'pricing_type' ],
						[ 'Field' => 'value' ],
						[ 'Field' => 'status' ],
						[ 'Field' => 'active_key' ],
						[ 'Field' => 'name' ],
						[ 'Field' => 'slug' ],
						[ 'Field' => 'sort_order' ],
						[ 'Field' => 'user_id' ],
						[ 'Field' => 'group_id' ],
						[ 'Field' => 'created_at' ],
						[ 'Field' => 'updated_at' ],
					];
				}
				if ( str_contains( $query, 'SHOW INDEX' ) ) {
					return [
						[ 'Key_name' => 'PRIMARY', 'Non_unique' => 0, 'Seq_in_index' => 1, 'Column_name' => 'id' ],
						// uq_active_rule with Non_unique = 1 (NOT UNIQUE!)
						[ 'Key_name' => 'uq_active_rule', 'Non_unique' => 1, 'Seq_in_index' => 1, 'Column_name' => 'active_key' ],
						[ 'Key_name' => 'uq_slug', 'Non_unique' => 0, 'Seq_in_index' => 1, 'Column_name' => 'slug' ],
						[ 'Key_name' => 'uq_user_id', 'Non_unique' => 0, 'Seq_in_index' => 1, 'Column_name' => 'user_id' ],
						[ 'Key_name' => 'idx_status_sort', 'Non_unique' => 1, 'Seq_in_index' => 1, 'Column_name' => 'status' ],
						[ 'Key_name' => 'idx_status_sort', 'Non_unique' => 1, 'Seq_in_index' => 2, 'Column_name' => 'sort_order' ],
						[ 'Key_name' => 'idx_target', 'Non_unique' => 1, 'Seq_in_index' => 1, 'Column_name' => 'target_type' ],
						[ 'Key_name' => 'idx_target', 'Non_unique' => 1, 'Seq_in_index' => 2, 'Column_name' => 'target_id' ],
						[ 'Key_name' => 'idx_target', 'Non_unique' => 1, 'Seq_in_index' => 3, 'Column_name' => 'status' ],
						[ 'Key_name' => 'idx_scope', 'Non_unique' => 1, 'Seq_in_index' => 1, 'Column_name' => 'scope_type' ],
						[ 'Key_name' => 'idx_scope', 'Non_unique' => 1, 'Seq_in_index' => 2, 'Column_name' => 'scope_id' ],
						[ 'Key_name' => 'idx_scope', 'Non_unique' => 1, 'Seq_in_index' => 3, 'Column_name' => 'status' ],
						[ 'Key_name' => 'idx_lookup', 'Non_unique' => 1, 'Seq_in_index' => 1, 'Column_name' => 'target_type' ],
						[ 'Key_name' => 'idx_lookup', 'Non_unique' => 1, 'Seq_in_index' => 2, 'Column_name' => 'target_id' ],
						[ 'Key_name' => 'idx_lookup', 'Non_unique' => 1, 'Seq_in_index' => 3, 'Column_name' => 'scope_type' ],
						[ 'Key_name' => 'idx_lookup', 'Non_unique' => 1, 'Seq_in_index' => 4, 'Column_name' => 'scope_id' ],
						[ 'Key_name' => 'idx_lookup', 'Non_unique' => 1, 'Seq_in_index' => 5, 'Column_name' => 'status' ],
						[ 'Key_name' => 'idx_group_id', 'Non_unique' => 1, 'Seq_in_index' => 1, 'Column_name' => 'group_id' ],
					];
				}
				return [];
			}
		);

		$issues = Schema::verify_schema( $mock_db );
		$this->assertNotEmpty( $issues );
		$found_non_unique = false;
		foreach ( $issues as $issue ) {
			if ( str_contains( $issue, 'Index "uq_active_rule" on table "wp_tierza_rules" must be UNIQUE' ) ) {
				$found_non_unique = true;
				break;
			}
		}
		$this->assertTrue( $found_non_unique, 'verify_schema must flag index when uniqueness requirement is not satisfied.' );
	}

	public function test_prepare_and_backfill_detects_conflicts_and_throws_exception(): void {
		$mock_db         = $this->createMock( \wpdb::class );
		$mock_db->prefix = 'wp_';
		$mock_db->method( 'prepare' )->willReturnCallback(
			function ( string $query, ...$args ) {
				$val = $args[0] ?? '';
				return str_replace( '%s', "'" . (string) $val . "'", $query );
			}
		);
		$mock_db->method( 'get_var' )->willReturn( 'wp_tierza_rules' ); // Table exists.
		$mock_db->method( 'get_results' )->willReturnCallback(
			function ( string $query ) {
				if ( str_contains( $query, 'SHOW FULL COLUMNS' ) ) {
					return [ [ 'Field' => 'active_key' ] ];
				}
				if ( str_contains( $query, 'HAVING conflict_count > 1' ) ) {
					return [
						[
							'target_type'     => 'group',
							'target_id'       => 1,
							'scope_type'      => 'global',
							'scope_id'        => 0,
							'conflict_count'  => 2,
							'conflicting_ids' => '10,11',
						],
					];
				}
				return [];
			}
		);

		$this->expectException( SchemaMigrationException::class );
		$this->expectExceptionMessage( 'detected 1 conflicting active pricing rule(s)' );

		Schema::prepare_and_backfill_rules( $mock_db );
	}
}
