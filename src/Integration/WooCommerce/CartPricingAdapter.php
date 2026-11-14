<?php

declare(strict_types=1);

namespace Tierza\Integration\WooCommerce;

use Tierza\Application\Pricing\Batch\PricingBatchPrefetcherInterface;
use Tierza\Application\Pricing\PricingFailureTracker;
use Tierza\Domain\Entities\PricingGroup;
use Tierza\Domain\Exceptions\InvalidMoneyException;
use Tierza\Domain\Pricing\Contracts\PriceCalculatorInterface;
use Tierza\Domain\Pricing\Contracts\PricingCacheInterface;
use Tierza\Domain\Pricing\Contracts\RuleResolverInterface;
use Tierza\Domain\Pricing\PricingContext;
use Tierza\Domain\Pricing\PricingResult;
use Tierza\Domain\ValueObjects\DecimalMath;
use Tierza\Domain\ValueObjects\Money;
use WC_Cart;
use WC_Product;
use WC_Product_Variation;

/**
 * Authoritative adapter connecting Tierza pricing engine with WooCommerce Cart and Checkout.
 *
 * Responsibilities:
 * 1. Deterministic reconstruction of PricingContext for cart items including line quantity.
 * 2. Unfiltered original active price preservation (preventing double discounting across recalculations).
 * 3. Authoritative price application to cart item instances ($cart_item['data']->set_price()).
 * 4. High-performance batch prefetching across heterogeneous cart lines.
 * 5. Time-of-check / time-of-use revalidation at the checkout boundary (preventing order creation with invalid prices).
 */
class CartPricingAdapter {

	private ProductPricingAdapter $product_adapter;
	private PriceCalculatorInterface $price_calculator;
	private RuleResolverInterface $rule_resolver;
	private PricingCacheInterface $cache;
	private PricingFailureTracker $failure_tracker;
	private ?PricingBatchPrefetcherInterface $batch_prefetcher;
	private string $math_driver;

	/** @var array<int, bool> */
	private static array $cart_priced_product_ids = [];

	/**
	 * Mark a product instance as authoritatively priced for cart calculation.
	 *
	 * @param WC_Product $product Product instance.
	 */
	public static function mark_as_cart_priced( WC_Product $product ): void {
		self::$cart_priced_product_ids[ spl_object_id( $product ) ] = true;
	}

	/**
	 * Check whether a product instance has already been priced by the cart adapter.
	 *
	 * @param WC_Product $product Product instance.
	 * @return bool True if already priced for cart.
	 */
	public static function is_cart_priced( WC_Product $product ): bool {
		return isset( self::$cart_priced_product_ids[ spl_object_id( $product ) ] );
	}

	/**
	 * Reset cart-priced product registry.
	 */
	public static function clear_cart_priced_registry(): void {
		self::$cart_priced_product_ids = [];
	}

	public function __construct(
		ProductPricingAdapter $product_adapter,
		PriceCalculatorInterface $price_calculator,
		RuleResolverInterface $rule_resolver,
		PricingCacheInterface $cache,
		PricingFailureTracker $failure_tracker,
		?PricingBatchPrefetcherInterface $batch_prefetcher = null,
		string $math_driver = 'auto'
	) {
		$this->product_adapter  = $product_adapter;
		$this->price_calculator = $price_calculator;
		$this->rule_resolver    = $rule_resolver;
		$this->cache            = $cache;
		$this->failure_tracker  = $failure_tracker;
		$this->batch_prefetcher = $batch_prefetcher;
		$this->math_driver      = $math_driver;
	}

	public function get_product_adapter(): ProductPricingAdapter {
		return $this->product_adapter;
	}

	public function get_failure_tracker(): PricingFailureTracker {
		return $this->failure_tracker;
	}

	public function get_cache(): PricingCacheInterface {
		return $this->cache;
	}

