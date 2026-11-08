<?php

declare(strict_types=1);

namespace Tierza\Tests\Unit\Domain\Pricing;

use DivisionByZeroError;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tierza\Domain\ValueObjects\DecimalMath;

/**
 * Comprehensive differential test suite verifying 100% parity
 * between the C-level BCMath driver and the pure-PHP fallback driver.
 */
final class DecimalMathDifferentialTest extends TestCase {

	/**
	 * @return array<string, array{string, int}>
	 */
	public static function normalization_vectors_provider(): array {
		return [
			'negative fraction half'                 => [ '-0.5', 6 ],
			'negative fraction eighth'               => [ '-0.125', 6 ],
			'negative boundary 6 decimals'           => [ '-0.000001', 6 ],
			'negative underflow becomes unsigned zero' => [ '-0.0000001', 6 ],
			'positive integer zero'                  => [ '0', 6 ],
			'negative zero becomes unsigned zero'    => [ '-0', 6 ],
			'negative zero with decimals'            => [ '-0.0000', 6 ],
			'positive fraction'                      => [ '1.5', 6 ],
			'negative fraction'                      => [ '-1.5', 6 ],
			'very large trillion value'              => [ '1000000000000.123456', 6 ],
			'very large integer'                     => [ '999999999999999999', 4 ],
			'small positive boundary'                => [ '0.000001', 6 ],
			'leading plus sign'                      => [ '+125.50', 2 ],
			'two display decimals'                   => [ '19.99', 2 ],
			'zero display decimals'                  => [ '500', 0 ],
			'high scale truncating'                  => [ '12.34567891', 6 ],
		];
	}

	/**
	 * @dataProvider normalization_vectors_provider
	 */
	public function test_normalization_differential_parity( string $input, int $scale ): void {
		$bcmath_res = DecimalMath::normalize( $input, $scale, DecimalMath::DRIVER_BCMATH );
		$php_res    = DecimalMath::normalize( $input, $scale, DecimalMath::DRIVER_PHP );

		$this->assertSame(
			$bcmath_res,
			$php_res,
			sprintf( 'Parity failure in normalize("%s", %d)', $input, $scale )
		);

		// Guarantee that zero is never negative
		if ( '0' === ltrim( str_replace( '.', '', $php_res ), '0' ) || '' === ltrim( str_replace( '.', '', $php_res ), '0' ) ) {
			$this->assertFalse( str_starts_with( $php_res, '-' ), 'Canonical zero must never retain a negative sign.' );
			$this->assertFalse( str_starts_with( $bcmath_res, '-' ), 'Canonical zero must never retain a negative sign.' );
		}
	}

	/**
	 * @return array<string, array{string, string, int}>
	 */
	public static function binary_operations_provider(): array {
		return [
			'both positive'                          => [ '100.25', '50.75', 6 ],
			'both negative'                          => [ '-100.25', '-50.75', 6 ],
			'first positive, second negative'        => [ '100.25', '-50.75', 6 ],
			'first negative, second positive'        => [ '-100.25', '50.75', 6 ],
			'result exactly zero'                    => [ '50.5', '-50.5', 6 ],
			'negative fraction arithmetic'           => [ '-0.5', '-0.25', 6 ],
			'very large numbers exceeding INT_MAX'   => [ '1000000000000.500000', '2000000000000.250000', 6 ],
			'subtraction yielding small negative'    => [ '0.000001', '0.000002', 6 ],
			'multiplication by zero'                 => [ '125.75', '0', 6 ],
			'multiplication by negative fraction'    => [ '200.00', '-0.15', 6 ],
			'multiplication of two negative numbers' => [ '-25.50', '-4.00', 6 ],
			'division clean integer quotient'        => [ '100.00', '25.00', 6 ],
			'division recurring decimal'             => [ '10.00', '3.00', 6 ],
			'division of very large numbers'         => [ '5000000000000.000000', '2.000000', 6 ],
			'division resulting in small fraction'   => [ '1.00', '8.00', 6 ],
			'scale 2 standard currency'              => [ '15.99', '2.50', 2 ],
			'scale 0 integer math'                   => [ '100', '3', 0 ],
		];
	}

