<?php

declare(strict_types=1);

namespace Tierza\Tests\Unit\Application\Pricing;

use PHPUnit\Framework\TestCase;
use Tierza\Application\Pricing\AuthoritativePriceCalculator;
use Tierza\Application\Pricing\DeterministicRuleResolver;
use Tierza\Domain\Entities\PricingGroup;
use Tierza\Domain\Entities\PricingRule;
use Tierza\Domain\Pricing\Enums\PrecedenceLevel;
use Tierza\Domain\Pricing\Enums\PricingSource;
use Tierza\Domain\Pricing\PricingContext;
use Tierza\Domain\ValueObjects\GroupStatus;
use Tierza\Domain\ValueObjects\Money;
use Tierza\Domain\ValueObjects\PricingValue;
use Tierza\Domain\ValueObjects\RuleScope;
use Tierza\Domain\ValueObjects\RuleTarget;

/**
 * Product and Variation Hierarchy Resolution Test Suite (TRZ-008 Section 3).
 *
 * Verifies variation inheritance, parent product fallback, multi-variation isolation,
 * and PRD 3-tier deterministic category conflict resolution.
 */
final class ProductVariationHierarchyTest extends TestCase {

	private InMemoryRuleRepository $rule_repo;
	private InMemoryGroupRepository $group_repo;
	private DeterministicRuleResolver $resolver;
	private AuthoritativePriceCalculator $calculator;
	private PricingGroup $group;

	protected function setUp(): void {
		parent::setUp();
		$this->rule_repo  = new InMemoryRuleRepository();
		$this->group_repo = new InMemoryGroupRepository();
		$this->resolver   = new DeterministicRuleResolver( $this->rule_repo, $this->group_repo );
		$this->calculator = new AuthoritativePriceCalculator( default_currency_decimals: 2 );

		$this->group = new PricingGroup( 'Gold B2B', 'gold-b2b', id: 5, status: GroupStatus::active() );
		$this->group_repo->save( $this->group );
	}

	public function test_simple_product_matches_product_rule(): void {
		$this->rule_repo->save( new PricingRule( RuleTarget::group( 5 ), RuleScope::product( 100 ), PricingValue::percentage( '20' ), id: 1 ) );

		$context = new PricingContext(
			product_id: 100,
			base_price: Money::from( '50.00', 'USD' ),
			customer_id: 10,
			group: $this->group,
			group_id: 5
		);

		$resolution = $this->resolver->resolve( $context );
		$result     = $this->calculator->calculate( $context, $resolution );

		$this->assertSame( 1, $result->applied_rule_id() );
		$this->assertSame( PrecedenceLevel::GROUP_PRODUCT, $resolution->precedence_level() );
		$this->assertSame( '40.000000', $result->final_price()->amount() );
	}

	public function test_variation_specific_rule_wins_over_parent_product_rule(): void {
		// Parent product rule: 20%
		$this->rule_repo->save( new PricingRule( RuleTarget::group( 5 ), RuleScope::product( 100 ), PricingValue::percentage( '20' ), id: 1 ) );
		// Variation rule: 35%
		$this->rule_repo->save( new PricingRule( RuleTarget::group( 5 ), RuleScope::variation( 101 ), PricingValue::percentage( '35' ), id: 2 ) );

		$context = new PricingContext(
			product_id: 100,
			base_price: Money::from( '80.00', 'USD' ),
			customer_id: 10,
			group: $this->group,
			variation_id: 101,
			group_id: 5
		);

		$resolution = $this->resolver->resolve( $context );
		$result     = $this->calculator->calculate( $context, $resolution );

		$this->assertSame( 2, $result->applied_rule_id() );
		$this->assertSame( PrecedenceLevel::GROUP_VARIATION, $resolution->precedence_level() );
		$this->assertSame( '52.000000', $result->final_price()->amount() );
	}

	public function test_variation_inherits_parent_product_rule_when_no_variation_rule_exists(): void {
		// Only parent product rule exists
		$this->rule_repo->save( new PricingRule( RuleTarget::group( 5 ), RuleScope::product( 100 ), PricingValue::percentage( '25' ), id: 10 ) );

		$context = new PricingContext(
			product_id: 100,
			base_price: Money::from( '60.00', 'USD' ),
			customer_id: 10,
			group: $this->group,
			variation_id: 102,
			group_id: 5
		);

		$resolution = $this->resolver->resolve( $context );
		$result     = $this->calculator->calculate( $context, $resolution );

		// Variation inherits parent product rule at Precedence 6 (GROUP_PRODUCT)
		$this->assertSame( 10, $result->applied_rule_id() );
		$this->assertSame( PrecedenceLevel::GROUP_PRODUCT, $resolution->precedence_level() );
		$this->assertSame( '45.000000', $result->final_price()->amount() );
	}

	public function test_different_variations_receive_their_respective_rules(): void {
		$this->rule_repo->save( new PricingRule( RuleTarget::group( 5 ), RuleScope::variation( 101 ), PricingValue::fixed( '29.99' ), id: 21 ) );
		$this->rule_repo->save( new PricingRule( RuleTarget::group( 5 ), RuleScope::variation( 102 ), PricingValue::fixed( '34.99' ), id: 22 ) );

		// Request for Variation 101
		$context1 = new PricingContext(
			product_id: 100,
			base_price: Money::from( '50.00', 'USD' ),
			customer_id: 10,
			group: $this->group,
			variation_id: 101,
			group_id: 5
		);
		$res1 = $this->calculator->calculate( $context1, $this->resolver->resolve( $context1 ) );
		$this->assertSame( 21, $res1->applied_rule_id() );
		$this->assertSame( '29.990000', $res1->final_price()->amount() );

		// Request for Variation 102
		$context2 = new PricingContext(
			product_id: 100,
			base_price: Money::from( '50.00', 'USD' ),
			customer_id: 10,
			group: $this->group,
			variation_id: 102,
			group_id: 5
		);
		$res2 = $this->calculator->calculate( $context2, $this->resolver->resolve( $context2 ) );
		$this->assertSame( 22, $res2->applied_rule_id() );
		$this->assertSame( '34.990000', $res2->final_price()->amount() );
	}

