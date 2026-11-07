<?php

declare(strict_types=1);

namespace Tierza\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use Tierza\Domain\Exceptions\InvalidRuleException;
use Tierza\Domain\ValueObjects\PricingValue;

/**
 * Unit tests for PricingValue value object.
 */
class PricingValueTest extends TestCase {

	public function test_valid_percentage_discount(): void {
		$value = PricingValue::percentage( '15.5' );
		$this->assertTrue( $value->is_percentage() );
		$this->assertFalse( $value->is_fixed() );
		$this->assertSame( '15.500000', $value->value() );
		$this->assertSame( '15.500000', (string) $value );
		$this->assertFalse( $value->is_zero() );
	}

	public function test_explicit_zero_percentage(): void {
		$value = PricingValue::percentage( '0' );
		$this->assertTrue( $value->is_percentage() );
		$this->assertTrue( $value->is_zero() );
		$this->assertSame( '0.000000', $value->value() );
	}

	public function test_100_percent_discount(): void {
		$value = PricingValue::percentage( 100 );
		$this->assertSame( '100.000000', $value->value() );
	}

	public function test_percentage_exceeding_100_throws_exception(): void {
		$this->expectException( InvalidRuleException::class );
		$this->expectExceptionMessage( 'Percentage discount cannot exceed 100%' );
		PricingValue::percentage( '100.000001' );
	}

	public function test_negative_percentage_throws_exception(): void {
		$this->expectException( InvalidRuleException::class );
		$this->expectExceptionMessage( 'Pricing value cannot be negative' );
		PricingValue::percentage( '-0.01' );
	}

	public function test_valid_fixed_price(): void {
		$value = PricingValue::fixed( '2500000' );
		$this->assertTrue( $value->is_fixed() );
		$this->assertFalse( $value->is_percentage() );
		$this->assertSame( '2500000.000000', $value->value() );
		$this->assertFalse( $value->is_zero() );
	}

	public function test_explicit_zero_fixed_price(): void {
		$value = PricingValue::fixed( '0.000000' );
		$this->assertTrue( $value->is_fixed() );
		$this->assertTrue( $value->is_zero() );
		$this->assertSame( '0.000000', $value->value() );
	}

	public function test_negative_fixed_price_throws_exception(): void {
		$this->expectException( InvalidRuleException::class );
		$this->expectExceptionMessage( 'Pricing value cannot be negative' );
		PricingValue::fixed( '-1000' );
	}

	public function test_non_numeric_value_throws_exception(): void {
		$this->expectException( InvalidRuleException::class );
		$this->expectExceptionMessage( 'Pricing value must be numeric' );
		new PricingValue( 'fixed', 'invalid-price' );
	}

	public function test_invalid_pricing_type_throws_exception(): void {
		$this->expectException( InvalidRuleException::class );
		$this->expectExceptionMessage( 'Invalid pricing type "multiplier"' );
		new PricingValue( 'multiplier', '10' );
	}

	public function test_precision_retention_without_float_distortion(): void {
		$value = PricingValue::fixed( '123456789.123456' );
		$this->assertSame( '123456789.123456', $value->value() );
	}

	public function test_equality_comparison(): void {
		$val1 = PricingValue::percentage( '20.00' );
		$val2 = PricingValue::percentage( '20' );
		$val3 = PricingValue::fixed( '20' );

		$this->assertTrue( $val1->equals( $val2 ) );
		$this->assertFalse( $val1->equals( $val3 ) );
	}
}