	/**
	 * Establish current trusted customer ID.
	 *
	 * Delegates strictly to ProductPricingAdapter, which derives from authenticated
	 * WordPress session in production, rejecting forged client overrides.
	 *
	 * @param int|null $explicit_id Optional customer ID (permitted in test mode only).
	 * @return int Positive user ID or 0 for guest.
	 */
	public function resolve_customer_id( ?int $explicit_id = null ): int {
		return $this->product_adapter->resolve_customer_id( $explicit_id );
	}

	/**
	 * Establish customer group for pricing.
	 *
	 * @param int $customer_id Customer user ID.
	 * @return PricingGroup|null Active group entity or null.
	 */
	public function resolve_customer_group( int $customer_id ): ?PricingGroup {
		return $this->product_adapter->resolve_customer_group( $customer_id );
	}

	/**
	 * Retrieve unfiltered original store price for a cart item product.
	 *
	 * Critical for idempotency: Repeated calls to WC_Cart::calculate_totals() or
	 * quantity changes must never apply discounts on top of previously discounted prices.
	 *
	 * In WooCommerce, calling $product->set_price() modifies the active price property
	 * on the cart product instance, but regular_price and sale_price properties are preserved.
	 *
	 * @param WC_Product           $product   Cart line item product instance.
	 * @param array<string, mixed> $cart_item Cart line item payload.
	 * @return string Arbitrary-precision decimal string.
	 * @throws InvalidMoneyException If monetary value cannot be extracted or is malformed.
	 */
	public function get_unfiltered_base_price( WC_Product $product, array $cart_item ): string {
		// 1. If previously recorded base price is present on the cart item, verify against product regular price:
		$raw_reg  = (string) $product->get_regular_price( 'edit' );
		$raw_sale = (string) $product->get_sale_price( 'edit' );

		// Validate syntax if present.
		if ( '' !== $raw_reg && ! DecimalMath::is_valid( $raw_reg ) ) {
			throw new InvalidMoneyException(
				sprintf( 'Product #%d has invalid regular price "%s".', $product->get_id(), $raw_reg )
			);
		}
		if ( '' !== $raw_sale && ! DecimalMath::is_valid( $raw_sale ) ) {
			throw new InvalidMoneyException(
				sprintf( 'Product #%d has invalid sale price "%s".', $product->get_id(), $raw_sale )
			);
		}

		// 2. Active scheduled promotional sale awareness.
		$is_native_sale = $this->product_adapter->is_product_on_sale( $product, $raw_reg, $raw_sale );
		if ( $is_native_sale && '' !== $raw_sale ) {
			return $raw_sale;
		}

		if ( '' !== $raw_reg ) {
			return $raw_reg;
		}

		// 3. For custom items or products without regular_price set, use preserved original price if present.
		if ( isset( $cart_item['tierza_pricing']['base_price'] ) && is_string( $cart_item['tierza_pricing']['base_price'] ) ) {
			return $cart_item['tierza_pricing']['base_price'];
		}

		$raw_price = (string) $product->get_price( 'edit' );
		if ( '' !== $raw_price && DecimalMath::is_valid( $raw_price ) ) {
			return $raw_price;
		}

		throw new InvalidMoneyException(
			sprintf( 'Unable to determine unfiltered base price for cart product #%d.', $product->get_id() )
		);
	}

