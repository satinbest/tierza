<?php

declare(strict_types=1);

namespace Tierza\Application\Pricing\Cache;

use Tierza\Domain\Entities\PricingGroup;
use Tierza\Domain\Entities\PricingRule;
use Tierza\Domain\Pricing\Contracts\PricingCacheInterface;
use Tierza\Domain\Pricing\PricingResult;
use Tierza\Domain\Pricing\ResolutionResult;

/**
 * High-performance, memory-bounded, request-scoped pricing cache (TRZ-009).
 *
 * Lifecycle:
 * - Request-scoped: purely in-memory PHP object, instantiated per request.
 * - Thread-safe within PHP-FPM / CLI single-process runtime.
 * - Automatically evicts oldest entries (FIFO bounded pruning) to guarantee predictable memory footprint.
 */
final class RequestPricingCache implements PricingCacheInterface {

	public const DEFAULT_MAX_CANDIDATE_ENTRIES   = 100;
	public const DEFAULT_MAX_RESOLUTION_ENTRIES  = 2000;
	public const DEFAULT_MAX_CALCULATION_ENTRIES = 2000;

	private int $max_candidate_entries;
	private int $max_resolution_entries;
	private int $max_calculation_entries;

	/**
	 * @var array<string, PricingRule[]>
	 */
	private array $candidate_rules = [];

	/**
	 * @var array<string, ResolutionResult>
	 */
	private array $resolutions = [];

	/**
	 * @var array<string, PricingResult>
	 */
	private array $calculated_prices = [];

	/**
	 * @var array<int, array{exists: bool, is_active: bool, group: ?PricingGroup}>
	 */
	private array $group_statuses = [];

	// Statistics counters.
	private int $candidate_hits     = 0;
	private int $candidate_misses   = 0;
	private int $resolution_hits    = 0;
	private int $resolution_misses  = 0;
	private int $calculation_hits   = 0;
	private int $calculation_misses = 0;
	private int $invalidations      = 0;

	public function __construct(
		int $max_candidate_entries = self::DEFAULT_MAX_CANDIDATE_ENTRIES,
		int $max_resolution_entries = self::DEFAULT_MAX_RESOLUTION_ENTRIES,
		int $max_calculation_entries = self::DEFAULT_MAX_CALCULATION_ENTRIES
	) {
		$this->max_candidate_entries   = max( 10, $max_candidate_entries );
		$this->max_resolution_entries  = max( 50, $max_resolution_entries );
		$this->max_calculation_entries = max( 50, $max_calculation_entries );
	}

	public function get_candidate_rules( string $target_key ): ?array {
		if ( array_key_exists( $target_key, $this->candidate_rules ) ) {
			++$this->candidate_hits;
			return $this->candidate_rules[ $target_key ];
		}

		++$this->candidate_misses;
		return null;
	}

	public function set_candidate_rules( string $target_key, array $rules ): void {
		// Prune if limit reached.
		if ( count( $this->candidate_rules ) >= $this->max_candidate_entries ) {
			array_shift( $this->candidate_rules );
		}

		$this->candidate_rules[ $target_key ] = $rules;
	}

	public function has_candidate_rules( string $target_key ): bool {
		return array_key_exists( $target_key, $this->candidate_rules );
	}

	public function get_resolution( string $context_key ): ?ResolutionResult {
		if ( array_key_exists( $context_key, $this->resolutions ) ) {
			++$this->resolution_hits;
			return $this->resolutions[ $context_key ];
		}

		++$this->resolution_misses;
		return null;
	}

	public function set_resolution( string $context_key, ResolutionResult $result ): void {
		if ( count( $this->resolutions ) >= $this->max_resolution_entries ) {
			array_shift( $this->resolutions );
		}

		$this->resolutions[ $context_key ] = $result;
	}

	public function has_resolution( string $context_key ): bool {
		return array_key_exists( $context_key, $this->resolutions );
	}

