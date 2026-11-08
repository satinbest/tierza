<?php

declare(strict_types=1);

namespace Tierza\Tests\Unit\Application\Pricing;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tierza\Application\Pricing\Batch\PricingBatchPrefetcher;
use Tierza\Application\Pricing\Cache\RequestPricingCache;
use Tierza\Application\Pricing\DeterministicRuleResolver;
use Tierza\Domain\Entities\PricingGroup;
use Tierza\Domain\Entities\PricingRule;
use Tierza\Domain\Exceptions\RuleResolutionException;
use Tierza\Domain\Pricing\Enums\PrecedenceLevel;
use Tierza\Domain\Pricing\Enums\PricingReasonCode;
use Tierza\Domain\Pricing\PricingContext;
use Tierza\Domain\ValueObjects\GroupSlug;
use Tierza\Domain\ValueObjects\GroupStatus;
use Tierza\Domain\ValueObjects\Money;
use Tierza\Domain\ValueObjects\PricingValue;
use Tierza\Domain\ValueObjects\RuleScope;
use Tierza\Domain\ValueObjects\RuleTarget;
use Tierza\Infrastructure\Repositories\CachedRuleRepository;

/**
 * Comprehensive unit tests for pricing cache, batch prefetching, and repository decorators (TRZ-009).
 */
final class PricingCacheAndBatchPrefetchTest extends TestCase {

	private InMemoryRuleRepository $rule_repo;
	private InMemoryGroupRepository $group_repo;
	private RequestPricingCache $cache;
	private DeterministicRuleResolver $resolver;

	protected function setUp(): void {
		parent::setUp();
		$this->rule_repo  = new InMemoryRuleRepository();
		$this->group_repo = new InMemoryGroupRepository();
		$this->cache      = new RequestPricingCache();
		$this->resolver   = new DeterministicRuleResolver(
			$this->rule_repo,
			$this->group_repo,
			$this->cache
		);
	}

	public function test_cold_cache_queries_repository_and_warms_cache(): void {
		$group = new PricingGroup( 'B2B Gold', new GroupSlug( 'b2b-gold' ), 1, null, GroupStatus::active() );
		$this->group_repo->save( $group );

		$rule = new PricingRule(
			RuleTarget::group( 1 ),
			RuleScope::product( 100 ),
			PricingValue::percentage( '20' )
		);
		$this->rule_repo->save( $rule );

		$ctx = new PricingContext(
			product_id: 100,
			base_price: Money::from( '100.00', 'USD' ),
			customer_id: 10,
			group: $group
		);

		// Cold resolution.
		$res1 = $this->resolver->resolve( $ctx );
		$this->assertTrue( $res1->has_rule() );
		$this->assertSame( PrecedenceLevel::GROUP_PRODUCT, $res1->precedence_level() );

		$stats1 = $this->cache->stats();
		$this->assertSame( 1, $stats1['resolution_count'] );
		$this->assertSame( 1, $stats1['candidate_count'] );

		// Warm resolution (same context).
		$res2 = $this->resolver->resolve( $ctx );
		$this->assertTrue( $res2->has_rule() );
		$this->assertSame( $res1->rule()?->id(), $res2->rule()?->id() );

		$stats2 = $this->cache->stats();
		// Resolution hit counter incremented without hitting DB.
		$this->assertSame( 1, $stats2['resolution_hits'] );
	}

	public function test_repeated_identical_lookups_produce_100_percent_cache_hits(): void {
		$group = new PricingGroup( 'Tier A', new GroupSlug( 'tier-a' ), 1, null, GroupStatus::active() );
		$this->group_repo->save( $group );

		$rule = new PricingRule( RuleTarget::group( 1 ), RuleScope::global(), PricingValue::percentage( '15' ) );
		$this->rule_repo->save( $rule );

		$ctx = new PricingContext(
			product_id: 50,
			base_price: Money::from( '80.00', 'USD' ),
			customer_id: 5,
			group: $group
		);

		// Initial lookup.
		$this->resolver->resolve( $ctx );

		// 99 repeated lookups.
		for ( $i = 0; $i < 99; $i++ ) {
			$res = $this->resolver->resolve( $ctx );
			$this->assertTrue( $res->has_rule() );
		}

		$stats = $this->cache->stats();
		$this->assertSame( 99, $stats['resolution_hits'] );
		$this->assertSame( 1, $stats['resolution_misses'] );
	}

