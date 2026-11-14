<?php

declare(strict_types=1);

namespace Tierza\Integration\WooCommerce;

use Tierza\Application\Pricing\PricingFailureTracker;
use Tierza\Domain\Exceptions\InvalidMoneyException;
use Tierza\Domain\Exceptions\InvalidPricingContextException;
use Tierza\Domain\Exceptions\PricingCalculationException;
use Tierza\Infrastructure\Exceptions\DatabaseQueryException;
use Throwable;
use WC_Cart;
use WC_Product;
use WP_Error;
use WP_User;

/**
 * WooCommerce integration layer wiring CartPricingAdapter into WooCommerce classic cart and checkout hooks.
 *
 * Hook Map:
 * - woocommerce_before_calculate_totals: Recalculates cart item prices with reentrancy protection and batch prefetch.
 * - woocommerce_cart_item_price: Formats cart item price HTML matching catalog strikethrough styling.
 * - woocommerce_cart_item_subtotal: Formats cart item subtotal HTML.
 * - woocommerce_after_checkout_validation: Revalidates prices at the checkout boundary, blocking checkout on failures.
 * - woocommerce_check_cart_items: Emits shopper notices on cart/checkout pages if pricing errors exist.
 * - wp_login / wp_logout: Invalidates runtime caches and triggers recalculation upon customer identity changes.
 * - woocommerce_get_cart_item_from_session: Restores Tierza pricing metadata upon cart session hydration.
 */
class CartPricingIntegration {

	private CartPricingAdapter $adapter;
	private PricingFailureTracker $failure_tracker;
	private bool $is_calculating = false;

	public function __construct( CartPricingAdapter $adapter, ?PricingFailureTracker $failure_tracker = null ) {
		$this->adapter         = $adapter;
		$this->failure_tracker = $failure_tracker ?? $adapter->get_failure_tracker();
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
		add_action( 'woocommerce_check_cart_items', [ $this, 'on_check_cart_items' ], 10 );
		add_action( 'wp_login', [ $this, 'on_user_login' ], 10, 2 );
		add_action( 'wp_logout', [ $this, 'on_user_logout' ], 10 );
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
			// 1. Batch-prefetch candidate rules for all items in 1 single query.
			$this->adapter->prefetch_cart( $cart_contents );

			// 2. Iterate and apply authoritative price to each cart item.
			foreach ( $cart->cart_contents as $key => &$cart_item ) {
				$product = $cart_item['data'] ?? null;
				if ( ! $product instanceof WC_Product ) {
					continue;
				}

				$pid = (int) $product->get_id();

				try {
					$this->adapter->apply_cart_item_pricing( $cart_item );
				} catch ( Throwable $e ) {
					$code         = $this->classify_exception( $e );
					$safe_message = $this->sanitize_error_message( $e );

					$this->failure_tracker->record_failure( $pid, $code, $safe_message );
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

		$quantity = isset( $cart_item['quantity'] ) ? max( 1, (int) $cart_item['quantity'] ) : 1;
		$raw_reg  = (string) $product->get_regular_price( 'edit' );
		$price    = (string) $product->get_price( 'edit' );

		if ( '' === $raw_reg || ! \Tierza\Domain\ValueObjects\DecimalMath::is_valid( $raw_reg ) ) {
			return $subtotal_html;
		}
		if ( '' === $price || ! \Tierza\Domain\ValueObjects\DecimalMath::is_valid( $price ) ) {
			return $subtotal_html;
		}

		$decimals   = $this->adapter->get_product_adapter()->get_store_decimals();
		$price_args = [ 'decimals' => $decimals ];

		$reg_subtotal   = \Tierza\Domain\ValueObjects\DecimalMath::mul( $raw_reg, (string) $quantity, $decimals );
		$final_subtotal = \Tierza\Domain\ValueObjects\DecimalMath::mul( $price, (string) $quantity, $decimals );

		if ( \Tierza\Domain\ValueObjects\DecimalMath::comp( $reg_subtotal, $final_subtotal, $decimals ) > 0 ) {
			if ( function_exists( 'wc_format_sale_price' ) && function_exists( 'wc_price' ) ) {
				$formatted_reg   = wc_price( $reg_subtotal, $price_args );
				$formatted_final = wc_price( $final_subtotal, $price_args );
				return wc_format_sale_price( $formatted_reg, $formatted_final );
			}
		}

		if ( function_exists( 'wc_price' ) ) {
			return wc_price( $final_subtotal, $price_args );
		}

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

		$has_failure = false;
		foreach ( $cart_contents as $item ) {
			$product = $item['data'] ?? null;
			if ( $product instanceof WC_Product && $this->failure_tracker->has_failed( (int) $product->get_id() ) ) {
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
		CartPricingAdapter::clear_cart_priced_registry();
		$this->adapter->get_product_adapter()->clear_runtime_cache();
		$this->failure_tracker->reset();

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
		CartPricingAdapter::clear_cart_priced_registry();
		$this->adapter->get_product_adapter()->clear_runtime_cache();
		$this->failure_tracker->reset();

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
