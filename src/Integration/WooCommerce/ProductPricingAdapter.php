<?php

declare(strict_types=1);

namespace Tierza\Integration\WooCommerce;

use Tierza\Application\Pricing\Batch\PricingBatchPrefetcherInterface;
use Tierza\Domain\Entities\PricingGroup;
use Tierza\Domain\Pricing\Contracts\PriceCalculatorInterface;
use Tierza\Domain\Pricing\Contracts\PricingCacheInterface;
use Tierza\Domain\Pricing\Contracts\RuleResolverInterface;
use Tierza\Domain\Pricing\PricingContext;
use Tierza\Domain\Pricing\PricingResult;
use Tierza\Domain\Repositories\GroupRepositoryInterface;
use Tierza\Domain\Repositories\UserGroupRepositoryInterface;
use Tierza\Domain\ValueObjects\Money;
use WC_Product;
use WC_Product_Variation;
use WP_Term;

/**
 * Authoritative adapter connecting Tierza pricing engine with WooCommerce products.
 *
 * Responsibilities:
 * 1. Safe unfiltered read of WooCommerce product properties (preventing recursion).
 * 2. Trusted customer identity & group resolution.
 * 3. Taxonomy hierarchy & depth resolution for category rules.
 * 4. Construction of strongly-typed PricingContext with native sale awareness.
 * 5. Deterministic rule resolution & authoritative price calculation.
 * 6. WooCommerce-compatible decimal formatting and standard price HTML.
 */
class ProductPricingAdapter {

	/**
	 * In-memory map of product_id => [ category_ids, category_depths ] for current request.
	 *
	 * @var array<int, array{0: int[], 1: array<int, int>}>
	 */
	private array $category_cache = [];

	/**
	 * In-memory map of customer_id => ?PricingGroup for current request.
	 *
	 * @var array<int, ?PricingGroup>
	 */
	private array $customer_groups = [];

	/**
	 * Constructor.
	 *
	 * @param RuleResolverInterface                $rule_resolver          Authoritative rule resolver.
	 * @param PriceCalculatorInterface             $price_calculator       Authoritative price calculator.
	 * @param GroupRepositoryInterface             $group_repository       Group persistence repository.
	 * @param UserGroupRepositoryInterface         $user_group_repository  User-group assignment repository.
	 * @param PricingCacheInterface                $cache                  Request-scoped pricing cache.
	 * @param PricingBatchPrefetcherInterface|null $batch_prefetcher       Optional batch prefetch service.
	 */
	public function __construct(
		private readonly RuleResolverInterface $rule_resolver,
		private readonly PriceCalculatorInterface $price_calculator,
		private readonly GroupRepositoryInterface $group_repository,
		private readonly UserGroupRepositoryInterface $user_group_repository,
		private readonly PricingCacheInterface $cache,
		private readonly ?PricingBatchPrefetcherInterface $batch_prefetcher = null
	) {}

	/**
	 * Obtain calculated B2B price for a WooCommerce product.
	 *
	 * Returns the calculated price as a WooCommerce-compatible decimal string
	 * (e.g. "80.00" or "0.00"), or the original unfiltered price if no rule matches
	 * or the product is unpriced.
	 *
	 * @param WC_Product $product     WooCommerce product or variation instance.
	 * @param int|null   $customer_id Optional explicit customer ID for testing or impersonation.
	 * @return string Formatted price string.
	 */
	public function get_adjusted_price( WC_Product $product, ?int $customer_id = null ): string {
		$context = $this->get_pricing_context( $product, $customer_id );

		// If product has no valid base price, preserve original value without modification.
		if ( null === $context ) {
			return (string) $product->get_price( 'edit' );
		}

		$result = $this->resolve_and_calculate( $context );

		// On fallback (no eligible Tierza rule), preserve original WooCommerce price untouched.
		if ( ! $result->is_rule_applied() ) {
			return (string) $product->get_price( 'edit' );
		}

		// Format output using store currency decimals.
		return $this->format_decimal(
			$result->final_price()->amount(),
			$context->currency_decimals()
		);
	}

