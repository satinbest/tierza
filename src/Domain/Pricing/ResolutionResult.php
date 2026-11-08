<?php

declare(strict_types=1);

namespace Tierza\Domain\Pricing;

use Tierza\Domain\Entities\PricingRule;
use Tierza\Domain\Pricing\Enums\PrecedenceLevel;
use Tierza\Domain\Pricing\Enums\PricingReasonCode;
use Tierza\Domain\Pricing\Enums\PricingSource;

/**
 * Immutable outcome of the rule resolution phase.
 *
 * Represents the single highest-precedence rule matched, or explicit fallback.
 */
final class ResolutionResult {

	private readonly ?PricingRule $rule;
	private readonly PrecedenceLevel $precedence_level;
	private readonly PricingSource $source;
	private readonly string $reason_code;
	/**
	 * @var array<string, mixed>
	 */
	private readonly array $diagnostic_metadata;

	/**
	 * @param PricingRule|null     $rule
	 * @param PrecedenceLevel      $precedence_level
	 * @param PricingSource        $source
	 * @param string               $reason_code
	 * @param array<string, mixed> $diagnostic_metadata
	 */
	public function __construct(
		?PricingRule $rule,
		PrecedenceLevel $precedence_level,
		PricingSource $source,
		string $reason_code,
		array $diagnostic_metadata = []
	) {
		$this->rule                = $rule;
		$this->precedence_level    = $precedence_level;
		$this->source              = $source;
		$this->reason_code         = $reason_code;
		$this->diagnostic_metadata = $diagnostic_metadata;
	}

	/**
	 * Creates a successful rule match result.
	 *
	 * @param PricingRule          $rule
	 * @param PrecedenceLevel      $level
	 * @param string|null          $reason_code
	 * @param array<string, mixed> $diagnostic_metadata
	 */
	public static function matched(
		PricingRule $rule,
		PrecedenceLevel $level,
		?string $reason_code = null,
		array $diagnostic_metadata = []
	): self {
		$code = $reason_code ?? self::determine_default_reason_code( $level, $rule );
		return new self(
			$rule,
			$level,
			$level->to_source(),
			$code,
			$diagnostic_metadata
		);
	}

	/**
	 * Creates a fallback result indicating no rule applied.
	 *
	 * @param string|null          $reason_code
	 * @param array<string, mixed> $diagnostic_metadata
	 */
	public static function fallback(
		?string $reason_code = null,
		array $diagnostic_metadata = []
	): self {
		return new self(
			null,
			PrecedenceLevel::WOOCOMMERCE_FALLBACK,
			PricingSource::WOOCOMMERCE_FALLBACK,
			$reason_code ?? PricingReasonCode::FALLBACK_NO_MATCHING_RULE->value,
			$diagnostic_metadata
		);
	}

	public function has_rule(): bool {
		return null !== $this->rule;
	}

	public function rule(): ?PricingRule {
		return $this->rule;
	}

	public function precedence_level(): PrecedenceLevel {
		return $this->precedence_level;
	}

	public function source(): PricingSource {
		return $this->source;
	}

	public function reason_code(): string {
		return $this->reason_code;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function diagnostic_metadata(): array {
		return $this->diagnostic_metadata;
	}

	public function is_fallback(): bool {
		return PrecedenceLevel::WOOCOMMERCE_FALLBACK === $this->precedence_level;
	}

	private static function determine_default_reason_code( PrecedenceLevel $level, PricingRule $rule ): string {
		if ( $rule->pricing_value()->is_percentage() && $rule->pricing_value()->is_zero() ) {
			return PricingReasonCode::RULE_APPLIED_ZERO_PERCENTAGE->value;
		}

		return match ( $level ) {
			PrecedenceLevel::CUSTOMER_VARIATION => PricingReasonCode::RULE_APPLIED_CUSTOMER_VARIATION->value,
			PrecedenceLevel::CUSTOMER_PRODUCT   => PricingReasonCode::RULE_APPLIED_CUSTOMER_PRODUCT->value,
			PrecedenceLevel::CUSTOMER_CATEGORY  => PricingReasonCode::RULE_APPLIED_CUSTOMER_CATEGORY->value,
			PrecedenceLevel::CUSTOMER_GLOBAL    => PricingReasonCode::RULE_APPLIED_CUSTOMER_GLOBAL->value,
			PrecedenceLevel::GROUP_VARIATION    => PricingReasonCode::RULE_APPLIED_GROUP_VARIATION->value,
			PrecedenceLevel::GROUP_PRODUCT      => PricingReasonCode::RULE_APPLIED_GROUP_PRODUCT->value,
			PrecedenceLevel::GROUP_CATEGORY     => PricingReasonCode::RULE_APPLIED_GROUP_CATEGORY->value,
			PrecedenceLevel::GROUP_GLOBAL       => PricingReasonCode::RULE_APPLIED_GROUP_GLOBAL->value,
			PrecedenceLevel::WOOCOMMERCE_FALLBACK => PricingReasonCode::FALLBACK_NO_MATCHING_RULE->value,
		};
	}
}
