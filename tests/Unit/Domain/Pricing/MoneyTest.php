<?php

declare(strict_types=1);

namespace Tierza\Tests\Unit\Domain\Pricing;

use PHPUnit\Framework\TestCase;
use Tierza\Domain\Exceptions\CurrencyMismatchException;
use Tierza\Domain\Exceptions\InvalidMoneyException;
use Tierza\Domain\ValueObjects\Money;

final class MoneyTest extends TestCase {

	public function test_money_instantiation_normalizes_to_standard_scale(): void {
		$money = new Money( '120.5', 'USD' );
		$this->assertSame( '120.500000', $money->amount() );
		$this->assertSame( 'USD', $money->currency() );
		$this->assertTrue( $money->is_positive() );
		$this->assertFalse( $money->is_zero() );
	}

	public function test_money_factory_methods(): void {
		$m1 = Money::from( 50, 'EUR' );
		$this->assertSame( '50.000000', $m1->amount() );
		$this->assertSame( 'EUR', $m1->currency() );

		$zero = Money::zero( 'IRR' );
		$this->assertSame( '0.000000', $zero->amount() );
		$this->assertSame( 'IRR', $zero->currency() );
		$this->assertTrue( $zero->is_zero() );
		$this->assertFalse( $zero->is_positive() );
	}

	public function test_explicit_zero_value_is_supported(): void {
		$zero1 = Money::from( '0.00', 'USD' );
		$zero2 = Money::from( 0, 'USD' );
		$this->assertTrue( $zero1->is_zero() );
		$this->assertTrue( $zero1->equals( $zero2 ) );
	}

	public function test_negative_monetary_amount_is_rejected(): void {
		$this->expectException( InvalidMoneyException::class );
		$this->expectExceptionMessage( 'Monetary amount cannot be negative' );
		new Money( '-10.00', 'USD' );
	}

	public function test_empty_currency_is_rejected(): void {
		$this->expectException( InvalidMoneyException::class );
		$this->expectExceptionMessage( 'Currency code cannot be empty' );
		new Money( '100.00', '' );
	}

	public function test_malformed_amount_is_rejected(): void {
		$this->expectException( InvalidMoneyException::class );
		$this->expectExceptionMessage( 'Invalid monetary amount' );
		new Money( 'not-a-number', 'USD' );
	}

	public function test_money_avoids_binary_floating_point_imprecision(): void {
		// In binary float: 0.1 + 0.2 != 0.3 (0.30000000000000004)
		$m1  = Money::from( '0.1', 'USD' );
		$m2  = Money::from( '0.2', 'USD' );
		$sum = $m1->add( $m2 );

		$expected = Money::from( '0.3', 'USD' );
		$this->assertTrue( $sum->equals( $expected ) );
		$this->assertSame( '0.300000', $sum->amount() );
	}

	public function test_store_currency_is_explicit_without_automatic_toman_conversion(): void {
		// Tierza preserves store currency strictly without assuming 1:10 conversion
		$irr = Money::from( '1000000', 'IRR' );
		$irt = Money::from( '100000', 'IRT' );

		$this->assertSame( 'IRR', $irr->currency() );
		$this->assertSame( '1000000.000000', $irr->amount() );

		$this->assertSame( 'IRT', $irt->currency() );
		$this->assertSame( '100000.000000', $irt->amount() );

		$this->assertFalse( $irr->equals( $irt ) );
	}

	public function test_currency_mismatch_throws_exception_on_arithmetic(): void {
		$usd = Money::from( '10.00', 'USD' );
		$eur = Money::from( '10.00', 'EUR' );

		$this->expectException( CurrencyMismatchException::class );
		$this->expectExceptionMessage( 'Currency mismatch: operation attempted between USD and EUR' );
		$usd->add( $eur );
	}

	public function test_subtraction_underflow_throws_invalid_money_exception(): void {
		$small = Money::from( '10.00', 'USD' );
		$large = Money::from( '25.00', 'USD' );

		$this->expectException( InvalidMoneyException::class );
		$this->expectExceptionMessage( 'Subtraction resulted in negative monetary value' );
		$small->subtract( $large );
	}

	public function test_multiplication_by_non_negative_factor(): void {
		$price = Money::from( '50.00', 'USD' );
		$multi = $price->multiply( '3.5' );
		$this->assertSame( '175.000000', $multi->amount() );

		$zero_multi = $price->multiply( 0 );
		$this->assertTrue( $zero_multi->is_zero() );
	}

	public function test_multiplication_rejects_negative_factor(): void {
		$price = Money::from( '50.00', 'USD' );
		$this->expectException( InvalidMoneyException::class );
		$price->multiply( -2 );
	}

	public function test_apply_percentage_discount(): void {
		$base = Money::from( '200.00', 'USD' );

		// 15% discount -> 170.00
		$discounted = $base->apply_percentage_discount( '15' );
		$this->assertSame( '170.000000', $discounted->amount() );

		// 0% discount -> unchanged
		$zero_discount = $base->apply_percentage_discount( '0' );
		$this->assertTrue( $zero_discount->equals( $base ) );

		// 100% discount -> clamped to zero
		$free = $base->apply_percentage_discount( '100' );
		$this->assertTrue( $free->is_zero() );

		// >100% discount -> clamped to zero
		$over_discount = $base->apply_percentage_discount( '120' );
		$this->assertTrue( $over_discount->is_zero() );
	}

	public function test_comparisons(): void {
		$a = Money::from( '100.00', 'USD' );
		$b = Money::from( '150.00', 'USD' );
		$c = Money::from( '100.00', 'USD' );

		$this->assertTrue( $a->is_less_than( $b ) );
		$this->assertTrue( $b->is_greater_than( $a ) );
		$this->assertTrue( $a->is_less_than_or_equal( $c ) );
		$this->assertTrue( $a->is_greater_than_or_equal( $c ) );
	}

	public function test_scientific_notation_string_is_rejected(): void {
		$this->expectException( InvalidMoneyException::class );
		$this->expectExceptionMessage( 'without scientific notation' );
		Money::from( '1e5', 'USD' );
	}

	public function test_float_input_is_rejected_under_strict_types(): void {
		$this->expectException( \TypeError::class );
		/** @phpstan-ignore-next-line */
		Money::from( 12.50, 'USD' );
	}

	public function test_round_and_format(): void {
		$money = Money::from( '12.345678', 'USD' );
		$this->assertSame( '12.35', $money->format( 2 ) );
		$this->assertSame( '12.3457', $money->format( 4 ) );

		$rounded = $money->round( 2 );
		$this->assertSame( '12.350000', $rounded->amount() );
	}
}
