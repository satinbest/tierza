<?php

declare(strict_types=1);

namespace Tierza\Tests\Unit\Application\Pricing;

use PHPUnit\Framework\TestCase;
use Tierza\Application\Pricing\AuthoritativePriceCalculator;
use Tierza\Domain\Entities\PricingRule;
use Tierza\Domain\Exceptions\CurrencyMismatchException;
use Tierza\Domain\Exceptions\InvalidMoneyException;
use Tierza\Domain\Exceptions\InvalidPricingContextException;
use Tierza\Domain\Exceptions\InvalidRuleException;
use Tierza\Domain\Exceptions\MissingBasePriceException;
use Tierza\Domain\Pricing\Enums\PrecedenceLevel;
use Tierza\Domain\Pricing\PricingContext;
use Tierza\Domain\Pricing\ResolutionResult;
use Tierza\Domain\ValueObjects\DecimalMath;
use Tierza\Domain\ValueObjects\Money;
use Tierza\Domain\ValueObjects\PricingValue;
use Tierza\Domain\ValueObjects\RuleScope;
use Tierza\Domain\ValueObjects\RuleTarget;

/**
 * Comprehensive Monetary Tests with Independent Oracle Comparison (TRZ-008 Section 4).
 *
 * Exercises all currency precision levels (0..6), boundary rounding, explicit zeroes,
 * large numbers, tiny prices, BCMath/Pure-PHP parity, and anti-corruption input validations.
 */
final class ComprehensiveMonetaryOracleTest extends TestCase {

	private AuthoritativePriceCalculator $bcmath_calculator;
	private AuthoritativePriceCalculator $php_calculator;

	protected function setUp(): void {
		parent::setUp();
		$this->bcmath_calculator = new AuthoritativePriceCalculator( default_currency_decimals: 2, math_driver: DecimalMath::DRIVER_BCMATH );
		$this->php_calculator    = new AuthoritativePriceCalculator( default_currency_decimals: 2, math_driver: DecimalMath::DRIVER_PHP );
	}

	/**
	 * @dataProvider provide_comprehensive_monetary_vectors
	 */
	public function test_monetary_vectors_match_independent_oracle_and_driver_parity(
		string $base_price,
		PricingValue $pricing_value,
		int $currency_decimals,
		string $expected_final_price
	): void {
		$context = new PricingContext(
			product_id: 10,
			base_price: Money::from( $base_price, 'USD' ),
			customer_id: 1,
			currency_decimals: $currency_decimals
		);

		$rule = new PricingRule(
			target: RuleTarget::user( 1 ),
			scope: RuleScope::product( 10 ),
			pricing_value: $pricing_value,
			id: 100
		);

		$resolution = ResolutionResult::matched( $rule, PrecedenceLevel::CUSTOMER_PRODUCT );

		// 1. Calculate using BCMath driver
		$bcmath_result = $this->bcmath_calculator->calculate( $context, $resolution );

		// 2. Calculate using Pure-PHP driver
		$php_result = $this->php_calculator->calculate( $context, $resolution );

		// 3. Independent oracle verification
		if ( $pricing_value->is_percentage() ) {
			$oracle_price = ExactRationalPricingOracle::calculate_percentage(
				$base_price,
				$pricing_value->value(),
				$currency_decimals
			);
		} else {
			$oracle_price = ExactRationalPricingOracle::calculate_fixed(
				$pricing_value->value(),
				$currency_decimals
			);
		}

		// Assertions:
		$this->assertSame( $expected_final_price, $bcmath_result->final_price()->amount() );
		$this->assertSame( $expected_final_price, $php_result->final_price()->amount() );
		$this->assertSame( $expected_final_price, $oracle_price );
		$this->assertSame( $bcmath_result->final_price()->amount(), $php_result->final_price()->amount() );
		$this->assertSame( $bcmath_result->formatted_final_price(), $php_result->formatted_final_price() );
	}

