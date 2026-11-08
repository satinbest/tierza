<?php

declare(strict_types=1);

namespace Tierza\Tests\Unit\Application\Pricing;

use PHPUnit\Framework\TestCase;
use Tierza\Application\Pricing\DeterministicRuleResolver;
use Tierza\Domain\Entities\PricingGroup;
use Tierza\Domain\Entities\PricingRule;
use Tierza\Domain\Exceptions\RuleResolutionException;
use Tierza\Domain\Pricing\Enums\PrecedenceLevel;
use Tierza\Domain\Pricing\Enums\PricingReasonCode;
use Tierza\Domain\Pricing\Enums\PricingSource;
use Tierza\Domain\Pricing\PricingContext;
use Tierza\Domain\ValueObjects\GroupSlug;
use Tierza\Domain\ValueObjects\GroupStatus;
use Tierza\Domain\ValueObjects\Money;
use Tierza\Domain\ValueObjects\PricingValue;
use Tierza\Domain\ValueObjects\RuleScope;
use Tierza\Domain\ValueObjects\RuleStatus;
use Tierza\Domain\ValueObjects\RuleTarget;
use Tierza\Infrastructure\Exceptions\DatabaseQueryException;

final class DeterministicRuleResolverTest extends TestCase {

	private InMemoryRuleRepository $rule_repo;
	private InMemoryGroupRepository $group_repo;
	private DeterministicRuleResolver $resolver;

	protected function setUp(): void {
		parent::setUp();
		$this->rule_repo  = new InMemoryRuleRepository();
		$this->group_repo = new InMemoryGroupRepository();
		$this->resolver   = new DeterministicRuleResolver( $this->rule_repo, $this->group_repo );
	}

	private function create_active_group( int $id = 5, string $name = 'VIP Wholesale' ): PricingGroup {
		$group = new PricingGroup(
			name: $name,
			slug: 'vip-wholesale',
			id: $id,
			status: GroupStatus::ACTIVE
		);
		$this->group_repo->save( $group );
		return $group;
	}

	private function create_inactive_group( int $id = 6, string $name = 'Inactive Group' ): PricingGroup {
		$group = new PricingGroup(
			name: $name,
			slug: 'inactive-group',
			id: $id,
			status: GroupStatus::INACTIVE
		);
		$this->group_repo->save( $group );
		return $group;
	}

	private function create_standard_context(
		int $product_id = 42,
		int $customer_id = 10,
		?PricingGroup $group = null,
		?int $variation_id = 105,
		array $category_ids = [ 20, 30 ],
		array $category_depths = [ 20 => 1, 30 => 2 ]
	): PricingContext {
		return new PricingContext(
			product_id: $product_id,
			base_price: Money::from( '100.00', 'USD' ),
			customer_id: $customer_id,
			group: $group,
			variation_id: $variation_id,
			category_ids: $category_ids,
			category_depths: $category_depths
		);
	}

	public function test_woocommerce_fallback_when_no_rules_match(): void {
		$context = $this->create_standard_context();
		$result  = $this->resolver->resolve( $context );

		$this->assertTrue( $result->is_fallback() );
		$this->assertSame( PrecedenceLevel::WOOCOMMERCE_FALLBACK, $result->precedence_level() );
		$this->assertSame( PricingSource::WOOCOMMERCE_FALLBACK, $result->source() );
		$this->assertSame( PricingReasonCode::FALLBACK_NO_MATCHING_RULE->value, $result->reason_code() );
		$this->assertNull( $result->rule() );
	}

	public function test_anonymous_user_falls_back_with_anonymous_reason_code(): void {
		$context = new PricingContext(
			product_id: 42,
			base_price: Money::from( '50.00', 'USD' ),
			customer_id: 0
		);

		$result = $this->resolver->resolve( $context );
		$this->assertTrue( $result->is_fallback() );
		$this->assertSame( PricingReasonCode::FALLBACK_ANONYMOUS_NO_RULE->value, $result->reason_code() );
	}

	public function test_anonymous_user_never_matches_customer_specific_rules(): void {
		// A rule exists for registered user 15
		$user_rule = new PricingRule(
			RuleTarget::user( 15 ),
			RuleScope::product( 42 ),
			PricingValue::percentage( '20.000000' )
		);
		$this->rule_repo->save( $user_rule );

		// Guest user (customer_id: 0) browses the same product
		$context = new PricingContext(
			product_id: 42,
			base_price: Money::from( '100.00', 'USD' ),
			customer_id: 0
		);

		$result = $this->resolver->resolve( $context );
		$this->assertTrue( $result->is_fallback() );
		$this->assertSame( PricingReasonCode::FALLBACK_ANONYMOUS_NO_RULE->value, $result->reason_code() );
	}

	public function test_all_eight_precedence_tiers_resolve_correctly(): void {
		$group   = $this->create_active_group( 5 );
		$context = $this->create_standard_context( product_id: 42, customer_id: 10, group: $group, variation_id: 105, category_ids: [ 20 ], category_depths: [ 20 => 1 ] );

		$tiers = [
			PrecedenceLevel::CUSTOMER_VARIATION->value => new PricingRule( RuleTarget::user( 10 ), RuleScope::variation( 105 ), PricingValue::percentage( '10.000000' ) ),
			PrecedenceLevel::CUSTOMER_PRODUCT->value   => new PricingRule( RuleTarget::user( 10 ), RuleScope::product( 42 ), PricingValue::percentage( '15.000000' ) ),
			PrecedenceLevel::CUSTOMER_CATEGORY->value  => new PricingRule( RuleTarget::user( 10 ), RuleScope::category( 20 ), PricingValue::percentage( '20.000000' ) ),
			PrecedenceLevel::CUSTOMER_GLOBAL->value    => new PricingRule( RuleTarget::user( 10 ), RuleScope::global(), PricingValue::percentage( '25.000000' ) ),
			PrecedenceLevel::GROUP_VARIATION->value    => new PricingRule( RuleTarget::group( 5 ), RuleScope::variation( 105 ), PricingValue::percentage( '30.000000' ) ),
			PrecedenceLevel::GROUP_PRODUCT->value      => new PricingRule( RuleTarget::group( 5 ), RuleScope::product( 42 ), PricingValue::percentage( '35.000000' ) ),
			PrecedenceLevel::GROUP_CATEGORY->value     => new PricingRule( RuleTarget::group( 5 ), RuleScope::category( 20 ), PricingValue::percentage( '40.000000' ) ),
			PrecedenceLevel::GROUP_GLOBAL->value       => new PricingRule( RuleTarget::group( 5 ), RuleScope::global(), PricingValue::percentage( '45.000000' ) ),
		];

		foreach ( $tiers as $level_val => $rule ) {
			$this->rule_repo->clear();
			$this->rule_repo->save( $rule );

			$result = $this->resolver->resolve( $context );

			$this->assertFalse( $result->is_fallback(), "Tier {$level_val} must not fall back." );
			$this->assertSame( $level_val, $result->precedence_level()->value, "Failed matching level {$level_val}." );
			$this->assertNotNull( $result->rule() );
			$this->assertSame( $rule->pricing_value()->value(), $result->rule()->pricing_value()->value() );
		}
	}

