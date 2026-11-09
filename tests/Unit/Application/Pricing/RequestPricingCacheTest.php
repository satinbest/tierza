<?php

declare(strict_types=1);

namespace Tierza\Tests\Unit\Application\Pricing;

use PHPUnit\Framework\TestCase;
use Tierza\Application\Pricing\Cache\RequestPricingCache;
use Tierza\Domain\Entities\PricingGroup;
use Tierza\Domain\Entities\PricingRule;
use Tierza\Domain\Pricing\Enums\PrecedenceLevel;
use Tierza\Domain\Pricing\Enums\PricingSource;
use Tierza\Domain\Pricing\PricingResult;
use Tierza\Domain\Pricing\ResolutionResult;
use Tierza\Domain\ValueObjects\GroupSlug;
use Tierza\Domain\ValueObjects\GroupStatus;
use Tierza\Domain\ValueObjects\Money;
use Tierza\Domain\ValueObjects\PricingValue;
use Tierza\Domain\ValueObjects\RuleScope;
use Tierza\Domain\ValueObjects\RuleTarget;

/**
 * Unit tests for RequestPricingCache (TRZ-009).
 */
final class RequestPricingCacheTest extends TestCase {

	private RequestPricingCache $cache;

	protected function setUp(): void {
		parent::setUp();
		$this->cache = new RequestPricingCache();
	}

	public function test_candidate_rules_lifecycle_and_stats(): void {
		$target_key = 'targets:user:1';
		$rule = new PricingRule(
			RuleTarget::user( 1 ),
			RuleScope::global(),
			PricingValue::percentage( '10' )
		);

		$this->assertFalse( $this->cache->has_candidate_rules( $target_key ) );
		$this->assertNull( $this->cache->get_candidate_rules( $target_key ) );

		$this->cache->set_candidate_rules( $target_key, [ $rule ] );

		$this->assertTrue( $this->cache->has_candidate_rules( $target_key ) );
		$retrieved = $this->cache->get_candidate_rules( $target_key );
		$this->assertNotNull( $retrieved );
		$this->assertCount( 1, $retrieved );
		$this->assertSame( $rule, $retrieved[0] );

		$stats = $this->cache->stats();
		$this->assertSame( 1, $stats['candidate_hits'] );
		$this->assertSame( 1, $stats['candidate_misses'] );
		$this->assertSame( 1, $stats['candidate_count'] );
	}

	public function test_resolution_lifecycle_and_stats(): void {
		$context_key = 'cust:auth:1#grp:none#prod:10#var:none#cats:none#qty:1#base:100.000000#reg:none#sale:none#promo:auto';
		$rule = new PricingRule(
			RuleTarget::user( 1 ),
			RuleScope::product( 10 ),
			PricingValue::percentage( '20' )
		);
		$resolution = ResolutionResult::matched( $rule, PrecedenceLevel::CUSTOMER_PRODUCT );

		$this->assertFalse( $this->cache->has_resolution( $context_key ) );
		$this->assertNull( $this->cache->get_resolution( $context_key ) );

		$this->cache->set_resolution( $context_key, $resolution );

		$this->assertTrue( $this->cache->has_resolution( $context_key ) );
		$retrieved = $this->cache->get_resolution( $context_key );
		$this->assertSame( $resolution, $retrieved );

		$stats = $this->cache->stats();
		$this->assertSame( 1, $stats['resolution_hits'] );
		$this->assertSame( 1, $stats['resolution_misses'] );
		$this->assertSame( 1, $stats['resolution_count'] );
	}

	public function test_calculated_price_lifecycle_and_stats(): void {
		$calc_key = 'cust:auth:1#grp:none#prod:10#var:none#cats:none#qty:1#base:100.000000#reg:none#sale:none#promo:auto#curr:USD#dec:2';
		$rule = new PricingRule( RuleTarget::user( 1 ), RuleScope::global(), PricingValue::percentage( '15' ) );
		$pricing_res = new PricingResult(
			Money::from( '100.00', 'USD' ),
			Money::from( '85.00', 'USD' ),
			1,
			'percentage',
			'global',
			PricingSource::CUSTOMER,
			true,
			false,
			'TEST'
		);

		$this->assertFalse( $this->cache->has_calculated_price( $calc_key ) );
		$this->assertNull( $this->cache->get_calculated_price( $calc_key ) );

		$this->cache->set_calculated_price( $calc_key, $pricing_res );

		$this->assertTrue( $this->cache->has_calculated_price( $calc_key ) );
		$this->assertSame( $pricing_res, $this->cache->get_calculated_price( $calc_key ) );

		$stats = $this->cache->stats();
		$this->assertSame( 1, $stats['calculation_hits'] );
		$this->assertSame( 1, $stats['calculation_misses'] );
	}

	public function test_group_status_lifecycle(): void {
		$this->assertNull( $this->cache->get_group_status( 99 ) );

		$group = new PricingGroup( 'VIP', new GroupSlug( 'vip-status' ), 99, null, GroupStatus::active() );
		$this->cache->set_group_status( 99, true, true, $group );

		$status = $this->cache->get_group_status( 99 );
		$this->assertNotNull( $status );
		$this->assertTrue( $status['exists'] );
		$this->assertTrue( $status['is_active'] );
		$this->assertSame( $group, $status['group'] );
	}