	/**
	 * Construct an authoritative PricingContext from a WooCommerce product.
	 *
	 * Reads product attributes using context 'edit' to completely bypass
	 * filtered price getters and eliminate any chance of filter recursion.
	 *
	 * @param WC_Product $product     WooCommerce product or variation.
	 * @param int|null   $customer_id Optional explicit customer ID.
	 * @return PricingContext|null Valid PricingContext, or null if unpriced.
	 */
	public function get_pricing_context( WC_Product $product, ?int $customer_id = null ): ?PricingContext {
		$raw_price = (string) $product->get_price( 'edit' );
		$raw_reg   = (string) $product->get_regular_price( 'edit' );
		$raw_sale  = (string) $product->get_sale_price( 'edit' );

		// If product has no price set in WooCommerce, return null.
		if ( '' === $raw_price && '' === $raw_reg ) {
			return null;
		}

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
		[ $category_ids, $category_depths ] = $this->get_category_data( $product_id );

		// Currency and decimal precision bounds.
		$currency = $this->get_store_currency();
		$decimals = $this->get_store_decimals();

		// Native WooCommerce sale awareness.
		$is_native_sale = $this->is_product_on_sale( $product, $raw_reg, $raw_sale );

		if ( $is_native_sale && '' !== $raw_sale && is_numeric( $raw_sale ) ) {
			// When active native sale exists, percentage discounts apply to the active sale price.
			$base_price    = Money::from( $raw_sale, $currency );
			$regular_price = '' !== $raw_reg && is_numeric( $raw_reg ) ? Money::from( $raw_reg, $currency ) : null;
			$sale_price    = Money::from( $raw_sale, $currency );
			$is_on_sale    = true;
		} else {
			$base_price_str = '' !== $raw_reg && is_numeric( $raw_reg ) ? $raw_reg : $raw_price;
			if ( '' === $base_price_str || ! is_numeric( $base_price_str ) ) {
				return null;
			}
			$base_price    = Money::from( $base_price_str, $currency );
			$regular_price = '' !== $raw_reg && is_numeric( $raw_reg ) ? Money::from( $raw_reg, $currency ) : null;
			$sale_price    = null;
			$is_on_sale    = false;
		}

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
			quantity: 1,
			group_id: $resolved_group?->id(),
			currency_decimals: $decimals,
			is_on_sale: $is_on_sale
		);
	}

	/**
	 * Execute deterministic rule resolution and price calculation.
	 *
	 * Leverages RequestPricingCache automatically through the injected resolver.
	 *
	 * @param PricingContext $context Strongly typed pricing context.
	 * @return PricingResult Immutable calculation result.
	 */
	public function resolve_and_calculate( PricingContext $context ): PricingResult {
		$resolution = $this->rule_resolver->resolve( $context );
		return $this->price_calculator->calculate( $context, $resolution );
	}

	/**
	 * Get full PricingResult object for a product.
	 *
	 * @param WC_Product $product     WooCommerce product.
	 * @param int|null   $customer_id Optional customer ID.
	 * @return PricingResult|null Result or null if unpriced.
	 */
	public function get_pricing_result( WC_Product $product, ?int $customer_id = null ): ?PricingResult {
		$context = $this->get_pricing_context( $product, $customer_id );
		if ( null === $context ) {
			return null;
		}
		return $this->resolve_and_calculate( $context );
	}

	/**
	 * Formats price HTML for WooCommerce display.
	 *
	 * Renders standard WooCommerce markup without leaking sensitive diagnostics:
	 * - If discount applied: <del>regular_price</del> <ins>final_price</ins>
	 * - If fixed price / 0% rule: standard formatted price
	 * - If fixed zero: <ins>$0.00</ins>
	 * - If fallback: original WooCommerce HTML
	 *
	 * @param string     $original_html Original price HTML.
	 * @param WC_Product $product       WooCommerce product.
	 * @param int|null   $customer_id   Optional customer ID.
	 * @return string Formatted price HTML.
	 */
	public function get_price_html( string $original_html, WC_Product $product, ?int $customer_id = null ): string {
		// Variable products generate range HTML from variation prices; let WooCommerce format ranges.
		if ( $product->is_type( 'variable' ) ) {
			return $original_html;
		}

		$context = $this->get_pricing_context( $product, $customer_id );
		if ( null === $context ) {
			return $original_html;
		}

		$result = $this->resolve_and_calculate( $context );

		// If fallback, return WooCommerce's original price HTML.
		if ( ! $result->is_rule_applied() ) {
			return $original_html;
		}

		$final_price = $result->final_price()->amount();
		$reg_money   = $context->regular_price();
		$suffix      = $product->get_price_suffix();

		// If a discount was applied relative to the regular price, show strike-through sale style.
		if ( null !== $reg_money && bccomp( $reg_money->amount(), $final_price, 6 ) > 0 ) {
			if ( function_exists( 'wc_format_sale_price' ) && function_exists( 'wc_price' ) ) {
				$formatted_reg   = wc_price( (float) $reg_money->amount() );
				$formatted_final = wc_price( (float) $final_price );
				return wc_format_sale_price( $formatted_reg, $formatted_final ) . $suffix;
			}
		}

		// Standard price formatting.
		if ( function_exists( 'wc_price' ) ) {
			return wc_price( (float) $final_price ) . $suffix;
		}

		return $original_html;
	}

	/**
	 * Prefetch candidate pricing rules for an array of products.
	 *
	 * In WooCommerce archive listings, prefetching candidate rules once eliminates
	 * N individual SQL queries, reducing 100 products from 100 queries to 1 single batch query.
	 *
	 * @param array<mixed> $products    Array of WooCommerce products.
	 * @param int|null     $customer_id Optional customer ID.
	 */
	public function prefetch_products( array $products, ?int $customer_id = null ): void {
		if ( empty( $products ) || null === $this->batch_prefetcher ) {
			return;
		}

		// Prime taxonomy term caches for all products in 1 query.
		$prime_ids = [];
		foreach ( $products as $prod_item ) {
			if ( $prod_item instanceof WC_Product ) {
				$prime_ids[] = (int) $prod_item->get_id();
			}
		}
		if ( function_exists( 'update_object_term_cache' ) && ! empty( $prime_ids ) ) {
			update_object_term_cache( $prime_ids, 'product' );
		}

		$contexts = [];
		foreach ( $products as $product ) {
			if ( ! $product instanceof WC_Product ) {
				continue;
			}

			if ( $product->is_type( 'variable' ) ) {
				$children_ids = $product->get_visible_children();
				foreach ( $children_ids as $child_id ) {
					$child = function_exists( 'wc_get_product' ) ? wc_get_product( $child_id ) : null;
					if ( $child instanceof WC_Product ) {
						$child_ctx = $this->get_pricing_context( $child, $customer_id );
						if ( null !== $child_ctx ) {
							$contexts[ 'var_' . $child_id ] = $child_ctx;
						}
					}
				}
			} else {
				$ctx = $this->get_pricing_context( $product, $customer_id );
				if ( null !== $ctx ) {
					$contexts[ 'prod_' . $product->get_id() ] = $ctx;
				}
			}
		}

		if ( ! empty( $contexts ) ) {
			$this->batch_prefetcher->prefetch_contexts( $contexts );
		}
	}

	/**
	 * Generate identity hash data for WooCommerce variation prices cache.
	 *
	 * Hooked into 'woocommerce_get_variation_prices_hash' to guarantee
	 * customer/group price segregation in WooCommerce's transient cache.
	 *
	 * @param int|null $customer_id Optional customer ID.
	 * @return array<string, mixed> Identity data.
	 */
	public function get_variation_prices_hash_data( ?int $customer_id = null ): array {
		$resolved_customer_id = $this->resolve_customer_id( $customer_id );
		$resolved_group       = $this->resolve_customer_group( $resolved_customer_id );

		return [
			'tierza_customer_id' => $resolved_customer_id,
			'tierza_group_id'    => $resolved_group?->id() ?? 0,
			'tierza_is_active'   => $resolved_group?->is_active() ?? false,
		];
	}

	/**
	 * Establish current trusted customer ID.
	 *
	 * Derived strictly from WordPress authentication state; never trusts
	 * query parameters or user-submitted input.
	 *
	 * @param int|null $explicit_id Optional explicit customer ID.
	 * @return int Positive user ID or 0 for guest.
	 */
	public function resolve_customer_id( ?int $explicit_id = null ): int {
		if ( null !== $explicit_id && $explicit_id >= 0 ) {
			return $explicit_id;
		}

		if ( function_exists( 'get_current_user_id' ) ) {
			return (int) get_current_user_id();
		}

		return 0;
	}

	/**
	 * Load validated group for customer from approved repository and cache.
	 *
	 * @param int $customer_id Customer user ID.
	 * @return PricingGroup|null Active group entity or null.
	 */
	public function resolve_customer_group( int $customer_id ): ?PricingGroup {
		if ( $customer_id <= 0 ) {
			return null;
		}

		if ( array_key_exists( $customer_id, $this->customer_groups ) ) {
			return $this->customer_groups[ $customer_id ];
		}

		$assignment = $this->user_group_repository->find_by_user_id( $customer_id );
		if ( null === $assignment ) {
			$this->customer_groups[ $customer_id ] = null;
			return null;
		}

		$group_id = $assignment->group_id();

		// Check request cache for existing group status.
		$cached_status = $this->cache->get_group_status( $group_id );
		if ( null !== $cached_status ) {
			if ( $cached_status['exists'] && $cached_status['is_active'] && null !== $cached_status['group'] ) {
				$this->customer_groups[ $customer_id ] = $cached_status['group'];
				return $cached_status['group'];
			}
			$this->customer_groups[ $customer_id ] = null;
			return null;
		}

		// Re-query from repository.
		$group     = $this->group_repository->find_by_id( $group_id );
		$exists    = null !== $group;
		$is_active = $exists && $group->is_active();

		$this->cache->set_group_status( $group_id, $exists, $is_active, $is_active ? $group : null );

		$resolved                              = $is_active ? $group : null;
		$this->customer_groups[ $customer_id ] = $resolved;
		return $resolved;
	}

	/**
	 * Determine if WooCommerce product has an active promotional sale.
	 *
	 * Does not rely on numerical equality alone; inspects raw sale price and
	 * scheduled date bounds.
	 *
	 * @param WC_Product $product       Product instance.
	 * @param string     $raw_reg_price Raw regular price string.
	 * @param string     $raw_sale_price Raw sale price string.
	 * @return bool True if active native sale is ongoing.
	 */
	private function is_product_on_sale( WC_Product $product, string $raw_reg_price, string $raw_sale_price ): bool {
		if ( '' === $raw_sale_price || ! is_numeric( $raw_sale_price ) ) {
			return false;
		}

		if ( '' === $raw_reg_price || ! is_numeric( $raw_reg_price ) ) {
			return false;
		}

		// Sale price must be strictly lower than regular price.
		if ( bccomp( $raw_reg_price, $raw_sale_price, 6 ) <= 0 ) {
			return false;
		}

		// Check scheduled dates using context 'edit' to avoid filter side-effects.
		$date_from = $product->get_date_on_sale_from( 'edit' );
		$date_to   = $product->get_date_on_sale_to( 'edit' );
		$now       = time();

		if ( null !== $date_from && $date_from->getTimestamp() > $now ) {
			return false;
		}

		if ( null !== $date_to && $date_to->getTimestamp() < $now ) {
			return false;
		}

		return true;
	}

	/**
	 * Extract product category IDs and taxonomy hierarchy depths.
	 *
	 * @param int $product_id Main product post ID.
	 * @return array{0: int[], 1: array<int, int>} [ category_ids, category_depths ]
	 */
	private function get_category_data( int $product_id ): array {
		if ( isset( $this->category_cache[ $product_id ] ) ) {
			return $this->category_cache[ $product_id ];
		}

		if ( ! function_exists( 'get_the_terms' ) ) {
			return [ [], [] ];
		}

		/** @var array<mixed>|false $terms */
		$terms = get_the_terms( $product_id, 'product_cat' );
		if ( ! is_array( $terms ) || empty( $terms ) ) {
			$this->category_cache[ $product_id ] = [ [], [] ];
			return $this->category_cache[ $product_id ];
		}

		$category_ids    = [];
		$category_depths = [];

		foreach ( $terms as $term ) {
			if ( ! $term instanceof WP_Term ) {
				continue;
			}
			$cid            = (int) $term->term_id;
			$category_ids[] = $cid;

			// In WordPress, count( get_ancestors( $cid, 'product_cat' ) ) gives taxonomy depth.
			/** @var array<int> $ancestors */
			$ancestors               = function_exists( 'get_ancestors' ) ? (array) get_ancestors( $cid, 'product_cat' ) : [];
			$depth                   = count( $ancestors );
			$category_depths[ $cid ] = $depth;
		}

		$category_ids = array_values( array_unique( $category_ids ) );

		$this->category_cache[ $product_id ] = [ $category_ids, $category_depths ];
		return $this->category_cache[ $product_id ];
	}

	/**
	 * Reset in-memory adapter request caches.
	 */
	public function clear_runtime_cache(): void {
		$this->category_cache  = [];
		$this->customer_groups = [];
	}

	/**
	 * Retrieve store currency code.
	 *
	 * @return string 3-character ISO currency code.
	 */
	private function get_store_currency(): string {
		if ( function_exists( 'get_woocommerce_currency' ) ) {
			$curr = (string) get_woocommerce_currency();
			if ( '' !== $curr ) {
				return strtoupper( $curr );
			}
		}
		return 'USD';
	}

	/**
	 * Retrieve store currency decimals bounded to Tierza's supported scale (0-6).
	 *
	 * @return int Number of decimal places (0 to 6).
	 */
	private function get_store_decimals(): int {
		if ( function_exists( 'wc_get_price_decimals' ) ) {
			$dec = wc_get_price_decimals();
			return max( 0, min( 6, (int) $dec ) );
		}
		return 2;
	}

	/**
	 * Format decimal amount using WooCommerce format decimal without float arithmetic.
	 *
	 * @param string $amount   Arbitrary-precision decimal string.
	 * @param int    $decimals Number of decimals.
	 * @return string Formatted decimal string.
	 */
	private function format_decimal( string $amount, int $decimals ): string {
		if ( function_exists( 'wc_format_decimal' ) ) {
			return (string) wc_format_decimal( $amount, $decimals );
		}

		// Fallback formatting without floats.
		$parts = explode( '.', $amount );
		$int   = $parts[0];
		if ( 0 === $decimals ) {
			return $int;
		}
		$frac = isset( $parts[1] ) ? substr( str_pad( $parts[1], $decimals, '0' ), 0, $decimals ) : str_repeat( '0', $decimals );
		return $int . '.' . $frac;
	}
}