	public function test_customer_rules_strictly_override_group_rules(): void {
		$group   = $this->create_active_group( 5 );
		$context = $this->create_standard_context( product_id: 42, customer_id: 10, group: $group, variation_id: 105 );

		// Customer global rule (Level 4) vs Group variation rule (Level 5)
		$customer_global = new PricingRule( RuleTarget::user( 10 ), RuleScope::global(), PricingValue::percentage( '5.000000' ) );
		$group_variation = new PricingRule( RuleTarget::group( 5 ), RuleScope::variation( 105 ), PricingValue::percentage( '50.000000' ) );

		$this->rule_repo->save( $group_variation );
		$this->rule_repo->save( $customer_global );

		$result = $this->resolver->resolve( $context );

		$this->assertSame( PrecedenceLevel::CUSTOMER_GLOBAL, $result->precedence_level() );
		$this->assertSame( PricingSource::CUSTOMER, $result->source() );
		$this->assertSame( '5.000000', $result->rule()->pricing_value()->value() );
	}

	public function test_variation_rule_overrides_product_rule_within_same_tier(): void {
		$context = $this->create_standard_context( product_id: 42, customer_id: 10, variation_id: 105 );

		$var_rule  = new PricingRule( RuleTarget::user( 10 ), RuleScope::variation( 105 ), PricingValue::percentage( '12.000000' ) );
		$prod_rule = new PricingRule( RuleTarget::user( 10 ), RuleScope::product( 42 ), PricingValue::percentage( '25.000000' ) );

		$this->rule_repo->save( $prod_rule );
		$this->rule_repo->save( $var_rule );

		$result = $this->resolver->resolve( $context );

		$this->assertSame( PrecedenceLevel::CUSTOMER_VARIATION, $result->precedence_level() );
		$this->assertSame( '12.000000', $result->rule()->pricing_value()->value() );
	}

	public function test_parent_product_rule_inheritance_for_variations(): void {
		$context = $this->create_standard_context( product_id: 42, customer_id: 10, variation_id: 105 );

		// Only product rule exists, no variation rule
		$prod_rule = new PricingRule( RuleTarget::user( 10 ), RuleScope::product( 42 ), PricingValue::percentage( '18.000000' ) );
		$this->rule_repo->save( $prod_rule );

		$result = $this->resolver->resolve( $context );

		$this->assertSame( PrecedenceLevel::CUSTOMER_PRODUCT, $result->precedence_level() );
		$this->assertSame( '18.000000', $result->rule()->pricing_value()->value() );
	}

	public function test_category_rule_overrides_global_rule(): void {
		$context = $this->create_standard_context( product_id: 42, customer_id: 10, category_ids: [ 20 ], category_depths: [ 20 => 1 ] );

		$cat_rule    = new PricingRule( RuleTarget::user( 10 ), RuleScope::category( 20 ), PricingValue::percentage( '15.000000' ) );
		$global_rule = new PricingRule( RuleTarget::user( 10 ), RuleScope::global(), PricingValue::percentage( '30.000000' ) );

		$this->rule_repo->save( $global_rule );
		$this->rule_repo->save( $cat_rule );

		$result = $this->resolver->resolve( $context );

		$this->assertSame( PrecedenceLevel::CUSTOMER_CATEGORY, $result->precedence_level() );
		$this->assertSame( '15.000000', $result->rule()->pricing_value()->value() );
	}

	public function test_multi_category_deeper_hierarchy_depth_wins(): void {
		// Category 30 has depth 2; Category 20 has depth 1
		$context = $this->create_standard_context(
			product_id: 42,
			customer_id: 10,
			category_ids: [ 20, 30 ],
			category_depths: [ 20 => 1, 30 => 2 ]
		);

		// Category 20 has 50% discount; Category 30 has only 10% discount
		$parent_cat_rule = new PricingRule( RuleTarget::user( 10 ), RuleScope::category( 20 ), PricingValue::percentage( '50.000000' ) );
		$child_cat_rule  = new PricingRule( RuleTarget::user( 10 ), RuleScope::category( 30 ), PricingValue::percentage( '10.000000' ) );

		$this->rule_repo->save( $parent_cat_rule );
		$this->rule_repo->save( $child_cat_rule );

		$result = $this->resolver->resolve( $context );

		// Deeper depth MUST WIN regardless of discount percentage!
		$this->assertSame( PrecedenceLevel::CUSTOMER_CATEGORY, $result->precedence_level() );
		$this->assertSame( '10.000000', $result->rule()->pricing_value()->value() );
		$this->assertSame( 30, $result->rule()->scope()->id() );
	}