	/**
	 * @dataProvider binary_operations_provider
	 */
	public function test_addition_subtraction_multiplication_parity( string $a, string $b, int $scale ): void {
		// Addition
		$add_bcmath = DecimalMath::add( $a, $b, $scale, DecimalMath::DRIVER_BCMATH );
		$add_php    = DecimalMath::add( $a, $b, $scale, DecimalMath::DRIVER_PHP );
		$this->assertSame( $add_bcmath, $add_php, sprintf( 'Add mismatch: %s + %s (scale %d)', $a, $b, $scale ) );

		// Subtraction
		$sub_bcmath = DecimalMath::sub( $a, $b, $scale, DecimalMath::DRIVER_BCMATH );
		$sub_php    = DecimalMath::sub( $a, $b, $scale, DecimalMath::DRIVER_PHP );
		$this->assertSame( $sub_bcmath, $sub_php, sprintf( 'Sub mismatch: %s - %s (scale %d)', $a, $b, $scale ) );

		// Multiplication
		$mul_bcmath = DecimalMath::mul( $a, $b, $scale, DecimalMath::DRIVER_BCMATH );
		$mul_php    = DecimalMath::mul( $a, $b, $scale, DecimalMath::DRIVER_PHP );
		$this->assertSame( $mul_bcmath, $mul_php, sprintf( 'Mul mismatch: %s * %s (scale %d)', $a, $b, $scale ) );

		// Comparison
		$comp_bcmath = DecimalMath::comp( $a, $b, $scale, DecimalMath::DRIVER_BCMATH );
		$comp_php    = DecimalMath::comp( $a, $b, $scale, DecimalMath::DRIVER_PHP );
		$this->assertSame( $comp_bcmath, $comp_php, sprintf( 'Comp mismatch: %s <=> %s (scale %d)', $a, $b, $scale ) );
	}

	/**
	 * @dataProvider binary_operations_provider
	 */
	public function test_division_parity( string $a, string $b, int $scale ): void {
		if ( DecimalMath::is_zero( $b ) ) {
			$this->expectException( DivisionByZeroError::class );
			DecimalMath::div( $a, $b, $scale, DecimalMath::DRIVER_PHP );
			return;
		}

		$div_bcmath = DecimalMath::div( $a, $b, $scale, DecimalMath::DRIVER_BCMATH );
		$div_php    = DecimalMath::div( $a, $b, $scale, DecimalMath::DRIVER_PHP );
		$this->assertSame( $div_bcmath, $div_php, sprintf( 'Div mismatch: %s / %s (scale %d)', $a, $b, $scale ) );
	}

	public function test_division_by_zero_throws_exception_on_both_drivers(): void {
		$zero_divisors = [ '0', '-0', '+0', '0.0', '0.000000', '-0.0000000000' ];

		foreach ( $zero_divisors as $divisor ) {
			$bcmath_threw = false;
			try {
				DecimalMath::div( '1', $divisor, 6, DecimalMath::DRIVER_BCMATH );
			} catch ( DivisionByZeroError ) {
				$bcmath_threw = true;
			}

			$php_threw = false;
			try {
				DecimalMath::div( '1', $divisor, 6, DecimalMath::DRIVER_PHP );
			} catch ( DivisionByZeroError ) {
				$php_threw = true;
			}

			$this->assertTrue( $bcmath_threw, sprintf( 'BCMath must throw DivisionByZeroError for divisor "%s"', $divisor ) );
			$this->assertTrue( $php_threw, sprintf( 'Pure PHP driver must throw DivisionByZeroError for divisor "%s"', $divisor ) );
		}
	}