	public function test_customer_isolation_never_leaks_between_different_users(): void {
		// User 1 gets 30% discount on product 10.
		$rule_user1 = new PricingRule(
			RuleTarget::user( 1 ),
			RuleScope::product( 10 ),
			PricingValue::percentage( '30' )
		);
		$this->rule_repo->save( $rule_user1 );

		// User 2 gets 10% discount on product 10.
		$rule_user2 = new PricingRule(
			RuleTarget::user( 2 ),
			RuleScope::product( 10 ),
			PricingValue::percentage( '10' )
		);
		$this->rule_repo->save( $rule_user2 );

		$ctx_user1 = new PricingContext( product_id: 10, base_price: Money::from( '100.00', 'USD' ), customer_id: 1 );
		$ctx_user2 = new PricingContext( product_id: 10, base_price: Money::from( '100.00', 'USD' ), customer_id: 2 );

		// Resolve for User 1.
		$res1 = $this->resolver->resolve( $ctx_user1 );
		$this->assertSame( '30.000000', $res1->rule()?->pricing_value()->value() );

		// Resolve for User 2 (must not receive User 1's 30% discount).
		$res2 = $this->resolver->resolve( $ctx_user2 );
		$this->assertSame( '10.000000', $res2->rule()?->pricing_value()->value() );

		// Repeat User 1 and User 2 resolutions from warm cache.
		$res1_warm = $this->resolver->resolve( $ctx_user1 );
		$this->assertSame( '30.000000', $res1_warm->rule()?->pricing_value()->value() );

		$res2_warm = $this->resolver->resolve( $ctx_user2 );
		$this->assertSame( '10.000000', $res2_warm->rule()?->pricing_value()->value() );
	}

	public function test_guest_isolation_never_receives_authenticated_rules(): void {
		// Rule configured for User 100.
		$rule_auth = new PricingRule( RuleTarget::user( 100 ), RuleScope::global(), PricingValue::percentage( '50' ) );
		$this->rule_repo->save( $rule_auth );

		$ctx_auth  = new PricingContext( product_id: 10, base_price: Money::from( '100.00', 'USD' ), customer_id: 100 );
		$ctx_guest = new PricingContext( product_id: 10, base_price: Money::from( '100.00', 'USD' ), customer_id: 0 );

		// Resolve auth customer.
		$res_auth = $this->resolver->resolve( $ctx_auth );
		$this->assertTrue( $res_auth->has_rule() );

		// Resolve guest customer.
		$res_guest = $this->resolver->resolve( $ctx_guest );
		$this->assertFalse( $res_guest->has_rule() );
		$this->assertSame( PricingReasonCode::FALLBACK_ANONYMOUS_NO_RULE->value, $res_guest->reason_code() );
	}

	public function test_group_isolation_between_different_groups(): void {
		$group_a = new PricingGroup( 'Group A', new GroupSlug( 'group-a' ), 1, null, GroupStatus::active() );
		$group_b = new PricingGroup( 'Group B', new GroupSlug( 'group-b' ), 2, null, GroupStatus::active() );
		$this->group_repo->save( $group_a );
		$this->group_repo->save( $group_b );

		$rule_a = new PricingRule( RuleTarget::group( 1 ), RuleScope::global(), PricingValue::percentage( '10' ) );
		$rule_b = new PricingRule( RuleTarget::group( 2 ), RuleScope::global(), PricingValue::percentage( '25' ) );
		$this->rule_repo->save( $rule_a );
		$this->rule_repo->save( $rule_b );

		$ctx_a = new PricingContext( product_id: 10, base_price: Money::from( '100.00', 'USD' ), customer_id: 1, group: $group_a );
		$ctx_b = new PricingContext( product_id: 10, base_price: Money::from( '100.00', 'USD' ), customer_id: 2, group: $group_b );

		$res_a = $this->resolver->resolve( $ctx_a );
		$res_b = $this->resolver->resolve( $ctx_b );

		$this->assertSame( '10.000000', $res_a->rule()?->pricing_value()->value() );
		$this->assertSame( '25.000000', $res_b->rule()?->pricing_value()->value() );
	}

