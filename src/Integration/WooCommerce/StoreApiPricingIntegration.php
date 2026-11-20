<?php

declare(strict_types=1);

namespace Tierza\Integration\WooCommerce;

use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;
use Automattic\WooCommerce\StoreApi\Exceptions\InvalidCartException;
use Throwable;
use Tierza\Application\Pricing\PricingFailureTracker;
use Tierza\Domain\ValueObjects\DecimalMath;
use WC_Cart;
use WC_Order;
use WC_Order_Item_Product;
use WC_Product;
use WP_Error;

/**
 * Connects CartPricingAdapter with modern WooCommerce Blocks and Store API lifecycle hooks.
 *
 * Reuses existing pricing engine, rule resolver, price calculator, batch prefetcher,
 * and fail-closed security boundary without implementing a parallel pricing engine.
 *
 * Hook interactions:
 * - 'woocommerce_store_api_cart_errors': blocks checkout and cart processing if pricing validation fails.
 * - 'woocommerce_store_api_checkout_update_order_meta': validates draft order creation/update against failure tracker.
 * - 'woocommerce_store_api_checkout_order_processed': authoritative order line item verification right before payment.
 */
class StoreApiPricingIntegration {

	private CartPricingAdapter $adapter;
	private PricingFailureTracker $failure_tracker;
	private bool $is_revalidating = false;

	public function __construct( CartPricingAdapter $adapter, PricingFailureTracker $failure_tracker ) {
		$this->adapter         = $adapter;
		$this->failure_tracker = $failure_tracker;
	}

	public function get_adapter(): CartPricingAdapter {
		return $this->adapter;
	}

	public function get_failure_tracker(): PricingFailureTracker {
		return $this->failure_tracker;
	}

