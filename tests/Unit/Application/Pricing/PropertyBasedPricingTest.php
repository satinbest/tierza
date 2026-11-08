<?php

declare(strict_types=1);

namespace Tierza\Tests\Unit\Application\Pricing;

use PHPUnit\Framework\TestCase;
use Tierza\Application\Pricing\AuthoritativePriceCalculator;
use Tierza\Application\Pricing\DeterministicRuleResolver;
use Tierza\Domain\Entities\PricingGroup;
use Tierza\Domain\Entities\PricingRule;
use Tierza\Domain\Pricing\Enums\PrecedenceLevel;
use Tierza\Domain\Pricing\PricingContext;
use Tierza\Domain\Pricing\ResolutionResult;
use Tierza\Domain\ValueObjects\DecimalMath;
use Tierza\Domain\ValueObjects\GroupStatus;
use Tierza\Domain\ValueObjects\Money;
use Tierza\Domain\ValueObjects\PricingValue;
use Tierza\Domain\ValueObjects\RuleScope;
use Tierza\Domain\ValueObjects\RuleTarget;

/**
 * Property-Based and Metamorphic Testing Suite (TRZ-008 Section 5).
 *
 * Verifies mathematical invariants, monotonicity, non-negativity, permutation invariance,
 * rule subsumption, idempotence, and immutability using reproducible pseudo-random generation.
 */
final class PropertyBasedPricingTest extends TestCase {

	public const REPRODUCIBLE_SEED = 20261109;

	private AuthoritativePriceCalculator $bcmath_calc;
	private AuthoritativePriceCalculator $php_calc;

	protected function setUp(): void {
		parent::setUp();
		$this->bcmath_calc = new AuthoritativePriceCalculator( default_currency_decimals: 2, math_driver: DecimalMath::DRIVER_BCMATH );
		$this->php_calc    = new AuthoritativePriceCalculator( default_currency_decimals: 2, math_driver: DecimalMath::DRIVER_PHP );
		mt_srand( self::REPRODUCIBLE_SEED );
	}

	/**
	 * Property 1: 0% discount retains the exact base price at the documented currency boundary.
	 */
	public function test_property_zero_percent_discount_identity(): void {
		for ( $i = 0; $i < 50; $i++ ) {
			$int_part  = mt_rand( 1, 100000 );
			$frac_part = str_pad( (string) mt_rand( 0, 999999 ), 6, '0', STR_PAD_LEFT );
			$price_str = $int_part . '.' . $frac_part;
			$decimals  = mt_rand( 0, 6 );

			$context = new PricingContext(
				product_id: 1,
				base_price: Money::from( $price_str, 'USD' ),
				customer_id: 1,
				currency_decimals: $decimals
			);

			$rule = new PricingRule( RuleTarget::user( 1 ), RuleScope::product( 1 ), PricingValue::percentage( '0' ), id: 10 );
			$res  = ResolutionResult::matched( $rule, PrecedenceLevel::CUSTOMER_PRODUCT );

			$result = $this->bcmath_calc->calculate( $context, $res );

			$expected_rounded = DecimalMath::round( $price_str, $decimals, PHP_ROUND_HALF_UP );
			$expected_money   = new Money( $expected_rounded, 'USD' );

			$this->assertSame( $expected_money->amount(), $result->final_price()->amount() );
			$this->assertTrue( $result->is_zero_percentage() );
			$this->assertTrue( $result->is_rule_applied() );
		}
	}

	/**
	 * Property 2: 100% discount produces exact zero across all base prices and currency decimals.
	 */
	public function test_property_hundred_percent_discount_produces_zero(): void {
		for ( $i = 0; $i < 50; $i++ ) {
			$price_str = (string) mt_rand( 1, 1000000 ) . '.' . str_pad( (string) mt_rand( 0, 99 ), 2, '0' );
			$decimals  = mt_rand( 0, 6 );

			$context = new PricingContext(
				product_id: 1,
				base_price: Money::from( $price_str, 'USD' ),
				customer_id: 1,
				currency_decimals: $decimals
			);

			$rule = new PricingRule( RuleTarget::user( 1 ), RuleScope::product( 1 ), PricingValue::percentage( '100' ), id: 10 );
			$res  = ResolutionResult::matched( $rule, PrecedenceLevel::CUSTOMER_PRODUCT );

			$result = $this->bcmath_calc->calculate( $context, $res );

			$this->assertSame( '0.000000', $result->final_price()->amount() );
			$this->assertTrue( $result->final_price()->is_zero() );
		}
	}

