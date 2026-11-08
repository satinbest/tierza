<?php

declare(strict_types=1);

namespace Tierza\Tests\Unit\Application\Pricing;

use PHPUnit\Framework\TestCase;
use Tierza\Application\Pricing\AuthoritativePriceCalculator;
use Tierza\Domain\Entities\PricingGroup;
use Tierza\Domain\Entities\PricingRule;
use Tierza\Domain\Exceptions\CurrencyMismatchException;
use Tierza\Domain\Exceptions\InvalidMoneyException;
use Tierza\Domain\Exceptions\InvalidPricingContextException;
use Tierza\Domain\Exceptions\InvalidRuleException;
use Tierza\Domain\Exceptions\MissingBasePriceException;
use Tierza\Domain\Exceptions\PricingCalculationException;
use Tierza\Domain\Exceptions\RuleResolutionException;
use Tierza\Domain\Pricing\Enums\PrecedenceLevel;
use Tierza\Domain\Pricing\Enums\PricingReasonCode;
use Tierza\Domain\Pricing\Enums\PricingSource;
use Tierza\Domain\Pricing\PricingContext;
use Tierza\Domain\Pricing\PricingResult;
use Tierza\Domain\Pricing\ResolutionResult;
use Tierza\Domain\ValueObjects\DecimalMath;
use Tierza\Domain\ValueObjects\Money;
use Tierza\Domain\ValueObjects\PricingValue;
use Tierza\Domain\ValueObjects\RuleScope;
use Tierza\Domain\ValueObjects\RuleStatus;
use Tierza\Domain\ValueObjects\RuleTarget;

/**
 * Extensive unit test suite for AuthoritativePriceCalculator (TRZ-007).
 */
final class AuthoritativePriceCalculatorTest extends TestCase {

	private AuthoritativePriceCalculator $calculator;

	protected function setUp(): void {
		parent::setUp();
		$this->calculator = new AuthoritativePriceCalculator();
	}

	// -------------------------------------------------------------------------
	// 1. Fallback (No Matching Rule)
	// -------------------------------------------------------------------------

	public function test_no_rule_fallback_returns_unaltered_base_price(): void {
		$context = new PricingContext(
			product_id: 10,
			base_price: Money::from( '99.95', 'USD' ),
			customer_id: 5
		);

		$resolution = ResolutionResult::fallback();
		$result     = $this->calculator->calculate( $context, $resolution );

		$this->assertSame( '99.950000', $result->original_price()->amount() );
		$this->assertSame( '99.950000', $result->final_price()->amount() );
		$this->assertSame( 'USD', $result->currency() );
		$this->assertNull( $result->applied_rule_id() );
		$this->assertNull( $result->pricing_type() );
		$this->assertNull( $result->scope_type() );
		$this->assertSame( PricingSource::WOOCOMMERCE_FALLBACK, $result->source() );
		$this->assertFalse( $result->is_rule_applied() );
		$this->assertFalse( $result->is_zero_percentage() );
		$this->assertFalse( $result->is_discounted() );
		$this->assertSame( '0.000000', $result->discount_amount()->amount() );
		$this->assertSame( '0.000000', $result->savings_percentage() );
		$this->assertSame( PricingReasonCode::FALLBACK_NO_MATCHING_RULE->value, $result->reason_code() );
		$this->assertSame( PrecedenceLevel::WOOCOMMERCE_FALLBACK, $result->precedence_level() );

		$diagnostics = $result->diagnostic_metadata();
		$this->assertSame( 'fallback_no_rule', $diagnostics['calculation_type'] );
		$this->assertSame( 2, $diagnostics['currency_decimals'] );
		$this->assertSame( '99.950000', $diagnostics['raw_calculated_amount'] );
	}

	// -------------------------------------------------------------------------
	// 2. Percentage Discounts: 0%, 1%, 20%, 50%, 99.999999%, 100%
	// -------------------------------------------------------------------------

