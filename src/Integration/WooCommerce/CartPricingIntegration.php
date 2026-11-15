<?php

declare(strict_types=1);

namespace Tierza\Integration\WooCommerce;

use Throwable;
use Tierza\Application\Pricing\PricingFailureTracker;
use Tierza\Domain\Exceptions\InvalidMoneyException;
use Tierza\Domain\Exceptions\InvalidPricingContextException;
use Tierza\Domain\Exceptions\PricingCalculationException;
use Tierza\Infrastructure\Exceptions\DatabaseQueryException;
use WC_Cart;
use WC_Product;
use WP_Error;
use WP_User;

/**
 * Connects CartPricingAdapter with the WooCommerce cart & checkout lifecycle hooks.
 *
 * Hook interactions:
 * - 'woocommerce_before_calculate_totals': calculates authoritative line prices before totals.
 * - 'woocommerce_cart_item_price': formats unit price with strikethrough.
 * - 'woocommerce_cart_item_subtotal': formats line subtotal with tax and strikethrough.
 * - 'woocommerce_after_checkout_validation': blocks order creation on unresolved failures or discrepancies.
 * - 'woocommerce_check_cart_items': displays customer-facing error notices.
 * - 'wp_login' & 'wp_logout': handles session and customer identity transitions.
 * - 'woocommerce_get_cart_item_from_session': safely restores session data without trusting stale prices.
 */
class CartPricingIntegration {

	private CartPricingAdapter $adapter;
	private PricingFailureTracker $failure_tracker;
	private bool $is_calculating = false;

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
	 * Register all WooCommerce cart and checkout hooks.
	 */
	public function register(): void {
		add_action( 'woocommerce_before_calculate_totals', [ $this, 'on_before_calculate_totals' ], 10, 1 );
		add_filter( 'woocommerce_cart_item_price', [ $this, 'filter_cart_item_price' ], 10, 3 );
		add_filter( 'woocommerce_cart_item_subtotal', [ $this, 'filter_cart_item_subtotal' ], 10, 3 );
		add_action( 'woocommerce_after_checkout_validation', [ $this, 'on_after_checkout_validation' ], 10, 2 );
		add_action( 'woocommerce_check_cart_items', [ $this, 'on_check_cart_items' ], 10, 0 );
		add_action( 'wp_login', [ $this, 'on_user_login' ], 10, 2 );
		add_action( 'wp_logout', [ $this, 'on_user_logout' ], 10, 0 );
		add_filter( 'woocommerce_get_cart_item_from_session', [ $this, 'on_get_cart_item_from_session' ], 10, 3 );
	}

	/**
	 * Unregister all WooCommerce cart and checkout hooks.
	 */
	public function unregister(): void {
		remove_action( 'woocommerce_before_calculate_totals', [ $this, 'on_before_calculate_totals' ], 10 );
		remove_filter( 'woocommerce_cart_item_price', [ $this, 'filter_cart_item_price' ], 10 );
		remove_filter( 'woocommerce_cart_item_subtotal', [ $this, 'filter_cart_item_subtotal' ], 10 );
		remove_action( 'woocommerce_after_checkout_validation', [ $this, 'on_after_checkout_validation' ], 10 );
		remove_action( 'woocommerce_check_cart_items', [ $this, 'on_check_cart_items' ], 10 );
		remove_action( 'wp_login', [ $this, 'on_user_login' ], 10 );
		remove_action( 'wp_logout', [ $this, 'on_user_logout' ], 10 );
		remove_filter( 'woocommerce_get_cart_item_from_session', [ $this, 'on_get_cart_item_from_session' ], 10 );
	}

