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
	public const MAX_SCALE     = 20;

	public const DRIVER_AUTO   = 'auto';
	public const DRIVER_BCMATH = 'bcmath';
	public const DRIVER_PHP    = 'php';

	/**
	 * Validates scale argument across all public arithmetic methods.
	 *
	 * @throws InvalidArgumentException If scale is negative or exceeds MAX_SCALE.
	 */
	private static function validate_scale( int $scale ): void {
		if ( $scale < 0 || $scale > self::MAX_SCALE ) {
			throw new InvalidArgumentException(
				sprintf( 'Scale must be an integer between 0 and %d, %d given.', self::MAX_SCALE, $scale )
			);
		}
	}

	/**
	 * Resolves active arithmetic driver.
	 */
	public static function resolve_driver( ?string $driver = null ): string {
		if ( self::DRIVER_BCMATH === $driver || self::DRIVER_PHP === $driver ) {
			return $driver;
		}

		return extension_loaded( 'bcmath' ) ? self::DRIVER_BCMATH : self::DRIVER_PHP;
	}

	/**
	 * Checks if a string is a valid non-empty decimal representation.
	 * Explicitly rejects scientific notation (e.g. 1e5, 1.5E2).
	 */
	public static function is_valid( string $val ): bool {
		$trimmed = trim( $val );
		if ( '' === $trimmed || 1 === preg_match( '/[eE]/', $trimmed ) ) {
			return false;
		}

		return 1 === preg_match( '/^[+-]?[0-9]+(\.[0-9]+)?$/', $trimmed );
	}

	/**
	 * Checks if a numeric string evaluates to exact mathematical zero, regardless of scale.
	 *
	 * Returns true for '0', '+0', '-0', '0.0', '0.000000', '-0.000000000000'.
	 * Returns false for nonzero values such as '0.0000001', '-0.0000001', '1', or invalid strings.
	 */
	public static function is_zero( string $val ): bool {
		$trimmed = trim( $val );
		if ( ! self::is_valid( $trimmed ) ) {
			return false;
		}

		$unsigned    = ltrim( $trimmed, '+-' );
		$digits_only = str_replace( '.', '', $unsigned );

		return '' === ltrim( $digits_only, '0' );
	}

	/**
	 * Normalizes a numeric string to a fixed decimal scale without floating point casting.
	 * Guarantees that canonical zero never retains a negative sign.
	 *
	 * @param string      $val
	 * @param int         $scale
	 * @param string|null $driver Explicit driver for testing ('bcmath'|'php'|null).
	 * @return numeric-string
	 */
	public static function normalize( string $val, int $scale = self::DEFAULT_SCALE, ?string $driver = null ): string {
		self::validate_scale( $scale );

		$val = trim( $val );
		if ( ! self::is_valid( $val ) ) {
			throw new InvalidArgumentException( sprintf( 'Invalid decimal string "%s".', $val ) );
		}

		if ( str_starts_with( $val, '+' ) ) {
			$val = substr( $val, 1 );
		}

		$active_driver = self::resolve_driver( $driver );
		if ( self::DRIVER_BCMATH === $active_driver ) {
			/** @var numeric-string $val */
			/** @var numeric-string $res */
			$res = bcadd( $val, '0', $scale );
			return self::canonicalize_zero( $res, $scale );
		}

		return self::fallback_normalize( $val, $scale );
	}

	/**
	 * Compares two decimal strings.
	 * Returns 0 if equal, 1 if $a > $b, -1 if $a < $b.
	 *
	 * @param string      $a
	 * @param string      $b
	 * @param int         $scale
	 * @param string|null $driver
	 */
	public static function comp( string $a, string $b, int $scale = self::DEFAULT_SCALE, ?string $driver = null ): int {
		self::validate_scale( $scale );

		$a = trim( $a );
		$b = trim( $b );

		if ( ! self::is_valid( $a ) || ! self::is_valid( $b ) ) {
			throw new InvalidArgumentException( 'Invalid decimal values for comparison.' );
		}

		if ( str_starts_with( $a, '+' ) ) {
			$a = substr( $a, 1 );
		}
		if ( str_starts_with( $b, '+' ) ) {
			$b = substr( $b, 1 );
		}

		$active_driver = self::resolve_driver( $driver );
		if ( self::DRIVER_BCMATH === $active_driver ) {
			/** @var numeric-string $a */
			/** @var numeric-string $b */
			return bccomp( $a, $b, $scale );
		}

		return self::fallback_comp( $a, $b, $scale );
	}

	/**
	 * Adds two decimal strings.
	 *
	 * @param string      $a
	 * @param string      $b
	 * @param int         $scale
	 * @param string|null $driver
	 * @return numeric-string
	 */
	public static function add( string $a, string $b, int $scale = self::DEFAULT_SCALE, ?string $driver = null ): string {
		self::validate_scale( $scale );

		$a = trim( $a );
		$b = trim( $b );

		if ( ! self::is_valid( $a ) || ! self::is_valid( $b ) ) {
			throw new InvalidArgumentException( 'Invalid decimal values for addition.' );
		}

		if ( str_starts_with( $a, '+' ) ) {
			$a = substr( $a, 1 );
		}
		if ( str_starts_with( $b, '+' ) ) {
			$b = substr( $b, 1 );
		}

		$active_driver = self::resolve_driver( $driver );
		if ( self::DRIVER_BCMATH === $active_driver ) {
			/** @var numeric-string $a */
			/** @var numeric-string $b */
			/** @var numeric-string $res */
			$res = bcadd( $a, $b, $scale );
			return self::canonicalize_zero( $res, $scale );
		}

		return self::fallback_add( $a, $b, $scale );
	}

	/**
	 * Subtracts $b from $a ($a - $b).
	 *
	 * @param string      $a
	 * @param string      $b
	 * @param int         $scale
	 * @param string|null $driver
	 * @return numeric-string
	 */
	public static function sub( string $a, string $b, int $scale = self::DEFAULT_SCALE, ?string $driver = null ): string {
		self::validate_scale( $scale );

		$a = trim( $a );
		$b = trim( $b );

		if ( ! self::is_valid( $a ) || ! self::is_valid( $b ) ) {
			throw new InvalidArgumentException( 'Invalid decimal values for subtraction.' );
		}

		if ( str_starts_with( $a, '+' ) ) {
			$a = substr( $a, 1 );
		}
		if ( str_starts_with( $b, '+' ) ) {
			$b = substr( $b, 1 );
		}

		$active_driver = self::resolve_driver( $driver );
		if ( self::DRIVER_BCMATH === $active_driver ) {
			/** @var numeric-string $a */
			/** @var numeric-string $b */
			/** @var numeric-string $res */
			$res = bcsub( $a, $b, $scale );
			return self::canonicalize_zero( $res, $scale );
		}

		return self::fallback_sub( $a, $b, $scale );
	}

	/**
	 * Multiplies two decimal strings.
	 *
	 * @param string      $a
	 * @param string      $b
	 * @param int         $scale
	 * @param string|null $driver
	 * @return numeric-string
	 */
	public static function mul( string $a, string $b, int $scale = self::DEFAULT_SCALE, ?string $driver = null ): string {
		self::validate_scale( $scale );

		$a = trim( $a );
		$b = trim( $b );

		if ( ! self::is_valid( $a ) || ! self::is_valid( $b ) ) {
			throw new InvalidArgumentException( 'Invalid decimal values for multiplication.' );
		}

		if ( str_starts_with( $a, '+' ) ) {
			$a = substr( $a, 1 );
		}
		if ( str_starts_with( $b, '+' ) ) {
			$b = substr( $b, 1 );
		}

		$active_driver = self::resolve_driver( $driver );
		if ( self::DRIVER_BCMATH === $active_driver ) {
			/** @var numeric-string $a */
			/** @var numeric-string $b */
			/** @var numeric-string $res */
			$res = bcmul( $a, $b, $scale );
			return self::canonicalize_zero( $res, $scale );
		}

		return self::fallback_mul( $a, $b, $scale );
	}

	/**
	 * Divides $a by $b ($a / $b).
	 *
	 * @param string      $a
	 * @param string      $b
	 * @param int         $scale
	 * @param string|null $driver
	 * @return numeric-string
	 */
	public static function div( string $a, string $b, int $scale = self::DEFAULT_SCALE, ?string $driver = null ): string {
		self::validate_scale( $scale );

		$a = trim( $a );
		$b = trim( $b );

		if ( ! self::is_valid( $a ) || ! self::is_valid( $b ) ) {
			throw new InvalidArgumentException( 'Invalid decimal values for division.' );
		}

		if ( str_starts_with( $a, '+' ) ) {
			$a = substr( $a, 1 );
		}
		if ( str_starts_with( $b, '+' ) ) {
			$b = substr( $b, 1 );
		}

		if ( self::is_zero( $b ) ) {
			throw new DivisionByZeroError( 'Division by zero in DecimalMath::div().' );
		}

		$active_driver = self::resolve_driver( $driver );
		if ( self::DRIVER_BCMATH === $active_driver ) {
			/** @var numeric-string $a */
			/** @var numeric-string $b */
			/** @var numeric-string $res */
			$res = bcdiv( $a, $b, $scale );
			return self::canonicalize_zero( $res, $scale );
		}

		return self::fallback_div( $a, $b, $scale );
	}

	/**
	 * Deterministically rounds a decimal string to the target decimal places (Half-Up).
	 * Guarantees that canonical zero never retains a negative sign.
	 *
	 * @param string      $val
	 * @param int         $precision
	 * @param int         $mode Currently PHP_ROUND_HALF_UP (default) is supported.
	 * @param string|null $driver
	 * @return numeric-string
	 */
	public static function round( string $val, int $precision = 2, int $mode = PHP_ROUND_HALF_UP, ?string $driver = null ): string {
		$val = trim( $val );
		if ( ! self::is_valid( $val ) ) {
			throw new InvalidArgumentException( sprintf( 'Invalid decimal string for rounding "%s".', $val ) );
		}

		if ( $precision < 0 || $precision > self::MAX_SCALE ) {
			throw new InvalidArgumentException(
				sprintf( 'Precision must be an integer between 0 and %d, %d given.', self::MAX_SCALE, $precision )
			);
		}

		if ( PHP_ROUND_HALF_UP !== $mode ) {
			throw new InvalidArgumentException(
				sprintf( 'Unsupported rounding mode "%d". Only PHP_ROUND_HALF_UP is supported.', $mode )
			);
		}

		if ( str_starts_with( $val, '+' ) ) {
			$val = substr( $val, 1 );
		}

		$is_negative = str_starts_with( $val, '-' );
		$unsigned    = $is_negative ? substr( $val, 1 ) : $val;

		$parts   = explode( '.', $unsigned );
		$integer = ltrim( $parts[0], '0' );
		if ( '' === $integer ) {
			$integer = '0';
		}
		$fraction = $parts[1] ?? '';

		$check_digit = (int) ( $fraction[ $precision ] ?? '0' );
		$main_frac   = str_pad( substr( $fraction, 0, $precision ), $precision, '0', STR_PAD_RIGHT );

		$should_round_up = ( $check_digit >= 5 );

		if ( ! $should_round_up ) {
			$res     = $precision > 0 ? $integer . '.' . $main_frac : $integer;
			$is_zero = ( '0' === $integer && ( '' === $main_frac || 1 === preg_match( '/^0+$/', $main_frac ) ) );
			$final   = ( $is_negative && ! $is_zero ) ? '-' . $res : $res;
			assert( is_numeric( $final ) );
			return $final;
		}

		// Add 1 to the least significant place
		$increment = '0.' . str_repeat( '0', max( 0, $precision - 1 ) ) . '1';
		if ( 0 === $precision ) {
			$increment = '1';
		}

		$truncated = $precision > 0 ? $integer . '.' . $main_frac : $integer;
		$rounded   = self::add( $truncated, $increment, $precision, $driver );

		$is_zero = ( '0' === $rounded || '0.' . str_repeat( '0', $precision ) === $rounded );
		$final   = ( $is_negative && ! $is_zero ) ? '-' . $rounded : $rounded;
		assert( is_numeric( $final ) );
		return $final;
	}

	// --------------------------------------------------------------------------
	// Canonical Zero & Pure PHP Fallback Algorithms
	// --------------------------------------------------------------------------

	/**
	 * Ensures that any representation of numerical zero is unsigned.
	 *
	 * @return numeric-string
	 */
	private static function canonicalize_zero( string $val, int $scale ): string {
		$is_neg = str_starts_with( $val, '-' );
		$clean  = $is_neg ? substr( $val, 1 ) : $val;
		$parts  = explode( '.', $clean );
		$int    = ltrim( $parts[0], '0' );
		$frac   = $parts[1] ?? '';

		$is_zero = ( '' === $int || '0' === $int ) && ( '' === $frac || 1 === preg_match( '/^0+$/', $frac ) );
		if ( $is_zero ) {
			$zero_res = $scale > 0 ? '0.' . str_repeat( '0', $scale ) : '0';
			assert( is_numeric( $zero_res ) );
			return $zero_res;
		}

		/** @var numeric-string $val */
		return $val;
	}

	/**
	 * @return numeric-string
	 */
	private static function fallback_normalize( string $val, int $scale ): string {
		if ( str_starts_with( $val, '+' ) ) {
			$val = substr( $val, 1 );
		}

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

		$is_zero = ( '0' === $int_part && ( '' === $frac_part || 1 === preg_match( '/^0+$/', $frac_part ) ) );
		if ( $is_zero ) {
			$zero_res = $scale > 0 ? '0.' . str_repeat( '0', $scale ) : '0';
			assert( is_numeric( $zero_res ) );
			return $zero_res;
		}

		$res = $scale > 0 ? $int_part . '.' . $frac_part : $int_part;
		if ( $is_neg ) {
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
	 * Splits a signed decimal string into its sign, integer part, and fractional part.
	 *
	 * @return array{0: bool, 1: string, 2: string} [is_negative, integer_part, fractional_part]
	 */
	private static function split_parts( string $val ): array {
		$is_neg = str_starts_with( $val, '-' );
		$clean  = $is_neg ? substr( $val, 1 ) : $val;
		$parts  = explode( '.', $clean );

		$int_part = ltrim( $parts[0], '0' );
		if ( '' === $int_part ) {
			$int_part = '0';
		}

		$frac_part = $parts[1] ?? '';

		return [ $is_neg, $int_part, $frac_part ];
	}

	/**
	 * @return numeric-string
	 */
	private static function fallback_add( string $a, string $b, int $scale ): string {
		[ $is_neg_a, $int_a, $frac_a ] = self::split_parts( $a );
		[ $is_neg_b, $int_b, $frac_b ] = self::split_parts( $b );

		$max_d = max( strlen( $frac_a ), strlen( $frac_b ) );

		$padded_frac_a = str_pad( $frac_a, $max_d, '0', STR_PAD_RIGHT );
		$padded_frac_b = str_pad( $frac_b, $max_d, '0', STR_PAD_RIGHT );

		$int_str_a = ( $is_neg_a ? '-' : '' ) . $int_a . $padded_frac_a;
		$int_str_b = ( $is_neg_b ? '-' : '' ) . $int_b . $padded_frac_b;

		$sum = self::string_add( $int_str_a, $int_str_b );
		return self::canonicalize_zero( self::unscale_digits( $sum, $max_d, $scale ), $scale );
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
		[ $is_neg_a, $int_a, $frac_a ] = self::split_parts( $a );
		[ $is_neg_b, $int_b, $frac_b ] = self::split_parts( $b );

		$d_a = strlen( $frac_a );
		$d_b = strlen( $frac_b );

		$digits_a = ( $is_neg_a ? '-' : '' ) . $int_a . $frac_a;
		$digits_b = ( $is_neg_b ? '-' : '' ) . $int_b . $frac_b;

		$product = self::string_mul( $digits_a, $digits_b );
		return self::canonicalize_zero( self::unscale_digits( $product, $d_a + $d_b, $scale ), $scale );
	}

	/**
	 * @return numeric-string
	 */
	private static function fallback_div( string $a, string $b, int $scale ): string {
		[ $is_neg_a, $int_a, $frac_a ] = self::split_parts( $a );
		[ $is_neg_b, $int_b, $frac_b ] = self::split_parts( $b );

		$max_d = max( strlen( $frac_a ), strlen( $frac_b ) );

		$padded_frac_a = str_pad( $frac_a, $max_d, '0', STR_PAD_RIGHT );
		$padded_frac_b = str_pad( $frac_b, $max_d, '0', STR_PAD_RIGHT );

		$int_str_a = ( $is_neg_a ? '-' : '' ) . $int_a . $padded_frac_a;
		$int_str_b = ( $is_neg_b ? '-' : '' ) . $int_b . $padded_frac_b;

		return self::canonicalize_zero( self::string_div( $int_str_a, $int_str_b, $scale ), $scale );
	}

	/**
	 * Arbitrary precision string division using schoolbook long division algorithm.
	 *
	 * @return numeric-string
	 */
	private static function string_div( string $dividend, string $divisor, int $scale ): string {
		$clean_div = ltrim( str_replace( '-', '', $divisor ), '0' );
		if ( '' === $clean_div ) {
			throw new DivisionByZeroError( 'Division by zero in DecimalMath::string_div().' );
		}

		$neg_a  = str_starts_with( $dividend, '-' );
		$neg_b  = str_starts_with( $divisor, '-' );
		$is_neg = ( $neg_a xor $neg_b );

		$clean_a = ltrim( $neg_a ? substr( $dividend, 1 ) : $dividend, '0' );
		$clean_b = $clean_div;

		if ( '' === $clean_a ) {
			$zero_res = $scale > 0 ? '0.' . str_repeat( '0', $scale ) : '0';
			assert( is_numeric( $zero_res ) );
			return $zero_res;
		}

		// Shift dividend by target scale
		$shifted = $clean_a . str_repeat( '0', $scale );

		$quotient  = '';
		$remainder = '';
		$len       = strlen( $shifted );

		for ( $i = 0; $i < $len; $i++ ) {
			$remainder .= $shifted[ $i ];
			$remainder  = ltrim( $remainder, '0' );
			if ( '' === $remainder ) {
				$quotient .= '0';
				continue;
			}

			if ( self::comp_digits( $remainder, $clean_b ) < 0 ) {
				$quotient .= '0';
				continue;
			}

			// Binary search for quotient digit 1..9
			$low   = 1;
			$high  = 9;
			$digit = 0;
			while ( $low <= $high ) {
				$mid     = (int) ( ( $low + $high ) / 2 );
				$product = self::string_mul_single( $clean_b, $mid );
				$c       = self::comp_digits( $product, $remainder );
				if ( $c <= 0 ) {
					$digit = $mid;
					$low   = $mid + 1;
				} else {
					$high = $mid - 1;
				}
			}

			$product   = self::string_mul_single( $clean_b, $digit );
			$remainder = self::string_sub_pos( $remainder, $product );
			$quotient .= (string) $digit;
		}

		$quotient = ltrim( $quotient, '0' );
		if ( '' === $quotient ) {
			$quotient = '0';
		}

		return self::unscale_digits( ( $is_neg ? '-' : '' ) . $quotient, $scale );
	}

	private static function string_mul_single( string $num, int $digit ): string {
		if ( 0 === $digit || '0' === $num ) {
			return '0';
		}
		if ( 1 === $digit ) {
			return $num;
		}

		$carry = 0;
		$res   = '';
		$len   = strlen( $num );

		for ( $i = $len - 1; $i >= 0; $i-- ) {
			$prod  = ( ( (int) $num[ $i ] ) * $digit ) + $carry;
			$res   = ( $prod % 10 ) . $res;
			$carry = intdiv( $prod, 10 );
		}

		if ( $carry > 0 ) {
			$res = $carry . $res;
		}

		return $res;
	}

	/**
	 * Pure string addition for arbitrary length integer strings.
	 */
	private static function string_add( string $a, string $b ): string {
		$neg_a = str_starts_with( $a, '-' );
		$neg_b = str_starts_with( $b, '-' );

		if ( $neg_a && $neg_b ) {
			$sum = self::string_add_pos( substr( $a, 1 ), substr( $b, 1 ) );
			return '0' === $sum ? '0' : '-' . $sum;
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
		$a   = ltrim( $a, '0' );
		$b   = ltrim( $b, '0' );
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

		$int_part = ltrim( $int_part, '0' );
		if ( '' === $int_part ) {
			$int_part = '0';
		}

		if ( $target_scale !== $current_scale ) {
			if ( $target_scale < $current_scale ) {
				$frac_part = substr( $frac_part, 0, $target_scale );
			} else {
				$frac_part = str_pad( $frac_part, $target_scale, '0', STR_PAD_RIGHT );
			}
		}

		$is_zero = ( '0' === $int_part && ( '' === $frac_part || 1 === preg_match( '/^0+$/', $frac_part ) ) );
		if ( $is_zero ) {
			$zero_res = $target_scale > 0 ? '0.' . str_repeat( '0', $target_scale ) : '0';
			assert( is_numeric( $zero_res ) );
			return $zero_res;
		}

		$res = $target_scale > 0 ? $int_part . '.' . $frac_part : $int_part;
		if ( $is_neg ) {
			$res = '-' . $res;
		}

		assert( is_numeric( $res ) );
		return $res;
	}
}