	public function test_multi_category_equal_depth_lowest_final_price_wins(): void {
		// Both Category 20 and Category 25 have depth 2
		$context = $this->create_standard_context(
			product_id: 42,
			customer_id: 10,
			category_ids: [ 20, 25 ],
			category_depths: [ 20 => 2, 25 => 2 ]
		);

		// Category 20 gives 15% discount; Category 25 gives 35% discount
		$rule20 = new PricingRule( RuleTarget::user( 10 ), RuleScope::category( 20 ), PricingValue::percentage( '15.000000' ) );
		$rule25 = new PricingRule( RuleTarget::user( 10 ), RuleScope::category( 25 ), PricingValue::percentage( '35.000000' ) );

		$this->rule_repo->save( $rule20 );
		$this->rule_repo->save( $rule25 );

		$result = $this->resolver->resolve( $context );

		// Higher percentage discount (35%) produces lower price -> MUST WIN
		$this->assertSame( PrecedenceLevel::CUSTOMER_CATEGORY, $result->precedence_level() );
		$this->assertSame( '35.000000', $result->rule()->pricing_value()->value() );
		$this->assertSame( 25, $result->rule()->scope()->id() );
	}

	public function test_multi_category_equal_depth_and_discount_smallest_category_id_wins(): void {
		// Equal depth (2) and equal discount (20%) on Category 15 and Category 40
		$context = $this->create_standard_context(
			product_id: 42,
			customer_id: 10,
			category_ids: [ 15, 40 ],
			category_depths: [ 15 => 2, 40 => 2 ]
		);

		$rule40 = new PricingRule( RuleTarget::user( 10 ), RuleScope::category( 40 ), PricingValue::percentage( '20.000000' ) );
		$rule15 = new PricingRule( RuleTarget::user( 10 ), RuleScope::category( 15 ), PricingValue::percentage( '20.000000' ) );

		$this->rule_repo->save( $rule40 );
		$this->rule_repo->save( $rule15 );

		$result = $this->resolver->resolve( $context );

		// Smallest category ID (15 < 40) MUST WIN
		$this->assertSame( PrecedenceLevel::CUSTOMER_CATEGORY, $result->precedence_level() );
		$this->assertSame( 15, $result->rule()->scope()->id() );
	}

	public function test_explicit_zero_percent_rule_is_authoritative_winner(): void {
		$group   = $this->create_active_group( 5 );
		$context = $this->create_standard_context( product_id: 42, customer_id: 10, group: $group );

		// Customer Product 0% (Level 2) vs Group Product 25% (Level 6)
		$zero_rule  = new PricingRule( RuleTarget::user( 10 ), RuleScope::product( 42 ), PricingValue::percentage( '0.000000' ) );
		$group_rule = new PricingRule( RuleTarget::group( 5 ), RuleScope::product( 42 ), PricingValue::percentage( '25.000000' ) );

		$this->rule_repo->save( $group_rule );
		$this->rule_repo->save( $zero_rule );

		$result = $this->resolver->resolve( $context );

		$this->assertSame( PrecedenceLevel::CUSTOMER_PRODUCT, $result->precedence_level() );
		$this->assertSame( '0.000000', $result->rule()->pricing_value()->value() );
		$this->assertSame( PricingReasonCode::RULE_APPLIED_ZERO_PERCENTAGE->value, $result->reason_code() );
	}

	public function test_explicit_fixed_zero_price_is_valid_and_wins(): void {
		$context    = $this->create_standard_context( product_id: 42, customer_id: 10 );
		$fixed_zero = new PricingRule( RuleTarget::user( 10 ), RuleScope::product( 42 ), PricingValue::fixed( '0.000000' ) );
		$this->rule_repo->save( $fixed_zero );

		$result = $this->resolver->resolve( $context );

		$this->assertSame( PrecedenceLevel::CUSTOMER_PRODUCT, $result->precedence_level() );
		$this->assertSame( '0.000000', $result->rule()->pricing_value()->value() );
		$this->assertTrue( $result->rule()->pricing_value()->is_fixed() );
	}

	public function test_inactive_rules_are_ignored_and_never_win(): void {
		$group   = $this->create_active_group( 5 );
		$context = $this->create_standard_context( product_id: 42, customer_id: 10, group: $group, variation_id: 105 );

		// Inactive customer variation rule (Level 1)
		$inactive_var_rule = new PricingRule(
			RuleTarget::user( 10 ),
			RuleScope::variation( 105 ),
			PricingValue::percentage( '10.000000' ),
			null,
			RuleStatus::INACTIVE
		);
		// Active group variation rule (Level 5)
		$active_group_rule = new PricingRule(
			RuleTarget::group( 5 ),
			RuleScope::variation( 105 ),
			PricingValue::percentage( '30.000000' )
		);

		$this->rule_repo->save( $inactive_var_rule );
		$this->rule_repo->save( $active_group_rule );

		$result = $this->resolver->resolve( $context );

		// Inactive rule is ignored; group rule wins
		$this->assertSame( PrecedenceLevel::GROUP_VARIATION, $result->precedence_level() );
		$this->assertSame( '30.000000', $result->rule()->pricing_value()->value() );
	}

	public function test_inactive_pricing_group_rules_are_never_eligible(): void {
		$inactive_group = $this->create_inactive_group( 6 );
		$context        = $this->create_standard_context( product_id: 42, customer_id: 10, group: $inactive_group );

		$group_rule = new PricingRule(
			RuleTarget::group( 6 ),
			RuleScope::product( 42 ),
			PricingValue::percentage( '40.000000' )
		);
		$this->rule_repo->save( $group_rule );

		$result = $this->resolver->resolve( $context );

		// Group is inactive, so its rules cannot apply -> falls back to WooCommerce price
		$this->assertTrue( $result->is_fallback() );
	}

	public function test_deleted_or_invalid_referenced_group_throws_exception(): void {
		// Context references group ID 999 which does NOT exist in group repository
		$context = new PricingContext(
			product_id: 42,
			base_price: Money::from( '100.00', 'USD' ),
			customer_id: 10,
			group_id: 999
		);

		$this->expectException( RuleResolutionException::class );
		$this->expectExceptionMessage( 'Referenced pricing group ID 999 does not exist or has been deleted.' );

		$this->resolver->resolve( $context );
	}

