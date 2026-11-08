<?php

declare(strict_types=1);

namespace Tierza\Domain\Pricing;

use Tierza\Domain\Entities\PricingGroup;
use Tierza\Domain\Exceptions\CurrencyMismatchException;
use Tierza\Domain\Exceptions\InvalidPricingContextException;
use Tierza\Domain\Exceptions\InvalidProductVariationException;
use Tierza\Domain\ValueObjects\Money;

/**
 * Strongly typed, immutable context for pricing resolution and calculation.
 *
 * Keeps all WordPress and WooCommerce globals outside the domain layer.
 */
final class PricingContext {

	private readonly int $customer_id;
	private readonly ?int $group_id;
	private readonly ?PricingGroup $group;
	private readonly int $product_id;
	private readonly ?int $variation_id;
	/**
	 * @var array<int>
	 */
	private readonly array $category_ids;
	/**
	 * @var array<int, int>
	 */
	private readonly array $category_depths;
	private readonly string $currency;
	private readonly Money $base_price;
	private readonly ?Money $regular_price;
	private readonly ?Money $sale_price;
	private readonly int $quantity;

	/**
	 * @param int                  $product_id      Mandatory product identifier (> 0).
	 * @param Money                $base_price      Mandatory active base price.
	 * @param int                  $customer_id     Customer identifier (0 represents anonymous/guest).
	 * @param PricingGroup|null    $group           Assigned pricing group entity, if any.
	 * @param int|null             $variation_id    Optional variation identifier (> 0).
	 * @param array<mixed>        $category_ids    Product category identifiers.
	 * @param array<mixed, mixed> $category_depths Category hierarchy depth mapping (category_id => depth).
	 * @param Money|null           $regular_price   Optional regular price metadata.
	 * @param Money|null           $sale_price      Optional sale price metadata.
	 * @param int                  $quantity        Item quantity (>= 1, reserved for future quantity pricing).
	 * @param int|null             $group_id        Optional pricing group identifier fallback.
	 *
	 * @throws InvalidPricingContextException
	 * @throws InvalidProductVariationException
	 * @throws CurrencyMismatchException
	 */
	public function __construct(
		int $product_id,
		Money $base_price,
		int $customer_id = 0,
		?PricingGroup $group = null,
		?int $variation_id = null,
		array $category_ids = [],
		array $category_depths = [],
		?Money $regular_price = null,
		?Money $sale_price = null,
		int $quantity = 1,
		?int $group_id = null
	) {
		if ( $product_id <= 0 ) {
			throw new InvalidPricingContextException(
				sprintf( 'Product ID must be greater than zero, got %d.', $product_id )
			);
		}

		if ( $customer_id < 0 ) {
			throw new InvalidPricingContextException(
				sprintf( 'Customer ID cannot be negative, got %d.', $customer_id )
			);
		}

		if ( null !== $variation_id ) {
			if ( $variation_id <= 0 ) {
				throw new InvalidProductVariationException(
					sprintf( 'Variation ID must be greater than zero when provided, got %d.', $variation_id )
				);
			}

			if ( $variation_id === $product_id ) {
				throw new InvalidProductVariationException(
					sprintf( 'Variation ID (%d) cannot be identical to Product ID (%d).', $variation_id, $product_id )
				);
			}
		}

		if ( $quantity < 1 ) {
			throw new InvalidPricingContextException(
				sprintf( 'Quantity must be at least 1, got %d.', $quantity )
			);
		}

		$currency = $base_price->currency();

		if ( null !== $regular_price && $regular_price->currency() !== $currency ) {
			throw new CurrencyMismatchException(
				sprintf(
					'Regular price currency (%s) does not match base price currency (%s).',
					$regular_price->currency(),
					$currency
				)
			);
		}

		if ( null !== $sale_price && $sale_price->currency() !== $currency ) {
			throw new CurrencyMismatchException(
				sprintf(
					'Sale price currency (%s) does not match base price currency (%s).',
					$sale_price->currency(),
					$currency
				)
			);
		}

		// Validate group entity and group ID consistency.
		if ( null !== $group && null !== $group_id && null !== $group->id() && $group->id() !== $group_id ) {
			throw new InvalidPricingContextException(
				sprintf( 'Group ID mismatch: entity group ID (%d) does not match supplied group ID (%d).', $group->id(), $group_id )
			);
		}

		// Sanitize and validate category IDs
		$clean_categories = [];
		foreach ( $category_ids as $cid ) {
			if ( ! is_int( $cid ) || $cid <= 0 ) {
				throw new InvalidPricingContextException(
					sprintf( 'Category ID must be a positive integer, got %s.', (string) $cid )
				);
			}
			$clean_categories[] = $cid;
		}
		$clean_categories = array_values( array_unique( $clean_categories ) );

		// Validate category depths: must reference present categories and non-negative depths (not silently ignored)
		$clean_depths = [];
		foreach ( $category_depths as $cid => $depth ) {
			if ( ! is_int( $cid ) || $cid <= 0 ) {
				throw new InvalidPricingContextException(
					sprintf( 'Category depth mapping key must be a positive integer category ID, got %s.', (string) $cid )
				);
			}
			if ( ! in_array( $cid, $clean_categories, true ) ) {
				throw new InvalidPricingContextException(
					sprintf( 'Category depth mapped for category ID %d, but this category is not in the product category IDs list.', $cid )
				);
			}
			if ( ! is_int( $depth ) || $depth < 0 ) {
				throw new InvalidPricingContextException(
					sprintf( 'Category depth for category ID %d must be a non-negative integer, got %s.', $cid, is_numeric( $depth ) ? (string) $depth : gettype( $depth ) )
				);
			}
			$clean_depths[ $cid ] = $depth;
		}

		$resolved_group_id = null;
		if ( null !== $group ) {
			$resolved_group_id = $group->id();
		} elseif ( null !== $group_id && $group_id > 0 ) {
			$resolved_group_id = $group_id;
		}

		$this->product_id      = $product_id;
		$this->base_price      = $base_price;
		$this->customer_id     = $customer_id;
		$this->group           = $group;
		$this->group_id        = $resolved_group_id;
		$this->variation_id    = $variation_id;
		$this->category_ids    = $clean_categories;
		$this->category_depths = $clean_depths;
		$this->regular_price   = $regular_price;
		$this->sale_price      = $sale_price;
		$this->quantity        = $quantity;
		$this->currency        = $currency;
	}

