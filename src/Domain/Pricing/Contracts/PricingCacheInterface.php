<?php

declare(strict_types=1);

namespace Tierza\Domain\Pricing\Contracts;

use Tierza\Domain\Entities\PricingGroup;
use Tierza\Domain\Entities\PricingRule;
use Tierza\Domain\Pricing\PricingResult;
use Tierza\Domain\Pricing\ResolutionResult;

/**
 * Contract for request-scoped pricing rule and resolution caching (TRZ-009).
 *
 * Requirements:
 * - Request-scoped: purely in-memory lifecycle, never persisted across user sessions.
 * - Multi-tier: caches reusable candidate rules, resolution results, and final calculated prices.
 * - Isolated: strictly segregates customer identities, groups, products, and categories.
 * - Observable: tracks hit/miss statistics and memory bounds.
 */
interface PricingCacheInterface {

	/**
	 * Retrieve cached candidate rules for a target signature.
	 *
	 * @return PricingRule[]|null Null on cache miss.
	 */
	public function get_candidate_rules( string $target_key ): ?array;

	/**
	 * Cache candidate rules for a target signature.
	 *
	 * @param PricingRule[] $rules
	 */
	public function set_candidate_rules( string $target_key, array $rules ): void;

	/**
	 * Check whether candidate rules are cached for a target signature.
	 */
	public function has_candidate_rules( string $target_key ): bool;

	/**
	 * Retrieve cached rule resolution result for a context signature.
	 */
	public function get_resolution( string $context_key ): ?ResolutionResult;

	/**
	 * Cache rule resolution result for a context signature.
	 */
	public function set_resolution( string $context_key, ResolutionResult $result ): void;

	/**
	 * Check whether a resolution result is cached for a context signature.
	 */
	public function has_resolution( string $context_key ): bool;

	/**
	 * Retrieve cached final calculation result for a calculation signature.
	 */
	public function get_calculated_price( string $calculation_key ): ?PricingResult;

	/**
	 * Cache final calculation result for a calculation signature.
	 */
	public function set_calculated_price( string $calculation_key, PricingResult $result ): void;

	/**
	 * Check whether a calculated price is cached.
	 */
	public function has_calculated_price( string $calculation_key ): bool;

	/**
	 * Retrieve cached verification status for a pricing group ID.
	 *
	 * @return array{exists: bool, is_active: bool, group: ?PricingGroup}|null
	 */
	public function get_group_status( int $group_id ): ?array;

	/**
	 * Cache verification status for a pricing group ID.
	 */
	public function set_group_status( int $group_id, bool $exists, bool $is_active, ?PricingGroup $group = null ): void;

	/**
	 * Invalidate all cached rules and resolution results (e.g. after rule mutations).
	 */
	public function invalidate_rules(): void;

	/**
	 * Invalidate cached group statuses and group-dependent resolutions.
	 */
	public function invalidate_groups(): void;

	/**
	 * Invalidate resolutions and prices cached for a specific customer ID.
	 */
	public function invalidate_customer( int $customer_id ): void;

	/**
	 * Invalidate resolutions and prices cached for a specific product ID.
	 */
	public function invalidate_product( int $product_id ): void;

	/**
	 * Completely flush all cached entries and reset statistics.
	 */
	public function clear(): void;

	/**
	 * Retrieve runtime telemetry and performance statistics.
	 *
	 * @return array{
	 *     hits: int,
	 *     misses: int,
	 *     candidate_hits: int,
	 *     candidate_misses: int,
	 *     resolution_hits: int,
	 *     resolution_misses: int,
	 *     calculation_hits: int,
	 *     calculation_misses: int,
	 *     candidate_count: int,
	 *     resolution_count: int,
	 *     calculation_count: int,
	 *     group_count: int
	 * }
	 */
	public function stats(): array;
}