	public function test_unrelated_product_or_variation_rules_never_match(): void {
		$context = $this->create_standard_context( product_id: 42, customer_id: 10, variation_id: 105 );

		// Rule for product 999
		$unrelated_prod = new PricingRule( RuleTarget::user( 10 ), RuleScope::product( 999 ), PricingValue::percentage( '20.000000' ) );
		// Rule for variation 777
		$unrelated_var  = new PricingRule( RuleTarget::user( 10 ), RuleScope::variation( 777 ), PricingValue::percentage( '25.000000' ) );

		$this->rule_repo->save( $unrelated_prod );
		$this->rule_repo->save( $unrelated_var );

		$result = $this->resolver->resolve( $context );

		$this->assertTrue( $result->is_fallback() );
	}

	public function test_conflicting_same_priority_rules_throw_exception(): void {
		$context = $this->create_standard_context( product_id: 42, customer_id: 10 );

		// Two active rules for the exact same customer and product
		$rule1 = new PricingRule( RuleTarget::user( 10 ), RuleScope::product( 42 ), PricingValue::percentage( '10.000000' ), 1 );
		$rule2 = new PricingRule( RuleTarget::user( 10 ), RuleScope::product( 42 ), PricingValue::percentage( '20.000000' ), 2 );

		$this->rule_repo->save( $rule1 );
		$this->rule_repo->save( $rule2 );

		$this->expectException( RuleResolutionException::class );
		$this->expectExceptionMessage( 'Conflicting same-priority rules detected' );

		$this->resolver->resolve( $context );
	}

	public function test_conflicting_active_rules_for_exact_same_category_throw_exception(): void {
		$context = $this->create_standard_context( product_id: 42, customer_id: 10, category_ids: [ 20 ], category_depths: [ 20 => 1 ] );

		// Two active rules for the exact same category ID 20
		$cat_rule1 = new PricingRule( RuleTarget::user( 10 ), RuleScope::category( 20 ), PricingValue::percentage( '10.000000' ), 10 );
		$cat_rule2 = new PricingRule( RuleTarget::user( 10 ), RuleScope::category( 20 ), PricingValue::percentage( '20.000000' ), 11 );

		$this->rule_repo->save( $cat_rule1 );
		$this->rule_repo->save( $cat_rule2 );

		$this->expectException( RuleResolutionException::class );
		$this->expectExceptionMessage( 'Conflicting same-priority rules detected for category ID 20' );

		$this->resolver->resolve( $context );
	}

	public function test_missing_category_depth_metadata_throws_exception(): void {
		// Context specifies categories 20 and 30, but depths map only includes 20
		$context = new PricingContext(
			product_id: 42,
			base_price: Money::from( '100.00', 'USD' ),
			customer_id: 10,
			category_ids: [ 20, 30 ],
			category_depths: [ 20 => 1 ] // 30 is missing from depth map!
		);

		$rule20 = new PricingRule( RuleTarget::user( 10 ), RuleScope::category( 20 ), PricingValue::percentage( '15.000000' ) );
		$rule30 = new PricingRule( RuleTarget::user( 10 ), RuleScope::category( 30 ), PricingValue::percentage( '25.000000' ) );
		$this->rule_repo->save( $rule20 );
		$this->rule_repo->save( $rule30 );

		$this->expectException( RuleResolutionException::class );
		$this->expectExceptionMessage( 'missing category-depth metadata for category ID 30' );

		$this->resolver->resolve( $context );
	}

	public function test_inconsistent_rule_target_throws_exception(): void {
		$context = $this->create_standard_context( product_id: 42, customer_id: 10 );

		// A rule targeting user 999 instead of 10
		$rogue_rule = new PricingRule( RuleTarget::user( 999 ), RuleScope::product( 42 ), PricingValue::percentage( '20.000000' ) );

		// Directly inject into repository
		$this->rule_repo->save( $rogue_rule );

		// Force repo to return it by overriding find_active_for_targets behavior
		$bad_repo = $this->createMock( \Tierza\Domain\Repositories\RuleRepositoryInterface::class );
		$bad_repo->method( 'find_active_for_targets' )->willReturn( [ $rogue_rule ] );

		$resolver = new DeterministicRuleResolver( $bad_repo );

		$this->expectException( RuleResolutionException::class );
		$this->expectExceptionMessage( 'Inconsistent rule target' );

		$resolver->resolve( $context );
	}

	public function test_invalid_percentage_bounds_throw_exception(): void {
		$context = $this->create_standard_context( product_id: 42, customer_id: 10 );

		// In extreme edge cases where a corrupted rule has percentage > 100
		$corrupted_rule = new PricingRule( RuleTarget::user( 10 ), RuleScope::product( 42 ), PricingValue::percentage( '20.000000' ), 99 );

		$ref_prop = new \ReflectionProperty( PricingValue::class, 'value' );
		$ref_prop->setValue( $corrupted_rule->pricing_value(), '120.000000' );

		$mock_repo = $this->createMock( \Tierza\Domain\Repositories\RuleRepositoryInterface::class );
		$mock_repo->method( 'find_active_for_targets' )->willReturn( [ $corrupted_rule ] );

		$resolver = new DeterministicRuleResolver( $mock_repo );

		$this->expectException( RuleResolutionException::class );
		$this->expectExceptionMessage( 'Invalid percentage value' );

		$resolver->resolve( $context );
	}

	public function test_repository_failure_wraps_in_rule_resolution_exception(): void {
		$context = $this->create_standard_context( product_id: 42, customer_id: 10 );
		$this->rule_repo->set_should_fail_on_query( true );

		$this->expectException( RuleResolutionException::class );
		$this->expectExceptionMessage( 'Repository failure while querying pricing rules' );

		$this->resolver->resolve( $context );
	}