	public function product_id(): int {
		return $this->product_id;
	}

	public function base_price(): Money {
		return $this->base_price;
	}

	public function currency(): string {
		return $this->currency;
	}

	public function customer_id(): int {
		return $this->customer_id;
	}

	public function is_anonymous(): bool {
		return 0 === $this->customer_id;
	}

	public function is_authenticated(): bool {
		return $this->customer_id > 0;
	}

	public function group(): ?PricingGroup {
		return $this->group;
	}

	public function group_id(): ?int {
		return $this->group_id;
	}

	public function has_group(): bool {
		return null !== $this->group_id && $this->group_id > 0;
	}

	public function variation_id(): ?int {
		return $this->variation_id;
	}

	public function is_variation(): bool {
		return null !== $this->variation_id;
	}

	/**
	 * @return array<int>
	 */
	public function category_ids(): array {
		return $this->category_ids;
	}

	/**
	 * @return array<int, int>
	 */
	public function category_depths(): array {
		return $this->category_depths;
	}

	public function get_category_depth( int $category_id ): int {
		return $this->category_depths[ $category_id ] ?? 0;
	}

	public function regular_price(): ?Money {
		return $this->regular_price;
	}

	public function sale_price(): ?Money {
		return $this->sale_price;
	}

	public function quantity(): int {
		return $this->quantity;
	}

	public function with_quantity( int $quantity ): self {
		return new self(
			$this->product_id,
			$this->base_price,
			$this->customer_id,
			$this->group,
			$this->variation_id,
			$this->category_ids,
			$this->category_depths,
			$this->regular_price,
			$this->sale_price,
			$quantity,
			$this->group_id
		);
	}
}
