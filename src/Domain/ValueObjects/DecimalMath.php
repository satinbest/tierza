<?php

declare(strict_types=1);

namespace Tierza\Domain\ValueObjects;

use DivisionByZeroError;
use InvalidArgumentException;

/**
 * Deterministic arbitrary-precision decimal-string arithmetic helper.
 *
 * Uses BCMath when available (C-level high performance).
 * Seamlessly falls back to pure-PHP string arithmetic when BCMath is unavailable on minimal shared hosts.
 */
final class DecimalMath {

	public const DEFAULT_SCALE = 6;

	/**
	 * Checks if a string is a valid non-empty decimal representation.
	 */
	public static function is_valid( string $val ): bool {
		return 1 === preg_match( '/^-?[0-9]+(\.[0-9]+)?$/', trim( $val ) );
	}

	/**
	 * Normalizes a numeric string to a fixed decimal scale without floating point casting.
	 *
	 * @param string $val
	 * @param int    $scale
	 * @return numeric-string
	 */
	public static function normalize( string $val, int $scale = self::DEFAULT_SCALE ): string {
		$val = trim( $val );
		if ( ! self::is_valid( $val ) ) {
			throw new InvalidArgumentException( sprintf( 'Invalid decimal string "%s".', $val ) );
		}

		/** @var numeric-string $val */
		if ( extension_loaded( 'bcmath' ) ) {
			/** @var numeric-string */
			return bcadd( $val, '0', $scale );
		}

		return self::fallback_normalize( $val, $scale );
	}

	/**
	 * Compares two decimal strings.
	 * Returns 0 if equal, 1 if $a > $b, -1 if $a < $b.
	 */
	public static function comp( string $a, string $b, int $scale = self::DEFAULT_SCALE ): int {
		$a = trim( $a );
		$b = trim( $b );

		if ( ! self::is_valid( $a ) || ! self::is_valid( $b ) ) {
			throw new InvalidArgumentException( 'Invalid decimal values for comparison.' );
		}

		/** @var numeric-string $a */
		/** @var numeric-string $b */
		if ( extension_loaded( 'bcmath' ) ) {
			return bccomp( $a, $b, $scale );
		}

		return self::fallback_comp( $a, $b, $scale );
	}

	/**
	 * Adds two decimal strings.
	 *
	 * @return numeric-string
	 */
	public static function add( string $a, string $b, int $scale = self::DEFAULT_SCALE ): string {
		$a = trim( $a );
		$b = trim( $b );

		if ( ! self::is_valid( $a ) || ! self::is_valid( $b ) ) {
			throw new InvalidArgumentException( 'Invalid decimal values for addition.' );
		}

		/** @var numeric-string $a */
		/** @var numeric-string $b */
		if ( extension_loaded( 'bcmath' ) ) {
			/** @var numeric-string */
			return bcadd( $a, $b, $scale );
		}

		return self::fallback_add( $a, $b, $scale );
	}

	/**
	 * Subtracts $b from $a ($a - $b).
	 *
	 * @return numeric-string
	 */
	public static function sub( string $a, string $b, int $scale = self::DEFAULT_SCALE ): string {
		$a = trim( $a );
		$b = trim( $b );

		if ( ! self::is_valid( $a ) || ! self::is_valid( $b ) ) {
			throw new InvalidArgumentException( 'Invalid decimal values for subtraction.' );
		}

		/** @var numeric-string $a */
		/** @var numeric-string $b */
		if ( extension_loaded( 'bcmath' ) ) {
			/** @var numeric-string */
			return bcsub( $a, $b, $scale );
		}

		return self::fallback_sub( $a, $b, $scale );
	}

	/**
	 * Multiplies two decimal strings.
	 *
	 * @return numeric-string
	 */
	public static function mul( string $a, string $b, int $scale = self::DEFAULT_SCALE ): string {
		$a = trim( $a );
		$b = trim( $b );

		if ( ! self::is_valid( $a ) || ! self::is_valid( $b ) ) {
			throw new InvalidArgumentException( 'Invalid decimal values for multiplication.' );
		}

		/** @var numeric-string $a */
		/** @var numeric-string $b */
		if ( extension_loaded( 'bcmath' ) ) {
			/** @var numeric-string */
			return bcmul( $a, $b, $scale );
		}

		return self::fallback_mul( $a, $b, $scale );
	}