	/**
	 * Tests sub-scale divisors and division edge cases required by code review.
	 */
	public function test_sub_scale_divisors_and_division_edge_cases(): void {
		$cases = [
			// [ $a, $b, $scale, $expected ]
			[ '1', '0.0000001', 6, '10000000.000000' ],
			[ '1', '-0.0000001', 6, '-10000000.000000' ],
			[ '0', '0.0000001', 6, '0.000000' ],
			[ '1', '0.000001', 6, '1000000.000000' ],
			[ '1', '0.0000000001', 6, '10000000000.000000' ],
			[ '1.0000009', '2.0000009', 6, '0.500000' ],
			[ '5.5', '0.0000001', 6, '55000000.000000' ],
			[ '-5.5', '-0.0000001', 6, '55000000.000000' ],
		];

		foreach ( $cases as [ $a, $b, $scale, $expected ] ) {
			$bc_res  = DecimalMath::div( $a, $b, $scale, DecimalMath::DRIVER_BCMATH );
			$php_res = DecimalMath::div( $a, $b, $scale, DecimalMath::DRIVER_PHP );

			$this->assertSame( $expected, $bc_res, sprintf( 'BCMath failed for %s / %s (scale %d)', $a, $b, $scale ) );
			$this->assertSame( $expected, $php_res, sprintf( 'Pure PHP failed for %s / %s (scale %d)', $a, $b, $scale ) );
			$this->assertSame( $bc_res, $php_res, sprintf( 'Parity mismatch for %s / %s (scale %d)', $a, $b, $scale ) );
		}
	}

	public function test_is_zero_exact_mathematical_evaluation(): void {
		$zeroes = [
			'0',
			'-0',
			'+0',
			'0.0',
			'0.000000',
			'-0.000000000000',
			'+0.0000',
			'000',
			'-000.000',
		];

		foreach ( $zeroes as $val ) {
			$this->assertTrue( DecimalMath::is_zero( $val ), sprintf( 'Expected is_zero("%s") to be true', $val ) );
		}

		$non_zeroes = [
			'0.0000001',
			'-0.0000001',
			'0.000001',
			'0.0000000001',
			'1',
			'-1',
			'0.00000000000000000001',
			'abc',
			'',
		];

		foreach ( $non_zeroes as $val ) {
			$this->assertFalse( DecimalMath::is_zero( $val ), sprintf( 'Expected is_zero("%s") to be false', $val ) );
		}
	}

	public function test_sub_scale_inputs_preserve_precision_across_operations(): void {
		// Addition preserving beyond scale 6 before truncating
		$add_bc  = DecimalMath::add( '0.0000006', '0.0000006', 6, DecimalMath::DRIVER_BCMATH );
		$add_php = DecimalMath::add( '0.0000006', '0.0000006', 6, DecimalMath::DRIVER_PHP );
		$this->assertSame( '0.000001', $add_bc );
		$this->assertSame( '0.000001', $add_php );

		// Subtraction preserving beyond scale 6 before truncating
		$sub_bc  = DecimalMath::sub( '1.0000012', '0.0000006', 6, DecimalMath::DRIVER_BCMATH );
		$sub_php = DecimalMath::sub( '1.0000012', '0.0000006', 6, DecimalMath::DRIVER_PHP );
		$this->assertSame( '1.000000', $sub_bc );
		$this->assertSame( '1.000000', $sub_php );

		// Multiplication preserving sub-scale divisor
		$mul_bc  = DecimalMath::mul( '10000000', '0.0000001', 6, DecimalMath::DRIVER_BCMATH );
		$mul_php = DecimalMath::mul( '10000000', '0.0000001', 6, DecimalMath::DRIVER_PHP );
		$this->assertSame( '1.000000', $mul_bc );
		$this->assertSame( '1.000000', $mul_php );
	}

	public function test_scale_arguments_validation(): void {
		// Negative scale
		$this->expectException( InvalidArgumentException::class );
		DecimalMath::normalize( '1.0', -1 );
	}

