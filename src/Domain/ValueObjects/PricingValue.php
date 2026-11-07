<?php

declare(strict_types=1);

namespace Tierza\Domain\ValueObjects;

use Tierza\Domain\Exceptions\InvalidRuleException;

/**
 * Value object representing pricing calculation value (percentage or fixed price).
 * Uses string decimal representation for exact numerical precision.
 */
final class PricingValue {

	public const TYPE_PERCENTAGE = 'percentage';
	public const TYPE_FIXED      = 'fixed';

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

		// Normalize to standard decimal format with 6 decimal places.
		$formatted = self::format_decimal( $string_val );

		// Validate bounds using bccomp or numerical comparison.
		if ( bccomp( $formatted, '0.000000', 6 ) < 0 ) {
			throw new InvalidRuleException(
				sprintf( 'Pricing value cannot be negative, got "%s".', $formatted )
			);
		}

		if ( self::TYPE_PERCENTAGE === $normalized_type ) {
			if ( bccomp( $formatted, '100.000000', 6 ) > 0 ) {
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
		return 0 === bccomp( $this->value, '0.000000', 6 );
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
		if ( function_exists( 'bcadd' ) ) {
			/** @var numeric-string */
			return bcadd( $val, '0', 6 );
		}
		return number_format( (float) $val, 6, '.', '' );
	}

	public function equals( PricingValue $other ): bool {
		return $this->type === $other->type() && 0 === bccomp( $this->value, $other->value(), 6 );
	}

	public function __toString(): string {
		return $this->value;
	}
}
