<?php

declare(strict_types=1);

namespace Tierza\Domain\ValueObjects;

use Tierza\Domain\Exceptions\InvalidRuleException;

/**
 * Value object representing a pricing rule scope (global, category, product, or variation).
 */
final class RuleScope {

	public const SCOPE_GLOBAL    = 'global';
	public const SCOPE_CATEGORY  = 'category';
	public const SCOPE_PRODUCT   = 'product';
	public const SCOPE_VARIATION = 'variation';

	private const ALLOWED_SCOPES = [
		self::SCOPE_GLOBAL,
		self::SCOPE_CATEGORY,
		self::SCOPE_PRODUCT,
		self::SCOPE_VARIATION,
	];

	private string $type;
	private int $id;

	public function __construct( string $type, int $id = 0 ) {
		$normalized_type = strtolower( trim( $type ) );

		if ( ! in_array( $normalized_type, self::ALLOWED_SCOPES, true ) ) {
			throw new InvalidRuleException(
				sprintf( 'Invalid scope type "%s". Allowed: %s', $type, implode( ', ', self::ALLOWED_SCOPES ) )
			);
		}

		if ( self::SCOPE_GLOBAL === $normalized_type ) {
			if ( 0 !== $id ) {
				throw new InvalidRuleException(
					sprintf( 'Global scope ID must be 0, got %d.', $id )
				);
			}
		} elseif ( $id <= 0 ) {
				throw new InvalidRuleException(
					sprintf( '%s scope ID must be a positive integer, got %d.', ucfirst( $normalized_type ), $id )
				);
		}

		$this->type = $normalized_type;
		$this->id   = $id;
	}

	public static function global(): self {
		return new self( self::SCOPE_GLOBAL, 0 );
	}

	public static function category( int $category_id ): self {
		return new self( self::SCOPE_CATEGORY, $category_id );
	}

	public static function product( int $product_id ): self {
		return new self( self::SCOPE_PRODUCT, $product_id );
	}

	public static function variation( int $variation_id ): self {
		return new self( self::SCOPE_VARIATION, $variation_id );
	}

	public function is_global(): bool {
		return self::SCOPE_GLOBAL === $this->type;
	}

	public function is_category(): bool {
		return self::SCOPE_CATEGORY === $this->type;
	}

	public function is_product(): bool {
		return self::SCOPE_PRODUCT === $this->type;
	}

	public function is_variation(): bool {
		return self::SCOPE_VARIATION === $this->type;
	}

	public function type(): string {
		return $this->type;
	}

	public function id(): int {
		return $this->id;
	}

	public function equals( RuleScope $other ): bool {
		return $this->type === $other->type() && $this->id === $other->id();
	}
}