	/**
	 * Register modern WooCommerce Store API and Blocks integration hooks.
	 */
	public function register(): void {
		add_action( 'woocommerce_store_api_cart_errors', [ $this, 'on_store_api_cart_errors' ], 10, 2 );
		add_action( 'woocommerce_store_api_checkout_update_order_meta', [ $this, 'on_store_api_checkout_update_order_meta' ], 10, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', [ $this, 'on_store_api_checkout_order_processed' ], 10, 1 );
	}

	/**
	 * Unregister Store API and Blocks integration hooks.
	 */
	public function unregister(): void {
		remove_action( 'woocommerce_store_api_cart_errors', [ $this, 'on_store_api_cart_errors' ], 10 );
		remove_action( 'woocommerce_store_api_checkout_update_order_meta', [ $this, 'on_store_api_checkout_update_order_meta' ], 10 );
		remove_action( 'woocommerce_store_api_checkout_order_processed', [ $this, 'on_store_api_checkout_order_processed' ], 10 );
	}

	/**
	 * Validate cart items during Store API cart validation.
	 *
	 * Hooked to 'woocommerce_store_api_cart_errors'.
	 *
	 * @param mixed $cart_errors WP_Error instance.
	 * @param mixed $cart WC_Cart instance.
	 */
	public function on_store_api_cart_errors( mixed $cart_errors, mixed $cart ): void {
		if ( ! $cart_errors instanceof WP_Error || ! $cart instanceof WC_Cart ) {
			return;
		}

		if ( $this->is_revalidating ) {
			return;
		}

		$cart_contents = $cart->get_cart();
		if ( empty( $cart_contents ) ) {
			return;
		}

		$this->is_revalidating = true;

		try {
			$customer_id = $this->adapter->resolve_customer_id();

			// 1. Authoritative recovery: if this customer has prior unresolved failures,
			// attempt authoritative recalculation to allow automatic recovery when repository/calculator is restored.
			if ( $this->failure_tracker->has_customer_failures( $customer_id ) ) {
				$prefetch_failed = false;
				try {
					$this->adapter->prefetch_cart( $cart_contents );
				} catch ( Throwable $e ) {
					$prefetch_failed = true;
					$code            = CartPricingAdapter::classify_exception( $e );
					$safe_message    = CartPricingAdapter::get_safe_error_message( $code );
					$this->log_diagnostic_error( 'Store API cart recovery prefetch failed', $code, PricingFailureTracker::redact_sensitive_text( $e->getMessage() ) );

					foreach ( $cart_contents as $key => $item ) {
						$p   = $item['data'] ?? null;
						$pid = $p instanceof WC_Product ? (int) $p->get_id() : 0;
						$this->failure_tracker->record_failure( $pid, $code, $safe_message, $customer_id, (string) $key );
					}
				}

				foreach ( $cart->cart_contents as $key => &$cart_item ) {
					$product = $cart_item['data'] ?? null;
					if ( ! $product instanceof WC_Product || $product->get_id() <= 0 ) {
						$this->failure_tracker->record_failure( 0, PricingFailureTracker::CODE_INVALID_CONTEXT, CartPricingAdapter::get_safe_error_message( 'invalid_order_item_product' ), $customer_id, (string) $key );
						continue;
					}

					$pid = (int) $product->get_id();

					try {
						$this->adapter->apply_cart_item_pricing( $cart_item, $customer_id );
						// Clear discrete line failure only when recalculation and batch prefetch both succeed:
						if ( ! $prefetch_failed ) {
							$this->failure_tracker->clear_failure( $pid, $customer_id, (string) $key );
						}
					} catch ( Throwable $e ) {
						$code         = CartPricingAdapter::classify_exception( $e );
						$safe_message = CartPricingAdapter::get_safe_error_message( $code );
						$this->failure_tracker->record_failure( $pid, $code, $safe_message, $customer_id, (string) $key );
						$this->log_diagnostic_error( sprintf( 'Store API cart recovery recalculation failed on product #%d', $pid ), $code, PricingFailureTracker::redact_sensitive_text( $e->getMessage() ) );
					}
				}
				unset( $cart_item );
			}

			// 2. Authoritative revalidation: verify every cart line against current rules and check failure tracker.
			$report = $this->adapter->revalidate_checkout( $cart, $customer_id );
			if ( ! $report['valid'] ) {
				$code    = ! empty( $report['errors'][0]['code'] ) ? (string) $report['errors'][0]['code'] : 'tierza_checkout_pricing_failed';
				$message = CartPricingAdapter::get_safe_error_message( 'tierza_checkout_pricing_failed' );
				$cart_errors->add( 'tierza_checkout_pricing_failed', $message );
				$this->emit_checkout_failure( $cart, $report['errors'] );
				$this->log_diagnostic_error( 'Store API cart validation failed', $code );
			}
		} finally {
			$this->is_revalidating = false;
		}
	}

	/**
	 * Defense-in-depth barrier upon draft order creation or update in Store API checkout.
	 *
	 * Hooked to 'woocommerce_store_api_checkout_update_order_meta'.
	 *
	 * @param mixed $order WC_Order instance.
	 * @throws RouteException|Throwable When pricing failure exists.
	 */
	public function on_store_api_checkout_update_order_meta( mixed $order ): void {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$customer_id      = (int) $order->get_customer_id();
		$authenticated_id = $this->adapter->resolve_customer_id();
		if ( $customer_id <= 0 || ( $authenticated_id > 0 && $customer_id !== $authenticated_id ) ) {
			$customer_id = $authenticated_id;
		}

		if ( $this->failure_tracker->has_customer_failures( $customer_id ) ) {
			$code    = 'unresolved_pricing_failure';
			$message = CartPricingAdapter::get_safe_error_message( $code );
			$this->emit_checkout_failure(
				$order,
				[
					[
						'code'          => $code,
						'message'       => $message,
						'product_id'    => 0,
						'cart_item_key' => '',
					],
				]
			);
			$this->log_diagnostic_error( 'Store API draft order update blocked: unresolved failure in tracker', $code );
			$this->throw_store_api_exception( 'tierza_checkout_pricing_failed', $message, 400 );
		}
	}

	/**
	 * Final authoritative order line item verification right before Store API payment processing.
	 *
	 * Hooked to 'woocommerce_store_api_checkout_order_processed'.
	 *
	 * @param mixed $order WC_Order instance.
	 * @throws RouteException|Throwable When pricing discrepancy or unresolved failure is detected.
	 */
	public function on_store_api_checkout_order_processed( mixed $order ): void {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$customer_id      = (int) $order->get_customer_id();
		$authenticated_id = $this->adapter->resolve_customer_id();
		if ( $customer_id <= 0 || ( $authenticated_id > 0 && $customer_id !== $authenticated_id ) ) {
			$customer_id = $authenticated_id;
		}

		// 1. Barrier: check failure tracker for unresolved failures:
		if ( $this->failure_tracker->has_customer_failures( $customer_id ) ) {
			$code    = 'unresolved_pricing_failure';
			$message = CartPricingAdapter::get_safe_error_message( $code );
			$this->emit_checkout_failure(
				$order,
				[
					[
						'code'          => $code,
						'message'       => $message,
						'product_id'    => 0,
						'cart_item_key' => '',
					],
				]
			);
			$this->log_diagnostic_error( 'Store API checkout order processed blocked: unresolved failure in tracker', $code );
			$this->throw_store_api_exception( 'tierza_checkout_pricing_failed', $message, 400 );
		}

		// 2. Fresh verification of every order line item against authoritative Tierza price:
		$discrepancies      = [];
		$decimals           = $this->adapter->get_product_adapter()->get_store_decimals();
		$prices_include_tax = function_exists( 'wc_prices_include_tax' ) && wc_prices_include_tax();

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			$product_id   = (int) $item->get_product_id();
			$variation_id = (int) $item->get_variation_id();
			$target_id    = $variation_id > 0 ? $variation_id : $product_id;
			$product      = $item->get_product();

			if ( ! $product instanceof WC_Product || $product->get_id() <= 0 ) {
				$discrepancies[] = [
					'code'          => PricingFailureTracker::CODE_INVALID_CONTEXT,
					'message'       => CartPricingAdapter::get_safe_error_message( 'invalid_order_item_product' ),
					'product_id'    => $target_id,
					'cart_item_key' => '',
				];
				continue;
			}

			try {
				$expected_unit_price = $this->adapter->get_product_adapter()->get_adjusted_price( $product, $customer_id );
				$qty                 = max( 1, (int) $item->get_quantity() );
				$expected_subtotal   = DecimalMath::mul( $expected_unit_price, (string) $qty, $decimals );

				// Line subtotal reconciliation:
				// Subtotal is pre-coupon, so order-level/item coupons do not cause false price discrepancy.
				// In tax-inclusive stores, $item->get_subtotal() is net of tax; add tax to compare with tax-inclusive unit price.
				if ( $prices_include_tax ) {
					$tax_amount      = (string) $item->get_subtotal_tax();
					$subtotal_tax    = '' !== $tax_amount ? $tax_amount : '0';
					$actual_raw      = DecimalMath::add( (string) $item->get_subtotal(), $subtotal_tax, $decimals );
					$actual_subtotal = wc_format_decimal( $actual_raw, $decimals );
				} else {
					$actual_subtotal = wc_format_decimal( (string) $item->get_subtotal(), $decimals );
				}

				if ( 0 !== DecimalMath::comp( $actual_subtotal, $expected_subtotal, $decimals ) ) {
					$discrepancies[] = [
						'code'          => PricingFailureTracker::CODE_CALCULATION_FAILED,
						'message'       => CartPricingAdapter::get_safe_error_message( 'order_price_mismatch' ),
						'product_id'    => $target_id,
						'cart_item_key' => '',
					];
				}
			} catch ( Throwable $e ) {
				$code            = CartPricingAdapter::classify_exception( $e );
				$discrepancies[] = [
					'code'          => $code,
					'message'       => CartPricingAdapter::get_safe_error_message( $code ),
					'product_id'    => $target_id,
					'cart_item_key' => '',
				];
				$this->log_diagnostic_error(
					'Store API order line verification failed',
					$code,
					PricingFailureTracker::redact_sensitive_text( $e->getMessage() )
				);
			}
		}

		if ( ! empty( $discrepancies ) ) {
			$this->emit_checkout_failure( $order, $discrepancies );
			$this->log_diagnostic_error( 'Store API checkout order rejected due to price discrepancy', 'price_discrepancy' );
			$this->throw_store_api_exception(
				'tierza_checkout_pricing_failed',
				CartPricingAdapter::get_safe_error_message( 'order_price_mismatch' ),
				400
			);
		}
	}