	public function test_randomized_rule_insertion_order_is_100_percent_deterministic(): void {
		$group   = $this->create_active_group( 5 );
		$context = $this->create_standard_context(
			product_id: 42,
			customer_id: 10,
			group: $group,
			variation_id: 105,
			category_ids: [ 20, 25, 30 ],
			category_depths: [ 20 => 1, 25 => 2, 30 => 2 ]
		);

		// Prepare a set of rules across various levels
		$rules = [
			new PricingRule( RuleTarget::group( 5 ), RuleScope::global(), PricingValue::percentage( '5.000000' ) ),
			new PricingRule( RuleTarget::group( 5 ), RuleScope::category( 20 ), PricingValue::percentage( '8.000000' ) ),
			new PricingRule( RuleTarget::group( 5 ), RuleScope::category( 25 ), PricingValue::percentage( '12.000000' ) ),
			new PricingRule( RuleTarget::group( 5 ), RuleScope::product( 42 ), PricingValue::percentage( '15.000000' ) ),
			new PricingRule( RuleTarget::user( 10 ), RuleScope::global(), PricingValue::percentage( '18.000000' ) ),
			new PricingRule( RuleTarget::user( 10 ), RuleScope::category( 20 ), PricingValue::percentage( '22.000000' ) ),
			new PricingRule( RuleTarget::user( 10 ), RuleScope::category( 25 ), PricingValue::percentage( '26.000000' ) ),
			new PricingRule( RuleTarget::user( 10 ), RuleScope::category( 30 ), PricingValue::percentage( '26.000000' ) ), // depth 2 tie-break
			new PricingRule( RuleTarget::user( 10 ), RuleScope::product( 42 ), PricingValue::percentage( '30.000000' ) ),
			new PricingRule( RuleTarget::user( 10 ), RuleScope::variation( 105 ), PricingValue::percentage( '35.000000' ) ), // WINNER: Level 1
		];

		// Shuffle and run 50 times
		for ( $i = 0; $i < 50; $i++ ) {
			$shuffled = $rules;
			shuffle( $shuffled );

			$this->rule_repo->clear();
			foreach ( $shuffled as $r ) {
				$this->rule_repo->save( $r );
			}

			$result = $this->resolver->resolve( $context );

			$this->assertSame(
				PrecedenceLevel::CUSTOMER_VARIATION,
				$result->precedence_level(),
				"Deterministic resolution failed on iteration {$i}."
			);
			$this->assertSame( '35.000000', $result->rule()->pricing_value()->value() );
		}
	}

	public function test_idempotent_repeated_resolution(): void {
		$context = $this->create_standard_context( product_id: 42, customer_id: 10 );
		$rule    = new PricingRule( RuleTarget::user( 10 ), RuleScope::product( 42 ), PricingValue::percentage( '25.000000' ) );
		$this->rule_repo->save( $rule );

		$first_result = $this->resolver->resolve( $context );
		for ( $i = 0; $i < 10; $i++ ) {
			$subsequent_result = $this->resolver->resolve( $context );
			$this->assertSame( $first_result->precedence_level(), $subsequent_result->precedence_level() );
			$this->assertSame( $first_result->reason_code(), $subsequent_result->reason_code() );
			$this->assertSame( $first_result->rule()->id(), $subsequent_result->rule()->id() );
		}
	}

	/**
	 * Table-driven pairwise precedence matrix comparing every higher-priority tier
	 * against every lower-priority tier (all 28 pairs of levels 1..8).
	 *
	 * @dataProvider pairwise_precedence_matrix_provider
	 */
	public function test_pairwise_precedence_matrix( int $higher_level, int $lower_level ): void {
		$group   = $this->create_active_group( 5 );
		$context = $this->create_standard_context(
			product_id: 42,
			customer_id: 10,
			group: $group,
			variation_id: 105,
			category_ids: [ 20 ],
			category_depths: [ 20 => 1 ]
		);

		$rule_factory = [
			1 => fn() => new PricingRule( RuleTarget::user( 10 ), RuleScope::variation( 105 ), PricingValue::percentage( '10.000000' ) ),
			2 => fn() => new PricingRule( RuleTarget::user( 10 ), RuleScope::product( 42 ), PricingValue::percentage( '12.000000' ) ),
			3 => fn() => new PricingRule( RuleTarget::user( 10 ), RuleScope::category( 20 ), PricingValue::percentage( '14.000000' ) ),
			4 => fn() => new PricingRule( RuleTarget::user( 10 ), RuleScope::global(), PricingValue::percentage( '16.000000' ) ),
			5 => fn() => new PricingRule( RuleTarget::group( 5 ), RuleScope::variation( 105 ), PricingValue::percentage( '18.000000' ) ),
			6 => fn() => new PricingRule( RuleTarget::group( 5 ), RuleScope::product( 42 ), PricingValue::percentage( '20.000000' ) ),
			7 => fn() => new PricingRule( RuleTarget::group( 5 ), RuleScope::category( 20 ), PricingValue::percentage( '22.000000' ) ),
			8 => fn() => new PricingRule( RuleTarget::group( 5 ), RuleScope::global(), PricingValue::percentage( '24.000000' ) ),
		];

		$higher_rule = $rule_factory[ $higher_level ]();
		$lower_rule  = $rule_factory[ $lower_level ]();

		// Test in both insertion orders: [higher, lower] and [lower, higher]
		foreach ( [ [ $higher_rule, $lower_rule ], [ $lower_rule, $higher_rule ] ] as $pair ) {
			$this->rule_repo->clear();
			$this->rule_repo->save( $pair[0] );
			$this->rule_repo->save( $pair[1] );

			$result = $this->resolver->resolve( $context );

			$this->assertSame(
				$higher_level,
				$result->precedence_level()->value,
				sprintf( 'Higher level %d failed to beat lower level %d.', $higher_level, $lower_level )
			);
		}
	}

	/**
	 * @return array<string, array{int, int}>
	 */
	public static function pairwise_precedence_matrix_provider(): array {
		$cases = [];
		for ( $high = 1; $high <= 7; $high++ ) {
			for ( $low = $high + 1; $low <= 8; $low++ ) {
				$cases[ "Level_{$high}_vs_Level_{$low}" ] = [ $high, $low ];
			}
		}

		return $cases;
	}

	// =========================================================================
	// Section 1: Database Failure & Repository Error Tests
	// =========================================================================