	/**
	 * Divides $a by $b ($a / $b).
	 *
	 * @return numeric-string
	 */
	public static function div( string $a, string $b, int $scale = self::DEFAULT_SCALE ): string {
		$a = trim( $a );
		$b = trim( $b );

		if ( ! self::is_valid( $a ) || ! self::is_valid( $b ) ) {
			throw new InvalidArgumentException( 'Invalid decimal values for division.' );
		}

		if ( self::comp( $b, '0', $scale ) === 0 ) {
			throw new DivisionByZeroError( 'Division by zero in DecimalMath::div().' );
		}

		/** @var numeric-string $a */
		/** @var numeric-string $b */
		if ( extension_loaded( 'bcmath' ) ) {
			/** @var numeric-string */
			return bcdiv( $a, $b, $scale );
		}

		return self::fallback_div( $a, $b, $scale );
	}

	/**
	 * Deterministically rounds a decimal string to the target decimal places (Half-Up).
	 *
	 * @param string $val
	 * @param int    $precision
	 * @param int    $mode Currently PHP_ROUND_HALF_UP (default) is supported.
	 * @return numeric-string
	 */
	public static function round( string $val, int $precision = 2, int $mode = PHP_ROUND_HALF_UP ): string {
		$val = trim( $val );
		if ( ! self::is_valid( $val ) ) {
			throw new InvalidArgumentException( sprintf( 'Invalid decimal string for rounding "%s".', $val ) );
		}

		$is_negative = str_starts_with( $val, '-' );
		$unsigned    = $is_negative ? substr( $val, 1 ) : $val;

		// Pad to at least precision + 1 digits to inspect the rounding digit.
		$extra_scale = $precision + 4;
		$normalized  = self::normalize( $unsigned, $extra_scale );

		$parts    = explode( '.', $normalized );
		$integer  = $parts[0];
		$fraction = $parts[1] ?? str_repeat( '0', $extra_scale );

		$check_digit = (int) ( $fraction[ $precision ] ?? '0' );
		$main_frac   = substr( $fraction, 0, $precision );

		$should_round_up = false;
		if ( PHP_ROUND_HALF_UP === $mode ) {
			$should_round_up = $check_digit >= 5;
		}

		if ( ! $should_round_up ) {
			$result = $precision > 0 ? $integer . '.' . $main_frac : $integer;
			/** @var numeric-string */
			return $is_negative && self::comp( $result, '0', $precision ) !== 0 ? '-' . $result : $result;
		}

		// Add 1 to the least significant place
		$increment = '0.' . str_repeat( '0', max( 0, $precision - 1 ) ) . '1';
		if ( 0 === $precision ) {
			$increment = '1';
		}

		$truncated = $precision > 0 ? $integer . '.' . $main_frac : $integer;
		$rounded   = self::add( $truncated, $increment, $precision );

		/** @var numeric-string */
		return $is_negative && self::comp( $rounded, '0', $precision ) !== 0 ? '-' . $rounded : $rounded;
	}

	// --------------------------------------------------------------------------
	// Pure PHP Fallback Algorithms (Zero Float Precision Loss)
	// --------------------------------------------------------------------------

	/**
	 * @return numeric-string
	 */
	private static function fallback_normalize( string $val, int $scale ): string {
		$is_neg   = str_starts_with( $val, '-' );
		$unsigned = $is_neg ? substr( $val, 1 ) : $val;

		$parts    = explode( '.', $unsigned );
		$int_part = ltrim( $parts[0], '0' );
		if ( '' === $int_part ) {
			$int_part = '0';
		}

		$frac_part = $parts[1] ?? '';
		if ( strlen( $frac_part ) < $scale ) {
			$frac_part = str_pad( $frac_part, $scale, '0', STR_PAD_RIGHT );
		} else {
			$frac_part = substr( $frac_part, 0, $scale );
		}

		$res = $scale > 0 ? $int_part . '.' . $frac_part : $int_part;
		if ( $is_neg && '0' !== $int_part ) {
			$res = '-' . $res;
		}

		assert( is_numeric( $res ) );
		return $res;
	}

