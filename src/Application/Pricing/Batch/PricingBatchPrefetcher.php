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

		$targets_by_sig = [];

		foreach ( $contexts as $context ) {
			// Customer target: authenticated users only.
			if ( $context->is_authenticated() ) {
				$user_target = RuleTarget::user( $context->customer_id() );
				$targets_by_sig[ $user_target->type() . ':' . $user_target->id() ] = $user_target;
			}

			// Group target: active groups only.
			$group_id = $context->group_id();
			if ( null !== $group_id && $group_id > 0 ) {
				$is_active = $this->resolve_group_active( $context, $group_id );
				if ( $is_active ) {
					$group_target = RuleTarget::group( $group_id );
					$targets_by_sig[ $group_target->type() . ':' . $group_target->id() ] = $group_target;
				}
			}
		}

		if ( empty( $targets_by_sig ) ) {
			return;
		}

		$targets    = array_values( $targets_by_sig );
		$target_key = PricingCacheKeyGenerator::target_key( $targets );

		if ( ! $this->cache->has_candidate_rules( $target_key ) ) {
			$rules = $this->rule_repository->find_active_for_targets( $targets );
			$this->cache->set_candidate_rules( $target_key, $rules );
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
