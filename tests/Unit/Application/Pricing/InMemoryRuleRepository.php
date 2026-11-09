<?php

declare(strict_types=1);

namespace Tierza\Tests\Unit\Application\Pricing;

use RuntimeException;
use Tierza\Domain\Entities\PricingRule;
use Tierza\Domain\Exceptions\DuplicateRuleException;
use Tierza\Domain\Repositories\RuleRepositoryInterface;
use Tierza\Domain\ValueObjects\RuleScope;
use Tierza\Domain\ValueObjects\RuleTarget;

/**
 * In-memory test double implementing RuleRepositoryInterface.
 */
final class InMemoryRuleRepository implements RuleRepositoryInterface {

	/**
	 * @var array<int, PricingRule>
	 */
	private array $rules = [];

	private int $auto_increment = 1;

	private bool $should_fail_on_query = false;

	private int $query_count = 0;

	public function query_count(): int {
		return $this->query_count;
	}

	public function reset_query_count(): void {
		$this->query_count = 0;
	}

	public function set_should_fail_on_query( bool $fail ): void {
		$this->should_fail_on_query = $fail;
	}

	public function save( PricingRule $rule ): PricingRule {
		$id = $rule->id();
		if ( null === $id ) {
			$id   = $this->auto_increment++;
			$rule = $rule->with_id( $id );
		}

		$this->rules[ $id ] = $rule;
		return $rule;
	}

	public function find_by_id( int $id ): ?PricingRule {
		return $this->rules[ $id ] ?? null;
	}

	public function find_active_by_target( RuleTarget $target ): array {
		if ( $this->should_fail_on_query ) {
			throw new RuntimeException( 'Simulated database read failure in find_active_by_target().' );
		}

		$results = [];
		foreach ( $this->rules as $rule ) {
			if ( $rule->is_active() && $rule->target()->type() === $target->type() && $rule->target()->id() === $target->id() ) {
				$results[] = $rule;
			}
		}

		return $results;
	}

	public function find_active_for_targets( array $targets, array $scopes = [] ): array {
		if ( $this->should_fail_on_query ) {
			throw new RuntimeException( 'Simulated database read failure in find_active_for_targets().' );
		}

		if ( empty( $targets ) ) {
			return [];
		}

		++$this->query_count;

		$target_map = [];
		foreach ( $targets as $target ) {
			$target_map[ $target->type() . ':' . $target->id() ] = true;
		}

		$scope_map = [];
		if ( ! empty( $scopes ) ) {
			foreach ( $scopes as $scope ) {
				$scope_map[ $scope->type() . ':' . (int) $scope->id() ] = true;
			}
		}

		$results = [];
		foreach ( $this->rules as $rule ) {
			if ( ! $rule->is_active() ) {
				continue;
			}
			$sig = $rule->target()->type() . ':' . $rule->target()->id();
			if ( ! isset( $target_map[ $sig ] ) ) {
				continue;
			}

			if ( ! empty( $scopes ) ) {
				$scope_sig = $rule->scope()->type() . ':' . (int) $rule->scope()->id();
				if ( ! isset( $scope_map[ $scope_sig ] ) ) {
					continue;
				}
			}

			$results[] = $rule;
		}

		return $results;
	}

	public function find_active_by_scope( RuleScope $scope ): array {
		$results = [];
		foreach ( $this->rules as $rule ) {
			if ( $rule->is_active() && $rule->scope()->type() === $scope->type() && $rule->scope()->id() === $scope->id() ) {
				$results[] = $rule;
			}
		}

		return $results;
	}

	public function list( array $filters = [], int $limit = 50, int $offset = 0 ): array {
		return array_slice( array_values( $this->rules ), $offset, $limit );
	}

	public function count( array $filters = [] ): int {
		return count( $this->rules );
	}

	public function find_conflicting_active_rule( PricingRule $rule ): ?PricingRule {
		foreach ( $this->rules as $existing ) {
			if ( ! $existing->is_active() ) {
				continue;
			}
			if ( null !== $rule->id() && $rule->id() === $existing->id() ) {
				continue;
			}
			if (
				$existing->target()->type() === $rule->target()->type() &&
				$existing->target()->id() === $rule->target()->id() &&
				$existing->scope()->type() === $rule->scope()->type() &&
				$existing->scope()->id() === $rule->scope()->id()
			) {
				return $existing;
			}
		}

		return null;
	}

	public function delete( int $id ): bool {
		if ( isset( $this->rules[ $id ] ) ) {
			unset( $this->rules[ $id ] );
			return true;
		}

		return false;
	}

	public function clear(): void {
		$this->rules          = [];
		$this->auto_increment = 1;
	}
}
