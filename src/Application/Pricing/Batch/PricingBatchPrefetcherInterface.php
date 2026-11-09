<?php

declare(strict_types=1);

namespace Tierza\Application\Pricing\Batch;

use Tierza\Domain\Pricing\PricingContext;

/**
 * Contract for batch prefetching pricing rules for multiple contexts (TRZ-009).
 */
interface PricingBatchPrefetcherInterface {

	/**
	 * Prefetches and warms candidate rules for an array of pricing contexts.
	 *
	 * Extracts distinct customer and group targets and executes a single bounded
	 * indexed database query to populate the request cache before resolution loops.
	 *
	 * @param PricingContext[] $contexts
	 */
	public function prefetch_contexts( array $contexts ): void;

	/**
	 * Prefetches and warms candidate rules for an explicit customer identity.
	 *
	 * @param int      $customer_id Customer user ID (0 for guest).
	 * @param int|null $group_id    Optional pricing group ID.
	 */
	public function prefetch_customer( int $customer_id, ?int $group_id = null ): void;
}
