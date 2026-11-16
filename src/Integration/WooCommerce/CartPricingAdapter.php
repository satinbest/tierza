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
 * 2. Unfiltered original active store price preservation (preventing double discounting across recalculations).
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

	/** @var \WeakMap<WC_Product, bool>|null */
	private static ?\WeakMap $cart_priced_products = null;

	/** @var \WeakMap<WC_Product, array{price: string, regular: string, sale: string}>|null */
	private static ?\WeakMap $original_product_prices = null;

	/**
	 * @return \WeakMap<WC_Product, bool>
	 */
	private static function get_cart_priced_map(): \WeakMap {
		if ( null === self::$cart_priced_products ) {
			self::$cart_priced_products = new \WeakMap();
		}
		return self::$cart_priced_products;
	}

	/**
	 * @return \WeakMap<WC_Product, array{price: string, regular: string, sale: string}>
	 */
	private static function get_original_prices_map(): \WeakMap {
		if ( null === self::$original_product_prices ) {
			self::$original_product_prices = new \WeakMap();
		}
		return self::$original_product_prices;
	}

	/**
	 * Mark a product instance as authoritatively priced for cart calculation.
	 *
	 * @param WC_Product $product Product instance.
	 */
	public static function mark_as_cart_priced( WC_Product $product ): void {
		self::get_cart_priced_map()->offsetSet( $product, true );
	}

	/**
	 * Check whether a product instance has already been priced by the cart adapter.
	 *
	 * @param WC_Product $product Product instance.
	 * @return bool True if already priced for cart.
	 */
	public static function is_cart_priced( WC_Product $product ): bool {
		return self::get_cart_priced_map()->offsetExists( $product );
	}

	/**
	 * Record clean original prices for a product instance before modification.
	 *
	 * @param WC_Product                                         $product Product instance.
	 * @param array{price: string, regular: string, sale: string} $prices  Clean prices.
	 */
	public static function record_original_product_prices( WC_Product $product, array $prices ): void {
		self::get_original_prices_map()->offsetSet( $product, $prices );
	}

	/**
	 * Retrieve clean original prices for a product instance.
	 *
	 * @param WC_Product $product Product instance.
	 * @return array{price: string, regular: string, sale: string}|null Clean prices or null if not recorded.
	 */
	public static function get_original_product_prices( WC_Product $product ): ?array {
		$map = self::get_original_prices_map();
		if ( ! $map->offsetExists( $product ) ) {
			return null;
		}
		return $map->offsetGet( $product );
	}

	/**
	 * Reset cart-priced product and original price registries.
	 */
	public static function clear_cart_priced_registry(): void {
		self::$cart_priced_products    = new \WeakMap();
		self::$original_product_prices = new \WeakMap();
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
	 * Retrieve unfiltered original active store price for a cart item product.
	 *
	 * Restores authoritative original active-price semantics:
	 * 1. Prioritizes genuine unfiltered active WooCommerce base price.
	 * 2. If native sale is active ($is_native_sale), uses sale_price.
	 * 3. If no native sale, uses active base price (even if different from regular price).
	 * 4. Preserves clean original price state across repeated calculate_totals() passes,
	 *    preventing double-discounting on already-discounted product instances.
	 * 5. Re-reads fresh database prices safely after product updates.
	 * 6. Preserves legitimate zero-priced items ('0' / '0.00').
	 * 7. Strictly validates monetary strings and rejects invalid inputs with InvalidMoneyException.
	 *
	 * @param WC_Product           $product   Cart line item product instance.
	 * @param array<string, mixed> $cart_item Cart line item payload.
	 * @return string Arbitrary-precision decimal string.
	 * @throws InvalidMoneyException If monetary value cannot be extracted or is malformed.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	public function get_unfiltered_base_price( WC_Product $product, array $cart_item ): string {
		$recorded = self::get_original_product_prices( $product );

		// 1. If product instance has already been priced by Tierza in current request,
		// retrieve preserved original store prices from registry:
		if ( self::is_cart_priced( $product ) && null !== $recorded ) {
			$raw_price = $recorded['price'];
			$raw_reg   = $recorded['regular'];
			$raw_sale  = $recorded['sale'];
		} elseif ( self::is_cart_priced( $product ) && function_exists( 'wc_get_product' ) && $product->get_id() > 0 ) {
			// Fallback: reload clean product from store if available
			$fresh = wc_get_product( $product->get_id() );
			if ( $fresh instanceof WC_Product && ! self::is_cart_priced( $fresh ) ) {
				$raw_price = (string) $fresh->get_price( 'edit' );
				$raw_reg   = (string) $fresh->get_regular_price( 'edit' );
				$raw_sale  = (string) $fresh->get_sale_price( 'edit' );
				self::record_original_product_prices(
					$product,
					[
						'price'   => $raw_price,
						'regular' => $raw_reg,
						'sale'    => $raw_sale,
					]
				);
			} else {
				$raw_price = (string) $product->get_price( 'edit' );
				$raw_reg   = (string) $product->get_regular_price( 'edit' );
				$raw_sale  = (string) $product->get_sale_price( 'edit' );
			}
		} else {
			// Fresh unmutated product instance as provided by WooCommerce or session:
			$raw_price = (string) $product->get_price( 'edit' );
			$raw_reg   = (string) $product->get_regular_price( 'edit' );
			$raw_sale  = (string) $product->get_sale_price( 'edit' );

			// Preserve clean state for subsequent passes in this request:
			self::record_original_product_prices(
				$product,
				[
					'price'   => $raw_price,
					'regular' => $raw_reg,
					'sale'    => $raw_sale,
				]
			);
		}

		// 2. Strict syntax validation:
		if ( '' !== $raw_price && ! DecimalMath::is_valid( $raw_price ) ) {
			throw new InvalidMoneyException(
				sprintf( 'Product #%d has invalid price "%s".', $product->get_id(), $raw_price )
			);
		}
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

		// 3. Reject unpriced items:
		if ( '' === $raw_price && '' === $raw_reg ) {
			throw new InvalidMoneyException(
				sprintf( 'Product #%d has no price configured in WooCommerce.', $product->get_id() )
			);
		}

		// 4. Candidate active price: primary is unfiltered active price; fall back to regular price:
		$base_price_candidate = '' !== $raw_price ? $raw_price : $raw_reg;

		// 5. Active scheduled promotional sale awareness:
		$is_native_sale = $this->product_adapter->is_product_on_sale( $product, $raw_reg, $raw_sale );
		if ( $is_native_sale && '' !== $raw_sale ) {
			return $raw_sale;
		}

		return $base_price_candidate;
	}

	/**
	 * Construct a strongly typed PricingContext from a cart line item.
	 *
	 * Preserves original base price, quantity, customer, group, and category taxonomy.
	 *
	 * @param array<string, mixed> $cart_item   Cart line item array.
	 * @param int|null             $customer_id Optional explicit customer ID (testing only).
	 * @return PricingContext|null Strongly typed context or null if unpriced.
	 * @throws InvalidMoneyException If price values are invalid.
	 */
	public function get_cart_item_pricing_context( array $cart_item, ?int $customer_id = null ): ?PricingContext {
		$product = $cart_item['data'] ?? null;
		if ( ! $product instanceof WC_Product ) {
			return null;
		}

		$raw_base_price = $this->get_unfiltered_base_price( $product, $cart_item );

		$obj_id   = spl_object_id( $product );
		$prices   = self::get_original_product_prices( $product );
		$raw_reg  = $prices['regular'] ?? (string) $product->get_regular_price( 'edit' );
		$raw_sale = $prices['sale'] ?? (string) $product->get_sale_price( 'edit' );

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
	 * Batch prefetch candidate rules and taxonomic terms for all cart items in a single query.
	 *
	 * @param array<string, array<string, mixed>> $cart_contents Cart contents array.
	 * @param int|null                            $customer_id   Optional customer ID.
	 */
	public function prefetch_cart( array $cart_contents, ?int $customer_id = null ): void {
		if ( null === $this->batch_prefetcher || empty( $cart_contents ) ) {
			return;
		}

		$contexts  = [];
		$products  = [];
		$prime_ids = [];

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
	 * 1. Fails closed: corrupted, missing, or unverifiable cart items block checkout.
	 * 2. No unresolved calculation, repository, or prefetch failure permits order creation.
	 * 3. Final cart line prices match currently active pricing rules.
	 * 4. Stale customer identities or modified rules block checkout until reconciled.
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

		$resolved_customer_id = $this->resolve_customer_id( $customer_id );

		// 1. Validate structure and fail closed on invalid or corrupted cart items:
		foreach ( $cart_contents as $key => $item ) {
			$product = $item['data'] ?? null;
			if ( ! $product instanceof WC_Product || $product->get_id() <= 0 ) {
				$errors[]             = sprintf( 'Cart line "%s" contains invalid or corrupted product data.', $key );
				$failed_product_ids[] = 0;
				$this->failure_tracker->record_failure( 0, PricingFailureTracker::CODE_INVALID_CONTEXT, 'Invalid or corrupted product data.', $resolved_customer_id, (string) $key );
				continue;
			}

			$pid = (int) $product->get_id();

			// 2. Fresh authoritative re-evaluation of this cart line against current database rules:
			try {
				$context = $this->get_cart_item_pricing_context( $item, $resolved_customer_id );
				if ( null === $context ) {
					$errors[]             = sprintf( 'Product #%d in cart cannot be authoritatively verified.', $pid );
					$failed_product_ids[] = $pid;
					$this->failure_tracker->record_failure( $pid, PricingFailureTracker::CODE_INVALID_CONTEXT, 'Context construction failed.', $resolved_customer_id, (string) $key );
					continue;
				}

				$resolution     = $this->rule_resolver->resolve( $context );
				$current_res    = $this->price_calculator->calculate( $context, $resolution );
				$decimals       = $this->product_adapter->get_store_decimals();
				$expected_price = $this->format_decimal( $current_res->final_price()->amount(), $decimals );

				$actual_cart_price = (string) $product->get_price( 'edit' );

				if ( DecimalMath::comp( $expected_price, $actual_cart_price, $decimals, $this->math_driver ) !== 0 ) {
					$discrepancies[ (string) $key ] = [
						'cart_price'    => $actual_cart_price,
						'current_price' => $expected_price,
					];
					$errors[]                       = sprintf(
						'Price discrepancy on product #%d: cart price is %s but current rule yields %s.',
						$pid,
						$actual_cart_price,
						$expected_price
					);
					$failed_product_ids[]           = $pid;
				}
			} catch ( \Throwable $e ) {
				$code         = ( $e instanceof \Tierza\Infrastructure\Exceptions\DatabaseQueryException )
					? PricingFailureTracker::CODE_REPOSITORY_UNAVAILABLE
					: ( ( $e instanceof \Tierza\Domain\Exceptions\PricingCalculationException )
						? PricingFailureTracker::CODE_CALCULATION_FAILED
						: ( ( $e instanceof \Tierza\Domain\Exceptions\InvalidPricingContextException || $e instanceof \Tierza\Domain\Exceptions\InvalidMoneyException )
							? PricingFailureTracker::CODE_INVALID_CONTEXT
							: PricingFailureTracker::CODE_UNEXPECTED ) );
				$safe_message = $e->getMessage();
				$this->failure_tracker->record_failure( $pid, $code, $safe_message, $resolved_customer_id, (string) $key );
				$failed_product_ids[] = $pid;
				$errors[]             = sprintf( 'Failed to verify current price for product #%d: %s', $pid, $safe_message );
			}
		}

		// 3. Unresolved failure barrier:
		// Any unresolved failure in failure tracker for this customer blocks checkout:
		foreach ( $cart_contents as $key => $item ) {
			$product = $item['data'] ?? null;
			if ( ! $product instanceof WC_Product || $product->get_id() <= 0 ) {
				continue;
			}

			$pid = (int) $product->get_id();
			if ( $this->failure_tracker->has_failed( $pid, $resolved_customer_id, (string) $key ) ) {
				$failed_product_ids[] = $pid;
				$errors[]             = sprintf( 'Product #%d has an unresolved pricing calculation error.', $pid );
			}
		}

		return [
			'valid'              => empty( $errors ),
			'errors'             => array_values( array_unique( $errors ) ),
			'failed_product_ids' => array_values( array_unique( $failed_product_ids ) ),
			'discrepancies'      => $discrepancies,
		];
	}

	/**
	 * Format cart item unit price HTML.
	 *
	 * Shows standard WooCommerce strikethrough (<del>regular</del> <ins>sale</ins>)
	 * when discounted, preserving WooCommerce tax display modes (inc/exc tax) and suffixes.
	 *
	 * @param string               $original_html Original formatted HTML.
	 * @param array<string, mixed> $cart_item     Cart line item.
	 * @return string Formatted price HTML.
	 */
	public function format_cart_item_price_html( string $original_html, array $cart_item ): string {
		$product = $cart_item['data'] ?? null;
		if ( ! $product instanceof WC_Product ) {
			return $original_html;
		}

		$current_price = (string) $product->get_price( 'edit' );
		if ( '' === $current_price || ! DecimalMath::is_valid( $current_price ) ) {
			return $original_html;
		}

		$raw_reg = (string) $product->get_regular_price( 'edit' );
		if ( '' === $raw_reg || ! DecimalMath::is_valid( $raw_reg ) ) {
			return $original_html;
		}

		$decimals = $this->product_adapter->get_store_decimals();

		// If current price is strictly less than regular price, show strikethrough:
		if ( DecimalMath::comp( $raw_reg, $current_price, $decimals, $this->math_driver ) > 0 ) {
			if ( function_exists( 'wc_format_sale_price' ) && function_exists( 'wc_price' ) ) {
				$price_args  = [ 'decimals' => $decimals ];
				$reg_display = function_exists( 'wc_get_price_to_display' )
					? wc_get_price_to_display( $product, [ 'price' => $raw_reg ] )
					: $raw_reg;
				$cur_display = function_exists( 'wc_get_price_to_display' )
					? wc_get_price_to_display( $product, [ 'price' => $current_price ] )
					: $current_price;

				$formatted_reg   = wc_price( $reg_display, $price_args );
				$formatted_final = wc_price( $cur_display, $price_args );
				$suffix          = $product->get_price_suffix();

				return wc_format_sale_price( $formatted_reg, $formatted_final ) . $suffix;
			}
		}

		// When price equals regular price or is zero-discount, return original HTML untouched:
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