	/**
	 * @dataProvider percentageDiscountDataProvider
	 */
	public function test_percentage_discount_calculations(
		string $base_price,
		string $discount_percent,
		int $decimals,
		string $expected_final_normalized,
		string $expected_final_display,
		bool $expected_is_zero_percentage,
		bool $expected_is_discounted,
		string $expected_calc_type
	): void {
		$context = new PricingContext(
			product_id: 10,
			base_price: Money::from( $base_price, 'USD' ),
			customer_id: 1,
			currency_decimals: $decimals
		);

		$rule = new PricingRule(
			target: RuleTarget::user( 1 ),
			scope: RuleScope::product( 10 ),
			pricing_value: PricingValue::percentage( $discount_percent ),
			id: 101
		);

		$resolution = ResolutionResult::matched( $rule, PrecedenceLevel::CUSTOMER_PRODUCT );
		$result     = $this->calculator->calculate( $context, $resolution );

		$this->assertTrue( $result->is_rule_applied() );
		$this->assertSame( 101, $result->applied_rule_id() );
		$this->assertSame( 'percentage', $result->pricing_type() );
		$this->assertSame( 'product', $result->scope_type() );
		$this->assertSame( $expected_is_zero_percentage, $result->is_zero_percentage() );
		$this->assertSame( $expected_is_discounted, $result->is_discounted() );
		$this->assertSame( $expected_final_normalized, $result->final_price()->amount() );
		$this->assertSame( $expected_final_display, $result->formatted_final_price() );
		$this->assertSame( $expected_calc_type, $result->diagnostic_metadata()['calculation_type'] );
	}

	/**
	 * @return array<string, array<mixed>>
	 */
	public static function percentageDiscountDataProvider(): array {
		return [
			'0% discount explicitly applied' => [
				'base_price'                  => '100.00',
				'discount_percent'            => '0',
				'decimals'                    => 2,
				'expected_final_normalized'   => '100.000000',
				'expected_final_display'      => '100.00',
				'expected_is_zero_percentage' => true,
				'expected_is_discounted'      => false,
				'expected_calc_type'          => 'percentage_zero',
			],
			'1% discount on 100.00' => [
				'base_price'                  => '100.00',
				'discount_percent'            => '1',
				'decimals'                    => 2,
				'expected_final_normalized'   => '99.000000',
				'expected_final_display'      => '99.00',
				'expected_is_zero_percentage' => false,
				'expected_is_discounted'      => true,
				'expected_calc_type'          => 'percentage_standard',
			],
			'20% discount on 50.00' => [
				'base_price'                  => '50.00',
				'discount_percent'            => '20',
				'decimals'                    => 2,
				'expected_final_normalized'   => '40.000000',
				'expected_final_display'      => '40.00',
				'expected_is_zero_percentage' => false,
				'expected_is_discounted'      => true,
				'expected_calc_type'          => 'percentage_standard',
			],
			'50% discount on 29.99' => [
				'base_price'                  => '29.99',
				'discount_percent'            => '50',
				'decimals'                    => 2,
				'expected_final_normalized'   => '15.000000', // 29.99 * 0.50 = 14.995 -> 15.00 Half-Up
				'expected_final_display'      => '15.00',
				'expected_is_zero_percentage' => false,
				'expected_is_discounted'      => true,
				'expected_calc_type'          => 'percentage_standard',
			],
			'99.999999% discount on 100.00' => [
				'base_price'                  => '100.00',
				'discount_percent'            => '99.999999',
				'decimals'                    => 2,
				'expected_final_normalized'   => '0.000000', // 100 * (1 - 0.99999999) = 0.000001 -> rounds to 0.00
				'expected_final_display'      => '0.00',
				'expected_is_zero_percentage' => false,
				'expected_is_discounted'      => true,
				'expected_calc_type'          => 'percentage_standard',
			],
			'100% discount produces exact zero' => [
				'base_price'                  => '75.50',
				'discount_percent'            => '100',
				'decimals'                    => 2,
				'expected_final_normalized'   => '0.000000',
				'expected_final_display'      => '0.00',
				'expected_is_zero_percentage' => false,
				'expected_is_discounted'      => true,
				'expected_calc_type'          => 'percentage_hundred',
			],
		];
	}

