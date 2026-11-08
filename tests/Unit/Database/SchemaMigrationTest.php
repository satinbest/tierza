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
}