	private static function fallback_comp( string $a, string $b, int $scale ): int {
		$norm_a = self::fallback_normalize( $a, $scale );
		$norm_b = self::fallback_normalize( $b, $scale );

		if ( $norm_a === $norm_b ) {
			return 0;
		}

		$is_neg_a = str_starts_with( $norm_a, '-' );
		$is_neg_b = str_starts_with( $norm_b, '-' );

		if ( $is_neg_a && ! $is_neg_b ) {
			return -1;
		}
		if ( ! $is_neg_a && $is_neg_b ) {
			return 1;
		}

		$clean_a = $is_neg_a ? substr( $norm_a, 1 ) : $norm_a;
		$clean_b = $is_neg_b ? substr( $norm_b, 1 ) : $norm_b;

		$parts_a = explode( '.', $clean_a );
		$parts_b = explode( '.', $clean_b );

		$int_a = $parts_a[0];
		$int_b = $parts_b[0];

		if ( strlen( $int_a ) !== strlen( $int_b ) ) {
			$cmp = strlen( $int_a ) > strlen( $int_b ) ? 1 : -1;
			return $is_neg_a ? -$cmp : $cmp;
		}

		if ( $int_a !== $int_b ) {
			$cmp = strcmp( $int_a, $int_b ) > 0 ? 1 : -1;
			return $is_neg_a ? -$cmp : $cmp;
		}

		$frac_a = $parts_a[1] ?? '';
		$frac_b = $parts_b[1] ?? '';

		$cmp = strcmp( $frac_a, $frac_b ) > 0 ? 1 : ( strcmp( $frac_a, $frac_b ) < 0 ? -1 : 0 );
		return $is_neg_a ? -$cmp : $cmp;
	}

	/**
	 * @return numeric-string
	 */
	private static function fallback_add( string $a, string $b, int $scale ): string {
		$norm_a = self::fallback_normalize( $a, $scale );
		$norm_b = self::fallback_normalize( $b, $scale );

		$parts_a = explode( '.', $norm_a );
		$parts_b = explode( '.', $norm_b );

		$digits_a = $parts_a[0] . ( $parts_a[1] ?? '' );
		$digits_b = $parts_b[0] . ( $parts_b[1] ?? '' );

		// For standard safe ranges (common e-commerce values), add using integer or string addition:
		if ( function_exists( 'gmp_add' ) ) {
			$sum = gmp_strval( gmp_add( $digits_a, $digits_b ) );
		} else {
			$sum = self::string_add( $digits_a, $digits_b );
		}

		return self::unscale_digits( $sum, $scale );
	}

	/**
	 * @return numeric-string
	 */
	private static function fallback_sub( string $a, string $b, int $scale ): string {
		$neg_b = str_starts_with( $b, '-' ) ? substr( $b, 1 ) : '-' . $b;
		return self::fallback_add( $a, $neg_b, $scale );
	}

	/**
	 * @return numeric-string
	 */
	private static function fallback_mul( string $a, string $b, int $scale ): string {
		$parts_a = explode( '.', self::fallback_normalize( $a, $scale ) );
		$parts_b = explode( '.', self::fallback_normalize( $b, $scale ) );

		$digits_a = $parts_a[0] . ( $parts_a[1] ?? '' );
		$digits_b = $parts_b[0] . ( $parts_b[1] ?? '' );

		if ( function_exists( 'gmp_mul' ) ) {
			$product = gmp_strval( gmp_mul( $digits_a, $digits_b ) );
		} else {
			$product = self::string_mul( $digits_a, $digits_b );
		}

		// Multiplication doubles the total scale ($scale * 2). We must adjust back to $scale.
		return self::unscale_digits( $product, $scale * 2, $scale );
	}

	/**
	 * @return numeric-string
	 */
	private static function fallback_div( string $a, string $b, int $scale ): string {
		// Shift dividend by additional scale digits for division precision
		$norm_a   = self::fallback_normalize( $a, $scale );
		$norm_b   = self::fallback_normalize( $b, $scale );
		$parts_a  = explode( '.', $norm_a );
		$parts_b  = explode( '.', $norm_b );
		$digits_a = $parts_a[0] . ( $parts_a[1] ?? '' ) . str_repeat( '0', $scale );
		$digits_b = $parts_b[0] . ( $parts_b[1] ?? '' );

		if ( function_exists( 'gmp_div_q' ) ) {
			$quotient = gmp_strval( gmp_div_q( $digits_a, $digits_b ) );
		} else {
			$quotient = (string) intdiv( (int) $digits_a, (int) $digits_b );
		}

		return self::unscale_digits( $quotient, $scale );
	}

	/**
	 * Pure string addition for arbitrary length integer strings.
	 */
	private static function string_add( string $a, string $b ): string {
		$neg_a = str_starts_with( $a, '-' );
		$neg_b = str_starts_with( $b, '-' );

		if ( $neg_a && $neg_b ) {
			return '-' . self::string_add_pos( substr( $a, 1 ), substr( $b, 1 ) );
		}
		if ( ! $neg_a && ! $neg_b ) {
			return self::string_add_pos( $a, $b );
		}

		$pos = $neg_a ? $b : $a;
		$neg = $neg_a ? substr( $a, 1 ) : substr( $b, 1 );

		return self::string_sub_pos( $pos, $neg );
	}