	/**
	 * Property 3: Monotonicity — for a fixed base price, increasing discount percentage never increases final price.
	 */
	public function test_property_discount_monotonicity(): void {
		for ( $i = 0; $i < 30; $i++ ) {
			$base_price = (string) mt_rand( 10, 5000 ) . '.00';
			$decimals   = mt_rand( 0, 4 );

			$context = new PricingContext(
				product_id: 1,
				base_price: Money::from( $base_price, 'USD' ),
				customer_id: 1,
				currency_decimals: $decimals
			);

			$previous_price = $context->base_price()->amount();

			// Generate ascending discounts: e.g. 5%, 10%, 15%, 20%, 50%, 80%, 100%
			$discounts = [ '0', '5.5', '12.25', '25', '50.1234', '75', '99.99', '100' ];
			foreach ( $discounts as $discount ) {
				$rule   = new PricingRule( RuleTarget::user( 1 ), RuleScope::product( 1 ), PricingValue::percentage( $discount ), id: 10 );
				$res    = ResolutionResult::matched( $rule, PrecedenceLevel::CUSTOMER_PRODUCT );
				$result = $this->bcmath_calc->calculate( $context, $res );

				$current_price = $result->final_price()->amount();

				// Assertion: current_price <= previous_price
				$this->assertLessThanOrEqual(
					0,
					DecimalMath::comp( $current_price, $previous_price, 6 ),
					sprintf( 'Monotonicity violated for base %s, discount %s%%: %s > %s', $base_price, $discount, $current_price, $previous_price )
				);

				$previous_price = $current_price;
			}
		}
	}

	/**
	 * Property 4: Non-negativity — final prices are never negative under any valid inputs.
	 */
	public function test_property_final_prices_never_negative(): void {
		for ( $i = 0; $i < 50; $i++ ) {
			$base_int  = mt_rand( 0, 50000 );
			$base_frac = str_pad( (string) mt_rand( 0, 999999 ), 6, '0', STR_PAD_LEFT );
			$base_str  = $base_int . '.' . $base_frac;

			$disc_int  = mt_rand( 0, 100 );
			$disc_frac = str_pad( (string) mt_rand( 0, 999999 ), 6, '0', STR_PAD_LEFT );
			$disc_str  = $disc_int === 100 ? '100.000000' : ( $disc_int . '.' . $disc_frac );

			$decimals = mt_rand( 0, 6 );

			$context = new PricingContext(
				product_id: 1,
				base_price: Money::from( $base_str, 'EUR' ),
				customer_id: 1,
				currency_decimals: $decimals
			);

			$rule   = new PricingRule( RuleTarget::user( 1 ), RuleScope::product( 1 ), PricingValue::percentage( $disc_str ), id: 10 );
			$result = $this->bcmath_calc->calculate( $context, ResolutionResult::matched( $rule, PrecedenceLevel::CUSTOMER_PRODUCT ) );

			$this->assertGreaterThanOrEqual(
				0,
				DecimalMath::comp( $result->final_price()->amount(), '0.000000', 6 )
			);
		}
	}

