<?php

declare(strict_types=1);

namespace Tierza\Infrastructure\Repositories;

use Tierza\Application\Pricing\Cache\PricingCacheKeyGenerator;
use Tierza\Domain\Entities\PricingRule;
use Tierza\Domain\Pricing\Contracts\PricingCacheInterface;
use Tierza\Domain\Repositories\RuleRepositoryInterface;
use Tierza\Domain\ValueObjects\RuleScope;
use Tierza\Domain\ValueObjects\RuleTarget;

/**
 * Caching decorator for RuleRepositoryInterface (TRZ-009).
 *
 * Intercepts active target queries and serves results from the request-scoped cache.
 * Automatically invalidates rule and resolution caches upon mutations (save, delete).
 */
final class CachedRuleRepository implements RuleRepositoryInterface {

	private RuleRepositoryInterface $inner;
	private PricingCacheInterface $cache;

	public function __construct( RuleRepositoryInterface $inner, PricingCacheInterface $cache ) {
		$this->inner = $inner;
		$this->cache = $cache;
	}

	public function inner(): RuleRepositoryInterface {
		return $this->inner;
	}

	public function cache(): PricingCacheInterface {
		return $this->cache;
	}

	public function save( PricingRule $rule ): PricingRule {
		$saved = $this->inner->save( $rule );
		$this->cache->invalidate_rules();
		return $saved;
	}

	public function find_by_id( int $id ): ?PricingRule {
		return $this->inner->find_by_id( $id );
	}

	public function find_active_by_target( RuleTarget $target ): array {
		$target_key = PricingCacheKeyGenerator::target_key( [ $target ] );
		$cached     = $this->cache->get_candidate_rules( $target_key );
		if ( null !== $cached ) {
			return $cached;
		}

		$rules = $this->inner->find_active_by_target( $target );
		$this->cache->set_candidate_rules( $target_key, $rules );
		return $rules;
	}

	public function find_active_for_targets( array $targets, array $scopes = [] ): array {
		if ( empty( $targets ) ) {
			return [];
		}

		// When scopes are empty, this represents the full candidate ruleset for these targets.
		if ( empty( $scopes ) ) {
			$target_key = PricingCacheKeyGenerator::target_key( $targets );
			$cached     = $this->cache->get_candidate_rules( $target_key );
			if ( null !== $cached ) {
				return $cached;
			}

			$rules = $this->inner->find_active_for_targets( $targets, $scopes );
			$this->cache->set_candidate_rules( $target_key, $rules );
			return $rules;
		}

		// When scopes are provided, query directly through inner repository.
		return $this->inner->find_active_for_targets( $targets, $scopes );
	}

	public function find_active_by_scope( RuleScope $scope ): array {
		return $this->inner->find_active_by_scope( $scope );
	}

	public function list( array $filters = [], int $limit = 50, int $offset = 0 ): array {
		return $this->inner->list( $filters, $limit, $offset );
	}

	public function count( array $filters = [] ): int {
		return $this->inner->count( $filters );
	}

	public function find_conflicting_active_rule( PricingRule $rule ): ?PricingRule {
		return $this->inner->find_conflicting_active_rule( $rule );
	}

	public function delete( int $id ): bool {
		$deleted = $this->inner->delete( $id );
		if ( $deleted ) {
			$this->cache->invalidate_rules();
		}
		return $deleted;
	}
}