	/**
	 * Recalculate cart item prices prior to WooCommerce totals calculation.
	 *
	 * Hooked to 'woocommerce_before_calculate_totals'.
	 *
	 * @param mixed $cart WC_Cart instance.
	 */
	public function on_before_calculate_totals( mixed $cart ): void {
		if ( ! $cart instanceof WC_Cart ) {
			return;
		}

		// Reentrancy guard: prevent infinite recursion if calculate_totals is called within.
		if ( $this->is_calculating ) {
			return;
		}

		// Prevent execution during admin product editing or bulk imports.
		if ( is_admin() && ! wp_doing_ajax() ) {
			global $pagenow;
			if ( in_array( $pagenow, [ 'post.php', 'post-new.php', 'edit.php' ], true ) ) {
				return;
			}
			if ( defined( 'WC_DOING_PRODUCT_IMPORT' ) && WC_DOING_PRODUCT_IMPORT ) {
				return;
			}
		}

		$cart_contents = $cart->get_cart();
		if ( empty( $cart_contents ) ) {
			return;
		}

		$this->is_calculating = true;

		try {
			$customer_id     = $this->adapter->resolve_customer_id();
			$prefetch_failed = false;

			// 1. Batch-prefetch candidate rules for all items with explicit error trapping.
			try {
				$this->adapter->prefetch_cart( $cart_contents );
			} catch ( Throwable $e ) {
				$prefetch_failed = true;
				$code            = $this->classify_exception( $e );
				$safe_message    = $this->sanitize_error_message( $e );
				$this->log_error( 'Batch prefetch failed for cart contents', $code, $safe_message );

				// Mark all cart items as unverified in PricingFailureTracker:
				foreach ( $cart_contents as $key => $item ) {
					$p   = $item['data'] ?? null;
					$pid = $p instanceof WC_Product ? (int) $p->get_id() : 0;
					$this->failure_tracker->record_failure( $pid, $code, $safe_message, $customer_id, (string) $key );
				}

				if ( function_exists( 'do_action' ) ) {
					do_action( 'tierza_cart_prefetch_failed', $cart_contents, $code, $safe_message );
				}
			}

			// 2. Iterate and apply authoritative price to each cart item.
			foreach ( $cart->cart_contents as $key => &$cart_item ) {
				$product = $cart_item['data'] ?? null;
				if ( ! $product instanceof WC_Product || $product->get_id() <= 0 ) {
					$this->failure_tracker->record_failure( 0, PricingFailureTracker::CODE_INVALID_CONTEXT, 'Invalid cart item product data.', $customer_id, (string) $key );
					continue;
				}

				$pid = (int) $product->get_id();

				try {
					$this->adapter->apply_cart_item_pricing( $cart_item );
					// Authoritative recalculation succeeded only if batch prefetch succeeded:
					if ( ! $prefetch_failed ) {
						$this->failure_tracker->clear_failure( $pid, $customer_id, (string) $key );
					}
				} catch ( Throwable $e ) {
					$code         = $this->classify_exception( $e );
					$safe_message = $this->sanitize_error_message( $e );

					$this->failure_tracker->record_failure( $pid, $code, $safe_message, $customer_id, (string) $key );
					$this->log_error( 'Cart item pricing failed for product #' . $pid, $code, $safe_message );

					if ( function_exists( 'do_action' ) ) {
						do_action( 'tierza_cart_item_pricing_failed', $pid, $code, $safe_message, $key );
					}
				}
			}
			unset( $cart_item );
		} finally {
			$this->is_calculating = false;
		}
	}

