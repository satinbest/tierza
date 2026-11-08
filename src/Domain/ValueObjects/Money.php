<?php

declare(strict_types=1);

namespace Tierza\Domain\ValueObjects;

use Tierza\Domain\Exceptions\CurrencyMismatchException;
use Tierza\Domain\Exceptions\InvalidMoneyException;

/**
 * Immutable monetary value object with deterministic arbitrary-precision decimal arithmetic.
 *
 * Prevents binary floating-point rounding errors and preserves explicit store currency.
 */
final class Money {

	public const DEFAULT_SCALE      = 6;
	public const MAX_INTEGER_DIGITS = 14;
	public const MAX_AMOUNT         = '99999999999999.999999';

	/**
	 * @var numeric-string
	 */
	private readonly string $amount;

	private readonly string $currency;

	/**
	 * @param string|int $amount
	 * @param string     $currency
	 * @throws InvalidMoneyException
	 */
	public function __construct( string|int $amount, string $currency ) {
		$clean_currency = strtoupper( trim( $currency ) );
		if ( '' === $clean_currency ) {
			throw new InvalidMoneyException( 'Currency code cannot be empty.' );
		}

		$amount_str = is_string( $amount ) ? trim( $amount ) : (string) $amount;
		if ( '' === $amount_str || ! is_numeric( $amount_str ) || ! DecimalMath::is_valid( $amount_str ) ) {
			throw new InvalidMoneyException(
				sprintf( 'Invalid monetary amount "%s". Amount must be a valid non-empty numeric string without scientific notation.', $amount_str )
			);
		}

		// Prevent silent truncation of digits beyond maximum supported scale.
		$clean_amount = ltrim( $amount_str, '+-' );
		if ( str_contains( $clean_amount, '.' ) ) {
			$fraction = explode( '.', $clean_amount )[1] ?? '';
			if ( strlen( rtrim( $fraction, '0' ) ) > self::DEFAULT_SCALE ) {
				throw new InvalidMoneyException(
					sprintf( 'Monetary amount "%s" exceeds maximum supported precision of %d decimal places.', $amount_str, self::DEFAULT_SCALE )
				);
			}
		}

		// Enforce persistent storage boundary (DECIMAL(20,6) allows at most 14 integer digits).
		$integer_part        = explode( '.', $clean_amount )[0];
		$significant_integer = ltrim( $integer_part, '0' );
		if ( strlen( $significant_integer ) > self::MAX_INTEGER_DIGITS ) {
			throw new InvalidMoneyException(
				sprintf( 'Monetary amount "%s" exceeds maximum supported integer magnitude of %d digits (storage limit DECIMAL(20,6)).', $amount_str, self::MAX_INTEGER_DIGITS )
			);
		}

		$normalized = DecimalMath::normalize( $amount_str, self::DEFAULT_SCALE );

		if ( DecimalMath::comp( $normalized, '0', self::DEFAULT_SCALE ) < 0 ) {
			throw new InvalidMoneyException(
				sprintf( 'Monetary amount cannot be negative, got "%s".', $normalized )
			);
		}

		$this->amount   = $normalized;
		$this->currency = $clean_currency;
	}

	public static function from( string|int $amount, string $currency ): self {
		return new self( $amount, $currency );
	}

	public static function zero( string $currency ): self {
		return new self( '0', $currency );
	}

	/**
	 * @return numeric-string
	 */
	public function amount(): string {
		return $this->amount;
	}

	public function currency(): string {
		return $this->currency;
	}

	public function is_zero(): bool {
		return 0 === DecimalMath::comp( $this->amount, '0', self::DEFAULT_SCALE );
	}

	public function is_positive(): bool {
		return DecimalMath::comp( $this->amount, '0', self::DEFAULT_SCALE ) > 0;
	}

	/**
	 * Asserts that the other Money object has the exact same currency.
	 *
	 * @throws CurrencyMismatchException
	 */
	private function assert_same_currency( Money $other ): void {
		if ( $this->currency !== $other->currency() ) {
			throw new CurrencyMismatchException(
				sprintf(
					'Currency mismatch: operation attempted between %s and %s.',
					$this->currency,
					$other->currency()
				)
			);
		}
	}

	public function equals( Money $other ): bool {
		if ( $this->currency !== $other->currency() ) {
			return false;
		}

		return 0 === DecimalMath::comp( $this->amount, $other->amount(), self::DEFAULT_SCALE );
	}

	public function is_greater_than( Money $other ): bool {
		$this->assert_same_currency( $other );
		return DecimalMath::comp( $this->amount, $other->amount(), self::DEFAULT_SCALE ) > 0;
	}

	public function is_less_than( Money $other ): bool {
		$this->assert_same_currency( $other );
		return DecimalMath::comp( $this->amount, $other->amount(), self::DEFAULT_SCALE ) < 0;
	}