	public function get_calculated_price( string $calculation_key ): ?PricingResult {
		if ( array_key_exists( $calculation_key, $this->calculated_prices ) ) {
			++$this->calculation_hits;
			return $this->calculated_prices[ $calculation_key ];
		}

		++$this->calculation_misses;
		return null;
	}

	public function set_calculated_price( string $calculation_key, PricingResult $result ): void {
		if ( count( $this->calculated_prices ) >= $this->max_calculation_entries ) {
			array_shift( $this->calculated_prices );
		}

		$this->calculated_prices[ $calculation_key ] = $result;
	}

	public function has_calculated_price( string $calculation_key ): bool {
		return array_key_exists( $calculation_key, $this->calculated_prices );
	}

	public function get_group_status( int $group_id ): ?array {
		return $this->group_statuses[ $group_id ] ?? null;
	}

	public function set_group_status( int $group_id, bool $exists, bool $is_active, ?PricingGroup $group = null ): void {
		$this->group_statuses[ $group_id ] = [
			'exists'    => $exists,
			'is_active' => $is_active,
			'group'     => $group,
		];
	}

	public function invalidate_rules(): void {
		++$this->invalidations;
		$this->candidate_rules   = [];
		$this->resolutions       = [];
		$this->calculated_prices = [];
	}

	public function invalidate_groups(): void {
		++$this->invalidations;
		$this->group_statuses    = [];
		$this->candidate_rules   = [];
		$this->resolutions       = [];
		$this->calculated_prices = [];
	}

	public function invalidate_customer( int $customer_id ): void {
		++$this->invalidations;

		// Evict customer candidate rules.
		$user_token = 'user:' . $customer_id;
		foreach ( array_keys( $this->candidate_rules ) as $key ) {
			if ( str_contains( $key, $user_token ) ) {
				unset( $this->candidate_rules[ $key ] );
			}
		}

		// Evict customer resolutions.
		$auth_prefix = 'cust:auth:' . $customer_id;
		foreach ( array_keys( $this->resolutions ) as $key ) {
			if ( str_starts_with( $key, $auth_prefix ) ) {
				unset( $this->resolutions[ $key ] );
			}
		}

		// Evict customer calculated prices.
		foreach ( array_keys( $this->calculated_prices ) as $key ) {
			if ( str_starts_with( $key, $auth_prefix ) ) {
				unset( $this->calculated_prices[ $key ] );
			}
		}
	}

	public function invalidate_product( int $product_id ): void {
		++$this->invalidations;

		$prod_token = '#prod:' . $product_id . '#';
		foreach ( array_keys( $this->resolutions ) as $key ) {
			if ( str_contains( $key, $prod_token ) ) {
				unset( $this->resolutions[ $key ] );
			}
		}

		foreach ( array_keys( $this->calculated_prices ) as $key ) {
			if ( str_contains( $key, $prod_token ) ) {
				unset( $this->calculated_prices[ $key ] );
			}
		}
	}

	public function clear(): void {
		$this->candidate_rules   = [];
		$this->resolutions       = [];
		$this->calculated_prices = [];
		$this->group_statuses    = [];

		$this->candidate_hits     = 0;
		$this->candidate_misses   = 0;
		$this->resolution_hits    = 0;
		$this->resolution_misses  = 0;
		$this->calculation_hits   = 0;
		$this->calculation_misses = 0;
		$this->invalidations      = 0;
	}

	public function stats(): array {
		return [
			'hits'               => $this->candidate_hits + $this->resolution_hits + $this->calculation_hits,
			'misses'             => $this->candidate_misses + $this->resolution_misses + $this->calculation_misses,
			'candidate_hits'     => $this->candidate_hits,
			'candidate_misses'   => $this->candidate_misses,
			'resolution_hits'    => $this->resolution_hits,
			'resolution_misses'  => $this->resolution_misses,
			'calculation_hits'   => $this->calculation_hits,
			'calculation_misses' => $this->calculation_misses,
			'candidate_count'    => count( $this->candidate_rules ),
			'resolution_count'   => count( $this->resolutions ),
			'calculation_count'  => count( $this->calculated_prices ),
			'group_count'        => count( $this->group_statuses ),
		];
	}
}
