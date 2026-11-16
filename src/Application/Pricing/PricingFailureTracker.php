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
 * Supports strict customer-identity and cart-line isolation, discrete clearing upon authoritative
 * recalculation, and complete checkout validation blocking.
 */
class PricingFailureTracker {

	public const CODE_CALCULATION_FAILED     = 'TRZ_ERR_CALCULATION_FAILED';
	public const CODE_REPOSITORY_UNAVAILABLE = 'TRZ_ERR_REPOSITORY_UNAVAILABLE';
	public const CODE_INVALID_CONTEXT        = 'TRZ_ERR_INVALID_CONTEXT';
	public const CODE_UNEXPECTED             = 'TRZ_ERR_UNEXPECTED';

	/**
	 * Map of composite key => failure record.
	 * Key format: sprintf('%d:%d:%s', $product_id, $customer_id, $cart_item_key)
	 *
	 * @var array<string, array{ error_code: string, message: string, product_id: int, customer_id: int, cart_item_key: string, timestamp: int }>
	 */
	private array $failures = [];

	/**
	 * Record a failure for a specific product ID, with customer and optional cart item context.
	 *
	 * @param int    $product_id    Product or variation ID (0 for invalid context).
	 * @param string $error_code    Machine-readable error code.
	 * @param string $safe_message  Sanitized failure description.
	 * @param int    $customer_id   Customer user ID (0 strictly represents guest).
	 * @param string $cart_item_key Optional cart line item key.
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
	 * When $cart_item_key is provided, clears ONLY that exact cart line failure.
	 * Clearing a line failure never erases a product-wide failure or other cart lines.
	 * When $cart_item_key is empty, clears ONLY the product-wide failure for this customer.
	 * Customer ID 0 strictly represents guest; it is never treated as a wildcard.
	 *
	 * @param int    $product_id    Product or variation ID.
	 * @param int    $customer_id   Customer user ID (0 for guest).
	 * @param string $cart_item_key Specific cart line key.
	 */
	public function clear_failure( int $product_id, int $customer_id = 0, string $cart_item_key = '' ): void {
		$key = $this->build_key( $product_id, $customer_id, $cart_item_key );
		unset( $this->failures[ $key ] );
	}

	/**
	 * Clear all failures for a product across all cart lines and product-wide for a specific customer.
	 *
	 * @param int $product_id  Product ID.
	 * @param int $customer_id Customer user ID (0 for guest).
	 */
	public function clear_all_product_failures( int $product_id, int $customer_id = 0 ): void {
		foreach ( $this->failures as $k => $record ) {
			if ( $record['product_id'] === $product_id && $record['customer_id'] === $customer_id ) {
				unset( $this->failures[ $k ] );
			}
		}
	}

	/**
	 * Check if a product has encountered a calculation or resolution failure.
	 *
	 * When $cart_item_key is provided:
	 * Returns true if an exact failure exists for this line OR if a product-wide failure exists
	 * for this exact customer. Does not check other lines or other customer identities.
	 *
	 * When $cart_item_key is empty:
	 * Returns true if any failure (product-wide or line-level) exists for this product and customer.
	 *
	 * Customer ID 0 strictly represents guest; it never acts as a wildcard for authenticated users,
	 * and authenticated customer IDs never match guest records.
	 *
	 * @param int    $product_id    Product or variation ID.
	 * @param int    $customer_id   Customer user ID (0 for guest).
	 * @param string $cart_item_key Optional specific cart line item key.
	 * @return bool True if failure was recorded.
	 */
	public function has_failed( int $product_id, int $customer_id = 0, string $cart_item_key = '' ): bool {
		if ( '' !== $cart_item_key ) {
			// 1. Exact line failure for this customer:
			$line_key = $this->build_key( $product_id, $customer_id, $cart_item_key );
			if ( isset( $this->failures[ $line_key ] ) ) {
				return true;
			}

			// 2. Product-wide failure for this customer:
			$product_key = $this->build_key( $product_id, $customer_id, '' );
			return isset( $this->failures[ $product_key ] );
		}

		// Product-level check: check if any failure exists for this exact product & customer:
		foreach ( $this->failures as $record ) {
			if ( $record['product_id'] === $product_id && $record['customer_id'] === $customer_id ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Retrieve failure details for a product.
	 *
	 * Semantics strictly agree with has_failed().
	 *
	 * @param int    $product_id    Product ID.
	 * @param int    $customer_id   Customer user ID (0 for guest).
	 * @param string $cart_item_key Optional cart line key.
	 * @return array{ error_code: string, message: string, product_id: int, customer_id: int, cart_item_key: string, timestamp: int }|null
	 */
	public function get_failure( int $product_id, int $customer_id = 0, string $cart_item_key = '' ): ?array {
		if ( '' !== $cart_item_key ) {
			$line_key = $this->build_key( $product_id, $customer_id, $cart_item_key );
			if ( isset( $this->failures[ $line_key ] ) ) {
				return $this->failures[ $line_key ];
			}

			$product_key = $this->build_key( $product_id, $customer_id, '' );
			if ( isset( $this->failures[ $product_key ] ) ) {
				return $this->failures[ $product_key ];
			}

			return null;
		}

		$product_key = $this->build_key( $product_id, $customer_id, '' );
		if ( isset( $this->failures[ $product_key ] ) ) {
			return $this->failures[ $product_key ];
		}

		foreach ( $this->failures as $record ) {
			if ( $record['product_id'] === $product_id && $record['customer_id'] === $customer_id ) {
				return $record;
			}
		}

		return null;
	}

	/**
	 * Retrieve unique product IDs that experienced failures for a specific customer.
	 *
	 * Passing an integer (e.g. 0 for guest, 12 for a user) returns only product IDs failed for that customer.
	 * Passing null returns all failed product IDs across all customers.
	 *
	 * @param int|null $customer_id Optional customer ID filter (0 is guest, null is wildcard).
	 * @return int[] Unique list of product IDs.
	 */
	public function get_failed_product_ids( ?int $customer_id = null ): array {
		$ids = [];
		foreach ( $this->failures as $record ) {
			if ( null !== $customer_id && $record['customer_id'] !== $customer_id ) {
				continue;
			}
			$ids[ $record['product_id'] ] = true;
		}
		return array_keys( $ids );
	}

	/**
	 * Retrieve all failed product IDs across all customers (explicit wildcard selector).
	 *
	 * @return int[] Unique list of product IDs.
	 */
	public function get_all_failed_product_ids(): array {
		return $this->get_failed_product_ids( null );
	}

	/**
	 * Reset all failures for a specific customer identity (e.g. on customer login/logout).
	 *
	 * Customer ID 0 resets strictly guest failures; it never touches authenticated customers.
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
	 * Check if any failure has been recorded across any customer identity.
	 *
	 * @return bool True if failure tracker contains any records.
	 */
	public function has_any_failure(): bool {
		return ! empty( $this->failures );
	}

	/**
	 * Check if a specific customer has any recorded failures.
	 *
	 * @param int $customer_id Customer user ID (0 for guest).
	 * @return bool True if customer has failures.
	 */
	public function has_customer_failures( int $customer_id ): bool {
		foreach ( $this->failures as $record ) {
			if ( $record['customer_id'] === $customer_id ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Retrieve all recorded failures across all contexts (diagnostic method).
	 *
	 * @return array<string, array{ error_code: string, message: string, product_id: int, customer_id: int, cart_item_key: string, timestamp: int }>
	 */
	public function get_all_failures(): array {
		return $this->failures;
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
