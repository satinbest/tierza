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

	public function test_invalidate_customer_only_evicts_target_customer(): void {
		$this->cache->set_candidate_rules( 'targets:group:1|user:10', [] );
		$this->cache->set_candidate_rules( 'targets:group:1|user:20', [] );

		$this->cache->set_resolution( 'cust:auth:10#grp:none#prod:1#var:none', ResolutionResult::fallback( 'none' ) );
		$this->cache->set_resolution( 'cust:auth:20#grp:none#prod:1#var:none', ResolutionResult::fallback( 'none' ) );

		$this->cache->invalidate_customer( 10 );

		$this->assertFalse( $this->cache->has_candidate_rules( 'targets:group:1|user:10' ) );
		$this->assertTrue( $this->cache->has_candidate_rules( 'targets:group:1|user:20' ) );

		$this->assertFalse( $this->cache->has_resolution( 'cust:auth:10#grp:none#prod:1#var:none' ) );
		$this->assertTrue( $this->cache->has_resolution( 'cust:auth:20#grp:none#prod:1#var:none' ) );
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