	/**
	 * Construct a strongly typed PricingContext for a WooCommerce cart line item.
	 *
	 * Incorporates line quantity, variation hierarchy, categories, and trusted identity.
	 *
	 * @param array<string, mixed> $cart_item   Cart item array from WC_Cart.
	 * @param int|null             $customer_id Optional customer ID override (test mode only).
	 * @return PricingContext|null Strongly typed context or null if unpriced.
	 */
	public function get_cart_item_pricing_context( array $cart_item, ?int $customer_id = null ): ?PricingContext {
		$product = $cart_item['data'] ?? null;
		if ( ! $product instanceof WC_Product ) {
			return null;
		}

		$raw_base_price = $this->get_unfiltered_base_price( $product, $cart_item );
		$raw_reg        = (string) $product->get_regular_price( 'edit' );
		$raw_sale       = (string) $product->get_sale_price( 'edit' );

		// Establish product and variation relationship.
		$variation_id = null;
		if ( $product instanceof WC_Product_Variation ) {
			$variation_id = (int) $product->get_id();
			$product_id   = (int) $product->get_parent_id();
			if ( $product_id <= 0 ) {
				$product_id   = $variation_id;
				$variation_id = null;
			}
		} else {
			$product_id = (int) $product->get_id();
		}

		if ( $product_id <= 0 ) {
			return null;
		}

		// Establish customer identity and validated group.
		$resolved_customer_id = $this->resolve_customer_id( $customer_id );
		$resolved_group       = $this->resolve_customer_group( $resolved_customer_id );

		// Category taxonomy mapping with depth hierarchy.
		[ $category_ids, $category_depths ] = $this->product_adapter->get_category_data( $product_id );

		// Currency and decimal precision bounds.
		$currency = $this->product_adapter->get_store_currency();
		$decimals = $this->product_adapter->get_store_decimals();

		$is_native_sale = $this->product_adapter->is_product_on_sale( $product, $raw_reg, $raw_sale );

		$base_price    = Money::from( $raw_base_price, $currency );
		$regular_price = ( '' !== $raw_reg && DecimalMath::is_valid( $raw_reg ) ) ? Money::from( $raw_reg, $currency ) : null;
		$sale_price    = ( $is_native_sale && '' !== $raw_sale && DecimalMath::is_valid( $raw_sale ) ) ? Money::from( $raw_sale, $currency ) : null;

		$quantity = isset( $cart_item['quantity'] ) ? max( 1, (int) $cart_item['quantity'] ) : 1;

		return new PricingContext(
			product_id: $product_id,
			base_price: $base_price,
			customer_id: $resolved_customer_id,
			group: $resolved_group,
			variation_id: $variation_id,
			category_ids: $category_ids,
			category_depths: $category_depths,
			regular_price: $regular_price,
			sale_price: $sale_price,
			quantity: $quantity,
			group_id: $resolved_group?->id(),
			currency_decimals: $decimals,
			is_on_sale: $is_native_sale
		);
	}

	/**
	 * Execute deterministic rule resolution and price calculation for a cart line item.
	 *
	 * Modifies $cart_item['data']->set_price() with the authoritative final price,
	 * attaches Tierza audit metadata, and records failures if exceptions occur.
	 *
	 * @param array<string, mixed> $cart_item   Reference to cart item payload.
	 * @param int|null             $customer_id Optional customer ID (test mode only).
	 * @return PricingResult Authoritative calculation result.
	 */
	public function apply_cart_item_pricing( array &$cart_item, ?int $customer_id = null ): PricingResult {
		$product = $cart_item['data'] ?? null;
		if ( ! $product instanceof WC_Product ) {
			throw new InvalidMoneyException( 'Cart item data is not a valid WC_Product instance.' );
		}

		$context = $this->get_cart_item_pricing_context( $cart_item, $customer_id );
		if ( null === $context ) {
			throw new InvalidMoneyException( sprintf( 'Could not construct pricing context for product #%d.', $product->get_id() ) );
		}

		$resolution     = $this->rule_resolver->resolve( $context );
		$pricing_result = $this->price_calculator->calculate( $context, $resolution );

		$final_price = $pricing_result->final_price()->amount();
		$decimals    = $this->product_adapter->get_store_decimals();
		$wc_price    = $this->format_decimal( $final_price, $decimals );

		// Apply authoritative price directly to the cart line item product instance.
		$product->set_price( $wc_price );
		self::mark_as_cart_priced( $product );

		// Preserve immutable metadata on the cart line item for verification and display formatting.
		$cart_item['tierza_pricing'] = [
			'base_price'       => $context->base_price()->amount(),
			'calculated_price' => $final_price,
			'quantity'         => $context->quantity(),
			'rule_id'          => $pricing_result->applied_rule_id(),
			'customer_id'      => $context->customer_id(),
			'group_id'         => $context->group()?->id() ?? 0,
			'is_fallback'      => ! $pricing_result->is_rule_applied(),
			'precedence_level' => null !== $pricing_result->precedence_level() ? $pricing_result->precedence_level()->value : '',
			'timestamp'        => time(),
		];

		return $pricing_result;
	}

