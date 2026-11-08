<?php

declare(strict_types=1);

namespace Tierza\Tests\Unit\Application\Pricing;

use PHPUnit\Framework\TestCase;
use Tierza\Application\Pricing\AuthoritativePriceCalculator;
use Tierza\Application\Pricing\DeterministicRuleResolver;
use Tierza\Domain\Entities\PricingGroup;
use Tierza\Domain\Entities\PricingRule;
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

/**
 * Table-driven, exhaustive test suite for the 8-level pricing decision matrix
 * and WooCommerce fallback (TRZ-008 Section 1).
 */
final class PricingDecisionMatrixTest extends TestCase {

	private InMemoryRuleRepository $rule_repo;
	private InMemoryGroupRepository $group_repo;
	private DeterministicRuleResolver $resolver;
	private AuthoritativePriceCalculator $calculator;

	protected function setUp(): void {
		parent::setUp();
		$this->rule_repo  = new InMemoryRuleRepository();
		$this->group_repo = new InMemoryGroupRepository();
		$this->resolver   = new DeterministicRuleResolver( $this->rule_repo, $this->group_repo );
		$this->calculator = new AuthoritativePriceCalculator( default_currency_decimals: 2 );
	}

	/**
	 * @dataProvider provide_precedence_tier_matrix
	 *
	 * @param array<PricingRule> $candidate_rules
	 * @param int                $expected_winning_rule_id
	 * @param PrecedenceLevel    $expected_precedence
	 * @param PricingSource      $expected_source
	 * @param string             $expected_reason
	 * @param string             $expected_final_price
	 */
	public function test_precedence_tier_matrix(
		PricingContext $context,
		array $candidate_rules,
		?int $expected_winning_rule_id,
		PrecedenceLevel $expected_precedence,
		PricingSource $expected_source,
		string $expected_reason,
		string $expected_final_price
	): void {
		foreach ( $candidate_rules as $rule ) {
			$this->rule_repo->save( $rule );
		}

		$resolution = $this->resolver->resolve( $context );
		$result     = $this->calculator->calculate( $context, $resolution );

		// 1. Assert winning rule identity
		$this->assertSame( $expected_winning_rule_id, $result->applied_rule_id() );

		// 2. Assert precedence level
		$this->assertSame( $expected_precedence, $resolution->precedence_level() );

		// 3. Assert pricing source
		$this->assertSame( $expected_source, $result->source() );

		// 4. Assert reason code
		$this->assertSame( $expected_reason, $result->reason_code() );

		// 5. Assert final monetary price
		$this->assertSame( $expected_final_price, $result->final_price()->amount() );

		// 6. Assert independent oracle agreement
		if ( null !== $expected_winning_rule_id ) {
			$winning_rule = $this->rule_repo->find_by_id( $expected_winning_rule_id );
			$this->assertNotNull( $winning_rule );
			if ( $winning_rule->pricing_value()->is_percentage() ) {
				$oracle_price = ExactRationalPricingOracle::calculate_percentage(
					$context->base_price()->amount(),
					$winning_rule->pricing_value()->value(),
					$context->currency_decimals()
				);
			} else {
				$oracle_price = ExactRationalPricingOracle::calculate_fixed(
					$winning_rule->pricing_value()->value(),
					$context->currency_decimals()
				);
			}
			$this->assertSame( $oracle_price, $result->final_price()->amount() );
			$this->assertTrue( $result->is_rule_applied() );
		} else {
			$this->assertFalse( $result->is_rule_applied() );
			$this->assertSame( $context->base_price()->amount(), $result->final_price()->amount() );
		}

		// 7. Assert non-stacking: exactly one rule applied, original price unaffected
		$this->assertSame( $context->base_price()->amount(), $result->original_price()->amount() );
		$this->assertSame( $context->base_price()->currency(), $result->final_price()->currency() );
	}