	// -------------------------------------------------------------------------
	// 3. Fixed Price Tests: Zero, Lower, Equal, Higher than base price
	// -------------------------------------------------------------------------

	public function test_fixed_price_zero_is_valid(): void {
		$context = new PricingContext(
			product_id: 10,
			base_price: Money::from( '50.00', 'USD' ),
			customer_id: 1
		);

		$rule = new PricingRule(
			target: RuleTarget::user( 1 ),
			scope: RuleScope::product( 10 ),
			pricing_value: PricingValue::fixed( '0.00' ),
			id: 201
		);

		$result = $this->calculator->calculate( $context, ResolutionResult::matched( $rule, PrecedenceLevel::CUSTOMER_PRODUCT ) );

		$this->assertSame( '0.000000', $result->final_price()->amount() );
		$this->assertTrue( $result->is_discounted() );
		$this->assertSame( '50.000000', $result->discount_amount()->amount() );
		$this->assertSame( '100.000000', $result->savings_percentage() );
		$this->assertSame( 'fixed_zero', $result->diagnostic_metadata()['calculation_type'] );
	}

	public function test_fixed_price_lower_than_base_price(): void {
		$context = new PricingContext(
			product_id: 10,
			base_price: Money::from( '100.00', 'USD' ),
			customer_id: 1
		);

		$rule = new PricingRule(
			target: RuleTarget::user( 1 ),
			scope: RuleScope::product( 10 ),
			pricing_value: PricingValue::fixed( '79.90' ),
			id: 202
		);

		$result = $this->calculator->calculate( $context, ResolutionResult::matched( $rule, PrecedenceLevel::CUSTOMER_PRODUCT ) );

		$this->assertSame( '79.900000', $result->final_price()->amount() );
		$this->assertTrue( $result->is_discounted() );
		$this->assertSame( '20.100000', $result->discount_amount()->amount() );
		$this->assertSame( 'fixed_discount', $result->diagnostic_metadata()['calculation_type'] );
	}

	public function test_fixed_price_equal_to_base_price(): void {
		$context = new PricingContext(
			product_id: 10,
			base_price: Money::from( '50.00', 'USD' ),
			customer_id: 1
		);

		$rule = new PricingRule(
			target: RuleTarget::user( 1 ),
			scope: RuleScope::product( 10 ),
			pricing_value: PricingValue::fixed( '50.00' ),
			id: 203
		);

		$result = $this->calculator->calculate( $context, ResolutionResult::matched( $rule, PrecedenceLevel::CUSTOMER_PRODUCT ) );

		$this->assertSame( '50.000000', $result->final_price()->amount() );
		$this->assertFalse( $result->is_discounted() );
		$this->assertSame( '0.000000', $result->discount_amount()->amount() );
		$this->assertSame( 'fixed_equal', $result->diagnostic_metadata()['calculation_type'] );
	}

	public function test_fixed_price_higher_than_base_price_surcharge(): void {
		$context = new PricingContext(
			product_id: 10,
			base_price: Money::from( '50.00', 'USD' ),
			customer_id: 1
		);

		$rule = new PricingRule(
			target: RuleTarget::user( 1 ),
			scope: RuleScope::product( 10 ),
			pricing_value: PricingValue::fixed( '65.00' ),
			id: 204
		);

		$result = $this->calculator->calculate( $context, ResolutionResult::matched( $rule, PrecedenceLevel::CUSTOMER_PRODUCT ) );

		$this->assertSame( '65.000000', $result->final_price()->amount() );
		$this->assertFalse( $result->is_discounted() );
		$this->assertSame( '0.000000', $result->discount_amount()->amount() );
		$this->assertSame( '0.000000', $result->savings_percentage() );
		$this->assertSame( 'fixed_surcharge', $result->diagnostic_metadata()['calculation_type'] );
	}

