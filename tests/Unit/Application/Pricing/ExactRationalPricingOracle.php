<?php

declare(strict_types=1);

namespace Tierza\Tests\Unit\Application\Pricing;

/**
 * Independent exact integer-rational reference pricing calculation oracle.
 *
 * Implements arbitrary-precision rational and integer arithmetic independent
 * of production DecimalMath and Money classes to serve as a pure mathematical
 * ground-truth oracle for verifying AuthoritativePriceCalculator.
 */
final class ExactRationalPricingOracle {

	/**
	 * Calculates percentage discount using pure integer-rational arithmetic:
	 * final_price = round_half_up( base_price × (100 - discount_percent) / 100, currency_decimals )
	 *
	 * @param string $base_price_str       Base price (e.g. "100.00", "29.99", "999999999999.99")
	 * @param string $discount_percent_str Discount percent (e.g. "20", "17.555555", "0", "100")
	 * @param int    $currency_decimals    Currency decimals (0 to 6)
	 * @return string Normalized decimal string with 6 decimal places (e.g. "80.000000")
	 */
	public static function calculate_percentage(
		string $base_price_str,
		string $discount_percent_str,
		int $currency_decimals
	): string {
		$base_price_clean = trim( $base_price_str );
		$discount_clean   = trim( $discount_percent_str );

		// Scale both values to integer micro-units (scale 6: 10^6).
		$base_int      = self::decimal_to_microunits( $base_price_clean );
		$discount_int  = self::decimal_to_microunits( $discount_clean );
		$hundred_int   = '100000000'; // 100.000000 scaled by 10^6

		// 100 - discount
		$remaining_int = self::bigint_sub( $hundred_int, $discount_int );

		// Exact numerator = base_int × remaining_int (scaled by 10^6 * 10^6 = 10^12)
		// True value = numerator / (10^6 * 10^6 * 100) = numerator / 10^14
		$numerator = self::bigint_mul( $base_int, $remaining_int );

		// Round half-up to currency_decimals:
		// We want round_half_up( numerator / 10^14, currency_decimals )
		// That is: round_half_up( numerator / 10^(14 - currency_decimals) )
		$k = 14 - $currency_decimals;
		$divisor = self::bigint_pow10( $k );

		[ $quotient, $remainder ] = self::bigint_div_rem( $numerator, $divisor );

		// Half-up threshold = 5 * 10^(k - 1)
		$threshold = self::bigint_mul( '5', self::bigint_pow10( $k - 1 ) );

		if ( self::bigint_comp( $remainder, $threshold ) >= 0 ) {
			$quotient = self::bigint_add( $quotient, '1' );
		}

		// Now quotient is in currency_decimals units. Format to 6-decimal string.
		return self::integer_to_six_decimal_string( $quotient, $currency_decimals );
	}

	/**
	 * Rounds a fixed price using pure rational integer arithmetic.
	 *
	 * @param string $fixed_price_str Fixed price string (e.g. "79.99", "0.00", "50.555500")
	 * @param int    $currency_decimals Currency decimals (0 to 6)
	 * @return string Normalized decimal string with 6 decimal places
	 */
	public static function calculate_fixed( string $fixed_price_str, int $currency_decimals ): string {
		$fixed_int = self::decimal_to_microunits( trim( $fixed_price_str ) );

		// fixed_int is in micro-units (scale 6, 10^6).
		// We want to round to currency_decimals: k = 6 - currency_decimals.
		$k = 6 - $currency_decimals;
		if ( $k <= 0 ) {
			return self::integer_to_six_decimal_string( $fixed_int, 6 );
		}

		$divisor = self::bigint_pow10( $k );
		[ $quotient, $remainder ] = self::bigint_div_rem( $fixed_int, $divisor );

		$threshold = self::bigint_mul( '5', self::bigint_pow10( $k - 1 ) );
		if ( self::bigint_comp( $remainder, $threshold ) >= 0 ) {
			$quotient = self::bigint_add( $quotient, '1' );
		}

		return self::integer_to_six_decimal_string( $quotient, $currency_decimals );
	}

	/**
	 * Converts a decimal string to micro-units integer string (scaled by 10^6).
	 */
	private static function decimal_to_microunits( string $dec ): string {
		$dec = trim( $dec );
		$parts = explode( '.', $dec );
		$integer_part = ltrim( $parts[0] ?? '0', '0' );
		if ( '' === $integer_part ) {
			$integer_part = '0';
		}
		$fractional_part = str_pad( substr( $parts[1] ?? '', 0, 6 ), 6, '0', STR_PAD_RIGHT );

		if ( '0' === $integer_part ) {
			return ltrim( $fractional_part, '0' ) ?: '0';
		}

		return $integer_part . $fractional_part;
	}

	/**
	 * Formats an integer amount at scale $currency_decimals into standard 6-decimal string.
	 */
	private static function integer_to_six_decimal_string( string $units, int $currency_decimals ): string {
		$units = ltrim( $units, '0' ) ?: '0';

		if ( 0 === $currency_decimals ) {
			return $units . '.000000';
		}

		if ( strlen( $units ) <= $currency_decimals ) {
			$padded = str_pad( $units, $currency_decimals, '0', STR_PAD_LEFT );
			$int_part = '0';
			$frac_part = $padded;
		} else {
			$int_part = substr( $units, 0, strlen( $units ) - $currency_decimals );
			$frac_part = substr( $units, strlen( $units ) - $currency_decimals );
		}

		$six_decimals = str_pad( substr( $frac_part, 0, 6 ), 6, '0', STR_PAD_RIGHT );
		return $int_part . '.' . $six_decimals;
	}