	public function test_unrelated_variation_falls_back_when_no_rules_match(): void {
		$this->rule_repo->save( new PricingRule( RuleTarget::group( 5 ), RuleScope::variation( 101 ), PricingValue::fixed( '29.99' ), id: 21 ) );

		// Request for Variation 103 (unrelated, no parent rule either)
		$context = new PricingContext(
			product_id: 100,
			base_price: Money::from( '50.00', 'USD' ),
			customer_id: 10,
			group: $this->group,
			variation_id: 103,
			group_id: 5
		);

		$resolution = $this->resolver->resolve( $context );
		$result     = $this->calculator->calculate( $context, $resolution );

		$this->assertTrue( $resolution->is_fallback() );
		$this->assertSame( '50.000000', $result->final_price()->amount() );
		$this->assertFalse( $result->is_rule_applied() );
	}

	public function test_child_category_beats_parent_category_by_hierarchy_depth(): void {
		// Category 10 is parent (depth 1, 30% discount)
		// Category 20 is child (depth 2, 10% discount)
		$this->rule_repo->save( new PricingRule( RuleTarget::group( 5 ), RuleScope::category( 10 ), PricingValue::percentage( '30' ), id: 101 ) );
		$this->rule_repo->save( new PricingRule( RuleTarget::group( 5 ), RuleScope::category( 20 ), PricingValue::percentage( '10' ), id: 102 ) );

		$context = new PricingContext(
			product_id: 200,
			base_price: Money::from( '100.00', 'USD' ),
			customer_id: 10,
			group: $this->group,
			category_ids: [ 10, 20 ],
			category_depths: [ 10 => 1, 20 => 2 ],
			group_id: 5
		);

		$resolution = $this->resolver->resolve( $context );
		$result     = $this->calculator->calculate( $context, $resolution );

		// Depth 2 strictly beats Depth 1 even though Depth 1 has a higher discount percentage
		$this->assertSame( 102, $result->applied_rule_id() );
		$this->assertSame( '90.000000', $result->final_price()->amount() );
	}

	public function test_identical_depth_categories_tie_break_by_highest_discount(): void {
		// Category 15: depth 2, 15% discount
		// Category 25: depth 2, 25% discount
		$this->rule_repo->save( new PricingRule( RuleTarget::group( 5 ), RuleScope::category( 15 ), PricingValue::percentage( '15' ), id: 201 ) );
		$this->rule_repo->save( new PricingRule( RuleTarget::group( 5 ), RuleScope::category( 25 ), PricingValue::percentage( '25' ), id: 202 ) );

		$context = new PricingContext(
			product_id: 200,
			base_price: Money::from( '100.00', 'USD' ),
			customer_id: 10,
			group: $this->group,
			category_ids: [ 15, 25 ],
			category_depths: [ 15 => 2, 25 => 2 ],
			group_id: 5
		);

		$resolution = $this->resolver->resolve( $context );
		$result     = $this->calculator->calculate( $context, $resolution );

		// Equal depth: 25% produces lowest price ($75), so rule 202 wins
		$this->assertSame( 202, $result->applied_rule_id() );
		$this->assertSame( '75.000000', $result->final_price()->amount() );
	}

	public function test_equal_depth_and_equal_discount_tie_break_by_lowest_category_id(): void {
		// Category 12: depth 2, 20% discount
		// Category 18: depth 2, 20% discount
		$this->rule_repo->save( new PricingRule( RuleTarget::group( 5 ), RuleScope::category( 12 ), PricingValue::percentage( '20' ), id: 301 ) );
		$this->rule_repo->save( new PricingRule( RuleTarget::group( 5 ), RuleScope::category( 18 ), PricingValue::percentage( '20' ), id: 302 ) );

		$context = new PricingContext(
			product_id: 200,
			base_price: Money::from( '100.00', 'USD' ),
			customer_id: 10,
			group: $this->group,
			category_ids: [ 12, 18 ],
			category_depths: [ 12 => 2, 18 => 2 ],
			group_id: 5
		);

		$resolution = $this->resolver->resolve( $context );
		$result     = $this->calculator->calculate( $context, $resolution );

		// Equal depth & discount: category ID 12 < 18, rule 301 wins
		$this->assertSame( 301, $result->applied_rule_id() );
		$this->assertSame( '80.000000', $result->final_price()->amount() );
	}

	public function test_calculation_stage_never_alters_or_overrides_chosen_rule(): void {
		$rule = new PricingRule( RuleTarget::group( 5 ), RuleScope::product( 100 ), PricingValue::percentage( '15' ), id: 401 );
		$this->rule_repo->save( $rule );

		$context = new PricingContext(
			product_id: 100,
			base_price: Money::from( '120.00', 'USD' ),
			customer_id: 10,
			group: $this->group,
			group_id: 5
		);

		$resolution = $this->resolver->resolve( $context );
		$result     = $this->calculator->calculate( $context, $resolution );

		// Check diagnostic metadata maintains exact traceability
		$this->assertSame( 401, $result->applied_rule_id() );
		$this->assertSame( 'product', $result->scope_type() );
		$this->assertSame( 'percentage', $result->pricing_type() );
		$this->assertSame( PricingSource::GROUP, $result->source() );
		$this->assertSame( '102.000000', $result->final_price()->amount() );
	}
}