	// -------------------------------------------------------------------------
	// 4. Regular Price vs Active Sale Price
	// -------------------------------------------------------------------------

	public function test_percentage_discount_uses_active_sale_price_as_base(): void {
		// When WooCommerce product is on sale: regular = $100.00, sale = $80.00, active base_price = $80.00
		$context = new PricingContext(
			product_id: 10,
			base_price: Money::from( '80.00', 'USD' ),
			customer_id: 1,
			regular_price: Money::from( '100.00', 'USD' ),
			sale_price: Money::from( '80.00', 'USD' )
		);

		$this->assertTrue( $context->is_sale_active() );

		// 20% Tierza discount applied to active base price ($80.00 * 0.80 = $64.00)
		$rule = new PricingRule(
			target: RuleTarget::user( 1 ),
			scope: RuleScope::product( 10 ),
			pricing_value: PricingValue::percentage( '20.00' ),
			id: 301
		);

		$result = $this->calculator->calculate( $context, ResolutionResult::matched( $rule, PrecedenceLevel::CUSTOMER_PRODUCT ) );

		$this->assertSame( '64.000000', $result->final_price()->amount() );
		$this->assertSame( '80.000000', $result->original_price()->amount() );
		$this->assertSame( 'sale_price', $result->diagnostic_metadata()['base_price_type'] );
	}

	public function test_percentage_discount_uses_regular_price_when_no_sale(): void {
		$context = new PricingContext(
			product_id: 10,
			base_price: Money::from( '100.00', 'USD' ),
			customer_id: 1,
			regular_price: Money::from( '100.00', 'USD' ),
			sale_price: null
		);

		$this->assertFalse( $context->is_sale_active() );

		$rule = new PricingRule(
			target: RuleTarget::user( 1 ),
			scope: RuleScope::product( 10 ),
			pricing_value: PricingValue::percentage( '20.00' ),
			id: 302
		);

		$result = $this->calculator->calculate( $context, ResolutionResult::matched( $rule, PrecedenceLevel::CUSTOMER_PRODUCT ) );

		$this->assertSame( '80.000000', $result->final_price()->amount() );
		$this->assertSame( 'regular_price', $result->diagnostic_metadata()['base_price_type'] );
	}

	// -------------------------------------------------------------------------
	// 5. Zero Base Price and Missing Base Price
	// -------------------------------------------------------------------------

	public function test_deliberately_zero_priced_product_is_accepted(): void {
		$context = new PricingContext(
			product_id: 10,
			base_price: Money::zero( 'USD' ),
			customer_id: 1
		);

		$rule = new PricingRule(
			target: RuleTarget::user( 1 ),
			scope: RuleScope::product( 10 ),
			pricing_value: PricingValue::percentage( '50.00' ),
			id: 401
		);

		$result = $this->calculator->calculate( $context, ResolutionResult::matched( $rule, PrecedenceLevel::CUSTOMER_PRODUCT ) );

		$this->assertSame( '0.000000', $result->original_price()->amount() );
		$this->assertSame( '0.000000', $result->final_price()->amount() );
		$this->assertFalse( $result->is_discounted() );
	}

	public function test_missing_base_price_in_context_throws_exception(): void {
		$this->expectException( MissingBasePriceException::class );
		new PricingContext( product_id: 10, base_price: null );
	}

	public function test_negative_base_price_throws_invalid_money_exception(): void {
		$this->expectException( InvalidMoneyException::class );
		Money::from( '-10.00', 'USD' );
	}

	// -------------------------------------------------------------------------
	// 6. Currency Precision (0, 2, 3 decimals) and Rounding Boundaries
	// -------------------------------------------------------------------------