	public function test_customer_group_reassignment_invalidation(): void {
		$group1 = new PricingGroup( 'Silver', new GroupSlug( 'silver' ), 1, null, GroupStatus::active() );
		$group2 = new PricingGroup( 'Gold', new GroupSlug( 'gold' ), 2, null, GroupStatus::active() );
		$this->group_repo->save( $group1 );
		$this->group_repo->save( $group2 );

		$this->rule_repo->save( new PricingRule( RuleTarget::group( 1 ), RuleScope::global(), PricingValue::percentage( '10' ) ) );
		$this->rule_repo->save( new PricingRule( RuleTarget::group( 2 ), RuleScope::global(), PricingValue::percentage( '30' ) ) );

		// Customer initially in Group 1.
		$ctx1 = new PricingContext( product_id: 10, base_price: Money::from( '100.00', 'USD' ), customer_id: 42, group: $group1 );
		$res1 = $this->resolver->resolve( $ctx1 );
		$this->assertSame( '10.000000', $res1->rule()?->pricing_value()->value() );

		// Customer reassigned to Group 2, invalidating customer cache.
		$this->cache->invalidate_customer( 42 );

		$ctx2 = new PricingContext( product_id: 10, base_price: Money::from( '100.00', 'USD' ), customer_id: 42, group: $group2 );
		$res2 = $this->resolver->resolve( $ctx2 );
		$this->assertSame( '30.000000', $res2->rule()?->pricing_value()->value() );
	}

	public function test_rule_deactivation_invalidation_within_same_request(): void {
		$rule = new PricingRule( RuleTarget::user( 10 ), RuleScope::product( 100 ), PricingValue::percentage( '25' ) );
		$saved_rule = $this->rule_repo->save( $rule );

		$ctx = new PricingContext( product_id: 100, base_price: Money::from( '100.00', 'USD' ), customer_id: 10 );

		// Initial resolution: matched.
		$res1 = $this->resolver->resolve( $ctx );
		$this->assertTrue( $res1->has_rule() );

		// Deactivate rule and invalidate cache.
		$this->rule_repo->save( $saved_rule->deactivate() );
		$this->cache->invalidate_rules();

		// Next resolution in same request: fallback.
		$res2 = $this->resolver->resolve( $ctx );
		$this->assertFalse( $res2->has_rule() );
		$this->assertSame( PrecedenceLevel::WOOCOMMERCE_FALLBACK, $res2->precedence_level() );
	}

	public function test_rule_creation_invalidation_overrides_immediately(): void {
		$group = new PricingGroup( 'Bronze', new GroupSlug( 'bronze' ), 1, null, GroupStatus::active() );
		$this->group_repo->save( $group );

		// Group global rule gives 10% (Precedence Level 8).
		$this->rule_repo->save( new PricingRule( RuleTarget::group( 1 ), RuleScope::global(), PricingValue::percentage( '10' ) ) );

		$ctx = new PricingContext( product_id: 50, base_price: Money::from( '100.00', 'USD' ), customer_id: 7, group: $group );

		$res1 = $this->resolver->resolve( $ctx );
		$this->assertSame( PrecedenceLevel::GROUP_GLOBAL, $res1->precedence_level() );

		// New higher priority customer-specific product rule (Level 2) created.
		$this->rule_repo->save( new PricingRule( RuleTarget::user( 7 ), RuleScope::product( 50 ), PricingValue::percentage( '40' ) ) );
		$this->cache->invalidate_rules();

		$res2 = $this->resolver->resolve( $ctx );
		$this->assertSame( PrecedenceLevel::CUSTOMER_PRODUCT, $res2->precedence_level() );
		$this->assertSame( '40.000000', $res2->rule()?->pricing_value()->value() );
	}

	public function test_inactive_and_missing_group_handling(): void {
		// Inactive group:
		$inactive = new PricingGroup( 'Inactive', new GroupSlug( 'inactive' ), 2, null, GroupStatus::inactive() );
		$this->group_repo->save( $inactive );
		$this->rule_repo->save( new PricingRule( RuleTarget::group( 2 ), RuleScope::global(), PricingValue::percentage( '20' ) ) );

		$ctx_inactive = new PricingContext( product_id: 10, base_price: Money::from( '100.00', 'USD' ), customer_id: 1, group: $inactive );
		$res_inactive = $this->resolver->resolve( $ctx_inactive );
		$this->assertFalse( $res_inactive->has_rule(), 'Inactive group rules must not match.' );

		// Missing group:
		$ctx_missing = new PricingContext( product_id: 10, base_price: Money::from( '100.00', 'USD' ), customer_id: 1, group_id: 999 );
		$this->expectException( RuleResolutionException::class );
		$this->expectExceptionMessage( 'does not exist or has been deleted' );
		$this->resolver->resolve( $ctx_missing );
	}

