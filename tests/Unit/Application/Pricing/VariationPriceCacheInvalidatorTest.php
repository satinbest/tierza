<?php

declare(strict_types=1);

namespace Tierza\Tests\Unit\Application\Pricing;

use PHPUnit\Framework\TestCase;
use Tierza\Application\Pricing\Cache\VariationPriceCacheInvalidator;
use Tierza\Domain\Entities\PricingRule;
use Tierza\Domain\ValueObjects\PricingValue;
use Tierza\Domain\ValueObjects\RuleScope;
use Tierza\Domain\ValueObjects\RuleTarget;
use wpdb;

/**
 * Unit tests for VariationPriceCacheInvalidator.
 */
class VariationPriceCacheInvalidatorTest extends TestCase {

	private wpdb $db;
	private VariationPriceCacheInvalidator $invalidator;

	protected function setUp(): void {
		parent::setUp();

		global $wpdb;
		$this->db          = $wpdb;
		$this->invalidator = new VariationPriceCacheInvalidator( $this->db );
	}

	public function test_initial_version_defaults_to_one(): void {
		$version = $this->invalidator->get_version();
		$this->assertGreaterThanOrEqual( 1, $version );
	}

	public function test_increment_version_advances_counter(): void {
		$initial = $this->invalidator->get_version();
		$next    = $this->invalidator->increment_version();

		$this->assertSame( $initial + 1, $next );
		$this->assertSame( $next, $this->invalidator->get_version() );
	}

	public function test_invalidate_for_product_scope_rule(): void {
		$rule = new PricingRule(
			RuleTarget::user( 10 ),
			RuleScope::product( 200 ),
			PricingValue::percentage( '10.000000' )
		);

		$initial = $this->invalidator->get_version();
		$this->invalidator->invalidate_for_rule( $rule );

		$this->assertSame( $initial + 1, $this->invalidator->get_version() );
	}

	public function test_invalidate_for_variation_scope_rule(): void {
		$rule = new PricingRule(
			RuleTarget::user( 10 ),
			RuleScope::variation( 201 ),
			PricingValue::fixed( '25.000000' )
		);

		$initial = $this->invalidator->get_version();
		$this->invalidator->invalidate_for_rule( $rule );

		$this->assertSame( $initial + 1, $this->invalidator->get_version() );
	}

	public function test_invalidate_for_global_or_category_rule(): void {
		$rule = new PricingRule(
			RuleTarget::group( 1 ),
			RuleScope::category( 5 ),
			PricingValue::percentage( '15.000000' )
		);

		$initial = $this->invalidator->get_version();
		$this->invalidator->invalidate_for_rule( $rule );

		$this->assertSame( $initial + 1, $this->invalidator->get_version() );
	}

	public function test_invalidate_for_group_and_customer(): void {
		$v1 = $this->invalidator->get_version();
		$this->invalidator->invalidate_for_group( 3 );
		$this->assertSame( $v1 + 1, $this->invalidator->get_version() );

		$v2 = $this->invalidator->get_version();
		$this->invalidator->invalidate_for_customer( 42 );
		$this->assertSame( $v2 + 1, $this->invalidator->get_version() );
	}

	public function test_database_write_failure_throws_exception_and_does_not_advance_version(): void {
		$failing_db = $this->createMock( wpdb::class );
		$failing_db->options = 'wp_options';
		$failing_db->method( 'prepare' )->willReturnCallback( fn( $q ) => $q );
		$failing_db->method( 'get_var' )->willReturn( '5' );
		$failing_db->method( 'query' )->willReturnCallback(
			function() use ( $failing_db ) {
				$failing_db->last_error = 'Simulated connection lost error';
				return false;
			}
		);

		$invalidator = new VariationPriceCacheInvalidator( $failing_db );
		$initial_version = $invalidator->get_version();
		$this->assertSame( 5, $initial_version );

		$this->expectException( \Tierza\Infrastructure\Exceptions\DatabaseQueryException::class );
		$this->expectExceptionMessage( 'Failed to increment variation cache version' );

		try {
			$invalidator->increment_version();
		} finally {
			// Version was not corrupted
			$this->assertSame( $initial_version, $invalidator->get_version() );
		}
	}

	public function test_database_verification_failure_throws_exception(): void {
		$failing_db = $this->createMock( wpdb::class );
		$failing_db->options = 'wp_options';
		$failing_db->method( 'prepare' )->willReturnCallback( fn( $q ) => $q );
		$failing_db->method( 'query' )->willReturn( 1 );
		$failing_db->method( 'get_var' )->willReturn( null ); // Verification fails

		$invalidator = new VariationPriceCacheInvalidator( $failing_db );

		$this->expectException( \Tierza\Infrastructure\Exceptions\DatabaseQueryException::class );
		$this->expectExceptionMessage( 'Failed to verify updated variation cache version' );

		$invalidator->increment_version();
	}

	public function test_get_version_throws_database_query_exception_on_sql_read_failure_and_does_not_fallback(): void {
		$failing_db = $this->createMock( wpdb::class );
		$failing_db->options = 'wp_options';
		$failing_db->method( 'prepare' )->willReturnCallback( fn( $q ) => $q );
		$failing_db->method( 'get_var' )->willReturnCallback(
			function() use ( $failing_db ) {
				$failing_db->last_error = 'Table wp_options does not exist';
				return null;
			}
		);

		$invalidator = new VariationPriceCacheInvalidator( $failing_db );

		$this->expectException( \Tierza\Infrastructure\Exceptions\DatabaseQueryException::class );
		$this->expectExceptionMessage( 'Failed to read variation cache version from database: Table wp_options does not exist' );

		$invalidator->get_version();
	}

	public function test_get_version_initializes_to_one_on_clean_empty_table(): void {
		$clean_db = $this->createMock( wpdb::class );
		$clean_db->options = 'wp_options';
		$clean_db->last_error = '';
		$clean_db->method( 'prepare' )->willReturnCallback( fn( $q ) => $q );
		$clean_db->method( 'get_var' )->willReturn( null ); // Clean query, 0 rows

		$invalidator = new VariationPriceCacheInvalidator( $clean_db );
		$this->assertSame( 1, $invalidator->get_version() );
	}

	public function test_clean_all_variation_transients_throws_database_query_exception_on_delete_failure(): void {
		$failing_db = $this->createMock( wpdb::class );
		$failing_db->options = 'wp_options';
		$failing_db->method( 'query' )->willReturnCallback(
			function() use ( $failing_db ) {
				$failing_db->last_error = 'Deadlock found when trying to get lock';
				return false;
			}
		);

		$invalidator = new VariationPriceCacheInvalidator( $failing_db );

		$this->expectException( \Tierza\Infrastructure\Exceptions\DatabaseQueryException::class );
		$this->expectExceptionMessage( 'Failed to clean variation transients from database: Deadlock found' );

		$invalidator->clean_all_variation_transients();
	}
}