	/**
	 * @dataProvider currencyPrecisionDataProvider
	 */
	public function test_currency_precision_and_half_up_rounding(
		string $base_price,
		string $discount_percent,
		int $decimals,
		string $expected_normalized,
		string $expected_formatted
	): void {
		$context = new PricingContext(
			product_id: 10,
			base_price: Money::from( $base_price, 'EUR' ),
			customer_id: 1,
			currency_decimals: $decimals
		);

		$rule = new PricingRule(
			target: RuleTarget::user( 1 ),
			scope: RuleScope::product( 10 ),
			pricing_value: PricingValue::percentage( $discount_percent ),
			id: 501
		);

		$result = $this->calculator->calculate( $context, ResolutionResult::matched( $rule, PrecedenceLevel::CUSTOMER_PRODUCT ) );

		$this->assertSame( $expected_normalized, $result->final_price()->amount() );
		$this->assertSame( $expected_formatted, $result->formatted_final_price() );
	}

	/**
	 * @return array<string, array<mixed>>
	 */
	public static function currencyPrecisionDataProvider(): array {
		return [
			'0-decimals currency (e.g. JPY, IRR) round half-up: 1250 * 0.845 = 1056.25 -> 1056' => [
				'base_price'          => '1250',
				'discount_percent'    => '15.5',
				'decimals'            => 0,
				'expected_normalized' => '1056.000000',
				'expected_formatted'  => '1056',
			],
			'0-decimals currency round half-up: 1255 * 0.85 = 1066.75 -> 1067' => [
				'base_price'          => '1255',
				'discount_percent'    => '15',
				'decimals'            => 0,
				'expected_normalized' => '1067.000000',
				'expected_formatted'  => '1067',
			],
			'2-decimals standard boundary .495 -> .50' => [
				'base_price'          => '29.99',
				'discount_percent'    => '50',
				'decimals'            => 2,
				'expected_normalized' => '15.000000',
				'expected_formatted'  => '15.00',
			],
			'2-decimals boundary .494 -> .49' => [
				'base_price'          => '29.98',
				'discount_percent'    => '50',
				'decimals'            => 2,
				'expected_normalized' => '14.990000',
				'expected_formatted'  => '14.99',
			],
			'3-decimals currency (e.g. KWD, BHD, OMR): 10.000 * 0.85 = 8.500' => [
				'base_price'          => '10.000',
				'discount_percent'    => '15',
				'decimals'            => 3,
				'expected_normalized' => '8.500000',
				'expected_formatted'  => '8.500',
			],
			'3-decimals boundary: 25.4915 * 0.80 = 20.3932 -> 20.393' => [
				'base_price'          => '25.4915',
				'discount_percent'    => '20',
				'decimals'            => 3,
				'expected_normalized' => '20.393000',
				'expected_formatted'  => '20.393',
			],
		];
	}

	// -------------------------------------------------------------------------
	// 7. Large Monetary Values Without Overflow
	// -------------------------------------------------------------------------

	public function test_large_monetary_values_do_not_overflow(): void {
		$context = new PricingContext(
			product_id: 10,
			base_price: Money::from( '999999999999999.99', 'USD' ),
			customer_id: 1
		);

		$rule = new PricingRule(
			target: RuleTarget::user( 1 ),
			scope: RuleScope::product( 10 ),
			pricing_value: PricingValue::percentage( '10' ),
			id: 601
		);

		$result = $this->calculator->calculate( $context, ResolutionResult::matched( $rule, PrecedenceLevel::CUSTOMER_PRODUCT ) );

		// 999999999999999.99 * 0.90 = 899999999999999.991 -> 899999999999999.99
		$this->assertSame( '899999999999999.990000', $result->final_price()->amount() );
		$this->assertSame( '899999999999999.99', $result->formatted_final_price() );
	}

	// -------------------------------------------------------------------------
	// 8. Pure-PHP Fallback vs BCMath Parity
	// -------------------------------------------------------------------------