	public function test_database_read_failure_propagates_and_never_becomes_fallback(): void {
		$ctx = new PricingContext( product_id: 10, base_price: Money::from( '100.00', 'USD' ), customer_id: 1 );

		// Simulate DB error.
		$this->rule_repo->set_should_fail_on_query( true );

		$this->expectException( RuleResolutionException::class );
		$this->expectExceptionMessage( 'Repository failure while querying pricing rules' );
		$this->resolver->resolve( $ctx );
	}

	public function test_product_and_variation_rule_inheritance_under_caching(): void {
		$group = new PricingGroup( 'Tiers', new GroupSlug( 'tiers' ), 1, null, GroupStatus::active() );
		$this->group_repo->save( $group );

		// Product-level rule on parent product 100.
		$this->rule_repo->save( new PricingRule( RuleTarget::group( 1 ), RuleScope::product( 100 ), PricingValue::percentage( '15' ) ) );

		// Variation 101 inherits parent rule.
		$ctx_var1 = new PricingContext( product_id: 100, base_price: Money::from( '50.00', 'USD' ), group: $group, variation_id: 101 );
		$res1 = $this->resolver->resolve( $ctx_var1 );
		$this->assertTrue( $res1->has_rule() );
		$this->assertSame( PrecedenceLevel::GROUP_PRODUCT, $res1->precedence_level() );

		// Add variation-specific rule for variation 102.
		$this->rule_repo->save( new PricingRule( RuleTarget::group( 1 ), RuleScope::variation( 102 ), PricingValue::percentage( '25' ) ) );
		$this->cache->invalidate_rules();

		$ctx_var2 = new PricingContext( product_id: 100, base_price: Money::from( '50.00', 'USD' ), group: $group, variation_id: 102 );
		$res2 = $this->resolver->resolve( $ctx_var2 );
		$this->assertSame( PrecedenceLevel::GROUP_VARIATION, $res2->precedence_level() );
		$this->assertSame( '25.000000', $res2->rule()?->pricing_value()->value() );
	}

	public function test_3_step_category_tie_breaking_under_caching(): void {
		$group = new PricingGroup( 'Cat Group', new GroupSlug( 'cat-group' ), 1, null, GroupStatus::active() );
		$this->group_repo->save( $group );

		// Category 10: depth 1, 20% discount.
		// Category 20: depth 2, 10% discount (deeper depth wins).
		$this->rule_repo->save( new PricingRule( RuleTarget::group( 1 ), RuleScope::category( 10 ), PricingValue::percentage( '20' ) ) );
		$this->rule_repo->save( new PricingRule( RuleTarget::group( 1 ), RuleScope::category( 20 ), PricingValue::percentage( '10' ) ) );

		$ctx = new PricingContext(
			product_id: 100,
			base_price: Money::from( '100.00', 'USD' ),
			group: $group,
			category_ids: [ 10, 20 ],
			category_depths: [ 10 => 1, 20 => 2 ]
		);

		$res = $this->resolver->resolve( $ctx );
		$this->assertSame( 20, $res->rule()?->scope()->id(), 'Deeper category hierarchy (depth 2) must win.' );

		// Equal depth: highest percentage wins.
		$this->rule_repo->save( new PricingRule( RuleTarget::group( 1 ), RuleScope::category( 30 ), PricingValue::percentage( '15' ) ) );
		$this->cache->invalidate_rules();

		$ctx_equal = new PricingContext(
			product_id: 100,
			base_price: Money::from( '100.00', 'USD' ),
			group: $group,
			category_ids: [ 20, 30 ],
			category_depths: [ 20 => 2, 30 => 2 ]
		);
		$res_equal = $this->resolver->resolve( $ctx_equal );
		$this->assertSame( 30, $res_equal->rule()?->scope()->id(), 'At equal depth, 15% wins over 10%.' );
	}

	public function test_explicit_zero_and_fixed_zero_preserved_in_cache(): void {
		$group = new PricingGroup( 'Zero Group', new GroupSlug( 'zero-group' ), 1, null, GroupStatus::active() );
		$this->group_repo->save( $group );

		// Customer has an explicit 0% rule (Level 2), group has a 20% rule (Level 6).
		$this->rule_repo->save( new PricingRule( RuleTarget::user( 5 ), RuleScope::product( 10 ), PricingValue::percentage( '0' ) ) );
		$this->rule_repo->save( new PricingRule( RuleTarget::group( 1 ), RuleScope::product( 10 ), PricingValue::percentage( '20' ) ) );

		$ctx = new PricingContext( product_id: 10, base_price: Money::from( '100.00', 'USD' ), customer_id: 5, group: $group );

		$res = $this->resolver->resolve( $ctx );
		$this->assertTrue( $res->has_rule() );
		$this->assertTrue( $res->rule()?->pricing_value()->is_zero() );
		$this->assertSame( PrecedenceLevel::CUSTOMER_PRODUCT, $res->precedence_level() );
		$this->assertSame( PricingReasonCode::RULE_APPLIED_ZERO_PERCENTAGE->value, $res->reason_code() );

		// Verify cached copy preserves explicit zero.
		$res_cached = $this->resolver->resolve( $ctx );
		$this->assertTrue( $res_cached->rule()?->pricing_value()->is_zero() );
		$this->assertSame( PricingReasonCode::RULE_APPLIED_ZERO_PERCENTAGE->value, $res_cached->reason_code() );
	}

