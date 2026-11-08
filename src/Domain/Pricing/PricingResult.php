<?php

declare(strict_types=1);

namespace Tierza\Domain\Pricing;

use Tierza\Domain\Exceptions\CurrencyMismatchException;
use Tierza\Domain\Pricing\Enums\PrecedenceLevel;
use Tierza\Domain\Pricing\Enums\PricingSource;
use Tierza\Domain\ValueObjects\DecimalMath;
use Tierza\Domain\ValueObjects\Money;

/**
 * Immutable final result of price calculation.
 *
 * Represents original price, calculated final price, rule attribution, and audit metadata.
 */
final class PricingResult {

	private readonly Money $original_price;
	private readonly Money $final_price;
	private readonly ?int $applied_rule_id;
	private readonly ?string $pricing_type;
	private readonly ?string $scope_type;
	private readonly PricingSource $source;
	private readonly bool $is_rule_applied;
	private readonly bool $is_zero_percentage;
	private readonly string $reason_code;
	/**
	 * @var array<string, mixed>
	 */
	private readonly array $diagnostic_metadata;

	/**
	 * @param Money                $original_price
	 * @param Money                $final_price
	 * @param int|null             $applied_rule_id
	 * @param string|null          $pricing_type
	 * @param string|null          $scope_type
	 * @param PricingSource        $source
	 * @param bool                 $is_rule_applied
	 * @param bool                 $is_zero_percentage
	 * @param string               $reason_code
	 * @param array<string, mixed> $diagnostic_metadata
	 * @throws CurrencyMismatchException
	 */
	public function __construct(
		Money $original_price,
		Money $final_price,
		?int $applied_rule_id,
		?string $pricing_type,
		?string $scope_type,
		PricingSource $source,
		bool $is_rule_applied,
		bool $is_zero_percentage,
		string $reason_code,
		array $diagnostic_metadata = []
	) {
		if ( $original_price->currency() !== $final_price->currency() ) {
			throw new CurrencyMismatchException(
				sprintf(
					'Original price currency (%s) does not match final price currency (%s).',
					$original_price->currency(),
					$final_price->currency()
				)
			);
		}

		$this->original_price      = $original_price;
		$this->final_price         = $final_price;
		$this->applied_rule_id     = $applied_rule_id;
		$this->pricing_type        = $pricing_type;
		$this->scope_type          = $scope_type;
		$this->source              = $source;
		$this->is_rule_applied     = $is_rule_applied;
		$this->is_zero_percentage  = $is_zero_percentage;
		$this->reason_code         = $reason_code;
		$this->diagnostic_metadata = $diagnostic_metadata;
	}

	public function original_price(): Money {
		return $this->original_price;
	}

	public function final_price(): Money {
		return $this->final_price;
	}

	public function currency(): string {
		return $this->original_price->currency();
	}

	public function applied_rule_id(): ?int {
		return $this->applied_rule_id;
	}

	public function pricing_type(): ?string {
		return $this->pricing_type;
	}

	public function scope_type(): ?string {
		return $this->scope_type;
	}

	public function source(): PricingSource {
		return $this->source;
	}

	public function is_rule_applied(): bool {
		return $this->is_rule_applied;
	}

	public function is_zero_percentage(): bool {
		return $this->is_zero_percentage;
	}

	public function reason_code(): string {
		return $this->reason_code;
	}

	public function precedence_level(): ?PrecedenceLevel {
		$level = $this->diagnostic_metadata['precedence_level'] ?? null;
		return is_int( $level ) ? PrecedenceLevel::tryFrom( $level ) : null;
	}

	public function formatted_final_price( ?int $decimals = null ): string {
		$dec = $decimals ?? ( $this->diagnostic_metadata['currency_decimals'] ?? 2 );
		return $this->final_price->format( (int) $dec );
	}

	public function formatted_original_price( ?int $decimals = null ): string {
		$dec = $decimals ?? ( $this->diagnostic_metadata['currency_decimals'] ?? 2 );
		return $this->original_price->format( (int) $dec );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function diagnostic_metadata(): array {
		return $this->diagnostic_metadata;
	}

	public function is_discounted(): bool {
		return $this->final_price->is_less_than( $this->original_price );
	}

	/**
	 * Calculates the difference between original and final price.
	 * If final price is equal or greater, returns Money::zero().
	 */
	public function discount_amount(): Money {
		if ( ! $this->is_discounted() ) {
			return Money::zero( $this->currency() );
		}

		return $this->original_price->subtract( $this->final_price );
	}

	/**
	 * Calculates percentage savings relative to original price.
	 * Returns normalized decimal string up to 6 decimals (e.g. "15.000000").
	 *
	 * @return numeric-string
	 */
	public function savings_percentage(): string {
		if ( ! $this->is_discounted() || $this->original_price->is_zero() ) {
			return '0.000000';
		}

		$discount = $this->discount_amount()->amount();
		$original = $this->original_price->amount();

		$fraction = DecimalMath::div( $discount, $original, 8 );
		return DecimalMath::mul( $fraction, '100', 6 );
	}

	/**
	 * Serializes pricing result to array for REST endpoints, frontend, or cart integration.
	 *
	 * @param bool $include_diagnostics
	 * @return array<string, mixed>
	 */
	public function to_array( bool $include_diagnostics = false ): array {
		$data = [
			'original_price'     => $this->original_price->amount(),
			'final_price'        => $this->final_price->amount(),
			'currency'           => $this->currency(),
			'applied_rule_id'    => $this->applied_rule_id,
			'pricing_type'       => $this->pricing_type,
			'scope_type'         => $this->scope_type,
			'source'             => $this->source->value,
			'is_rule_applied'    => $this->is_rule_applied,
			'is_zero_percentage' => $this->is_zero_percentage,
			'is_discounted'      => $this->is_discounted(),
			'discount_amount'    => $this->discount_amount()->amount(),
			'savings_percentage' => $this->savings_percentage(),
			'reason_code'        => $this->reason_code,
		];

		if ( $include_diagnostics ) {
			$data['diagnostics'] = $this->diagnostic_metadata;
		}

		return $data;
	}
}
