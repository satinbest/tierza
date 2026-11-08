<?php

declare(strict_types=1);

namespace Tierza\Tests\Unit\Domain\Pricing;

use PHPUnit\Framework\TestCase;
use Tierza\Domain\Entities\PricingGroup;
use Tierza\Domain\Exceptions\CurrencyMismatchException;
use Tierza\Domain\Exceptions\InvalidPricingContextException;
use Tierza\Domain\Exceptions\InvalidProductVariationException;
use Tierza\Domain\Exceptions\MissingBasePriceException;
use Tierza\Domain\Pricing\PricingContext;
use Tierza\Domain\ValueObjects\GroupSlug;
use Tierza\Domain\ValueObjects\Money;

final class PricingContextTest extends TestCase {

	public function test_context_creation_for_logged_in_customer(): void {
		$base_price = Money::from( '100.00', 'USD' );
		$context    = new PricingContext(
			product_id: 42,
			base_price: $base_price,
			customer_id: 15,
			variation_id: null,
			category_ids: [ 5, 8 ],
			category_depths: [
				5 => 0,
				8 => 1,
			],
			quantity: 2
		);

		$this->assertSame( 42, $context->product_id() );
		$this->assertSame( 15, $context->customer_id() );
		$this->assertFalse( $context->is_anonymous() );
		$this->assertTrue( $context->is_authenticated() );
		$this->assertFalse( $context->is_variation() );
		$this->assertNull( $context->variation_id() );
		$this->assertSame( [ 5, 8 ], $context->category_ids() );
		$this->assertSame( 1, $context->get_category_depth( 8 ) );
		$this->assertSame( 0, $context->get_category_depth( 5 ) );
		$this->assertSame( 2, $context->quantity() );
		$this->assertSame( 'USD', $context->currency() );
		$this->assertTrue( $context->base_price()->equals( $base_price ) );
	}

	public function test_context_creation_for_anonymous_customer(): void {
		$context = new PricingContext(
			product_id: 10,
			base_price: Money::from( '50.00', 'IRR' ),
			customer_id: 0
		);

		$this->assertTrue( $context->is_anonymous() );
		$this->assertFalse( $context->is_authenticated() );
		$this->assertSame( 0, $context->customer_id() );
		$this->assertSame( 1, $context->quantity() );
	}

	public function test_context_for_variable_product(): void {
		$context = new PricingContext(
			product_id: 100,
			base_price: Money::from( '75.00', 'USD' ),
			variation_id: 105
		);

		$this->assertTrue( $context->is_variation() );
		$this->assertSame( 105, $context->variation_id() );
	}

	public function test_variable_product_with_identical_product_and_variation_id_throws_exception(): void {
		$this->expectException( InvalidProductVariationException::class );
		$this->expectExceptionMessage( 'Variation ID (100) cannot be identical to Product ID (100)' );

		new PricingContext(
			product_id: 100,
			base_price: Money::from( '75.00', 'USD' ),
			variation_id: 100
		);
	}

	public function test_variable_product_with_non_positive_variation_id_throws_exception(): void {
		$this->expectException( InvalidProductVariationException::class );
		new PricingContext(
			product_id: 100,
			base_price: Money::from( '75.00', 'USD' ),
			variation_id: 0
		);
	}

	public function test_invalid_product_id_throws_exception(): void {
		$this->expectException( InvalidPricingContextException::class );
		$this->expectExceptionMessage( 'Product ID must be greater than zero' );

		new PricingContext(
			product_id: 0,
			base_price: Money::from( '50.00', 'USD' )
		);
	}

	public function test_negative_customer_id_throws_exception(): void {
		$this->expectException( InvalidPricingContextException::class );
		$this->expectExceptionMessage( 'Customer ID cannot be negative' );

		new PricingContext(
			product_id: 10,
			base_price: Money::from( '50.00', 'USD' ),
			customer_id: -1
		);
	}

	public function test_invalid_quantity_throws_exception(): void {
		$this->expectException( InvalidPricingContextException::class );
		$this->expectExceptionMessage( 'Quantity must be at least 1' );

		new PricingContext(
			product_id: 10,
			base_price: Money::from( '50.00', 'USD' ),
			quantity: 0
		);
	}

	public function test_currency_mismatch_between_base_and_regular_price(): void {
		$this->expectException( CurrencyMismatchException::class );
		$this->expectExceptionMessage( 'Regular price currency (EUR) does not match base price currency (USD)' );

		new PricingContext(
			product_id: 10,
			base_price: Money::from( '50.00', 'USD' ),
			regular_price: Money::from( '60.00', 'EUR' )
		);
	}

	public function test_currency_mismatch_between_base_and_sale_price(): void {
		$this->expectException( CurrencyMismatchException::class );
		$this->expectExceptionMessage( 'Sale price currency (EUR) does not match base price currency (USD)' );

		new PricingContext(
			product_id: 10,
			base_price: Money::from( '50.00', 'USD' ),
			sale_price: Money::from( '40.00', 'EUR' )
		);
	}

	public function test_pricing_group_assignment(): void {
		$group   = new PricingGroup( 'VIP Wholesale', new GroupSlug( 'vip-wholesale' ), 7, 'VIP group', 'active' );
		$context = new PricingContext(
			product_id: 10,
			base_price: Money::from( '50.00', 'USD' ),
			group: $group
		);

		$this->assertTrue( $context->has_group() );
		$this->assertSame( 7, $context->group_id() );
		$this->assertSame( $group, $context->group() );
	}