	private static function string_add_pos( string $a, string $b ): string {
		$a   = ltrim( $a, '0' );
		$b   = ltrim( $b, '0' );
		$len = max( strlen( $a ), strlen( $b ) );
		$a   = str_pad( $a, $len, '0', STR_PAD_LEFT );
		$b   = str_pad( $b, $len, '0', STR_PAD_LEFT );

		$carry = 0;
		$res   = '';
		for ( $i = $len - 1; $i >= 0; $i-- ) {
			$sum   = ( (int) $a[ $i ] ) + ( (int) $b[ $i ] ) + $carry;
			$res   = ( $sum % 10 ) . $res;
			$carry = intdiv( $sum, 10 );
		}
		if ( $carry > 0 ) {
			$res = $carry . $res;
		}
		return '' === $res ? '0' : $res;
	}

	private static function string_sub_pos( string $a, string $b ): string {
		$cmp = self::comp_digits( $a, $b );
		if ( 0 === $cmp ) {
			return '0';
		}
		$neg = false;
		if ( $cmp < 0 ) {
			$neg  = true;
			$temp = $a;
			$a    = $b;
			$b    = $temp;
		}

		$len = strlen( $a );
		$b   = str_pad( $b, $len, '0', STR_PAD_LEFT );

		$borrow = 0;
		$res    = '';
		for ( $i = $len - 1; $i >= 0; $i-- ) {
			$sub = ( (int) $a[ $i ] ) - ( (int) $b[ $i ] ) - $borrow;
			if ( $sub < 0 ) {
				$sub   += 10;
				$borrow = 1;
			} else {
				$borrow = 0;
			}
			$res = $sub . $res;
		}

		$clean = ltrim( $res, '0' );
		if ( '' === $clean ) {
			return '0';
		}
		return $neg ? '-' . $clean : $clean;
	}

	private static function string_mul( string $a, string $b ): string {
		$neg_a = str_starts_with( $a, '-' );
		$neg_b = str_starts_with( $b, '-' );
		$neg   = ( $neg_a xor $neg_b );

		$clean_a = ltrim( $neg_a ? substr( $a, 1 ) : $a, '0' );
		$clean_b = ltrim( $neg_b ? substr( $b, 1 ) : $b, '0' );

		if ( '' === $clean_a || '' === $clean_b ) {
			return '0';
		}

		$len_a  = strlen( $clean_a );
		$len_b  = strlen( $clean_b );
		$result = array_fill( 0, $len_a + $len_b, 0 );

		for ( $i = $len_a - 1; $i >= 0; $i-- ) {
			for ( $j = $len_b - 1; $j >= 0; $j-- ) {
				$mul            = ( (int) $clean_a[ $i ] ) * ( (int) $clean_b[ $j ] );
				$p1             = $i + $j;
				$p2             = $i + $j + 1;
				$sum            = $mul + $result[ $p2 ];
				$result[ $p2 ]  = $sum % 10;
				$result[ $p1 ] += intdiv( $sum, 10 );
			}
		}

		$str = ltrim( implode( '', $result ), '0' );
		if ( '' === $str ) {
			return '0';
		}
		return $neg ? '-' . $str : $str;
	}

	private static function comp_digits( string $a, string $b ): int {
		$clean_a = ltrim( $a, '0' );
		$clean_b = ltrim( $b, '0' );
		if ( strlen( $clean_a ) !== strlen( $clean_b ) ) {
			return strlen( $clean_a ) > strlen( $clean_b ) ? 1 : -1;
		}
		return strcmp( $clean_a, $clean_b );
	}

	/**
	 * @return numeric-string
	 */
	private static function unscale_digits( string $digits, int $current_scale, ?int $target_scale = null ): string {
		$target_scale = $target_scale ?? $current_scale;
		$is_neg       = str_starts_with( $digits, '-' );
		$clean        = $is_neg ? substr( $digits, 1 ) : $digits;

		if ( strlen( $clean ) <= $current_scale ) {
			$padded = str_pad( $clean, $current_scale + 1, '0', STR_PAD_LEFT );
		} else {
			$padded = $clean;
		}

		$int_part  = substr( $padded, 0, strlen( $padded ) - $current_scale );
		$frac_part = substr( $padded, -$current_scale );

		if ( $target_scale !== $current_scale ) {
			if ( $target_scale < $current_scale ) {
				$frac_part = substr( $frac_part, 0, $target_scale );
			} else {
				$frac_part = str_pad( $frac_part, $target_scale, '0', STR_PAD_RIGHT );
			}
		}

		$res = $target_scale > 0 ? $int_part . '.' . $frac_part : $int_part;
		$res = $is_neg && '0' !== $int_part ? '-' . $res : $res;

		/** @var numeric-string $res */
		return $res;
	}
}