	public function test_excessive_scale_arguments_validation(): void {
		$this->expectException( InvalidArgumentException::class );
		DecimalMath::normalize( '1.0', 21 );
	}

	public function test_all_public_arithmetic_methods_reject_invalid_scale(): void {
		$methods = [
			fn() => DecimalMath::comp( '1', '2', -1 ),
			fn() => DecimalMath::comp( '1', '2', 25 ),
			fn() => DecimalMath::add( '1', '2', -1 ),
			fn() => DecimalMath::add( '1', '2', 25 ),
			fn() => DecimalMath::sub( '1', '2', -1 ),
			fn() => DecimalMath::sub( '1', '2', 25 ),
			fn() => DecimalMath::mul( '1', '2', -1 ),
			fn() => DecimalMath::mul( '1', '2', 25 ),
			fn() => DecimalMath::div( '1', '2', -1 ),
			fn() => DecimalMath::div( '1', '2', 25 ),
			fn() => DecimalMath::round( '1.5', -1 ),
			fn() => DecimalMath::round( '1.5', 25 ),
			fn() => DecimalMath::round( '1.5', 2, 999 ),
		];

		foreach ( $methods as $idx => $invoker ) {
			$threw = false;
			try {
				$invoker();
			} catch ( InvalidArgumentException ) {
				$threw = true;
			}
			$this->assertTrue( $threw, sprintf( 'Method check at index %d must throw InvalidArgumentException', $idx ) );
		}
	}

	/**
	 * @return array<string, array{string, int, string}>
	 */
	public static function rounding_vectors_provider(): array {
		return [
			'round half up positive below 5' => [ '12.344444', 2, '12.34' ],
			'round half up positive at 5'    => [ '12.345000', 2, '12.35' ],
			'round half up positive above 5' => [ '12.346000', 2, '12.35' ],
			'round half up negative below 5' => [ '-12.344444', 2, '-12.34' ],
			'round half up negative at 5'    => [ '-12.345000', 2, '-12.35' ],
			'round half up to integer'       => [ '9.50', 0, '10' ],
			'round 9.49 to integer'          => [ '9.49', 0, '9' ],
			'round -9.50 to integer'         => [ '-9.50', 0, '-10' ],
			'round small negative to zero'   => [ '-0.004', 2, '0.00' ],
			'round 99.999 up to 100'         => [ '99.999', 2, '100.00' ],
		];
	}

	/**
	 * @dataProvider rounding_vectors_provider
	 */
	public function test_rounding_differential_parity( string $val, int $precision, string $expected ): void {
		$res_bcmath = DecimalMath::round( $val, $precision, PHP_ROUND_HALF_UP, DecimalMath::DRIVER_BCMATH );
		$res_php    = DecimalMath::round( $val, $precision, PHP_ROUND_HALF_UP, DecimalMath::DRIVER_PHP );

		$this->assertSame( $expected, $res_bcmath );
		$this->assertSame( $expected, $res_php );
		$this->assertSame( $res_bcmath, $res_php );

		// Ensure canonical zero never retains negative sign
		if ( '0' === ltrim( str_replace( '.', '', $res_php ), '0' ) || '' === ltrim( str_replace( '.', '', $res_php ), '0' ) ) {
			$this->assertFalse( str_starts_with( $res_php, '-' ) );
			$this->assertFalse( str_starts_with( $res_bcmath, '-' ) );
		}
	}

	public function test_scientific_notation_is_strictly_rejected(): void {
		$this->assertFalse( DecimalMath::is_valid( '1e5' ) );
		$this->assertFalse( DecimalMath::is_valid( '1.5E2' ) );
		$this->assertFalse( DecimalMath::is_valid( '2.5e-3' ) );
		$this->assertFalse( DecimalMath::is_valid( '1E+6' ) );

		$this->expectException( InvalidArgumentException::class );
		DecimalMath::normalize( '1e5' );
	}
}