	/**
	 * Filter cart item price HTML to match catalog formatting.
	 *
	 * Hooked to 'woocommerce_cart_item_price'.
	 *
	 * @param mixed $price_html    Original HTML.
	 * @param mixed $cart_item     Cart item payload.
	 * @param mixed $cart_item_key Cart item key.
	 * @return string Formatted price HTML.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	public function filter_cart_item_price( mixed $price_html, mixed $cart_item, mixed $cart_item_key ): string {
		if ( ! is_string( $price_html ) || ! is_array( $cart_item ) ) {
			return (string) $price_html;
		}

		return $this->adapter->format_cart_item_price_html( $price_html, $cart_item );
	}

	/**
	 * Filter cart item subtotal HTML.
	 *
	 * Shows standard strikethrough (<del>regular</del> <ins>discounted</ins>)
	 * while preserving WooCommerce tax display modes and coupon-adjusted subtotal values.
	 *
	 * Hooked to 'woocommerce_cart_item_subtotal'.
	 *
	 * @param mixed $subtotal_html Original HTML.
	 * @param mixed $cart_item     Cart item payload.
	 * @param mixed $cart_item_key Cart item key.
	 * @return string Formatted subtotal HTML.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	public function filter_cart_item_subtotal( mixed $subtotal_html, mixed $cart_item, mixed $cart_item_key ): string {
		if ( ! is_string( $subtotal_html ) || ! is_array( $cart_item ) ) {
			return (string) $subtotal_html;
		}

		$product = $cart_item['data'] ?? null;
		if ( ! $product instanceof WC_Product ) {
			return $subtotal_html;
		}

		$raw_reg = (string) $product->get_regular_price( 'edit' );
		$price   = (string) $product->get_price( 'edit' );

		if ( '' === $raw_reg || ! \Tierza\Domain\ValueObjects\DecimalMath::is_valid( $raw_reg ) ) {
			return $subtotal_html;
		}
		if ( '' === $price || ! \Tierza\Domain\ValueObjects\DecimalMath::is_valid( $price ) ) {
			return $subtotal_html;
		}

		$decimals = $this->adapter->get_product_adapter()->get_store_decimals();

		// Only show strike-through when discounted below regular price:
		if ( \Tierza\Domain\ValueObjects\DecimalMath::comp( $raw_reg, $price, $decimals ) > 0 ) {
			if ( function_exists( 'wc_format_sale_price' ) && function_exists( 'wc_price' ) ) {
				$quantity   = isset( $cart_item['quantity'] ) ? max( 1, (int) $cart_item['quantity'] ) : 1;
				$price_args = [ 'decimals' => $decimals ];

				if ( function_exists( 'wc_get_price_to_display' ) ) {
					$display_reg_subtotal = wc_get_price_to_display(
						$product,
						[
							'price' => $raw_reg,
							'qty'   => $quantity,
						]
					);
					$formatted_reg        = wc_price( $display_reg_subtotal, $price_args );
				} else {
					$reg_subtotal  = \Tierza\Domain\ValueObjects\DecimalMath::mul( $raw_reg, (string) $quantity, $decimals );
					$formatted_reg = wc_price( $reg_subtotal, $price_args );
				}

				return wc_format_sale_price( $formatted_reg, $subtotal_html );
			}
		}

		// When price equals regular price or is zero-discount, return native subtotal HTML:
		return $subtotal_html;
	}

	/**
	 * Checkout validation boundary: block checkout on any pricing failure or discrepancy.
	 *
	 * Hooked to 'woocommerce_after_checkout_validation'.
	 *
	 * @param mixed $data   Checkout post data.
	 * @param mixed $errors WP_Error instance.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
	public function on_after_checkout_validation( mixed $data, mixed $errors ): void {
		if ( ! $errors instanceof WP_Error ) {
			return;
		}

		if ( ! function_exists( 'WC' ) || ! isset( WC()->cart ) || ! WC()->cart instanceof WC_Cart ) {
			return;
		}

		$validation = $this->adapter->revalidate_checkout( WC()->cart );

		if ( ! $validation['valid'] ) {
			$errors->add(
				'tierza_checkout_pricing_error',
				__( 'A pricing verification error occurred with items in your cart. Please refresh your cart to ensure your order reflects current pricing.', 'tierza' )
			);

			if ( function_exists( 'do_action' ) ) {
				do_action(
					'tierza_checkout_pricing_validation_failed',
					$validation['failed_product_ids'],
					$validation['errors']
				);
			}
		}
	}

	/**
	 * Emits error notices on cart/checkout pages if unresolved pricing failures exist.
	 *
	 * Hooked to 'woocommerce_check_cart_items'.
	 */
	public function on_check_cart_items(): void {
		if ( ! function_exists( 'WC' ) || ! isset( WC()->cart ) || ! WC()->cart instanceof WC_Cart ) {
			return;
		}

		$cart_contents = WC()->cart->get_cart();
		if ( empty( $cart_contents ) ) {
			return;
		}

		$customer_id = $this->adapter->resolve_customer_id();
		$has_failure = false;
		foreach ( $cart_contents as $key => $item ) {
			$product = $item['data'] ?? null;
			$pid     = $product instanceof WC_Product ? (int) $product->get_id() : 0;
			if ( $this->failure_tracker->has_failed( $pid, $customer_id, (string) $key ) ) {
				$has_failure = true;
				break;
			}
		}

		if ( $has_failure && function_exists( 'wc_add_notice' ) ) {
			wc_add_notice(
				__( 'Some items in your cart encountered a pricing calculation error. Please reload the page before proceeding.', 'tierza' ),
				'error'
			);
		}
	}