	public function is_greater_than_or_equal( Money $other ): bool {
		$this->assert_same_currency( $other );
		return DecimalMath::comp( $this->amount, $other->amount(), self::DEFAULT_SCALE ) >= 0;
	}

	public function is_less_than_or_equal( Money $other ): bool {
		$this->assert_same_currency( $other );
		return DecimalMath::comp( $this->amount, $other->amount(), self::DEFAULT_SCALE ) <= 0;
	}

	/**
	 * Adds another monetary amount.
	 *
	 * @throws CurrencyMismatchException
	 */
	public function add( Money $other ): self {
		$this->assert_same_currency( $other );
		$sum = DecimalMath::add( $this->amount, $other->amount(), self::DEFAULT_SCALE );
		return new self( $sum, $this->currency );
	}

	/**
	 * Subtracts another monetary amount.
	 * Rejects negative outcomes.
	 *
	 * @throws CurrencyMismatchException
	 * @throws InvalidMoneyException
	 */
	public function subtract( Money $other ): self {
		$this->assert_same_currency( $other );

		if ( $this->is_less_than( $other ) ) {
			throw new InvalidMoneyException(
				sprintf(
					'Subtraction resulted in negative monetary value: %s %s minus %s %s.',
					$this->amount,
					$this->currency,
					$other->amount(),
					$other->currency()
				)
			);
		}

		$diff = DecimalMath::sub( $this->amount, $other->amount(), self::DEFAULT_SCALE );
		return new self( $diff, $this->currency );
	}

	/**
	 * Multiplies money by a non-negative multiplier factor.
	 *
	 * @param string|int $factor
	 * @throws InvalidMoneyException
	 */
	public function multiply( string|int $factor ): self {
		$factor_str = is_string( $factor ) ? trim( $factor ) : (string) $factor;
		if ( ! is_numeric( $factor_str ) || ! DecimalMath::is_valid( $factor_str ) ) {
			throw new InvalidMoneyException(
				sprintf( 'Multiplier factor must be a valid numeric, got "%s".', $factor_str )
			);
		}

		$normalized_factor = DecimalMath::normalize( $factor_str, self::DEFAULT_SCALE );
		if ( DecimalMath::comp( $normalized_factor, '0', self::DEFAULT_SCALE ) < 0 ) {
			throw new InvalidMoneyException(
				sprintf( 'Multiplier factor cannot be negative, got "%s".', $normalized_factor )
			);
		}

		$product = DecimalMath::mul( $this->amount, $normalized_factor, self::DEFAULT_SCALE );
		return new self( $product, $this->currency );
	}

	/**
	 * Calculates percentage discount and returns the remaining price:
	 * final_price = base_price * (1 - (percentage / 100))
	 *
	 * Clamps at zero if percentage >= 100%.
	 *
	 * @param string|int $percentage
	 * @throws InvalidMoneyException
	 */
	public function apply_percentage_discount( string|int $percentage ): self {
		$percent_str = is_string( $percentage ) ? trim( $percentage ) : (string) $percentage;
		if ( ! is_numeric( $percent_str ) || ! DecimalMath::is_valid( $percent_str ) ) {
			throw new InvalidMoneyException(
				sprintf( 'Discount percentage must be numeric, got "%s".', $percent_str )
			);
		}

		$norm_percent = DecimalMath::normalize( $percent_str, self::DEFAULT_SCALE );
		if ( DecimalMath::comp( $norm_percent, '0', self::DEFAULT_SCALE ) < 0 ) {
			throw new InvalidMoneyException(
				sprintf( 'Discount percentage cannot be negative, got "%s".', $norm_percent )
			);
		}

		if ( DecimalMath::comp( $norm_percent, '100', self::DEFAULT_SCALE ) >= 0 ) {
			return self::zero( $this->currency );
		}

		if ( DecimalMath::comp( $norm_percent, '0', self::DEFAULT_SCALE ) === 0 ) {
			return $this;
		}

		// fraction_to_keep = 1.000000 - (percent / 100)
		$percent_fraction = DecimalMath::div( $norm_percent, '100', self::DEFAULT_SCALE + 2 );
		$fraction_to_keep = DecimalMath::sub( '1', $percent_fraction, self::DEFAULT_SCALE + 2 );

		$calculated = DecimalMath::mul( $this->amount, $fraction_to_keep, self::DEFAULT_SCALE );
		return new self( $calculated, $this->currency );
	}

	/**
	 * Rounds the monetary amount to standard currency decimal places (Half-Up).
	 */
	public function round( int $decimals = 2, int $mode = PHP_ROUND_HALF_UP ): self {
		$rounded = DecimalMath::round( $this->amount, $decimals, $mode );
		return new self( $rounded, $this->currency );
	}

	/**
	 * Formats the monetary amount for human display or WooCommerce export.
	 */
	public function format( int $decimals = 2 ): string {
		return DecimalMath::round( $this->amount, $decimals );
	}

	public function __toString(): string {
		return $this->amount;
	}
}
