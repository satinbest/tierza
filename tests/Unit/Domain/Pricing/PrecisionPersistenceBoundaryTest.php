<?php

declare(strict_types=1);

namespace Tierza\Tests\Unit\Domain\Pricing;

use PHPUnit\Framework\TestCase;
use Tierza\Application\Pricing\AuthoritativePriceCalculator;
use Tierza\Domain\Entities\PricingRule;
use Tierza\Domain\Exceptions\InvalidMoneyException;
use Tierza\Domain\Exceptions\InvalidPricingContextException;
use Tierza\Domain\Exceptions\InvalidRuleException;
use Tierza\Domain\Pricing\Enums\PrecedenceLevel;
use Tierza\Domain\Pricing\PricingContext;
use Tierza\Domain\Pricing\ResolutionResult;
use Tierza\Domain\ValueObjects\DecimalMath;
use Tierza\Domain\ValueObjects\Money;
use Tierza\Domain\ValueObjects\PricingValue;
use Tierza\Domain\ValueObjects\RuleScope;
use Tierza\Domain\ValueObjects\RuleTarget;

/**
 * Precision and Persistence Boundary Audit Test Suite (TRZ-008 Section 6).
 *
 * Enforces the strict invariant relationship between MariaDB DECIMAL(20,6) storage,
 * Money domain scale (6), PricingValue limits (14 integer digits), intermediate calculator
 * precision (scale 14), and final currency precision (0..6).
 */
final class PrecisionPersistenceBoundaryTest extends TestCase {

	private AuthoritativePriceCalculator $calculator;

	protected function setUp(): void {
		parent::setUp();
		$this->calculator = new AuthoritativePriceCalculator( default_currency_decimals: 2 );
	}

	public function test_mariadb_storage_boundary_constants(): void {
		$this->assertSame( 14, Money::MAX_INTEGER_DIGITS );
		$this->assertSame( '99999999999999.999999', Money::MAX_AMOUNT );
		$this->assertSame( 6, Money::DEFAULT_SCALE );

		$this->assertSame( 14, PricingValue::MAX_INTEGER_DIGITS );
		$this->assertSame( '99999999999999.999999', PricingValue::MAX_VALUE );
	}

	public function test_exact_maximum_storable_amount_in_money(): void {
		$max_money = Money::from( '99999999999999.999999', 'USD' );
		$this->assertSame( '99999999999999.999999', $max_money->amount() );
	}

	public function test_exact_maximum_storable_fixed_price_in_pricing_value(): void {
		$max_fixed = PricingValue::fixed( '99999999999999.999999' );
		$this->assertSame( '99999999999999.999999', $max_fixed->value() );
	}

	public function test_amount_exceeding_14_integer_digits_rejected_in_money(): void {
		$this->expectException( InvalidMoneyException::class );
		$this->expectExceptionMessage( 'exceeds maximum supported integer magnitude of 14 digits' );

		Money::from( '100000000000000.000000', 'USD' );
	}

	public function test_fixed_value_exceeding_14_integer_digits_rejected_in_pricing_value(): void {
		$this->expectException( InvalidRuleException::class );
		$this->expectExceptionMessage( 'exceeds maximum supported integer magnitude of 14 digits' );

		PricingValue::fixed( '100000000000000.000000' );
	}

	public function test_fractional_digits_exceeding_6_rejected_in_money(): void {
		$this->expectException( InvalidMoneyException::class );
		$this->expectExceptionMessage( 'exceeds maximum supported precision of 6 decimal places' );

		Money::from( '100.1234567', 'USD' );
	}

	public function test_fractional_digits_exceeding_6_rejected_in_pricing_value(): void {
		$this->expectException( InvalidRuleException::class );
		$this->expectExceptionMessage( 'exceeds maximum supported precision of 6 decimal places' );

		PricingValue::percentage( '10.1234567' );
	}

	public function test_trailing_zeroes_safely_normalized_in_money(): void {
		// Value with 8 digits but only 6 significant digits (trailing zeroes)
		$money = Money::from( '100.50000000', 'USD' );
		$this->assertSame( '100.500000', $money->amount() );
	}

	public function test_trailing_zeroes_safely_normalized_in_pricing_value(): void {
		$val = PricingValue::percentage( '20.00000000' );
		$this->assertSame( '20.000000', $val->value() );
	}

	public function test_intermediate_scale_14_preserves_guard_digits_before_rounding(): void {
		// Base price: $99.999999 (6 fractional digits)
		// Discount: 33.333333% (6 fractional digits)
		// Remaining: 66.666667%
		// Product (scale 12): 99.999999 * 66.666667 = 6666.666600000000 (approx)
		// Division by 100 (scale 14): 66.66666600000000
		$base_str = '99.999999';
		$disc_str = '33.333333';

		$context = new PricingContext(
			product_id: 10,
			base_price: Money::from( $base_str, 'USD' ),
			customer_id: 1,
			currency_decimals: 2
		);

		$rule   = new PricingRule( RuleTarget::user( 1 ), RuleScope::product( 10 ), PricingValue::percentage( $disc_str ), id: 1 );
		$result = $this->calculator->calculate( $context, ResolutionResult::matched( $rule, PrecedenceLevel::CUSTOMER_PRODUCT ) );

		$diagnostics = $result->diagnostic_metadata();
		$this->assertSame( 14, strlen( explode( '.', $diagnostics['raw_calculated_amount'] )[1] ?? '' ) );
		$this->assertSame( '66.670000', $result->final_price()->amount() );
		$this->assertSame( '66.67', $result->formatted_final_price() );
	}

	public function test_currency_precision_boundary_limits_between_0_and_6(): void {
		for ( $scale = 0; $scale <= 6; $scale++ ) {
			$context = new PricingContext(
				product_id: 10,
				base_price: Money::from( '100.000000', 'USD' ),
				customer_id: 1,
				currency_decimals: $scale
			);
			$this->assertSame( $scale, $context->currency_decimals() );
		}

		$this->expectException( InvalidPricingContextException::class );
		new PricingContext(
			product_id: 10,
			base_price: Money::from( '100.000000', 'USD' ),
			customer_id: 1,
			currency_decimals: 7
		);
	}
}