	public function test_invalidate_rules_clears_candidate_and_resolution_caches(): void {
		$this->cache->set_candidate_rules( 'targets:user:1', [] );
		$this->cache->set_resolution( 'res:1', ResolutionResult::fallback( 'none' ) );
		$rule = new PricingRule( RuleTarget::user( 1 ), RuleScope::global(), PricingValue::percentage( '10' ) );
		$this->cache->set_calculated_price(
			'calc:1',
			new PricingResult(
				Money::from( '10.00', 'USD' ),
				Money::from( '9.00', 'USD' ),
				1,
				'percentage',
				'global',
				PricingSource::CUSTOMER,
				true,
				false,
				'TEST'
			)
		);
		$this->cache->set_group_status( 5, true, true, null );

		$this->cache->invalidate_rules();

		$this->assertFalse( $this->cache->has_candidate_rules( 'targets:user:1' ) );
		$this->assertFalse( $this->cache->has_resolution( 'res:1' ) );
		$this->assertFalse( $this->cache->has_calculated_price( 'calc:1' ) );
		// Group statuses are preserved on rule invalidation.
		$this->assertNotNull( $this->cache->get_group_status( 5 ) );
	}

	public function test_invalidate_groups_clears_group_status_and_rules(): void {
		$this->cache->set_candidate_rules( 'targets:group:5', [] );
		$this->cache->set_resolution( 'res:5', ResolutionResult::fallback( 'none' ) );
		$this->cache->set_group_status( 5, true, true, null );

		$this->cache->invalidate_groups();

		$this->assertNull( $this->cache->get_group_status( 5 ) );
		$this->assertFalse( $this->cache->has_candidate_rules( 'targets:group:5' ) );
		$this->assertFalse( $this->cache->has_resolution( 'res:5' ) );
	}

	public function test_exact_customer_invalidation_boundary_regression_12_vs_123(): void {
		// Populate entries for customer 1, 10, 12, 123, 1234, and guest (0).
		$customers = [ 1, 10, 12, 123, 1234 ];
		foreach ( $customers as $id ) {
			$this->cache->set_candidate_rules( "targets:group:1|user:{$id}", [] );
			$this->cache->set_resolution( "cust:auth:{$id}#grp:1#prod:10#var:none#cats:none", ResolutionResult::fallback( 'none' ) );
			$this->cache->set_calculated_price(
				"cust:auth:{$id}#grp:1#prod:10#var:none#cats:none#qty:1#base:100.000000#reg:none#sale:none#promo:auto#curr:USD#dec:2",
				new PricingResult( Money::from( '100', 'USD' ), Money::from( '100', 'USD' ), null, null, null, PricingSource::WOOCOMMERCE_FALLBACK, false, false, 'none' )
			);
		}

		// Also populate guest entry.
		$this->cache->set_resolution( 'cust:guest:0#grp:none#prod:10#var:none#cats:none', ResolutionResult::fallback( 'none' ) );

		// Invalidate customer 12 strictly.
		$this->cache->invalidate_customer( 12 );

		// Customer 12 must be completely evicted across all tiers.
		$this->assertFalse( $this->cache->has_candidate_rules( 'targets:group:1|user:12' ) );
		$this->assertFalse( $this->cache->has_resolution( 'cust:auth:12#grp:1#prod:10#var:none#cats:none' ) );
		$this->assertFalse( $this->cache->has_calculated_price( 'cust:auth:12#grp:1#prod:10#var:none#cats:none#qty:1#base:100.000000#reg:none#sale:none#promo:auto#curr:USD#dec:2' ) );

		// Customer 123, 1234, 1, 10, and Guest must remain 100% intact!
		foreach ( [ 1, 10, 123, 1234 ] as $unaffected_id ) {
			$this->assertTrue(
				$this->cache->has_candidate_rules( "targets:group:1|user:{$unaffected_id}" ),
				"Candidate rules for customer {$unaffected_id} must remain untouched when customer 12 is invalidated."
			);
			$this->assertTrue(
				$this->cache->has_resolution( "cust:auth:{$unaffected_id}#grp:1#prod:10#var:none#cats:none" ),
				"Resolution for customer {$unaffected_id} must remain untouched when customer 12 is invalidated."
			);
			$this->assertTrue(
				$this->cache->has_calculated_price( "cust:auth:{$unaffected_id}#grp:1#prod:10#var:none#cats:none#qty:1#base:100.000000#reg:none#sale:none#promo:auto#curr:USD#dec:2" ),
				"Calculation for customer {$unaffected_id} must remain untouched when customer 12 is invalidated."
			);
		}

		$this->assertTrue( $this->cache->has_resolution( 'cust:guest:0#grp:none#prod:10#var:none#cats:none' ), 'Guest entries must remain untouched.' );

		// Next: Invalidate customer 1 strictly.
		$this->cache->invalidate_customer( 1 );
		$this->assertFalse( $this->cache->has_resolution( 'cust:auth:1#grp:1#prod:10#var:none#cats:none' ) );
		$this->assertTrue( $this->cache->has_resolution( 'cust:auth:10#grp:1#prod:10#var:none#cats:none' ), 'Customer 10 must not be evicted when customer 1 is invalidated.' );
		$this->assertTrue( $this->cache->has_resolution( 'cust:auth:123#grp:1#prod:10#var:none#cats:none' ) );

		// Next: Invalidate guest (0).
		$this->cache->invalidate_customer( 0 );
		$this->assertFalse( $this->cache->has_resolution( 'cust:guest:0#grp:none#prod:10#var:none#cats:none' ) );
		$this->assertTrue( $this->cache->has_resolution( 'cust:auth:10#grp:1#prod:10#var:none#cats:none' ) );
	}

