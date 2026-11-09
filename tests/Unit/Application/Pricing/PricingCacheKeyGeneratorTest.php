<?php

declare(strict_types=1);

namespace Tierza\Tests\Unit\Application\Pricing;

use PHPUnit\Framework\TestCase;
use Tierza\Application\Pricing\Cache\PricingCacheKeyGenerator;
use Tierza\Domain\Entities\PricingGroup;
use Tierza\Domain\Pricing\PricingContext;
use Tierza\Domain\ValueObjects\GroupSlug;
use Tierza\Domain\ValueObjects\GroupStatus;
use Tierza\Domain\ValueObjects\Money;
use Tierza\Domain\ValueObjects\RuleTarget;

/**
 * Unit tests for PricingCacheKeyGenerator (TRZ-009).
 */
final class PricingCacheKeyGeneratorTest extends TestCase {

	public function test_target_key_sorting_is_deterministic(): void {
		$target1 = RuleTarget::user( 42 );
		$target2 = RuleTarget::group( 7 );

		$key_order1 = PricingCacheKeyGenerator::target_key( [ $target1, $target2 ] );
		$key_order2 = PricingCacheKeyGenerator::target_key( [ $target2, $target1 ] );

		$this->assertSame( 'targets:group:7|user:42', $key_order1 );
		$this->assertSame( $key_order1, $key_order2 );
	}

	public function test_target_key_empty(): void {
		$this->assertSame( 'targets:none', PricingCacheKeyGenerator::target_key( [] ) );
	}

	public function test_context_key_distinguishes_authenticated_from_guest(): void {
		$ctx_guest = new PricingContext(
			product_id: 100,
			base_price: Money::from( '50.00', 'USD' ),
			customer_id: 0
		);

		$ctx_auth = new PricingContext(
			product_id: 100,
			base_price: Money::from( '50.00', 'USD' ),
			customer_id: 12
		);

		$key_guest = PricingCacheKeyGenerator::context_key( $ctx_guest );
		$key_auth  = PricingCacheKeyGenerator::context_key( $ctx_auth );

		$this->assertStringContainsString( 'cust:guest', $key_guest );
		$this->assertStringContainsString( 'cust:auth:12', $key_auth );
		$this->assertNotEquals( $key_guest, $key_auth );
	}

	public function test_context_key_distinguishes_different_customers(): void {
		$ctx_cust1 = new PricingContext( product_id: 100, base_price: Money::from( '50.00', 'USD' ), customer_id: 1 );
		$ctx_cust2 = new PricingContext( product_id: 100, base_price: Money::from( '50.00', 'USD' ), customer_id: 2 );

		$key1 = PricingCacheKeyGenerator::context_key( $ctx_cust1 );
		$key2 = PricingCacheKeyGenerator::context_key( $ctx_cust2 );

		$this->assertNotEquals( $key1, $key2 );
	}

	public function test_context_key_distinguishes_group_states(): void {
		$ctx_none = new PricingContext( product_id: 100, base_price: Money::from( '50.00', 'USD' ) );
		$this->assertStringContainsString( 'grp:none', PricingCacheKeyGenerator::context_key( $ctx_none ) );

		$active_group = new PricingGroup( 'Active', new GroupSlug( 'active-grp' ), 5, null, GroupStatus::active() );
		$ctx_active = new PricingContext( product_id: 100, base_price: Money::from( '50.00', 'USD' ), group: $active_group );
		$this->assertStringContainsString( 'grp:active:5', PricingCacheKeyGenerator::context_key( $ctx_active ) );

		$inactive_group = new PricingGroup( 'Inactive', new GroupSlug( 'inactive-grp' ), 5, null, GroupStatus::inactive() );
		$ctx_inactive = new PricingContext( product_id: 100, base_price: Money::from( '50.00', 'USD' ), group: $inactive_group );
		$this->assertStringContainsString( 'grp:inactive:5', PricingCacheKeyGenerator::context_key( $ctx_inactive ) );

		$ctx_unverified = new PricingContext( product_id: 100, base_price: Money::from( '50.00', 'USD' ), group_id: 5 );
		$this->assertStringContainsString( 'grp:unverified:5', PricingCacheKeyGenerator::context_key( $ctx_unverified ) );

		// With explicit status override.
		$key_verified_active = PricingCacheKeyGenerator::context_key( $ctx_unverified, true );
		$this->assertStringContainsString( 'grp:active:5', $key_verified_active );

		$key_verified_inactive = PricingCacheKeyGenerator::context_key( $ctx_unverified, false );
		$this->assertStringContainsString( 'grp:inactive:5', $key_verified_inactive );
	}

	public function test_context_key_distinguishes_products_and_variations(): void {
		$ctx_prod = new PricingContext( product_id: 101, base_price: Money::from( '50.00', 'USD' ) );
		$key_prod = PricingCacheKeyGenerator::context_key( $ctx_prod );
		$this->assertStringContainsString( 'prod:101#var:none', $key_prod );

		$ctx_var = new PricingContext( product_id: 101, base_price: Money::from( '50.00', 'USD' ), variation_id: 202 );
		$key_var = PricingCacheKeyGenerator::context_key( $ctx_var );
		$this->assertStringContainsString( 'prod:101#var:202', $key_var );

		$this->assertNotEquals( $key_prod, $key_var );
	}

	public function test_context_key_category_sorting_is_deterministic(): void {
		$ctx1 = new PricingContext(
			product_id: 100,
			base_price: Money::from( '50.00', 'USD' ),
			category_ids: [ 25, 10 ],
			category_depths: [ 25 => 2, 10 => 1 ]
		);

		$ctx2 = new PricingContext(
			product_id: 100,
			base_price: Money::from( '50.00', 'USD' ),
			category_ids: [ 10, 25 ],
			category_depths: [ 10 => 1, 25 => 2 ]
		);

		$key1 = PricingCacheKeyGenerator::context_key( $ctx1 );
		$key2 = PricingCacheKeyGenerator::context_key( $ctx2 );

		$this->assertStringContainsString( 'cats:10@1,25@2', $key1 );
		$this->assertSame( $key1, $key2 );
	}

	public function test_calculation_key_includes_currency_and_precision(): void {
		$ctx = new PricingContext( product_id: 100, base_price: Money::from( '50.00', 'USD' ) );

		$key2 = PricingCacheKeyGenerator::calculation_key( $ctx, 2 );
		$key4 = PricingCacheKeyGenerator::calculation_key( $ctx, 4 );

		$this->assertStringContainsString( 'curr:USD#dec:2', $key2 );
		$this->assertStringContainsString( 'curr:USD#dec:4', $key4 );
		$this->assertNotEquals( $key2, $key4 );

		$ctx_eur = new PricingContext( product_id: 100, base_price: Money::from( '50.00', 'EUR' ) );
		$key_eur = PricingCacheKeyGenerator::calculation_key( $ctx_eur, 2 );
		$this->assertStringContainsString( 'curr:EUR#dec:2', $key_eur );
		$this->assertNotEquals( $key2, $key_eur );
	}
}
