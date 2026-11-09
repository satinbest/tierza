<?php

declare(strict_types=1);

namespace Tierza\Application\Pricing\Cache;

use Tierza\Domain\Pricing\PricingContext;
use Tierza\Domain\ValueObjects\RuleTarget;

/**
 * Deterministic cache key generator for Tierza pricing operations (TRZ-009).
 *
 * Guarantees:
 * - Unambiguous canonical representations of all context dimensions.
 * - Strict segregation between authenticated and guest customers.
 * - Strict segregation between active, inactive, unverified, and missing groups.
 * - Deterministic output independent of array insertion order.
 * - Delimiters that prevent parameter concatenation collisions.
 */
final class PricingCacheKeyGenerator {

	/**
	 * Generates a canonical target signature for a set of RuleTarget objects.
	 *
	 * Sorts targets deterministically by type and ID.
	 *
	 * @param RuleTarget[] $targets
	 */
	public static function target_key( array $targets ): string {
		if ( empty( $targets ) ) {
			return 'targets:none';
		}

		$signatures = [];
		foreach ( $targets as $target ) {
			$signatures[] = $target->type() . ':' . $target->id();
		}

		sort( $signatures, SORT_STRING );

		return 'targets:' . implode( '|', $signatures );
	}

	/**
	 * Generates a canonical deterministic cache key for a PricingContext.
	 *
	 * Dimensions included:
	 * - Customer authentication state and ID
	 * - Group ID and active verification status
	 * - Product ID and Variation ID
	 * - Categories and hierarchy depths (sorted by category ID)
	 * - Quantity
	 * - Base price amount
	 * - Regular price and promotional sale price amounts
	 * - Promotional sale confirmation flag
	 *
	 * @param PricingContext $context
	 * @param bool|null      $group_active Optional explicit group active status.
	 */
	public static function context_key( PricingContext $context, ?bool $group_active = null ): string {
		// 1. Customer dimension.
		$customer_segment = $context->is_authenticated()
			? 'cust:auth:' . $context->customer_id()
			: 'cust:guest:0';

		// 2. Group dimension.
		$group_id = $context->group_id();
		if ( null === $group_id || $group_id <= 0 ) {
			$group_segment = 'grp:none';
		} else {
			$group = $context->group();
			if ( null !== $group ) {
				$group_segment = $group->is_active()
					? 'grp:active:' . $group_id
					: 'grp:inactive:' . $group_id;
			} elseif ( null !== $group_active ) {
				$group_segment = $group_active
					? 'grp:active:' . $group_id
					: 'grp:inactive:' . $group_id;
			} else {
				$group_segment = 'grp:unverified:' . $group_id;
			}
		}

		// 3. Product & Variation dimension.
		$product_segment   = 'prod:' . $context->product_id();
		$variation_segment = $context->is_variation()
			? 'var:' . (int) $context->variation_id()
			: 'var:none';

		// 4. Category & Depth dimension.
		$cat_ids = $context->category_ids();
		if ( empty( $cat_ids ) ) {
			$cat_segment = 'cats:none';
		} else {
			$cat_pairs = [];
			foreach ( $cat_ids as $cat_id ) {
				$depth       = $context->has_category_depth( $cat_id ) ? (string) $context->get_category_depth( $cat_id ) : 'x';
				$cat_pairs[] = $cat_id . '@' . $depth;
			}
			sort( $cat_pairs, SORT_STRING );
			$cat_segment = 'cats:' . implode( ',', $cat_pairs );
		}

		// 5. Quantity & Base Price dimension.
		$qty_segment  = 'qty:' . $context->quantity();
		$base_segment = 'base:' . $context->base_price()->amount();

		// 6. Regular and Sale Price dimension.
		$reg_segment  = null !== $context->regular_price() ? 'reg:' . $context->regular_price()->amount() : 'reg:none';
		$sale_segment = null !== $context->sale_price() ? 'sale:' . $context->sale_price()->amount() : 'sale:none';

		// 7. Promotional flag dimension.
		$promo_flag    = $context->is_on_sale();
		$promo_segment = null === $promo_flag ? 'promo:auto' : ( $promo_flag ? 'promo:true' : 'promo:false' );

		return implode(
			'#',
			[
				$customer_segment,
				$group_segment,
				$product_segment,
				$variation_segment,
				$cat_segment,
				$qty_segment,
				$base_segment,
				$reg_segment,
				$sale_segment,
				$promo_segment,
			]
		);
	}

	/**
	 * Generates a canonical deterministic calculation key for PriceCalculator.
	 *
	 * Extends the context key with store currency and requested currency decimals.
	 */
	public static function calculation_key( PricingContext $context, int $decimals, ?bool $group_active = null ): string {
		$context_segment  = self::context_key( $context, $group_active );
		$currency_segment = 'curr:' . $context->base_price()->currency();
		$decimals_segment = 'dec:' . $decimals;

		return $context_segment . '#' . $currency_segment . '#' . $decimals_segment;
	}
}