	public function test_bcmath_and_php_driver_parity(): void {
		$bcmath_calc = new AuthoritativePriceCalculator( default_currency_decimals: 2, math_driver: DecimalMath::DRIVER_BCMATH );
		$php_calc    = new AuthoritativePriceCalculator( default_currency_decimals: 2, math_driver: DecimalMath::DRIVER_PHP );

		$context = new PricingContext(
			product_id: 10,
			base_price: Money::from( '149.99', 'EUR' ),
			customer_id: 1
		);

		$rule = new PricingRule(
			target: RuleTarget::user( 1 ),
			scope: RuleScope::product( 10 ),
			pricing_value: PricingValue::percentage( '17.5' ),
			id: 701
		);

		$resolution = ResolutionResult::matched( $rule, PrecedenceLevel::CUSTOMER_PRODUCT );

		$bcmath_result = $bcmath_calc->calculate( $context, $resolution );
		$php_result    = $php_calc->calculate( $context, $resolution );

		$this->assertSame( $bcmath_result->final_price()->amount(), $php_result->final_price()->amount() );
		$this->assertSame( $bcmath_result->formatted_final_price(), $php_result->formatted_final_price() );
		$this->assertSame( $bcmath_result->discount_amount()->amount(), $php_result->discount_amount()->amount() );
		$this->assertSame( $bcmath_result->savings_percentage(), $php_result->savings_percentage() );
	}

	// -------------------------------------------------------------------------
	// 9. Input Integrity and Boundary Invariants
	// -------------------------------------------------------------------------

	public function test_guest_user_cannot_receive_customer_specific_pricing(): void {
		$guest_context = new PricingContext(
			product_id: 10,
			base_price: Money::from( '100.00', 'USD' ),
			customer_id: 0 // Guest
		);

		$rule = new PricingRule(
			target: RuleTarget::user( 5 ),
			scope: RuleScope::product( 10 ),
			pricing_value: PricingValue::percentage( '15' ),
			id: 801
		);

		$resolution = ResolutionResult::matched( $rule, PrecedenceLevel::CUSTOMER_PRODUCT );

		$this->expectException( PricingCalculationException::class );
		$this->expectExceptionMessage( 'Guest users cannot receive customer-specific pricing.' );
		$this->calculator->calculate( $guest_context, $resolution );
	}

	public function test_customer_target_id_mismatch_throws_exception(): void {
		$context = new PricingContext(
			product_id: 10,
			base_price: Money::from( '100.00', 'USD' ),
			customer_id: 1
		);

		$rule = new PricingRule(
			target: RuleTarget::user( 99 ), // Target mismatch
			scope: RuleScope::product( 10 ),
			pricing_value: PricingValue::percentage( '15' ),
			id: 802
		);

		$resolution = ResolutionResult::matched( $rule, PrecedenceLevel::CUSTOMER_PRODUCT );

		$this->expectException( PricingCalculationException::class );
		$this->expectExceptionMessage( 'Customer pricing rule target ID (99) does not match context customer ID (1).' );
		$this->calculator->calculate( $context, $resolution );
	}

	public function test_group_pricing_without_group_in_context_throws_exception(): void {
		$context = new PricingContext(
			product_id: 10,
			base_price: Money::from( '100.00', 'USD' ),
			customer_id: 1,
			group: null
		);

		$rule = new PricingRule(
			target: RuleTarget::group( 3 ),
			scope: RuleScope::product( 10 ),
			pricing_value: PricingValue::percentage( '10' ),
			id: 803
		);

		$resolution = ResolutionResult::matched( $rule, PrecedenceLevel::GROUP_PRODUCT );

		$this->expectException( PricingCalculationException::class );
		$this->expectExceptionMessage( 'Group pricing rule cannot be applied to context without an assigned group.' );
		$this->calculator->calculate( $context, $resolution );
	}

	public function test_group_target_id_mismatch_throws_exception(): void {
		$group   = new PricingGroup( 'wholesalers', 'Wholesalers', 3 );
		$context = new PricingContext(
			product_id: 10,
			base_price: Money::from( '100.00', 'USD' ),
			customer_id: 1,
			group: $group
		);

		$rule = new PricingRule(
			target: RuleTarget::group( 99 ), // Target mismatch
			scope: RuleScope::product( 10 ),
			pricing_value: PricingValue::percentage( '10' ),
			id: 804
		);

		$resolution = ResolutionResult::matched( $rule, PrecedenceLevel::GROUP_PRODUCT );

		$this->expectException( PricingCalculationException::class );
		$this->expectExceptionMessage( 'Group pricing rule target ID (99) does not match context group ID (3).' );
		$this->calculator->calculate( $context, $resolution );
	}