	/**
	 * Handle customer authentication login transition.
	 *
	 * Hooked to 'wp_login'.
	 *
	 * @param string  $user_login Username.
	 * @param WP_User $user       WP_User instance.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	public function on_user_login( string $user_login, WP_User $user ): void {
		$uid = (int) $user->ID;
		$this->failure_tracker->reset_customer( $uid );
		$this->failure_tracker->reset_customer( 0 ); // guest
		CartPricingAdapter::clear_cart_priced_registry();
		$this->adapter->get_product_adapter()->clear_runtime_cache();

		if ( function_exists( 'WC' ) && isset( WC()->cart ) && WC()->cart instanceof WC_Cart ) {
			WC()->cart->calculate_totals();
		}
	}

	/**
	 * Handle customer logout transition.
	 *
	 * Hooked to 'wp_logout'.
	 */
	public function on_user_logout(): void {
		$this->failure_tracker->reset();
		CartPricingAdapter::clear_cart_priced_registry();
		$this->adapter->get_product_adapter()->clear_runtime_cache();

		if ( function_exists( 'WC' ) && isset( WC()->cart ) && WC()->cart instanceof WC_Cart ) {
			WC()->cart->calculate_totals();
		}
	}

	/**
	 * Hydrate Tierza pricing metadata upon cart item session restoration.
	 *
	 * Hooked to 'woocommerce_get_cart_item_from_session'.
	 *
	 * @param array<string, mixed> $session_data Cart item session data.
	 * @param array<string, mixed> $values       Original values array.
	 * @param string               $key          Cart item key.
	 * @return array<string, mixed> Hydrated session data.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	public function on_get_cart_item_from_session( array $session_data, array $values, string $key ): array {
		if ( isset( $values['tierza_pricing'] ) && is_array( $values['tierza_pricing'] ) ) {
			$session_data['tierza_pricing'] = $values['tierza_pricing'];
		}
		return $session_data;
	}

	/**
	 * Classify exception into machine-readable safe error code.
	 *
	 * @param Throwable $e Exception instance.
	 * @return string Error code constant.
	 */
	private function classify_exception( Throwable $e ): string {
		if ( $e instanceof DatabaseQueryException ) {
			return PricingFailureTracker::CODE_REPOSITORY_UNAVAILABLE;
		}
		if ( $e instanceof PricingCalculationException ) {
			return PricingFailureTracker::CODE_CALCULATION_FAILED;
		}
		if ( $e instanceof InvalidPricingContextException || $e instanceof InvalidMoneyException ) {
			return PricingFailureTracker::CODE_INVALID_CONTEXT;
		}
		return PricingFailureTracker::CODE_UNEXPECTED;
	}

	/**
	 * Sanitize exception message to avoid exposing SQL, credentials, or internal paths.
	 *
	 * @param Throwable $e Exception instance.
	 * @return string Sanitized error message.
	 */
	private function sanitize_error_message( Throwable $e ): string {
		$message = $e->getMessage();
		$message = (string) preg_replace( '/[a-zA-Z0-9_\-\.\/\\\\]+\.(php|sql|log)/i', '[file]', $message );
		$message = (string) preg_replace( '/(password|secret|token|key|hash)\s*=\s*[^\s,]+/i', '$1=[redacted]', $message );
		$message = (string) preg_replace( '/wp_[a-zA-Z0-9_]+/i', '[table]', $message );
		return substr( trim( $message ), 0, 255 );
	}

	/**
	 * Safe internal error logging without leaking sensitive credentials.
	 *
	 * @param string $context Context description.
	 * @param string $code    Safe machine-readable error code.
	 * @param string $message Sanitized message.
	 */
	private function log_error( string $context, string $code, string $message ): void {
		if ( function_exists( 'wc_get_logger' ) ) {
			try {
				$logger = wc_get_logger();
				if ( method_exists( $logger, 'error' ) ) {
					$logger->error(
						sprintf( '[Tierza Cart] %s | Code: %s | Message: %s', $context, $code, $message ),
						[ 'source' => 'tierza-cart-pricing' ]
					);
				}
			} catch ( Throwable $e ) {
				unset( $e );
				// Prevent logging failures from halting application execution.
			}
		}
	}
}