	public function test_repository_read_failure_does_not_become_fallback_and_preserves_cause(): void {
		$context  = $this->create_standard_context( product_id: 42, customer_id: 10 );
		$bad_repo = $this->createMock( \Tierza\Domain\Repositories\RuleRepositoryInterface::class );
		$db_error = new DatabaseQueryException( 'Database query failed while retrieving active rules for targets.' );
		$bad_repo->method( 'find_active_for_targets' )->willThrowException( $db_error );

		$resolver = new DeterministicRuleResolver( $bad_repo, $this->group_repo );

		$this->expectException( RuleResolutionException::class );
		$this->expectExceptionMessage( 'Repository failure while querying pricing rules' );

		try {
			$resolver->resolve( $context );
		} catch ( RuleResolutionException $e ) {
			$this->assertSame( $db_error, $e->getPrevious() );
			throw $e;
		}
	}

	public function test_successful_empty_query_returns_clean_fallback(): void {
		$context = $this->create_standard_context( product_id: 42, customer_id: 10 );

		// Repository contains 0 rules -> returns [] successfully without error
		$result = $this->resolver->resolve( $context );

		$this->assertTrue( $result->is_fallback() );
		$this->assertSame( PrecedenceLevel::WOOCOMMERCE_FALLBACK, $result->precedence_level() );
		$this->assertSame( PricingReasonCode::FALLBACK_NO_MATCHING_RULE->value, $result->reason_code() );
	}

	// =========================================================================
	// Section 2: Group Verification & Active Assumptions Tests
	// =========================================================================

	public function test_numeric_group_id_without_trusted_entity_or_repository_throws_exception(): void {
		// Context provides only a numeric group ID 5, but NO entity
		$context = new PricingContext(
			product_id: 42,
			base_price: Money::from( '100.00', 'USD' ),
			customer_id: 10,
			group_id: 5 // Numeric only
		);

		// Resolver instantiated with NO group repository
		$resolver = new DeterministicRuleResolver( $this->rule_repo, null );

		$this->expectException( RuleResolutionException::class );
		$this->expectExceptionMessage( 'Cannot verify group eligibility for group ID 5: neither a trusted PricingGroup entity in PricingContext nor a GroupRepositoryInterface was provided.' );

		$resolver->resolve( $context );
	}

	public function test_active_valid_group_via_trusted_entity_succeeds(): void {
		$group   = $this->create_active_group( 5 );
		$context = $this->create_standard_context( product_id: 42, customer_id: 10, group: $group );

		$group_rule = new PricingRule( RuleTarget::group( 5 ), RuleScope::product( 42 ), PricingValue::percentage( '20.000000' ) );
		$this->rule_repo->save( $group_rule );

		// Resolver does not even need group repo because entity is explicitly trusted in context
		$resolver = new DeterministicRuleResolver( $this->rule_repo, null );
		$result   = $resolver->resolve( $context );

		$this->assertFalse( $result->is_fallback() );
		$this->assertSame( PrecedenceLevel::GROUP_PRODUCT, $result->precedence_level() );
	}

	public function test_active_valid_group_via_repository_lookup_succeeds(): void {
		$this->group_repo->save( new PricingGroup( 'Active Group', 'active-grp', 8, '', GroupStatus::ACTIVE ) );

		// Context has ONLY group_id = 8, no entity
		$context = new PricingContext(
			product_id: 42,
			base_price: Money::from( '100.00', 'USD' ),
			customer_id: 10,
			group_id: 8
		);

		$group_rule = new PricingRule( RuleTarget::group( 8 ), RuleScope::product( 42 ), PricingValue::percentage( '20.000000' ) );
		$this->rule_repo->save( $group_rule );

		$result = $this->resolver->resolve( $context );

		$this->assertFalse( $result->is_fallback() );
		$this->assertSame( PrecedenceLevel::GROUP_PRODUCT, $result->precedence_level() );
	}

	public function test_inactive_group_via_repository_lookup_does_not_contribute_group_rules(): void {
		$this->group_repo->save( new PricingGroup( 'Inactive Group', 'inactive-grp', 9, '', GroupStatus::INACTIVE ) );

		$context = new PricingContext(
			product_id: 42,
			base_price: Money::from( '100.00', 'USD' ),
			customer_id: 10,
			group_id: 9
		);

		$group_rule = new PricingRule( RuleTarget::group( 9 ), RuleScope::product( 42 ), PricingValue::percentage( '20.000000' ) );
		$this->rule_repo->save( $group_rule );

		$result = $this->resolver->resolve( $context );

		$this->assertTrue( $result->is_fallback() );
	}

	public function test_group_repository_failure_wraps_in_rule_resolution_exception_with_cause(): void {
		$bad_group_repo = $this->createMock( \Tierza\Domain\Repositories\GroupRepositoryInterface::class );
		$db_error       = new DatabaseQueryException( 'Database query failed while retrieving pricing group by ID.' );
		$bad_group_repo->method( 'find_by_id' )->willThrowException( $db_error );

		$context = new PricingContext(
			product_id: 42,
			base_price: Money::from( '100.00', 'USD' ),
			customer_id: 10,
			group_id: 12
		);

		$resolver = new DeterministicRuleResolver( $this->rule_repo, $bad_group_repo );

		$this->expectException( RuleResolutionException::class );
		$this->expectExceptionMessage( 'Failed to verify referenced pricing group ID 12' );

		try {
			$resolver->resolve( $context );
		} catch ( RuleResolutionException $e ) {
			$this->assertSame( $db_error, $e->getPrevious() );
			throw $e;
		}
	}

	public function test_authenticated_customer_with_inactive_group_still_matches_customer_rule(): void {
		$inactive_group = $this->create_inactive_group( 7 );
		$context        = $this->create_standard_context( product_id: 42, customer_id: 10, group: $inactive_group );

		// Customer rule (Level 2) and Inactive Group rule (Level 6)
		$customer_rule = new PricingRule( RuleTarget::user( 10 ), RuleScope::product( 42 ), PricingValue::percentage( '15.000000' ) );
		$group_rule    = new PricingRule( RuleTarget::group( 7 ), RuleScope::product( 42 ), PricingValue::percentage( '35.000000' ) );

		$this->rule_repo->save( $customer_rule );
		$this->rule_repo->save( $group_rule );

		$result = $this->resolver->resolve( $context );

		// Customer rule must match and win; inactive group rule is completely omitted
		$this->assertFalse( $result->is_fallback() );
		$this->assertSame( PrecedenceLevel::CUSTOMER_PRODUCT, $result->precedence_level() );
		$this->assertSame( '15.000000', $result->rule()->pricing_value()->value() );
	}