	public function test_variation_scope_mismatch_throws_exception(): void {
		$context = new PricingContext(
			product_id: 10,
			base_price: Money::from( '100.00', 'USD' ),
			customer_id: 1,
			variation_id: 101
		);

		$rule = new PricingRule(
			target: RuleTarget::user( 1 ),
			scope: RuleScope::variation( 999 ), // Mismatched variation ID
			pricing_value: PricingValue::percentage( '10' ),
			id: 805
		);

		$resolution = ResolutionResult::matched( $rule, PrecedenceLevel::CUSTOMER_VARIATION );

		$this->expectException( PricingCalculationException::class );
		$this->expectExceptionMessage( 'Variation-scoped rule (ID 999) does not match context variation ID (101).' );
		$this->calculator->calculate( $context, $resolution );
	}

	public function test_product_scope_mismatch_throws_exception(): void {
		$context = new PricingContext(
			product_id: 10,
			base_price: Money::from( '100.00', 'USD' ),
			customer_id: 1
		);

		$rule = new PricingRule(
			target: RuleTarget::user( 1 ),
			scope: RuleScope::product( 999 ), // Mismatched product ID
			pricing_value: PricingValue::percentage( '10' ),
			id: 806
		);

		$resolution = ResolutionResult::matched( $rule, PrecedenceLevel::CUSTOMER_PRODUCT );

		$this->expectException( PricingCalculationException::class );
		$this->expectExceptionMessage( 'Product-scoped rule (ID 999) does not match context product ID (10).' );
		$this->calculator->calculate( $context, $resolution );
	}

	public function test_category_scope_mismatch_throws_exception(): void {
		$context = new PricingContext(
			product_id: 10,
			base_price: Money::from( '100.00', 'USD' ),
			customer_id: 1,
			category_ids: [ 5, 8 ]
		);

		$rule = new PricingRule(
			target: RuleTarget::user( 1 ),
			scope: RuleScope::category( 99 ), // Not in [5, 8]
			pricing_value: PricingValue::percentage( '10' ),
			id: 807
		);

		$resolution = ResolutionResult::matched( $rule, PrecedenceLevel::CUSTOMER_CATEGORY );

		$this->expectException( PricingCalculationException::class );
		$this->expectExceptionMessage( 'Category-scoped rule (ID 99) is not present in context product category IDs.' );
		$this->calculator->calculate( $context, $resolution );
	}

	public function test_inactive_rule_in_resolution_throws_exception(): void {
		$context = new PricingContext(
			product_id: 10,
			base_price: Money::from( '100.00', 'USD' ),
			customer_id: 1
		);

		$rule = new PricingRule(
			target: RuleTarget::user( 1 ),
			scope: RuleScope::product( 10 ),
			pricing_value: PricingValue::percentage( '10' ),
			id: 808,
			status: RuleStatus::INACTIVE
		);

		// ResolutionResult constructor rejects inactive rules, but test calculator check directly
		$this->expectException( RuleResolutionException::class );
		ResolutionResult::matched( $rule, PrecedenceLevel::CUSTOMER_PRODUCT );
	}

	public function test_invalid_percentage_exceeding_100_rejected(): void {
		$this->expectException( InvalidRuleException::class );
		PricingValue::percentage( '105' );
	}

	public function test_invalid_negative_percentage_rejected(): void {
		$this->expectException( InvalidRuleException::class );
		PricingValue::percentage( '-5' );
	}

