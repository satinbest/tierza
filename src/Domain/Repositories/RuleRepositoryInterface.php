<?php

declare(strict_types=1);

namespace Tierza\Domain\Repositories;

use Tierza\Domain\Entities\PricingRule;
use Tierza\Domain\Exceptions\DuplicateRuleException;
use Tierza\Domain\ValueObjects\RuleScope;
use Tierza\Domain\ValueObjects\RuleTarget;

/**
 * Interface for pricing rule persistence.
 */
interface RuleRepositoryInterface {

	/**
	 * Persist a pricing rule (insert or update).
	 *
	 * @throws DuplicateRuleException If an active rule with identical logical signature exists.
	 */
	public function save( PricingRule $rule ): PricingRule;

	/**
	 * Find a rule by its primary ID.
	 */
	public function find_by_id( int $id ): ?PricingRule;

	/**
	 * Find active rules for a specific target.
	 *
	 * @return PricingRule[]
	 */
	public function find_active_by_target( RuleTarget $target ): array;

	/**
	 * Find active rules matching any of the given targets.
	 *
	 * High-efficiency indexed batch query using idx_target (target_type, target_id, status).
	 *
	 * @param RuleTarget[] $targets
	 * @return PricingRule[]
	 */
	public function find_active_for_targets( array $targets ): array;

	/**
	 * Find active rules for a specific scope.
	 *
	 * @return PricingRule[]
	 */
	public function find_active_by_scope( RuleScope $scope ): array;

	/**
	 * List rules with optional filtering and pagination.
	 * Filters may include: 'target_type', 'target_id', 'scope_type', 'scope_id', 'pricing_type', 'status'.
	 *
	 * @param array<string, mixed> $filters
	 * @return PricingRule[]
	 */
	public function list( array $filters = [], int $limit = 50, int $offset = 0 ): array;

	/**
	 * Count total rules matching filters.
	 *
	 * @param array<string, mixed> $filters
	 */
	public function count( array $filters = [] ): int;

	/**
	 * Check if an active rule conflicting with this rule already exists.
	 */
	public function find_conflicting_active_rule( PricingRule $rule ): ?PricingRule;

	/**
	 * Delete a rule by its primary ID.
	 */
	public function delete( int $id ): bool;
}
