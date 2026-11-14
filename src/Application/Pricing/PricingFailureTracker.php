<?php

declare(strict_types=1);

namespace Tierza\Application\Pricing;

/**
 * Tracks failed pricing calculations and repository lookups during the current execution.
 *
 * Provides a reliable failure signal that downstream adapters (e.g. TRZ-011 cart & checkout)
 * can consume to ensure degraded storefront fallback prices cannot silently become
 * authorized B2B purchase prices.
 */
class PricingFailureTracker {

	public const CODE_CALCULATION_FAILED     = 'TRZ_ERR_CALCULATION_FAILED';
	public const CODE_REPOSITORY_UNAVAILABLE = 'TRZ_ERR_REPOSITORY_UNAVAILABLE';
	public const CODE_INVALID_CONTEXT        = 'TRZ_ERR_INVALID_CONTEXT';
	public const CODE_UNEXPECTED             = 'TRZ_ERR_UNEXPECTED';

	/**
	 * Map of product ID => failure record.
	 *
	 * @var array<int, array{ error_code: string, message: string, timestamp: int }>
	 */
	private array $failures = [];

	/**
	 * Record a failure for a specific product ID.
	 *
	 * @param int    $product_id   Product or variation ID.
	 * @param string $error_code   Machine-readable error code.
	 * @param string $safe_message Sanitized failure description.
	 */
	public function record_failure( int $product_id, string $error_code, string $safe_message ): void {
		if ( $product_id <= 0 ) {
			return;
		}

		$this->failures[ $product_id ] = [
			'error_code' => $error_code,
			'message'    => $safe_message,
			'timestamp'  => time(),
		];
	}

	/**
	 * Check if a product has encountered a calculation or resolution failure.
	 *
	 * @param int $product_id Product or variation ID.
	 * @return bool True if failure was recorded.
	 */
	public function has_failed( int $product_id ): bool {
		return isset( $this->failures[ $product_id ] );
	}

	/**
	 * Retrieve failure details for a product.
	 *
	 * @param int $product_id Product ID.
	 * @return array{ error_code: string, message: string, timestamp: int }|null
	 */
	public function get_failure( int $product_id ): ?array {
		return $this->failures[ $product_id ] ?? null;
	}

	/**
	 * Retrieve all product IDs that experienced failures.
	 *
	 * @return int[] List of product IDs.
	 */
	public function get_failed_product_ids(): array {
		return array_keys( $this->failures );
	}

	/**
	 * Clear all recorded failures (for testing or request boundary reset).
	 */
	public function reset(): void {
		$this->failures = [];
	}
}
