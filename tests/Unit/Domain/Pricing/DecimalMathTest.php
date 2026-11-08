<?php

declare(strict_types=1);

namespace Tierza\Tests\Unit\Domain\Pricing;

use DivisionByZeroError;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tierza\Domain\ValueObjects\DecimalMath;

final class DecimalMathTest extends TestCase {

	public function test_is_valid_validates_numeric_decimal_strings(): void {
		$this->assertTrue( DecimalMath::is_valid( '100' ) );
		$this->assertTrue( DecimalMath::is_valid( '100.50' ) );
		$this->assertTrue( DecimalMath::is_valid( '0' ) );
		$this->assertTrue( DecimalMath::is_valid( '0.000000' ) );
		$this->assertTrue( DecimalMath::is_valid( '-25.99' ) );

		$this->assertFalse( DecimalMath::is_valid( '' ) );
		$this->assertFalse( DecimalMath::is_valid( 'abc' ) );
		$this->assertFalse( DecimalMath::is_valid( '12.34.56' ) );
		$this->assertFalse( DecimalMath::is_valid( ' 12a ' ) );
	}

	public function test_normalize_formats_to_exact_scale(): void {
		$this->assertSame( '150.000000', DecimalMath::normalize( '150', 6 ) );
		$this->assertSame( '150.50', DecimalMath::normalize( '150.5', 2 ) );
		$this->assertSame( '0.00', DecimalMath::normalize( '0', 2 ) );
	}

	public function test_normalize_throws_exception_on_invalid_string(): void {
		$this->expectException( InvalidArgumentException::class );
		DecimalMath::normalize( 'invalid-number' );
	}

	public function test_comp_correctly_orders_decimals(): void {
		$this->assertSame( 0, DecimalMath::comp( '100.00', '100.0000' ) );
		$this->assertSame( 1, DecimalMath::comp( '100.01', '100.00' ) );
		$this->assertSame( -1, DecimalMath::comp( '99.99', '100.00' ) );
		$this->assertSame( 1, DecimalMath::comp( '0', '-5' ) );
		$this->assertSame( -1, DecimalMath::comp( '-10', '-5' ) );
	}

	public function test_add_sub_mul_operations(): void {
		$this->assertSame( '15.500000', DecimalMath::add( '10.25', '5.25', 6 ) );
		$this->assertSame( '5.000000', DecimalMath::sub( '10.25', '5.25', 6 ) );
		$this->assertSame( '50.000000', DecimalMath::mul( '10.00', '5.00', 6 ) );
	}

	public function test_div_calculates_quotient_accurately(): void {
		$this->assertSame( '3.333333', DecimalMath::div( '10', '3', 6 ) );
		$this->assertSame( '2.500000', DecimalMath::div( '5', '2', 6 ) );
	}

	public function test_div_throws_division_by_zero(): void {
		$this->expectException( DivisionByZeroError::class );
		DecimalMath::div( '10', '0', 6 );
	}

	public function test_round_half_up_precision(): void {
		$this->assertSame( '12.35', DecimalMath::round( '12.345678', 2 ) );
		$this->assertSame( '12.34', DecimalMath::round( '12.344444', 2 ) );
		$this->assertSame( '13', DecimalMath::round( '12.50', 0 ) );
		$this->assertSame( '12', DecimalMath::round( '12.49', 0 ) );
		$this->assertSame( '100.00', DecimalMath::round( '99.999', 2 ) );
	}
}