	/**
	 * @return array<string, array<mixed>>
	 */
	public static function provide_comprehensive_monetary_vectors(): array {
		return [
			// Scale 0 (IRR / JPY)
			'scale_0_round_up' => [
				'1000',
				PricingValue::percentage( '15.5' ),
				0,
				'845.000000', // 1000 * 0.845 = 845
			],
			'scale_0_halfway_boundary_round_up' => [
				'100',
				PricingValue::percentage( '12.5' ),
				0,
				'88.000000', // 100 * 0.875 = 87.5 -> round half-up -> 88
			],
			'scale_0_fixed_zero' => [
				'50000',
				PricingValue::fixed( '0' ),
				0,
				'0.000000',
			],
			// Scale 2 (USD / EUR)
			'scale_2_round_half_up_boundary' => [
				'10.00',
				PricingValue::percentage( '15.55' ),
				2,
				'8.450000', // 10 * 0.8445 = 8.445 -> 8.45
			],
			'scale_2_round_just_below_boundary' => [
				'10.00',
				PricingValue::percentage( '15.56' ),
				2,
				'8.440000', // 10 * 0.8444 = 8.444 -> 8.44
			],
			'scale_2_explicit_0_percent' => [
				'49.99',
				PricingValue::percentage( '0' ),
				2,
				'49.990000',
			],
			'scale_2_100_percent_discount' => [
				'199.95',
				PricingValue::percentage( '100' ),
				2,
				'0.000000',
			],
			'scale_2_fixed_surcharge' => [
				'50.00',
				PricingValue::fixed( '65.00' ),
				2,
				'65.000000',
			],
			'scale_2_fixed_discount' => [
				'50.00',
				PricingValue::fixed( '35.00' ),
				2,
				'35.000000',
			],
			// Scale 3 (KWD / BHD)
			'scale_3_round_halfway' => [
				'100.000',
				PricingValue::percentage( '12.345' ),
				3,
				'87.655000', // 100 * 0.87655 = 87.655
			],
			'scale_3_sub_cent_boundary' => [
				'10.000',
				PricingValue::percentage( '12.3455' ),
				3,
				'8.765000', // 10 * 0.876545 = 8.76545 -> 8.765
			],
			// Scale 6 (Micro-pricing)
			'scale_6_tiny_amount' => [
				'0.000001',
				PricingValue::percentage( '10' ),
				6,
				'0.000001', // 0.000001 * 0.9 = 0.0000009 -> round half up at 6 dec -> 0.000001
			],
			'scale_6_six_decimal_percentage' => [
				'100.000000',
				PricingValue::percentage( '12.345678' ),
				6,
				'87.654322', // 100 * 0.87654322 = 87.654322
			],
			// Storage Boundary (14 integer digits: 99 trillion)
			'scale_2_large_storage_boundary' => [
				'99999999999999.99',
				PricingValue::percentage( '10' ),
				2,
				'89999999999999.990000',
			],
		];
	}

	public function test_rejection_of_unsupported_currency_precision(): void {
		$this->expectException( InvalidPricingContextException::class );
		$this->expectExceptionMessage( 'Currency decimals must be between 0 and 6, got 7.' );

		new PricingContext(
			product_id: 10,
			base_price: Money::from( '10.00', 'USD' ),
			currency_decimals: 7
		);
	}

	public function test_rejection_of_negative_currency_precision(): void {
		$this->expectException( InvalidPricingContextException::class );
		$this->expectExceptionMessage( 'Currency decimals must be between 0 and 6, got -1.' );

		new PricingContext(
			product_id: 10,
			base_price: Money::from( '10.00', 'USD' ),
			currency_decimals: -1
		);
	}

	public function test_missing_base_price_throws_exception(): void {
		$this->expectException( MissingBasePriceException::class );
		$this->expectExceptionMessage( 'Active base price is required' );

		new PricingContext( product_id: 10, base_price: null );
	}

	public function test_currency_mismatch_throws_exception(): void {
		$this->expectException( CurrencyMismatchException::class );
		$this->expectExceptionMessage( 'Regular price currency (EUR) does not match base price currency (USD).' );

		new PricingContext(
			product_id: 10,
			base_price: Money::from( '10.00', 'USD' ),
			regular_price: Money::from( '10.00', 'EUR' )
		);
	}

	public function test_scientific_notation_rejection_in_money(): void {
		$this->expectException( InvalidMoneyException::class );
		$this->expectExceptionMessage( 'without scientific notation' );

		Money::from( '1e5', 'USD' );
	}

	public function test_scientific_notation_rejection_in_pricing_value(): void {
		$this->expectException( InvalidRuleException::class );
		$this->expectExceptionMessage( 'cannot use scientific notation' );

		PricingValue::fixed( '1e3' );
	}

	public function test_sub_scale_fractional_digits_rejected_in_money(): void {
		$this->expectException( InvalidMoneyException::class );
		$this->expectExceptionMessage( 'exceeds maximum supported precision of 6 decimal places' );

		Money::from( '10.1234567', 'USD' );
	}

	public function test_sub_scale_fractional_digits_rejected_in_pricing_value(): void {
		$this->expectException( InvalidRuleException::class );
		$this->expectExceptionMessage( 'exceeds maximum supported precision of 6 decimal places' );

		PricingValue::percentage( '15.1234567' );
	}

	public function test_integer_digits_exceeding_14_rejected_in_money(): void {
		$this->expectException( InvalidMoneyException::class );
		$this->expectExceptionMessage( 'exceeds maximum supported integer magnitude of 14 digits' );

		Money::from( '100000000000000.00', 'USD' );
	}

	public function test_integer_digits_exceeding_14_rejected_in_pricing_value(): void {
		$this->expectException( InvalidRuleException::class );
		$this->expectExceptionMessage( 'exceeds maximum supported integer magnitude of 14 digits' );

		PricingValue::fixed( '100000000000000.00' );
	}

	public function test_invalid_decimal_strings_rejected_in_money(): void {
		$this->expectException( InvalidMoneyException::class );
		Money::from( 'abc.def', 'USD' );
	}

	public function test_negative_base_price_rejected(): void {
		$this->expectException( InvalidMoneyException::class );
		$this->expectExceptionMessage( 'Monetary amount cannot be negative' );

		Money::from( '-10.00', 'USD' );
	}
}
