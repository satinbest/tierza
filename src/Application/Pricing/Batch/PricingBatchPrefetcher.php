<?php

declare(strict_types=1);

namespace Tierza\Application\Pricing\Batch;

use Tierza\Application\Pricing\Cache\PricingCacheKeyGenerator;
use Tierza\Domain\Pricing\Contracts\PricingCacheInterface;
use Tierza\Domain\Pricing\PricingContext;
use Tierza\Domain\Repositories\GroupRepositoryInterface;
use Tierza\Domain\Repositories\RuleRepositoryInterface;
use Tierza\Domain\ValueObjects\RuleTarget;

/**
 * Concrete batch rule prefetcher for Tierza pricing (TRZ-009).
 */
final class PricingBatchPrefetcher implements PricingBatchPrefetcherInterface {

	private RuleRepositoryInterface $rule_repository;
	private ?GroupRepositoryInterface $group_repository;
	private PricingCacheInterface $cache;

	public function __construct(
		RuleRepositoryInterface $rule_repository,
		PricingCacheInterface $cache,
		?GroupRepositoryInterface $group_repository = null
	) {
		$this->rule_repository  = $rule_repository;
		$this->cache            = $cache;
		$this->group_repository = $group_repository;
	}

	public function prefetch_contexts( array $contexts ): void {
		if ( empty( $contexts ) ) {
			return;
		}

		/** @var array<string, RuleTarget[]> $target_sets_by_sig */
		$target_sets_by_sig = [];
		/** @var array<string, RuleTarget> $missing_targets */
		$missing_targets = [];

		foreach ( $contexts as $context ) {
			$context_targets = [];

			// Customer target: authenticated users only.
			if ( $context->is_authenticated() ) {
				$user_target       = RuleTarget::user( $context->customer_id() );
				$context_targets[] = $user_target;
			}

			// Group target: active groups only.
			$group_id = $context->group_id();
			if ( null !== $group_id && $group_id > 0 ) {
				$is_active = $this->resolve_group_active( $context, $group_id );
				if ( $is_active ) {
					$context_targets[] = RuleTarget::group( $group_id );
				}
			}

			if ( empty( $context_targets ) ) {
				continue;
			}

			$target_sig = PricingCacheKeyGenerator::target_key( $context_targets );
			if ( ! isset( $target_sets_by_sig[ $target_sig ] ) ) {
				$target_sets_by_sig[ $target_sig ] = $context_targets;

				// If not already cached, collect missing targets for database query.
				if ( ! $this->cache->has_candidate_rules( $target_sig ) ) {
					foreach ( $context_targets as $t ) {
						$missing_targets[ $t->type() . ':' . $t->id() ] = $t;
					}
				}
			}
		}

		if ( empty( $missing_targets ) ) {
			return;
		}

		// Fetch active rules for all missing targets across the batch in a single database query.
		$rules = $this->rule_repository->find_active_for_targets( array_values( $missing_targets ) );

		// Index returned rules by target signature for fast, isolated partitioning.
		/** @var array<string, \Tierza\Domain\Entities\PricingRule[]> $rules_by_target */
		$rules_by_target = [];
		foreach ( $rules as $rule ) {
			$rt_sig                       = $rule->target()->type() . ':' . $rule->target()->id();
			$rules_by_target[ $rt_sig ][] = $rule;
		}

		// Populate candidate cache for each target set with strictly and only its own rules.
		foreach ( $target_sets_by_sig as $target_sig => $targets ) {
			if ( $this->cache->has_candidate_rules( $target_sig ) ) {
				continue;
			}

			$set_rules = [];
			foreach ( $targets as $t ) {
				$t_sig = $t->type() . ':' . $t->id();
				if ( isset( $rules_by_target[ $t_sig ] ) ) {
					foreach ( $rules_by_target[ $t_sig ] as $r ) {
						$set_rules[] = $r;
					}
				}
			}

			$this->cache->set_candidate_rules( $target_sig, $set_rules );
		}
	}

	public function prefetch_customer( int $customer_id, ?int $group_id = null ): void {
		$targets = [];

		if ( $customer_id > 0 ) {
			$targets[] = RuleTarget::user( $customer_id );
		}

		if ( null !== $group_id && $group_id > 0 ) {
			$status    = $this->cache->get_group_status( $group_id );
			$is_active = false;

			if ( null !== $status ) {
				$is_active = $status['is_active'];
			} elseif ( null !== $this->group_repository ) {
				$group = $this->group_repository->find_by_id( $group_id );
				if ( null !== $group ) {
					$is_active = $group->is_active();
					$this->cache->set_group_status( $group_id, true, $is_active, $group );
				} else {
					$this->cache->set_group_status( $group_id, false, false, null );
				}
			}

			if ( $is_active ) {
				$targets[] = RuleTarget::group( $group_id );
			}
		}

		if ( empty( $targets ) ) {
			return;
		}

		$target_key = PricingCacheKeyGenerator::target_key( $targets );
		if ( ! $this->cache->has_candidate_rules( $target_key ) ) {
			$rules = $this->rule_repository->find_active_for_targets( $targets );
			$this->cache->set_candidate_rules( $target_key, $rules );
		}
	}

	private function resolve_group_active( PricingContext $context, int $group_id ): bool {
		$group = $context->group();
		if ( null !== $group ) {
			return $group->is_active();
		}

		$cached_status = $this->cache->get_group_status( $group_id );
		if ( null !== $cached_status ) {
			return $cached_status['is_active'];
		}

		if ( null !== $this->group_repository ) {
			$loaded = $this->group_repository->find_by_id( $group_id );
			if ( null !== $loaded ) {
				$is_active = $loaded->is_active();
				$this->cache->set_group_status( $group_id, true, $is_active, $loaded );
				return $is_active;
			}
			$this->cache->set_group_status( $group_id, false, false, null );
			return false;
		}

		return false;
	}
}
