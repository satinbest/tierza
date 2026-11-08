<?php

declare(strict_types=1);

namespace Tierza\Tests\Unit\Application\Pricing;

use PHPUnit\Framework\TestCase;
use Tierza\Application\Pricing\AuthoritativePriceCalculator;
use Tierza\Domain\Entities\PricingRule;
use Tierza\Domain\Pricing\Enums\PrecedenceLevel;
use Tierza\Domain\Pricing\PricingContext;
use Tierza\Domain\Pricing\ResolutionResult;
use Tierza\Domain\ValueObjects\Money;
use Tierza\Domain\ValueObjects\PricingValue;
use Tierza\Domain\ValueObjects\RuleScope;
use Tierza\Domain\ValueObjects\RuleTarget;

/**
 * Independent oracle verification test suite for AuthoritativePriceCalculator (TRZ-007).
 *
 * Verifies production calculation output against the independent exact integer-rational
 * calculation oracle across boundary values, halfway boundaries, micro-prices, and large numbers.
 */
final class ExactRationalPricingOracleTest extends TestCase {

	private AuthoritativePriceCalculator $calculator;

	protected function setUp(): void {
		parent::setUp();
		$this->calculator = new AuthoritativePriceCalculator();
	}

	/**
	 * @dataProvider oraclePercentageVectorsDataProvider
	 */
	public function test_calculator_matches_independent_rational_oracle_for_percentages(
		string $base_price,
		string $percent,
		int $decimals
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
			pricing_value: PricingValue::percentage( $percent ),
			id: 1
		);

		$resolution = ResolutionResult::matched( $rule, PrecedenceLevel::CUSTOMER_PRODUCT );
		$actual     = $this->calculator->calculate( $context, $resolution );

		$expected_oracle = ExactRationalPricingOracle::calculate_percentage( $base_price, $percent, $decimals );

		$this->assertSame(
			$expected_oracle,
			$actual->final_price()->amount(),
			sprintf(
				'Mismatch with independent oracle for base=%s, percent=%s, decimals=%d. Expected %s, got %s',
				$base_price,
				$percent,
				$decimals,
				$expected_oracle,
				$actual->final_price()->amount()
			)
		);
	}

	/**
	 * @return array<string, array{string, string, int}>
	 */
	public static function oraclePercentageVectorsDataProvider(): array {
		return [
			// 1. Rounding boundary tests (below, exact halfway, above)
			'halfway boundary exact .495 -> .50 (decimals 2)' => [ '29.99', '50.000000', 2 ],
			'immediately below boundary .4949 -> .49 (decimals 2)' => [ '29.98', '50.000000', 2 ],
			'exact halfway .5000 -> 1.00 (decimals 0)' => [ '10.00', '5.000000', 0 ], // 10 * 0.95 = 9.5 -> 10
			'immediately below halfway .4900 -> 9.00 (decimals 0)' => [ '10.00', '5.100000', 0 ], // 10 * 0.949 = 9.49 -> 9
			'immediately above halfway .5100 -> 10.00 (decimals 0)' => [ '10.00', '4.900000', 0 ], // 10 * 0.951 = 9.51 -> 10

			// 2. Large numbers without integer overflow
			'large number 999999999999.99 with 15.555555% (decimals 2)' => [ '999999999999.99', '15.555555', 2 ],
			'large number 500000000000.00 with 33.333333% (decimals 2)' => [ '500000000000.00', '33.333333', 2 ],
			'large number 123456789012.34 with 0.000001% (decimals 2)' => [ '123456789012.34', '0.000001', 2 ],

			// 3. Very small base prices
			'very small base price 0.000001 with 50% (decimals 6)' => [ '0.000001', '50.000000', 6 ], // 0.0000005 -> 0.000001
			'very small base price 0.000010 with 20% (decimals 6)' => [ '0.000010', '20.000000', 6 ], // 0.000008
			'tiny base price 0.000100 with 1% (decimals 6)' => [ '0.000100', '1.000000', 6 ],

			// 4. Percentages with 6 fractional digits
			'percentage 12.345678% on 150.00 (decimals 2)' => [ '150.00', '12.345678', 2 ],
			'percentage 0.000001% on 100.00 (decimals 6)' => [ '100.00', '0.000001', 6 ],
			'percentage 99.999999% on 100.00 (decimals 2)' => [ '100.00', '99.999999', 2 ],
			'percentage 99.999999% on 100.00 (decimals 6)' => [ '100.00', '99.999999', 6 ],

			// 5. Currency decimals 0, 2, 3, and 6 (maximum genuinely supported precision)
			'currency decimals 0: 1255 with 15% (IRR/JPY)' => [ '1255', '15.000000', 0 ],
			'currency decimals 0: 1250 with 15.5% (IRR/JPY)' => [ '1250', '15.500000', 0 ],
			'currency decimals 2: 89.90 with 12.5% (USD/EUR)' => [ '89.90', '12.500000', 2 ],
			'currency decimals 3: 25.4915 with 20% (KWD/BHD)' => [ '25.4915', '20.000000', 3 ],
			'currency decimals 6: 1.234567 with 10% (Micro-pricing)' => [ '1.234567', '10.000000', 6 ],

			// 6. Explicit 0% and 100% boundary discounts
			'explicit 0% discount on 100.00 (decimals 2)' => [ '100.00', '0.000000', 2 ],
			'explicit 100% discount on 100.00 (decimals 2)' => [ '100.00', '100.000000', 2 ],
			'explicit 0% discount on 1250 (decimals 0)' => [ '1250', '0.000000', 0 ],
			'explicit 100% discount on 1250 (decimals 0)' => [ '1250', '100.000000', 0 ],
		];
	}

	/**
	 * @dataProvider oracleFixedPriceVectorsDataProvider
	 */
	public function test_calculator_matches_independent_rational_oracle_for_fixed_prices(
		string $base_price,
		string $fixed_price,
		int $decimals
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
			pricing_value: PricingValue::fixed( $fixed_price ),
			id: 2
		);

		$resolution = ResolutionResult::matched( $rule, PrecedenceLevel::CUSTOMER_PRODUCT );
		$actual     = $this->calculator->calculate( $context, $resolution );

		$expected_oracle = ExactRationalPricingOracle::calculate_fixed( $fixed_price, $decimals );

		$this->assertSame(
			$expected_oracle,
			$actual->final_price()->amount(),
			sprintf(
				'Mismatch with independent oracle for fixed=%s, decimals=%d. Expected %s, got %s',
				$fixed_price,
				$decimals,
				$expected_oracle,
				$actual->final_price()->amount()
			)
		);
	}

	/**
	 * @return array<string, array{string, string, int}>
	 */
	public static function oracleFixedPriceVectorsDataProvider(): array {
		return [
			'fixed price halfway boundary .995 -> 80.00 (decimals 2)' => [ '100.00', '79.995000', 2 ],
			'fixed price below boundary .9949 -> 79.99 (decimals 2)' => [ '100.00', '79.994900', 2 ],
			'fixed price halfway .500 -> 13 (decimals 0)' => [ '100', '12.500000', 0 ],
			'fixed price below halfway .490 -> 12 (decimals 0)' => [ '100', '12.490000', 0 ],
			'fixed price exact zero' => [ '50.00', '0.000000', 2 ],
			'fixed price 3 decimals boundary .4915 -> 25.492' => [ '100.00', '25.491500', 3 ],
			'fixed price max precision 6 decimals preserved' => [ '100.00', '50.123456', 6 ],
			'fixed price surcharge exceeding base price' => [ '50.00', '65.500000', 2 ],
		];
	}
}