	/**
	 * Prefetch candidate pricing rules for all items in a cart in a single batch query.
	 *
	 * @param array<string, array<string, mixed>> $cart_contents Array of cart items.
	 * @param int|null                            $customer_id   Optional customer ID.
	 */
	public function prefetch_cart( array $cart_contents, ?int $customer_id = null ): void {
		if ( empty( $cart_contents ) || null === $this->batch_prefetcher ) {
			return;
		}

		$products  = [];
		$prime_ids = [];
		$contexts  = [];

		foreach ( $cart_contents as $key => $item ) {
			$product = $item['data'] ?? null;
			if ( ! $product instanceof WC_Product ) {
				continue;
			}
			$products[]  = $product;
			$prime_ids[] = (int) $product->get_id();

			try {
				$ctx = $this->get_cart_item_pricing_context( $item, $customer_id );
				if ( null !== $ctx ) {
					$contexts[ $key ] = $ctx;
				}
			} catch ( \Throwable $e ) {
				unset( $e );
				// Ignore context failures during prefetch; individual item loop will handle properly.
			}
		}

		if ( function_exists( 'update_object_term_cache' ) && ! empty( $prime_ids ) ) {
			update_object_term_cache( $prime_ids, 'product' );
		}

		if ( ! empty( $contexts ) ) {
			$this->batch_prefetcher->prefetch_contexts( $contexts );
		}
	}

	/**
	 * Time-of-check / time-of-use revalidation at the checkout boundary.
	 *
	 * Guarantees that:
	 * 1. No unresolved calculation or repository failure permits order creation.
	 * 2. Final cart line prices match currently active pricing rules.
	 * 3. Stale customer identities or modified rules block checkout until reconciled.
	 *
	 * @param WC_Cart  $cart        WooCommerce cart instance.
	 * @param int|null $customer_id Optional customer ID.
	 * @return array{
	 *     valid: bool,
	 *     errors: string[],
	 *     failed_product_ids: int[],
	 *     discrepancies: array<string, array{cart_price: string, current_price: string}>
	 * } Revalidation report.
	 */
	public function revalidate_checkout( WC_Cart $cart, ?int $customer_id = null ): array {
		$errors             = [];
		$failed_product_ids = [];
		$discrepancies      = [];

		$cart_contents = $cart->get_cart();
		if ( empty( $cart_contents ) ) {
			return [
				'valid'              => true,
				'errors'             => [],
				'failed_product_ids' => [],
				'discrepancies'      => [],
			];
		}

		// 1. Check for previously recorded unresolved pricing failures on cart products.
		foreach ( $cart_contents as $item ) {
			$product = $item['data'] ?? null;
			if ( ! $product instanceof WC_Product ) {
				continue;
			}
			$pid = (int) $product->get_id();
			if ( $this->failure_tracker->has_failed( $pid ) ) {
				$failed_product_ids[] = $pid;
				$errors[]             = sprintf( 'Product #%d has an unresolved pricing calculation error.', $pid );
			}
		}

		// 2. Fresh re-evaluation of all cart items against current database rules.
		foreach ( $cart_contents as $key => $item ) {
			$product = $item['data'] ?? null;
			if ( ! $product instanceof WC_Product ) {
				continue;
			}
			$pid = (int) $product->get_id();

			try {
				$context = $this->get_cart_item_pricing_context( $item, $customer_id );
				if ( null === $context ) {
					continue;
				}

				$resolution     = $this->rule_resolver->resolve( $context );
				$pricing_result = $this->price_calculator->calculate( $context, $resolution );

				$decimals       = $this->product_adapter->get_store_decimals();
				$expected_price = $this->format_decimal( $pricing_result->final_price()->amount(), $decimals );
				$actual_price   = (string) $product->get_price( 'edit' );

				if ( DecimalMath::comp( $expected_price, $actual_price, $decimals, $this->math_driver ) !== 0 ) {
					$discrepancies[ $key ] = [
						'cart_price'    => $actual_price,
						'current_price' => $expected_price,
					];
					$errors[]              = sprintf(
						'Price discrepancy on product #%d: cart has %s, current rules evaluate to %s.',
						$pid,
						$actual_price,
						$expected_price
					);
				}
			} catch ( \Throwable $e ) {
				$failed_product_ids[] = $pid;
				$this->failure_tracker->record_failure(
					$pid,
					PricingFailureTracker::CODE_CALCULATION_FAILED,
					$e->getMessage()
				);
				$errors[] = sprintf( 'Pricing engine failure during checkout validation on product #%d.', $pid );
			}
		}

		$is_valid = empty( $errors ) && empty( $failed_product_ids ) && empty( $discrepancies );

		return [
			'valid'              => $is_valid,
			'errors'             => $errors,
			'failed_product_ids' => array_values( array_unique( $failed_product_ids ) ),
			'discrepancies'      => $discrepancies,
		];
	}