	public function test_anonymous_customer_without_group_executes_zero_queries_and_returns_fallback(): void {
		$context = new PricingContext(
			product_id: 42,
			base_price: Money::from( '100.00', 'USD' ),
			customer_id: 0 // Guest
		);

		$mock_repo = $this->createMock( \Tierza\Domain\Repositories\RuleRepositoryInterface::class );
		$mock_repo->expects( $this->never() )->method( 'find_active_for_targets' );

		$resolver = new DeterministicRuleResolver( $mock_repo, $this->group_repo );
		$result   = $resolver->resolve( $context );

		$this->assertTrue( $result->is_fallback() );
		$this->assertSame( PricingReasonCode::FALLBACK_ANONYMOUS_NO_RULE->value, $result->reason_code() );
	}

	public function test_anonymous_customer_with_verified_public_group_matches_group_rule(): void {
		$public_group = $this->create_active_group( 3 );
		$context      = new PricingContext(
			product_id: 42,
			base_price: Money::from( '100.00', 'USD' ),
			customer_id: 0, // Guest
			group: $public_group
		);

		$group_rule = new PricingRule( RuleTarget::group( 3 ), RuleScope::product( 42 ), PricingValue::percentage( '10.000000' ) );
		$this->rule_repo->save( $group_rule );

		$result = $this->resolver->resolve( $context );

		$this->assertFalse( $result->is_fallback() );
		$this->assertSame( PrecedenceLevel::GROUP_PRODUCT, $result->precedence_level() );
		$this->assertSame( '10.000000', $result->rule()->pricing_value()->value() );
	}

	// =========================================================================
	// Section 3: Deterministic Category-Depth Validation Tests
	// =========================================================================

	public function test_competing_categories_with_completely_missing_depth_map_throws_exception(): void {
		$context = new PricingContext(
			product_id: 42,
			base_price: Money::from( '100.00', 'USD' ),
			customer_id: 10,
			category_ids: [ 10, 20 ],
			category_depths: [] // Completely empty depth map!
		);

		$rule10 = new PricingRule( RuleTarget::user( 10 ), RuleScope::category( 10 ), PricingValue::percentage( '10.000000' ) );
		$rule20 = new PricingRule( RuleTarget::user( 10 ), RuleScope::category( 20 ), PricingValue::percentage( '20.000000' ) );
		$this->rule_repo->save( $rule10 );
		$this->rule_repo->save( $rule20 );

		$this->expectException( RuleResolutionException::class );
		$this->expectExceptionMessage( 'missing category-depth metadata for category ID 10 in pricing context.' );

		$this->resolver->resolve( $context );
	}

	public function test_competing_categories_with_partial_depth_data_throws_exception(): void {
		$context = new PricingContext(
			product_id: 42,
			base_price: Money::from( '100.00', 'USD' ),
			customer_id: 10,
			category_ids: [ 10, 20 ],
			category_depths: [ 10 => 1 ] // 20 is missing!
		);

		$rule10 = new PricingRule( RuleTarget::user( 10 ), RuleScope::category( 10 ), PricingValue::percentage( '10.000000' ) );
		$rule20 = new PricingRule( RuleTarget::user( 10 ), RuleScope::category( 20 ), PricingValue::percentage( '20.000000' ) );
		$this->rule_repo->save( $rule10 );
		$this->rule_repo->save( $rule20 );

		$this->expectException( RuleResolutionException::class );
		$this->expectExceptionMessage( 'missing category-depth metadata for category ID 20 in pricing context.' );

		$this->resolver->resolve( $context );
	}

	public function test_competing_categories_at_valid_depth_zero_are_preserved_and_resolved_by_discount(): void {
		$context = new PricingContext(
			product_id: 42,
			base_price: Money::from( '100.00', 'USD' ),
			customer_id: 10,
			category_ids: [ 10, 20 ],
			category_depths: [ 10 => 0, 20 => 0 ] // Both at valid root depth 0
		);

		$rule10 = new PricingRule( RuleTarget::user( 10 ), RuleScope::category( 10 ), PricingValue::percentage( '15.000000' ) );
		$rule20 = new PricingRule( RuleTarget::user( 10 ), RuleScope::category( 20 ), PricingValue::percentage( '30.000000' ) );
		$this->rule_repo->save( $rule10 );
		$this->rule_repo->save( $rule20 );

		$result = $this->resolver->resolve( $context );

		// Equal depth 0 -> higher discount (30% > 15%) wins!
		$this->assertSame( 20, $result->rule()->scope()->id() );
		$this->assertSame( '30.000000', $result->rule()->pricing_value()->value() );
	}

	public function test_competing_categories_at_valid_depth_zero_with_equal_discount_resolved_by_smallest_id(): void {
		$context = new PricingContext(
			product_id: 42,
			base_price: Money::from( '100.00', 'USD' ),
			customer_id: 10,
			category_ids: [ 10, 20 ],
			category_depths: [ 10 => 0, 20 => 0 ]
		);

		$rule10 = new PricingRule( RuleTarget::user( 10 ), RuleScope::category( 10 ), PricingValue::percentage( '20.000000' ) );
		$rule20 = new PricingRule( RuleTarget::user( 10 ), RuleScope::category( 20 ), PricingValue::percentage( '20.000000' ) );
		$this->rule_repo->save( $rule20 );
		$this->rule_repo->save( $rule10 );

		$result = $this->resolver->resolve( $context );

		// Equal depth 0 and equal discount 20% -> smaller ID (10 < 20) wins!
		$this->assertSame( 10, $result->rule()->scope()->id() );
	}