	public function test_resolve_batch_and_determinism_under_randomized_order(): void {
		$group = new PricingGroup( 'Batch Group', new GroupSlug( 'batch-group' ), 1, null, GroupStatus::active() );
		$this->group_repo->save( $group );

		// Seed rules for products 1..10.
		for ( $i = 1; $i <= 10; $i++ ) {
			$this->rule_repo->save(
				new PricingRule( RuleTarget::group( 1 ), RuleScope::product( $i ), PricingValue::percentage( (string) ( $i * 5 ) ) )
			);
		}

		$contexts = [];
		for ( $i = 1; $i <= 10; $i++ ) {
			$contexts[ 'p' . $i ] = new PricingContext(
				product_id: $i,
				base_price: Money::from( '100.00', 'USD' ),
				customer_id: 3,
				group: $group
			);
		}

		// Sequential resolve_batch.
		$batch_results = $this->resolver->resolve_batch( $contexts );
		$this->assertCount( 10, $batch_results );

		for ( $i = 1; $i <= 10; $i++ ) {
			$expected_val = sprintf( '%.6f', $i * 5 );
			$this->assertSame( $expected_val, $batch_results[ 'p' . $i ]->rule()?->pricing_value()->value() );
		}

		// Randomized order evaluation.
		$shuffled_keys = array_keys( $contexts );
		shuffle( $shuffled_keys );
		$shuffled_contexts = [];
		foreach ( $shuffled_keys as $k ) {
			$shuffled_contexts[ $k ] = $contexts[ $k ];
		}

		$shuffled_batch = $this->resolver->resolve_batch( $shuffled_contexts );
		foreach ( $shuffled_keys as $k ) {
			$this->assertSame(
				$batch_results[ $k ]->rule()?->id(),
				$shuffled_batch[ $k ]->rule()?->id(),
				'Order of resolution must never alter winning rule.'
			);
		}
	}

	public function test_cached_rule_repository_decorator(): void {
		$cached_repo = new CachedRuleRepository( $this->rule_repo, $this->cache );

		$rule = new PricingRule( RuleTarget::user( 1 ), RuleScope::global(), PricingValue::percentage( '10' ) );
		$saved = $cached_repo->save( $rule );
		$this->assertNotNull( $saved->id() );

		$targets = [ RuleTarget::user( 1 ) ];

		// First call populates cache.
		$rules1 = $cached_repo->find_active_for_targets( $targets );
		$this->assertCount( 1, $rules1 );

		// Second call returns from cache without touching inner repo.
		$rules2 = $cached_repo->find_active_for_targets( $targets );
		$this->assertSame( $rules1, $rules2 );

		// Deleting invalidates cache.
		$cached_repo->delete( (int) $saved->id() );
		$this->assertFalse( $this->cache->has_candidate_rules( 'targets:user:1' ) );
	}

	public function test_pricing_batch_prefetcher(): void {
		$group = new PricingGroup( 'Prefetch Group', new GroupSlug( 'prefetch-group' ), 1, null, GroupStatus::active() );
		$this->group_repo->save( $group );

		$prefetcher = new PricingBatchPrefetcher( $this->rule_repo, $this->cache, $this->group_repo );

		$contexts = [
			new PricingContext( product_id: 1, base_price: Money::from( '10', 'USD' ), customer_id: 5, group: $group ),
			new PricingContext( product_id: 2, base_price: Money::from( '20', 'USD' ), customer_id: 5, group: $group ),
		];

		$prefetcher->prefetch_contexts( $contexts );
		$this->assertTrue( $this->cache->has_candidate_rules( 'targets:group:1|user:5' ) );

		// Prefetch for customer directly.
		$prefetcher->prefetch_customer( 10, 1 );
		$this->assertTrue( $this->cache->has_candidate_rules( 'targets:group:1|user:10' ) );
	}
}
