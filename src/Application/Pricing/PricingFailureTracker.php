<?php

declare(strict_types=1);

namespace Tierza\Application\Pricing;

/**
 * Tracks failed pricing calculations and repository lookups during the current execution.
 *
 * Provides a reliable failure signal that downstream adapters (e.g. TRZ-011 cart & checkout)
 * consume to ensure degraded storefront fallback prices cannot silently become
 * authorized B2B purchase prices.
 *
 * Supports customer-identity and cart-line isolation, discrete clearing upon authoritative
 * recalculation, and complete checkout validation blocking.
 */
class PricingFailureTracker {

	public const CODE_CALCULATION_FAILED     = 'TRZ_ERR_CALCULATION_FAILED';
	public const CODE_REPOSITORY_UNAVAILABLE = 'TRZ_ERR_REPOSITORY_UNAVAILABLE';
	public const CODE_INVALID_CONTEXT        = 'TRZ_ERR_INVALID_CONTEXT';
	public const CODE_UNEXPECTED             = 'TRZ_ERR_UNEXPECTED';

	/**
	 * Map of composite key => failure record.
	 *
	 * @var array<string, array{ error_code: string, message: string, product_id: int, customer_id: int, cart_item_key: string, timestamp: int }>
	 */
	private array $failures = [];

	/**
	 * Record a failure for a specific product ID, with optional customer and cart item context.
	 *
	 * @param int    $product_id    Product or variation ID.
	 * @param string $error_code    Machine-readable error code.
	 * @param string $safe_message  Sanitized failure description.
	 * @param int    $customer_id   Customer user ID (0 for guest).
	 * @param string $cart_item_key Cart line item key.
	 */
	public function record_failure(
		int $product_id,
		string $error_code,
		string $safe_message,
		int $customer_id = 0,
		string $cart_item_key = ''
	): void {
		$key = $this->build_key( $product_id, $customer_id, $cart_item_key );

		$this->failures[ $key ] = [
			'error_code'    => $error_code,
			'message'       => $safe_message,
			'product_id'    => $product_id,
			'customer_id'   => $customer_id,
			'cart_item_key' => $cart_item_key,
			'timestamp'     => time(),
		];
	}

	/**
	 * Clear recorded failure(s) upon authoritative recalculation.
	 *
	 * @param int    $product_id    Product or variation ID.
	 * @param int    $customer_id   Customer user ID (0 for all customers if omitted).
	 * @param string $cart_item_key Specific cart line key (clears all line keys for product/customer if empty).
	 */
	public function clear_failure( int $product_id, int $customer_id = 0, string $cart_item_key = '' ): void {
		if ( '' !== $cart_item_key ) {
			$exact_key = $this->build_key( $product_id, $customer_id, $cart_item_key );
			unset( $this->failures[ $exact_key ] );

			$line_agnostic_key = $this->build_key( $product_id, $customer_id, '' );
			unset( $this->failures[ $line_agnostic_key ] );

			if ( $customer_id > 0 ) {
				$global_key      = $this->build_key( $product_id, 0, $cart_item_key );
				$global_agnostic = $this->build_key( $product_id, 0, '' );
				unset( $this->failures[ $global_key ], $this->failures[ $global_agnostic ] );
			}
			return;
		}

		foreach ( $this->failures as $k => $record ) {
			if ( $record['product_id'] !== $product_id ) {
				continue;
			}
			if ( $customer_id > 0 && $record['customer_id'] !== $customer_id && $record['customer_id'] > 0 ) {
				continue;
			}
			unset( $this->failures[ $k ] );
		}
	}

	/**
	 * Check if a product has encountered a calculation or resolution failure.
	 *
	 * @param int    $product_id    Product or variation ID.
	 * @param int    $customer_id   Optional customer ID filter (0 checks any customer).
	 * @param string $cart_item_key Optional specific cart line item key.
	 * @return bool True if failure was recorded.
	 */
	public function has_failed( int $product_id, int $customer_id = 0, string $cart_item_key = '' ): bool {
		if ( '' !== $cart_item_key ) {
			$exact_key = $this->build_key( $product_id, $customer_id, $cart_item_key );
			if ( isset( $this->failures[ $exact_key ] ) ) {
				return true;
			}

			$line_agnostic_key = $this->build_key( $product_id, $customer_id, '' );
			if ( isset( $this->failures[ $line_agnostic_key ] ) ) {
				return true;
			}

			if ( $customer_id > 0 ) {
				$global_key      = $this->build_key( $product_id, 0, $cart_item_key );
				$global_agnostic = $this->build_key( $product_id, 0, '' );
				if ( isset( $this->failures[ $global_key ] ) || isset( $this->failures[ $global_agnostic ] ) ) {
					return true;
				}
			}
		}

		foreach ( $this->failures as $record ) {
			if ( $record['product_id'] !== $product_id ) {
				continue;
			}
			if ( $customer_id > 0 && $record['customer_id'] > 0 && $record['customer_id'] !== $customer_id ) {
				continue;
			}
			return true;
		}

		return false;
	}

	/**
	 * Retrieve failure details for a product.
	 *
	 * @param int    $product_id    Product ID.
	 * @param int    $customer_id   Optional customer ID.
	 * @param string $cart_item_key Optional cart line key.
	 * @return array{ error_code: string, message: string, product_id: int, customer_id: int, cart_item_key: string, timestamp: int }|null
	 */
	public function get_failure( int $product_id, int $customer_id = 0, string $cart_item_key = '' ): ?array {
		if ( '' !== $cart_item_key ) {
			$key = $this->build_key( $product_id, $customer_id, $cart_item_key );
			return $this->failures[ $key ] ?? null;
		}

		foreach ( $this->failures as $record ) {
			if ( $record['product_id'] !== $product_id ) {
				continue;
			}
			if ( $customer_id > 0 && $record['customer_id'] !== $customer_id ) {
				continue;
			}
			return $record;
		}

		return null;
	}

	/**
	 * Retrieve all product IDs that experienced failures.
	 *
	 * @param int $customer_id Optional customer ID filter.
	 * @return int[] Unique list of product IDs.
	 */
	public function get_failed_product_ids( int $customer_id = 0 ): array {
		$ids = [];
		foreach ( $this->failures as $record ) {
			if ( $customer_id > 0 && $record['customer_id'] !== $customer_id ) {
				continue;
			}
			$ids[ $record['product_id'] ] = true;
		}
		return array_keys( $ids );
	}

	/**
	 * Reset all failures for a specific customer identity (e.g. on login/logout).
	 *
	 * @param int $customer_id Customer user ID.
	 */
	public function reset_customer( int $customer_id ): void {
		foreach ( $this->failures as $k => $record ) {
			if ( $record['customer_id'] === $customer_id ) {
				unset( $this->failures[ $k ] );
			}
		}
	}

	/**
	 * Clear all recorded failures across all contexts.
	 */
	public function reset(): void {
		$this->failures = [];
	}

	/**
	 * Build unique storage key.
	 *
	 * @param int    $product_id    Product ID.
	 * @param int    $customer_id   Customer ID.
	 * @param string $cart_item_key Cart item key.
	 * @return string Internal key.
	 */
	private function build_key( int $product_id, int $customer_id, string $cart_item_key ): string {
		return sprintf( '%d:%d:%s', $product_id, $customer_id, $cart_item_key );
	}
}