	/**
	 * Property 5: Permutation invariance — reordering repository results does not change the winning rule.
	 */
	public function test_property_permutation_invariance_of_rules(): void {
		$group = new PricingGroup( 'VIP', 'vip', id: 5, status: GroupStatus::active() );

		$rules = [
			new PricingRule( RuleTarget::user( 1 ), RuleScope::variation( 101 ), PricingValue::percentage( '50' ), id: 1 ),
			new PricingRule( RuleTarget::user( 1 ), RuleScope::product( 10 ), PricingValue::percentage( '40' ), id: 2 ),
			new PricingRule( RuleTarget::group( 5 ), RuleScope::variation( 101 ), PricingValue::percentage( '30' ), id: 3 ),
			new PricingRule( RuleTarget::group( 5 ), RuleScope::product( 10 ), PricingValue::percentage( '20' ), id: 4 ),
			new PricingRule( RuleTarget::group( 5 ), RuleScope::global(), PricingValue::percentage( '10' ), id: 5 ),
		];

		$context = new PricingContext(
			product_id: 10,
			base_price: Money::from( '100.00', 'USD' ),
			customer_id: 1,
			group: $group,
			variation_id: 101,
			group_id: 5
		);

		// Try 10 random permutations of the candidate rules
		for ( $i = 0; $i < 10; $i++ ) {
			$shuffled = $rules;
			shuffle( $shuffled );

			$repo     = new InMemoryRuleRepository();
			$grp_repo = new InMemoryGroupRepository();
			$grp_repo->save( $group );
			foreach ( $shuffled as $r ) {
				$repo->save( $r );
			}

			$resolver   = new DeterministicRuleResolver( $repo, $grp_repo );
			$resolution = $resolver->resolve( $context );

			// Rule 1 (Customer Variation) must win regardless of insertion order
			$this->assertSame( 1, $resolution->rule()?->id() );
			$this->assertSame( PrecedenceLevel::CUSTOMER_VARIATION, $resolution->precedence_level() );
		}
	}

	/**
	 * Property 6: Subsumption / Independence — inserting unrelated rules does not change the resolution result.
	 */
	public function test_property_subsumption_unrelated_rules_do_not_affect_result(): void {
		$repo     = new InMemoryRuleRepository();
		$grp_repo = new InMemoryGroupRepository();

		// Target rule
		$target_rule = new PricingRule( RuleTarget::user( 1 ), RuleScope::product( 10 ), PricingValue::percentage( '25' ), id: 100 );
		$repo->save( $target_rule );

		$context = new PricingContext(
			product_id: 10,
			base_price: Money::from( '100.00', 'USD' ),
			customer_id: 1
		);

		$resolver     = new DeterministicRuleResolver( $repo, $grp_repo );
		$base_res     = $resolver->resolve( $context );
		$base_outcome = $this->bcmath_calc->calculate( $context, $base_res );

		// Insert 20 unrelated rules (different users, different products)
		for ( $i = 1; $i <= 20; $i++ ) {
			$repo->save( new PricingRule( RuleTarget::user( 200 + $i ), RuleScope::product( 500 + $i ), PricingValue::percentage( '10' ), id: 1000 + $i ) );
		}

		$new_res     = $resolver->resolve( $context );
		$new_outcome = $this->bcmath_calc->calculate( $context, $new_res );

		$this->assertSame( $base_res->rule()?->id(), $new_res->rule()?->id() );
		$this->assertSame( $base_outcome->final_price()->amount(), $new_outcome->final_price()->amount() );
	}

	/**
	 * Property 7: Idempotence — repeated resolution and calculation are completely deterministic.
	 */
	public function test_property_idempotence_and_determinism(): void {
		$repo     = new InMemoryRuleRepository();
		$grp_repo = new InMemoryGroupRepository();
		$rule     = new PricingRule( RuleTarget::user( 1 ), RuleScope::product( 10 ), PricingValue::percentage( '17.5' ), id: 42 );
		$repo->save( $rule );

		$context = new PricingContext(
			product_id: 10,
			base_price: Money::from( '89.99', 'GBP' ),
			customer_id: 1,
			currency_decimals: 2
		);

		$resolver = new DeterministicRuleResolver( $repo, $grp_repo );

		$first_result = $this->bcmath_calc->calculate( $context, $resolver->resolve( $context ) );

		for ( $i = 0; $i < 20; $i++ ) {
			$subsequent_result = $this->bcmath_calc->calculate( $context, $resolver->resolve( $context ) );
			$this->assertSame( $first_result->final_price()->amount(), $subsequent_result->final_price()->amount() );
			$this->assertSame( $first_result->applied_rule_id(), $subsequent_result->applied_rule_id() );
			$this->assertSame( $first_result->reason_code(), $subsequent_result->reason_code() );
		}
	}