	public function test_group_status_bounded_fifo_eviction_and_memory(): void {
		$cache = new RequestPricingCache( max_group_entries: 500 );

		$mem_before = memory_get_usage();

		// Insert 550 distinct group statuses.
		for ( $g = 1; $g <= 550; $g++ ) {
			$group = new PricingGroup( "Group {$g}", new GroupSlug( "group-{$g}" ), $g, null, GroupStatus::active() );
			$cache->set_group_status( $g, true, true, $group );
		}

		$mem_after = memory_get_usage();
		$delta_kb  = ( $mem_after - $mem_before ) / 1024;

		// Group count must be strictly bounded to 500 entries.
		$this->assertSame( 500, $cache->stats()['group_count'], 'Group statuses must be bounded to maximum 500 entries.' );

		// Oldest 50 entries (1..50) must have been evicted.
		for ( $g = 1; $g <= 50; $g++ ) {
			$this->assertNull(
				$cache->get_group_status( $g ),
				"Group {$g} should have been evicted by FIFO."
			);
		}

		// Entries 51..550 must remain retained.
		for ( $g = 51; $g <= 550; $g++ ) {
			$status = $cache->get_group_status( $g );
			$this->assertNotNull( $status, "Group {$g} must be retained in cache." );
			$this->assertTrue( $status['exists'] );
			$this->assertTrue( $status['is_active'] );
			$this->assertSame( $g, $status['group']?->id() );
		}

		// Updating an existing entry should not prune or grow.
		$updated_group = new PricingGroup( 'Group 550 Updated', new GroupSlug( 'group-550-updated' ), 550, null, GroupStatus::inactive() );
		$cache->set_group_status( 550, true, false, $updated_group );
		$this->assertSame( 500, $cache->stats()['group_count'] );
		$this->assertFalse( $cache->get_group_status( 550 )['is_active'] );

		// Memory check: 500 entries must not exceed realistic bound (typically ~150-300 KB).
		$this->assertLessThan( 2048, $delta_kb, '500 group entries should consume far less than 2 MB.' );
	}

	public function test_invalidate_product_only_evicts_target_product(): void {
		$this->cache->set_resolution( 'cust:auth:1#grp:none#prod:100#var:none', ResolutionResult::fallback( 'none' ) );
		$this->cache->set_resolution( 'cust:auth:1#grp:none#prod:200#var:none', ResolutionResult::fallback( 'none' ) );

		$this->cache->invalidate_product( 100 );

		$this->assertFalse( $this->cache->has_resolution( 'cust:auth:1#grp:none#prod:100#var:none' ) );
		$this->assertTrue( $this->cache->has_resolution( 'cust:auth:1#grp:none#prod:200#var:none' ) );
	}

	public function test_memory_bounding_fifo_eviction(): void {
		// Create cache with small maximum capacity of 50 entries.
		$small_cache = new RequestPricingCache( max_resolution_entries: 50 );

		for ( $i = 1; $i <= 60; $i++ ) {
			$small_cache->set_resolution( 'key:' . $i, ResolutionResult::fallback( 'none' ) );
		}

		$stats = $small_cache->stats();
		$this->assertSame( 50, $stats['resolution_count'] );

		// The first 10 entries should have been pruned by FIFO.
		$this->assertFalse( $small_cache->has_resolution( 'key:1' ) );
		$this->assertFalse( $small_cache->has_resolution( 'key:10' ) );
		$this->assertTrue( $small_cache->has_resolution( 'key:60' ) );
	}

	public function test_clear_resets_everything(): void {
		$this->cache->set_candidate_rules( 'k1', [] );
		$this->cache->set_resolution( 'k2', ResolutionResult::fallback( 'none' ) );
		$this->cache->get_candidate_rules( 'k1' ); // hit
		$this->cache->get_candidate_rules( 'miss' ); // miss

		$this->cache->clear();

		$stats = $this->cache->stats();
		$this->assertSame( 0, $stats['hits'] );
		$this->assertSame( 0, $stats['misses'] );
		$this->assertSame( 0, $stats['candidate_count'] );
		$this->assertSame( 0, $stats['resolution_count'] );
	}
}