	/**
	 * Throw a Store API RouteException if available, or RuntimeException fallback.
	 *
	 * @param string $code Error code.
	 * @param string $message Safe, translatable message.
	 * @param int    $status HTTP status code.
	 * @return never
	 * @throws RouteException|Throwable
	 */
	private function throw_store_api_exception( string $code, string $message, int $status = 400 ): never {
		if ( class_exists( RouteException::class ) ) {
			throw new RouteException( $code, $message, $status );
		}

		throw new \RuntimeException( $message, $status );
	}

	/**
	 * Emit checkout failure hook with structured, redacted error records.
	 *
	 * @param mixed                                                                                   $context WC_Cart or WC_Order instance.
	 * @param array<int, array{code: string, message: string, product_id?: int, cart_item_key?: string}> $errors Array of structured error records.
	 */
	private function emit_checkout_failure( mixed $context, array $errors ): void {
		/**
		 * Action triggered when Tierza pricing validation blocks Store API checkout.
		 *
		 * @param mixed                                                                                   $context WC_Cart or WC_Order instance.
		 * @param array<int, array{code: string, message: string, product_id?: int, cart_item_key?: string}> $errors Safe structured errors.
		 */
		do_action( 'tierza_checkout_pricing_validation_failed', $context, $errors );
	}

	/**
	 * Log diagnostic error internally via wc_get_logger() with redaction.
	 *
	 * @param string $message High-level error summary.
	 * @param string $code Machine-readable classification code.
	 * @param string $details Additional diagnostic details (already redacted).
	 */
	private function log_diagnostic_error( string $message, string $code, string $details = '' ): void {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		$logger = wc_get_logger();
		if ( ! method_exists( $logger, 'error' ) ) {
			return;
		}

		$context = [
			'source'  => 'tierza',
			'code'    => $code,
			'details' => PricingFailureTracker::redact_sensitive_text( $details ),
		];

		$logger->error( $message, $context );
	}
}