	public function test_parent_category_versus_child_category_depth_wins(): void {
		$context = new PricingContext(
			product_id: 42,
			base_price: Money::from( '100.00', 'USD' ),
			customer_id: 10,
			category_ids: [ 5, 15 ],
			category_depths: [ 5 => 1, 15 => 2 ] // 15 is child at depth 2
		);

		$rule_parent = new PricingRule( RuleTarget::user( 10 ), RuleScope::category( 5 ), PricingValue::percentage( '50.000000' ) );
		$rule_child  = new PricingRule( RuleTarget::user( 10 ), RuleScope::category( 15 ), PricingValue::percentage( '10.000000' ) );
		$this->rule_repo->save( $rule_parent );
		$this->rule_repo->save( $rule_child );

		$result = $this->resolver->resolve( $context );

		// Child category at depth 2 wins over parent at depth 1, despite parent having 50% vs 10%
		$this->assertSame( 15, $result->rule()->scope()->id() );
		$this->assertSame( '10.000000', $result->rule()->pricing_value()->value() );
	}

	public function test_single_category_rule_without_depth_metadata_succeeds_without_error(): void {
		// Single category match: no competing rules, depth comparison is not required
		$context = new PricingContext(
			product_id: 42,
			base_price: Money::from( '100.00', 'USD' ),
			customer_id: 10,
			category_ids: [ 25 ],
			category_depths: [] // No depth metadata provided
		);

		$single_rule = new PricingRule( RuleTarget::user( 10 ), RuleScope::category( 25 ), PricingValue::percentage( '18.000000' ) );
		$this->rule_repo->save( $single_rule );

		$result = $this->resolver->resolve( $context );

		$this->assertFalse( $result->is_fallback() );
		$this->assertSame( PrecedenceLevel::CUSTOMER_CATEGORY, $result->precedence_level() );
		$this->assertSame( 25, $result->rule()->scope()->id() );
		$this->assertSame( '18.000000', $result->rule()->pricing_value()->value() );
	}

	public function test_randomized_repository_ordering_for_competing_categories(): void {
		$context = new PricingContext(
			product_id: 42,
			base_price: Money::from( '100.00', 'USD' ),
			customer_id: 10,
			category_ids: [ 10, 20, 30, 40 ],
			category_depths: [ 10 => 1, 20 => 2, 30 => 2, 40 => 3 ]
		);

		// Rule 40 at depth 3 is the clear expected winner
		$rules = [
			new PricingRule( RuleTarget::user( 10 ), RuleScope::category( 10 ), PricingValue::percentage( '50.000000' ), 1 ),
			new PricingRule( RuleTarget::user( 10 ), RuleScope::category( 20 ), PricingValue::percentage( '20.000000' ), 2 ),
			new PricingRule( RuleTarget::user( 10 ), RuleScope::category( 30 ), PricingValue::percentage( '25.000000' ), 3 ),
			new PricingRule( RuleTarget::user( 10 ), RuleScope::category( 40 ), PricingValue::percentage( '10.000000' ), 4 ),
		];

		for ( $i = 0; $i < 10; $i++ ) {
			$shuffled = $rules;
			shuffle( $shuffled );
			$this->rule_repo->clear();
			foreach ( $shuffled as $r ) {
				$this->rule_repo->save( $r );
			}

			$result = $this->resolver->resolve( $context );
			$this->assertSame( 40, $result->rule()->scope()->id() );
		}
	}

	// =========================================================================
	// Section 4: Related Invariants & Defensive Handling Tests
	// =========================================================================

	public function test_corrupted_inactive_rule_from_repository_throws_exception(): void {
		$context = $this->create_standard_context( product_id: 42, customer_id: 10 );

		// Simulate a rogue repository returning an inactive rule
		$inactive_rule = new PricingRule(
			RuleTarget::user( 10 ),
			RuleScope::product( 42 ),
			PricingValue::percentage( '20.000000' ),
			1,
			RuleStatus::INACTIVE
		);

		$rogue_repo = $this->createMock( \Tierza\Domain\Repositories\RuleRepositoryInterface::class );
		$rogue_repo->method( 'find_active_for_targets' )->willReturn( [ $inactive_rule ] );

		$resolver = new DeterministicRuleResolver( $rogue_repo, $this->group_repo );

		$this->expectException( RuleResolutionException::class );
		$this->expectExceptionMessage( 'Corrupted candidate rules: repository returned inactive rule ID 1.' );

		$resolver->resolve( $context );
	}

	public function test_fixed_price_rule_at_category_scope_throws_exception(): void {
		$context = $this->create_standard_context( product_id: 42, customer_id: 10, category_ids: [ 20 ], category_depths: [ 20 => 1 ] );

		// Fixed-price rule on category scope (constructed via bypass)
		$rule = new PricingRule(
			RuleTarget::user( 10 ),
			RuleScope::product( 42 ),
			PricingValue::fixed( '50.000000' )
		);
		// Mutate scope to category via reflection to test defensive resolver validation
		$ref   = new \ReflectionClass( $rule );
		$scope = $ref->getProperty( 'scope' );
		$scope->setAccessible( true );
		$scope->setValue( $rule, RuleScope::category( 20 ) );

		$this->rule_repo->save( $rule );

		$this->expectException( RuleResolutionException::class );
		$this->expectExceptionMessage( 'fixed-price rules are restricted to product or variation scopes' );

		$this->resolver->resolve( $context );
	}

	public function test_stale_cached_group_entity_trust_boundary_documentation(): void {
		// When a trusted PricingGroup entity is provided in PricingContext, the resolver
		// treats it as the authoritative server-side snapshot for that calculation turn.
		$group   = $this->create_active_group( 5 );
		$context = $this->create_standard_context( product_id: 42, customer_id: 10, group: $group );

		$this->assertSame( 5, $context->group()->id() );
		$this->assertTrue( $context->group()->is_active() );

		// The resolver does NOT perform a redundant DB query to re-fetch the group if the entity is present
		$mock_group_repo = $this->createMock( \Tierza\Domain\Repositories\GroupRepositoryInterface::class );
		$mock_group_repo->expects( $this->never() )->method( 'find_by_id' );

		$resolver = new DeterministicRuleResolver( $this->rule_repo, $mock_group_repo );
		$result   = $resolver->resolve( $context );

		$this->assertTrue( $result->is_fallback() );
	}
}
