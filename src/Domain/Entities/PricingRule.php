<?php

declare(strict_types=1);

namespace Tierza\Domain\Entities;

use DateTimeImmutable;
use Tierza\Domain\Exceptions\InvalidRuleException;
use Tierza\Domain\ValueObjects\PricingValue;
use Tierza\Domain\ValueObjects\RuleScope;
use Tierza\Domain\ValueObjects\RuleStatus;
use Tierza\Domain\ValueObjects\RuleTarget;

/**
 * Domain entity representing a customer or group pricing rule.
 */
final class PricingRule {

	private ?int $id;
	private RuleTarget $target;
	private RuleScope $scope;
	private PricingValue $pricing_value;
	private RuleStatus $status;
	private ?DateTimeImmutable $created_at;
	private ?DateTimeImmutable $updated_at;

	public function __construct(
		RuleTarget $target,
		RuleScope $scope,
		PricingValue $pricing_value,
		?int $id = null,
		RuleStatus|string $status = RuleStatus::ACTIVE,
		?DateTimeImmutable $created_at = null,
		?DateTimeImmutable $updated_at = null
	) {
		// Enforce v1 business rule: fixed prices can only apply to products and variations.
		if ( $pricing_value->is_fixed() && ! $scope->is_product() && ! $scope->is_variation() ) {
			throw new InvalidRuleException(
				sprintf(
					'Fixed-price rules are restricted to product or variation scopes in v1. Got scope "%s".',
					$scope->type()
				)
			);
		}

		$this->id            = $id;
		$this->target        = $target;
		$this->scope         = $scope;
		$this->pricing_value = $pricing_value;
		$this->status        = is_string( $status ) ? new RuleStatus( $status ) : $status;
		$this->created_at    = $created_at;
		$this->updated_at    = $updated_at;
	}

	public function id(): ?int {
		return $this->id;
	}

	public function with_id( int $id ): self {
		$clone     = clone $this;
		$clone->id = $id;
		return $clone;
	}

	public function target(): RuleTarget {
		return $this->target;
	}

	public function scope(): RuleScope {
		return $this->scope;
	}

	public function pricing_value(): PricingValue {
		return $this->pricing_value;
	}

	public function status(): RuleStatus {
		return $this->status;
	}

	public function created_at(): ?DateTimeImmutable {
		return $this->created_at;
	}

	public function updated_at(): ?DateTimeImmutable {
		return $this->updated_at;
	}

	public function is_active(): bool {
		return $this->status->is_active();
	}

	public function activate(): self {
		$clone             = clone $this;
		$clone->status     = RuleStatus::active();
		$clone->updated_at = new DateTimeImmutable();
		return $clone;
	}

	public function deactivate(): self {
		$clone             = clone $this;
		$clone->status     = RuleStatus::inactive();
		$clone->updated_at = new DateTimeImmutable();
		return $clone;
	}

	public function update_value( PricingValue $new_value ): self {
		if ( $new_value->is_fixed() && ! $this->scope->is_product() && ! $this->scope->is_variation() ) {
			throw new InvalidRuleException(
				sprintf(
					'Fixed-price rules are restricted to product or variation scopes in v1. Got scope "%s".',
					$this->scope->type()
				)
			);
		}

		$clone                = clone $this;
		$clone->pricing_value = $new_value;
		$clone->updated_at    = new DateTimeImmutable();
		return $clone;
	}

	/**
	 * Unique logical key for active collision checking.
	 * Format: "target_type:target_id|scope_type:scope_id".
	 * Pricing type is intentionally excluded to ensure only one active rule exists per target & scope.
	 */
	public function logical_key(): string {
		return sprintf(
			'%s:%d|%s:%d',
			$this->target->type(),
			$this->target->id(),
			$this->scope->type(),
			$this->scope->id()
		);
	}

	/**
	 * Database active key representation (null when inactive to allow multiple historical inactive rules).
	 */
	public function active_key(): ?string {
		return $this->is_active() ? $this->logical_key() : null;
	}
}