	/**
	 * Property 8: Fixed-price independence — a fixed-price rule value is independent from base price.
	 */
	public function test_property_fixed_price_independent_from_base_price(): void {
		$fixed_rule = new PricingRule( RuleTarget::user( 1 ), RuleScope::product( 10 ), PricingValue::fixed( '49.99' ), id: 1 );
		$resolution = ResolutionResult::matched( $fixed_rule, PrecedenceLevel::CUSTOMER_PRODUCT );

		$base_prices = [ '10.00', '49.99', '100.00', '999.99', '5000.00' ];
		foreach ( $base_prices as $base_str ) {
			$context = new PricingContext(
				product_id: 10,
				base_price: Money::from( $base_str, 'USD' ),
				customer_id: 1,
				currency_decimals: 2
			);

			$result = $this->bcmath_calc->calculate( $context, $resolution );
			$this->assertSame( '49.990000', $result->final_price()->amount() );
		}
	}

	/**
	 * Property 9: Immutability — calculations never modify PricingContext, Money or PricingRule.
	 */
	public function test_property_immutability_of_context_and_rule(): void {
		$base_money = Money::from( '150.00', 'USD' );
		$rule_value = PricingValue::percentage( '20' );
		$rule       = new PricingRule( RuleTarget::user( 1 ), RuleScope::product( 10 ), $rule_value, id: 99 );

		$context = new PricingContext(
			product_id: 10,
			base_price: $base_money,
			customer_id: 1,
			currency_decimals: 2
		);

		$resolution = ResolutionResult::matched( $rule, PrecedenceLevel::CUSTOMER_PRODUCT );

		$result = $this->bcmath_calc->calculate( $context, $resolution );

		// Assert context unchanged
		$this->assertSame( '150.000000', $context->base_price()->amount() );
		$this->assertSame( 'USD', $context->base_price()->currency() );
		$this->assertSame( 1, $context->customer_id() );
		$this->assertSame( 10, $context->product_id() );

		// Assert rule unchanged
		$this->assertSame( '20.000000', $rule->pricing_value()->value() );
		$this->assertSame( 99, $rule->id() );

		// Assert base Money unchanged
		$this->assertSame( '150.000000', $base_money->amount() );

		// Result is distinct instance
		$this->assertNotSame( $context->base_price(), $result->final_price() );
	}

	/**
	 * Property 10: Driver Parity — BCMath and Pure-PHP operations yield identical outputs on 100 randomized vectors.
	 */
	public function test_property_bcmath_and_pure_php_driver_parity(): void {
		for ( $i = 0; $i < 100; $i++ ) {
			$base_int  = mt_rand( 1, 10000 );
			$base_frac = str_pad( (string) mt_rand( 0, 999999 ), 6, '0', STR_PAD_LEFT );
			$base_str  = $base_int . '.' . $base_frac;

			$disc_int  = mt_rand( 0, 100 );
			$disc_frac = str_pad( (string) mt_rand( 0, 999999 ), 6, '0', STR_PAD_LEFT );
			$disc_str  = $disc_int === 100 ? '100.000000' : ( $disc_int . '.' . $disc_frac );

			$decimals = mt_rand( 0, 6 );

			$context = new PricingContext(
				product_id: 1,
				base_price: Money::from( $base_str, 'USD' ),
				customer_id: 1,
				currency_decimals: $decimals
			);

			$rule = new PricingRule( RuleTarget::user( 1 ), RuleScope::product( 1 ), PricingValue::percentage( $disc_str ), id: 10 );
			$res  = ResolutionResult::matched( $rule, PrecedenceLevel::CUSTOMER_PRODUCT );

			$bcmath_out = $this->bcmath_calc->calculate( $context, $res );
			$php_out    = $this->php_calc->calculate( $context, $res );

			$this->assertSame(
				$bcmath_out->final_price()->amount(),
				$php_out->final_price()->amount(),
				sprintf( 'Driver disparity for base=%s disc=%s dec=%d', $base_str, $disc_str, $decimals )
			);
			$this->assertSame(
				$bcmath_out->formatted_final_price(),
				$php_out->formatted_final_price()
			);
		}
	}
}