	/**
	 * Format cart item unit price HTML matching catalog strikethrough styling.
	 *
	 * @param string               $original_html Original price HTML.
	 * @param array<string, mixed> $cart_item    Cart line item payload.
	 * @param int|null             $customer_id  Optional customer ID.
	 * @return string Formatted price HTML.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	public function format_cart_item_price_html( string $original_html, array $cart_item, ?int $customer_id = null ): string {
		$product = $cart_item['data'] ?? null;
		if ( ! $product instanceof WC_Product ) {
			return $original_html;
		}

		$raw_reg = (string) $product->get_regular_price( 'edit' );
		if ( '' === $raw_reg || ! DecimalMath::is_valid( $raw_reg ) ) {
			return $original_html;
		}

		$current_price = (string) $product->get_price( 'edit' );
		if ( '' === $current_price || ! DecimalMath::is_valid( $current_price ) ) {
			return $original_html;
		}

		$decimals   = $this->product_adapter->get_store_decimals();
		$price_args = [ 'decimals' => $decimals ];

		// If discount applied relative to regular price, show strikethrough sale format.
		if ( DecimalMath::comp( $raw_reg, $current_price, $decimals, $this->math_driver ) > 0 ) {
			if ( function_exists( 'wc_format_sale_price' ) && function_exists( 'wc_price' ) ) {
				$formatted_reg   = wc_price( $raw_reg, $price_args );
				$formatted_final = wc_price( $current_price, $price_args );
				return wc_format_sale_price( $formatted_reg, $formatted_final );
			}
		}

		if ( function_exists( 'wc_price' ) ) {
			return wc_price( $current_price, $price_args );
		}

		return $original_html;
	}

	/**
	 * Format decimal amount using WooCommerce format decimal without float arithmetic.
	 *
	 * @param string $amount   Decimal amount string.
	 * @param int    $decimals Number of decimals.
	 * @return string Formatted decimal string.
	 */
	private function format_decimal( string $amount, int $decimals ): string {
		if ( function_exists( 'wc_format_decimal' ) ) {
			return (string) wc_format_decimal( $amount, $decimals );
		}

		$parts = explode( '.', $amount, 2 );
		$int   = $parts[0];
		$frac  = $parts[1] ?? '';
		if ( 0 === $decimals ) {
			return $int;
		}
		$frac = str_pad( substr( $frac, 0, $decimals ), $decimals, '0' );
		return $int . '.' . $frac;
	}
}