	public function test_contradictory_group_entity_and_group_id_throws_exception(): void {
		$group = new PricingGroup( 'VIP Wholesale', new GroupSlug( 'vip-wholesale' ), 7, 'VIP group', 'active' );

		$this->expectException( InvalidPricingContextException::class );
		$this->expectExceptionMessage( 'Group ID mismatch: entity group ID (7) does not match supplied group ID (12)' );

		new PricingContext(
			product_id: 10,
			base_price: Money::from( '50.00', 'USD' ),
			group: $group,
			group_id: 12
		);
	}

	public function test_category_depth_mapping_for_missing_category_throws_exception(): void {
		$this->expectException( InvalidPricingContextException::class );
		$this->expectExceptionMessage( 'Category depth mapped for category ID 99, but this category is not in the product category IDs list' );

		new PricingContext(
			product_id: 10,
			base_price: Money::from( '50.00', 'USD' ),
			category_ids: [ 5, 8 ],
			category_depths: [
				5  => 0,
				99 => 1,
			]
		);
	}

	public function test_negative_category_depth_throws_exception(): void {
		$this->expectException( InvalidPricingContextException::class );
		$this->expectExceptionMessage( 'must be a non-negative integer' );

		new PricingContext(
			product_id: 10,
			base_price: Money::from( '50.00', 'USD' ),
			category_ids: [ 5 ],
			category_depths: [
				5 => -1,
			]
		);
	}

	public function test_explicit_zero_base_price_is_supported_consistently(): void {
		$zero_price = Money::zero( 'USD' );
		$context    = new PricingContext(
			product_id: 10,
			base_price: $zero_price
		);

		$this->assertTrue( $context->base_price()->is_zero() );
		$this->assertSame( '0.000000', $context->base_price()->amount() );
	}

	public function test_with_quantity_returns_immutable_copy(): void {
		$context = new PricingContext(
			product_id: 10,
			base_price: Money::from( '50.00', 'USD' ),
			quantity: 1
		);

		$updated = $context->with_quantity( 5 );

		$this->assertSame( 1, $context->quantity() );
		$this->assertSame( 5, $updated->quantity() );
		$this->assertSame( $context->product_id(), $updated->product_id() );
	}

	public function test_currency_decimals_default_and_custom(): void {
		$context_default = new PricingContext(
			product_id: 10,
			base_price: Money::from( '50.00', 'USD' )
		);
		$this->assertSame( 2, $context_default->currency_decimals() );

		$context_custom = new PricingContext(
			product_id: 10,
			base_price: Money::from( '50.00', 'USD' ),
			currency_decimals: 3
		);
		$this->assertSame( 3, $context_custom->currency_decimals() );

		$context_modified = $context_default->with_currency_decimals( 0 );
		$this->assertSame( 0, $context_modified->currency_decimals() );
		$this->assertSame( 2, $context_default->currency_decimals() );
	}

	public function test_currency_decimals_bounds_validation(): void {
		$this->expectException( InvalidPricingContextException::class );
		$this->expectExceptionMessage( 'Currency decimals must be between 0 and 6, got 7.' );

		new PricingContext(
			product_id: 10,
			base_price: Money::from( '50.00', 'USD' ),
			currency_decimals: 7
		);
	}

	public function test_currency_decimals_8_is_explicitly_rejected(): void {
		$this->expectException( InvalidPricingContextException::class );
		$this->expectExceptionMessage( 'Currency decimals must be between 0 and 6, got 8.' );

		new PricingContext(
			product_id: 10,
			base_price: Money::from( '50.00', 'USD' ),
			currency_decimals: 8
		);
	}

	public function test_missing_base_price_throws_missing_base_price_exception(): void {
		$this->expectException( MissingBasePriceException::class );
		$this->expectExceptionMessage( 'Active base price is required and cannot be null or omitted.' );

		new PricingContext(
			product_id: 10,
			base_price: null
		);
	}

	public function test_is_sale_active_detection_requires_explicit_confirmation(): void {
		// Numerical equality alone must NOT trigger is_sale_active() when unconfirmed (null)
		$context_unconfirmed = new PricingContext(
			product_id: 10,
			base_price: Money::from( '80.00', 'USD' ),
			regular_price: Money::from( '100.00', 'USD' ),
			sale_price: Money::from( '80.00', 'USD' ),
			is_on_sale: null
		);
		$this->assertNull( $context_unconfirmed->is_on_sale() );
		$this->assertFalse( $context_unconfirmed->is_sale_active() );

		// Explicit confirmation as true
		$context_confirmed_sale = new PricingContext(
			product_id: 10,
			base_price: Money::from( '80.00', 'USD' ),
			regular_price: Money::from( '100.00', 'USD' ),
			sale_price: Money::from( '80.00', 'USD' ),
			is_on_sale: true
		);
		$this->assertTrue( $context_confirmed_sale->is_on_sale() );
		$this->assertTrue( $context_confirmed_sale->is_sale_active() );

		// Explicit confirmation as false
		$context_confirmed_no_sale = new PricingContext(
			product_id: 10,
			base_price: Money::from( '100.00', 'USD' ),
			regular_price: Money::from( '100.00', 'USD' ),
			sale_price: Money::from( '100.00', 'USD' ),
			is_on_sale: false
		);
		$this->assertFalse( $context_confirmed_no_sale->is_on_sale() );
		$this->assertFalse( $context_confirmed_no_sale->is_sale_active() );

		// with_sale_active helper
		$promoted = $context_unconfirmed->with_sale_active( true );
		$this->assertTrue( $promoted->is_sale_active() );
		$this->assertFalse( $context_unconfirmed->is_sale_active() );
	}
}
