<?php

declare(strict_types=1);

namespace Tierza\Domain\ValueObjects;

use Tierza\Domain\Exceptions\InvalidRuleException;

/**
 * Value object representing pricing calculation value (percentage or fixed price).
 * Uses string decimal representation for exact numerical precision.
 */
final class PricingValue {

	public const TYPE_PERCENTAGE    = 'percentage';
	public const TYPE_FIXED         = 'fixed';
	public const MAX_INTEGER_DIGITS = 14;
	public const MAX_VALUE          = '99999999999999.999999';

	private const ALLOWED_TYPES = [
		self::TYPE_PERCENTAGE,
		self::TYPE_FIXED,
	];

	private string $type;
	/**
	 * @var numeric-string
	 */
	private string $value;

	public function __construct( string $type, string|int|float $value ) {
		$normalized_type = strtolower( trim( $type ) );

		if ( ! in_array( $normalized_type, self::ALLOWED_TYPES, true ) ) {
			throw new InvalidRuleException(
				sprintf( 'Invalid pricing type "%s". Allowed: %s', $type, implode( ', ', self::ALLOWED_TYPES ) )
			);
		}

		$string_val = is_string( $value ) ? trim( $value ) : (string) $value;

		if ( ! is_numeric( $string_val ) ) {
			throw new InvalidRuleException(
				sprintf( 'Pricing value must be numeric, got "%s".', $string_val )
			);
		}

		if ( ! DecimalMath::is_valid( $string_val ) ) {
			throw new InvalidRuleException(
				sprintf( 'Pricing value cannot use scientific notation, got "%s".', $string_val )
			);
		}

		// Reject fractional digits exceeding 6 to prevent silent truncation.
		$clean_val = ltrim( $string_val, '+-' );
		if ( str_contains( $clean_val, '.' ) ) {
			$fraction = explode( '.', $clean_val )[1] ?? '';
			if ( strlen( rtrim( $fraction, '0' ) ) > 6 ) {
				throw new InvalidRuleException(
					sprintf( 'Pricing value "%s" exceeds maximum supported precision of 6 decimal places (DECIMAL(20,6)).', $string_val )
				);
			}
		}

		// Enforce persistent storage boundary (DECIMAL(20,6) allows at most 14 integer digits).
		$integer_part        = explode( '.', $clean_val )[0];
		$significant_integer = ltrim( $integer_part, '0' );
		if ( strlen( $significant_integer ) > self::MAX_INTEGER_DIGITS ) {
			throw new InvalidRuleException(
				sprintf( 'Pricing value "%s" exceeds maximum supported integer magnitude of %d digits (storage limit DECIMAL(20,6)).', $string_val, self::MAX_INTEGER_DIGITS )
			);
		}

		// Normalize to standard decimal format with 6 decimal places.
		$formatted = DecimalMath::normalize( $string_val, 6 );

		// Validate bounds using DecimalMath.
		if ( DecimalMath::comp( $formatted, '0.000000', 6 ) < 0 ) {
			throw new InvalidRuleException(
				sprintf( 'Pricing value cannot be negative, got "%s".', $formatted )
			);
		}

		if ( self::TYPE_PERCENTAGE === $normalized_type ) {
			if ( DecimalMath::comp( $formatted, '100.000000', 6 ) > 0 ) {
				throw new InvalidRuleException(
					sprintf( 'Percentage discount cannot exceed 100%%, got "%s".', $formatted )
				);
			}
		}

		$this->type  = $normalized_type;
		$this->value = $formatted;
	}

	public static function percentage( string|int|float $percent ): self {
		return new self( self::TYPE_PERCENTAGE, $percent );
	}

	public static function fixed( string|int|float $amount ): self {
		return new self( self::TYPE_FIXED, $amount );
	}

	public function is_percentage(): bool {
		return self::TYPE_PERCENTAGE === $this->type;
	}

	public function is_fixed(): bool {
		return self::TYPE_FIXED === $this->type;
	}

	/**
	 * Check if value is explicitly zero (0% or 0.00).
	 */
	public function is_zero(): bool {
		return 0 === DecimalMath::comp( $this->value, '0.000000', 6 );
	}

	public function type(): string {
		return $this->type;
	}

	/**
	 * @return numeric-string
	 */
	public function value(): string {
		return $this->value;
	}

	/**
	 * Formats a numeric string into standard 6 decimal places format.
	 *
	 * @param numeric-string $val
	 * @return numeric-string
	 */
	public static function format_decimal( string $val ): string {
		return DecimalMath::normalize( $val, 6 );
	}

	public function equals( PricingValue $other ): bool {
		return $this->type === $other->type() && 0 === DecimalMath::comp( $this->value, $other->value(), 6 );
	}

	public function __toString(): string {
		return $this->value;
	}
}