	// -------------------------------------------------------------------------
	// Pure String Big-Integer Arithmetic (Textbook grade-school algorithms)
	// -------------------------------------------------------------------------

	public static function bigint_add( string $a, string $b ): string {
		$a = ltrim( $a, '0' ) ?: '0';
		$b = ltrim( $b, '0' ) ?: '0';
		$i = strlen( $a ) - 1;
		$j = strlen( $b ) - 1;
		$carry = 0;
		$res = '';

		while ( $i >= 0 || $j >= 0 || $carry > 0 ) {
			$sum = $carry;
			if ( $i >= 0 ) {
				$sum += (int) $a[ $i-- ];
			}
			if ( $j >= 0 ) {
				$sum += (int) $b[ $j-- ];
			}
			$carry = intdiv( $sum, 10 );
			$res .= (string) ( $sum % 10 );
		}

		return strrev( $res );
	}

	public static function bigint_sub( string $a, string $b ): string {
		$a = ltrim( $a, '0' ) ?: '0';
		$b = ltrim( $b, '0' ) ?: '0';
		if ( self::bigint_comp( $a, $b ) < 0 ) {
			return '0';
		}

		$i = strlen( $a ) - 1;
		$j = strlen( $b ) - 1;
		$borrow = 0;
		$res = '';

		while ( $i >= 0 ) {
			$diff = ( (int) $a[ $i-- ] ) - $borrow;
			if ( $j >= 0 ) {
				$diff -= (int) $b[ $j-- ];
			}
			if ( $diff < 0 ) {
				$diff += 10;
				$borrow = 1;
			} else {
				$borrow = 0;
			}
			$res .= (string) $diff;
		}

		$out = ltrim( strrev( $res ), '0' );
		return '' === $out ? '0' : $out;
	}

	public static function bigint_mul( string $a, string $b ): string {
		$a = ltrim( $a, '0' ) ?: '0';
		$b = ltrim( $b, '0' ) ?: '0';
		if ( '0' === $a || '0' === $b ) {
			return '0';
		}

		$len_a = strlen( $a );
		$len_b = strlen( $b );
		$res   = array_fill( 0, $len_a + $len_b, 0 );

		for ( $i = $len_a - 1; $i >= 0; $i-- ) {
			for ( $j = $len_b - 1; $j >= 0; $j-- ) {
				$mul = ( (int) $a[ $i ] ) * ( (int) $b[ $j ] );
				$sum = $res[ $i + $j + 1 ] + $mul;
				$res[ $i + $j + 1 ] = $sum % 10;
				$res[ $i + $j ]     += intdiv( $sum, 10 );
			}
		}

		$out = ltrim( implode( '', $res ), '0' );
		return '' === $out ? '0' : $out;
	}

	/**
	 * @return array{string, string} [quotient, remainder]
	 */
	public static function bigint_div_rem( string $num, string $denom ): array {
		$num   = ltrim( $num, '0' ) ?: '0';
		$denom = ltrim( $denom, '0' ) ?: '0';

		if ( '0' === $denom ) {
			throw new \InvalidArgumentException( 'Division by zero in oracle.' );
		}

		if ( self::bigint_comp( $num, $denom ) < 0 ) {
			return [ '0', $num ];
		}

		$quotient = '';
		$current  = '';
		$len = strlen( $num );

		for ( $i = 0; $i < $len; $i++ ) {
			$current .= $num[ $i ];
			$current = ltrim( $current, '0' );
			if ( '' === $current ) {
				$current = '0';
			}

			if ( self::bigint_comp( $current, $denom ) < 0 ) {
				$quotient .= '0';
				continue;
			}

			// Binary search quotient digit 1..9
			$low = 1;
			$high = 9;
			$d = 0;
			while ( $low <= $high ) {
				$mid = intdiv( $low + $high, 2 );
				$product = self::bigint_mul( $denom, (string) $mid );
				if ( self::bigint_comp( $product, $current ) <= 0 ) {
					$d = $mid;
					$low = $mid + 1;
				} else {
					$high = $mid - 1;
				}
			}

			$quotient .= (string) $d;
			$current = self::bigint_sub( $current, self::bigint_mul( $denom, (string) $d ) );
		}

		$q_clean = ltrim( $quotient, '0' ) ?: '0';
		$r_clean = ltrim( $current, '0' ) ?: '0';

		return [ $q_clean, $r_clean ];
	}

	public static function bigint_comp( string $a, string $b ): int {
		$a = ltrim( $a, '0' ) ?: '0';
		$b = ltrim( $b, '0' ) ?: '0';
		$len_a = strlen( $a );
		$len_b = strlen( $b );
		if ( $len_a !== $len_b ) {
			return $len_a <=> $len_b;
		}
		return strcmp( $a, $b );
	}

	public static function bigint_pow10( int $power ): string {
		if ( $power <= 0 ) {
			return '1';
		}
		return '1' . str_repeat( '0', $power );
	}
}