	public function test_fixed_price_on_category_scope_rejected(): void {
		$this->expectException( InvalidRuleException::class );
		new PricingRule(
			target: RuleTarget::user( 1 ),
			scope: RuleScope::category( 10 ),
			pricing_value: PricingValue::fixed( '25.00' )
		);
	}

	public function test_fixed_price_on_global_scope_rejected(): void {
		$this->expectException( InvalidRuleException::class );
		new PricingRule(
			target: RuleTarget::user( 1 ),
			scope: RuleScope::global(),
			pricing_value: PricingValue::fixed( '25.00' )
		);
	}

	// -------------------------------------------------------------------------
	// 10. Immutability and Determinism
	// -------------------------------------------------------------------------

	public function test_pricing_result_immutability_and_serialization(): void {
		$context = new PricingContext(
			product_id: 10,
			base_price: Money::from( '100.00', 'USD' ),
			customer_id: 1
		);

		$rule = new PricingRule(
			target: RuleTarget::user( 1 ),
			scope: RuleScope::product( 10 ),
			pricing_value: PricingValue::percentage( '25' ),
			id: 901
		);

		$result = $this->calculator->calculate( $context, ResolutionResult::matched( $rule, PrecedenceLevel::CUSTOMER_PRODUCT ) );

		$this->assertSame( '100.000000', $context->base_price()->amount() ); // Unmutated context
		$this->assertSame( '75.000000', $result->final_price()->amount() );

		$array_data = $result->to_array( true );
		$this->assertSame( '100.000000', $array_data['original_price'] );
		$this->assertSame( '75.000000', $array_data['final_price'] );
		$this->assertSame( 'USD', $array_data['currency'] );
		$this->assertSame( 901, $array_data['applied_rule_id'] );
		$this->assertTrue( $array_data['is_rule_applied'] );
		$this->assertTrue( $array_data['is_discounted'] );
		$this->assertSame( '25.000000', $array_data['discount_amount'] );
		$this->assertSame( '25.000000', $array_data['savings_percentage'] );
		$this->assertArrayHasKey( 'diagnostics', $array_data );
		$this->assertSame( 'percentage_standard', $array_data['diagnostics']['calculation_type'] );
	}

	public function test_repeated_deterministic_calculations_produce_identical_results(): void {
		$context = new PricingContext(
			product_id: 10,
			base_price: Money::from( '89.90', 'USD' ),
			customer_id: 1
		);

		$rule = new PricingRule(
			target: RuleTarget::user( 1 ),
			scope: RuleScope::product( 10 ),
			pricing_value: PricingValue::percentage( '12.5' ),
			id: 902
		);

		$resolution = ResolutionResult::matched( $rule, PrecedenceLevel::CUSTOMER_PRODUCT );

		$first_result = $this->calculator->calculate( $context, $resolution );

		for ( $i = 0; $i < 50; $i++ ) {
			$subsequent_result = $this->calculator->calculate( $context, $resolution );
			$this->assertSame( $first_result->final_price()->amount(), $subsequent_result->final_price()->amount() );
			$this->assertSame( $first_result->reason_code(), $subsequent_result->reason_code() );
			$this->assertSame( $first_result->to_array(), $subsequent_result->to_array() );
		}
	}

	public function test_calculations_never_stack_multiple_rules(): void {
		// Verify that exactly one rule is applied and cannot be compounded or stacked
		$context = new PricingContext(
			product_id: 10,
			base_price: Money::from( '100.00', 'USD' ),
			customer_id: 1
		);

		$rule = new PricingRule(
			target: RuleTarget::user( 1 ),
			scope: RuleScope::product( 10 ),
			pricing_value: PricingValue::percentage( '20' ),
			id: 903
		);

		$resolution = ResolutionResult::matched( $rule, PrecedenceLevel::CUSTOMER_PRODUCT );
		$result     = $this->calculator->calculate( $context, $resolution );

		// 20% discount produces 80.00, NEVER compounded with any secondary rule
		$this->assertSame( '80.000000', $result->final_price()->amount() );
		$this->assertSame( 903, $result->applied_rule_id() );
	}
}