	/**
	 * @return array<string, array<mixed>>
	 */
	public static function provide_precedence_tier_matrix(): array {
		$group = new PricingGroup(
			name: 'VIP Wholesale',
			slug: new GroupSlug( 'vip-wholesale' ),
			status: GroupStatus::active(),
			id: 10
		);

		$base_price = Money::from( '100.000000', 'USD' );

		// Context with authenticated customer, assigned group, variation product, categories
		$context = new PricingContext(
			product_id: 50,
			base_price: $base_price,
			customer_id: 101,
			group: $group,
			variation_id: 501,
			category_ids: [ 10, 20 ],
			category_depths: [ 10 => 1, 20 => 2 ],
			group_id: 10,
			currency_decimals: 2
		);

		// Rules covering all 8 levels:
		$rule1_cust_var  = new PricingRule( RuleTarget::user( 101 ), RuleScope::variation( 501 ), PricingValue::percentage( '50' ), id: 1 );
		$rule2_cust_prod = new PricingRule( RuleTarget::user( 101 ), RuleScope::product( 50 ), PricingValue::percentage( '40' ), id: 2 );
		$rule3_cust_cat  = new PricingRule( RuleTarget::user( 101 ), RuleScope::category( 20 ), PricingValue::percentage( '35' ), id: 3 );
		$rule4_cust_glob = new PricingRule( RuleTarget::user( 101 ), RuleScope::global(), PricingValue::percentage( '30' ), id: 4 );
		$rule5_grp_var   = new PricingRule( RuleTarget::group( 10 ), RuleScope::variation( 501 ), PricingValue::percentage( '25' ), id: 5 );
		$rule6_grp_prod  = new PricingRule( RuleTarget::group( 10 ), RuleScope::product( 50 ), PricingValue::percentage( '20' ), id: 6 );
		$rule7_grp_cat   = new PricingRule( RuleTarget::group( 10 ), RuleScope::category( 20 ), PricingValue::percentage( '15' ), id: 7 );
		$rule8_grp_glob  = new PricingRule( RuleTarget::group( 10 ), RuleScope::global(), PricingValue::percentage( '10' ), id: 8 );

		return [
			'tier_1_customer_variation_wins_over_all' => [
				$context,
				[ $rule1_cust_var, $rule2_cust_prod, $rule3_cust_cat, $rule4_cust_glob, $rule5_grp_var, $rule6_grp_prod, $rule7_grp_cat, $rule8_grp_glob ],
				1,
				PrecedenceLevel::CUSTOMER_VARIATION,
				PricingSource::CUSTOMER,
				PricingReasonCode::RULE_APPLIED_CUSTOMER_VARIATION->value,
				'50.000000',
			],
			'tier_2_customer_product_wins_over_cust_cat_glob_and_group' => [
				$context,
				[ $rule2_cust_prod, $rule3_cust_cat, $rule4_cust_glob, $rule5_grp_var, $rule6_grp_prod, $rule7_grp_cat, $rule8_grp_glob ],
				2,
				PrecedenceLevel::CUSTOMER_PRODUCT,
				PricingSource::CUSTOMER,
				PricingReasonCode::RULE_APPLIED_CUSTOMER_PRODUCT->value,
				'60.000000',
			],
			'tier_3_customer_category_wins_over_cust_glob_and_group' => [
				$context,
				[ $rule3_cust_cat, $rule4_cust_glob, $rule5_grp_var, $rule6_grp_prod, $rule7_grp_cat, $rule8_grp_glob ],
				3,
				PrecedenceLevel::CUSTOMER_CATEGORY,
				PricingSource::CUSTOMER,
				PricingReasonCode::RULE_APPLIED_CUSTOMER_CATEGORY->value,
				'65.000000',
			],
			'tier_4_customer_global_wins_over_group_variation' => [
				$context,
				[ $rule4_cust_glob, $rule5_grp_var, $rule6_grp_prod, $rule7_grp_cat, $rule8_grp_glob ],
				4,
				PrecedenceLevel::CUSTOMER_GLOBAL,
				PricingSource::CUSTOMER,
				PricingReasonCode::RULE_APPLIED_CUSTOMER_GLOBAL->value,
				'70.000000',
			],
			'tier_5_group_variation_wins_over_group_product' => [
				$context,
				[ $rule5_grp_var, $rule6_grp_prod, $rule7_grp_cat, $rule8_grp_glob ],
				5,
				PrecedenceLevel::GROUP_VARIATION,
				PricingSource::GROUP,
				PricingReasonCode::RULE_APPLIED_GROUP_VARIATION->value,
				'75.000000',
			],
			'tier_6_group_product_wins_over_group_category' => [
				$context,
				[ $rule6_grp_prod, $rule7_grp_cat, $rule8_grp_glob ],
				6,
				PrecedenceLevel::GROUP_PRODUCT,
				PricingSource::GROUP,
				PricingReasonCode::RULE_APPLIED_GROUP_PRODUCT->value,
				'80.000000',
			],
			'tier_7_group_category_wins_over_group_global' => [
				$context,
				[ $rule7_grp_cat, $rule8_grp_glob ],
				7,
				PrecedenceLevel::GROUP_CATEGORY,
				PricingSource::GROUP,
				PricingReasonCode::RULE_APPLIED_GROUP_CATEGORY->value,
				'85.000000',
			],
			'tier_8_group_global_wins_over_fallback' => [
				$context,
				[ $rule8_grp_glob ],
				8,
				PrecedenceLevel::GROUP_GLOBAL,
				PricingSource::GROUP,
				PricingReasonCode::RULE_APPLIED_GROUP_GLOBAL->value,
				'90.000000',
			],
			'tier_9_no_rules_yields_woocommerce_fallback' => [
				$context,
				[],
				null,
				PrecedenceLevel::WOOCOMMERCE_FALLBACK,
				PricingSource::WOOCOMMERCE_FALLBACK,
				PricingReasonCode::FALLBACK_NO_MATCHING_RULE->value,
				'100.000000',
			],
		];
	}

	public function test_anonymous_user_with_no_rules_yields_anonymous_fallback_reason(): void {
		$context = new PricingContext(
			product_id: 10,
			base_price: Money::from( '100.000000', 'USD' ),
			customer_id: 0,
			currency_decimals: 2
		);

		$resolution = $this->resolver->resolve( $context );
		$result     = $this->calculator->calculate( $context, $resolution );

		$this->assertSame( PrecedenceLevel::WOOCOMMERCE_FALLBACK, $resolution->precedence_level() );
		$this->assertSame( PricingReasonCode::FALLBACK_ANONYMOUS_NO_RULE->value, $result->reason_code() );
		$this->assertSame( '100.000000', $result->final_price()->amount() );
		$this->assertFalse( $result->is_rule_applied() );
	}
}
