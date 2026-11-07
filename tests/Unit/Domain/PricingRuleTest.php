<?php

declare(strict_types=1);

namespace Tierza\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use Tierza\Domain\Entities\PricingRule;
use Tierza\Domain\Exceptions\InvalidRuleException;
use Tierza\Domain\ValueObjects\PricingValue;
use Tierza\Domain\ValueObjects\RuleScope;
use Tierza\Domain\ValueObjects\RuleStatus;
use Tierza\Domain\ValueObjects\RuleTarget;

/**
 * Unit tests for PricingRule domain entity.
 */
class PricingRuleTest extends TestCase {

	public function test_create_valid_percentage_rule(): void {
		$target = RuleTarget::group( 1 );
		$scope  = RuleScope::global();
		$val    = PricingValue::percentage( '12.5' );

		$rule = new PricingRule( $target, $scope, $val, 10 );

		$this->assertSame( 10, $rule->id() );
		$this->assertSame( 'group', $rule->target()->type() );
		$this->assertSame( 1, $rule->target()->id() );
		$this->assertSame( 'global', $rule->scope()->type() );
		$this->assertSame( 0, $rule->scope()->id() );
		$this->assertSame( 'percentage', $rule->pricing_value()->type() );
		$this->assertSame( '12.500000', $rule->pricing_value()->value() );
		$this->assertTrue( $rule->is_active() );
		$this->assertSame( 'group:1|global:0|percentage', $rule->logical_key() );
	}

	public function test_create_valid_fixed_price_rule_on_product(): void {
		$target = RuleTarget::group( 2 );
		$scope  = RuleScope::product( 45 );
		$val    = PricingValue::fixed( '1500000' );

		$rule = new PricingRule( $target, $scope, $val );

		$this->assertTrue( $rule->pricing_value()->is_fixed() );
		$this->assertSame( 'product', $rule->scope()->type() );
		$this->assertSame( 45, $rule->scope()->id() );
		$this->assertSame( 'group:2|product:45|fixed', $rule->logical_key() );
	}

	public function test_create_valid_fixed_price_rule_on_variation(): void {
		$target = RuleTarget::user( 7 );
		$scope  = RuleScope::variation( 120 );
		$val    = PricingValue::fixed( '950000' );

		$rule = new PricingRule( $target, $scope, $val );

		$this->assertTrue( $rule->pricing_value()->is_fixed() );
		$this->assertSame( 'variation', $rule->scope()->type() );
		$this->assertSame( 120, $rule->scope()->id() );
	}

	public function test_fixed_price_on_global_scope_throws_exception_in_v1(): void {
		$this->expectException( InvalidRuleException::class );
		$this->expectExceptionMessage( 'Fixed-price rules are restricted to product or variation scopes in v1. Got scope "global".' );

		new PricingRule(
			RuleTarget::group( 1 ),
			RuleScope::global(),
			PricingValue::fixed( '1000' )
		);
	}

	public function test_fixed_price_on_category_scope_throws_exception_in_v1(): void {
		$this->expectException( InvalidRuleException::class );
		$this->expectExceptionMessage( 'Fixed-price rules are restricted to product or variation scopes in v1. Got scope "category".' );

		new PricingRule(
			RuleTarget::group( 1 ),
			RuleScope::category( 10 ),
			PricingValue::fixed( '2000' )
		);
	}

	public function test_activate_and_deactivate_rule(): void {
		$rule = new PricingRule(
			RuleTarget::group( 1 ),
			RuleScope::category( 5 ),
			PricingValue::percentage( '20' ),
			1,
			RuleStatus::ACTIVE
		);

		$this->assertTrue( $rule->is_active() );

		$deactivated = $rule->deactivate();
		$this->assertFalse( $deactivated->is_active() );
		$this->assertSame( 'inactive', $deactivated->status()->value() );
		$this->assertNotNull( $deactivated->updated_at() );

		$reactivated = $deactivated->activate();
		$this->assertTrue( $reactivated->is_active() );
	}

	public function test_update_value(): void {
		$rule = new PricingRule(
			RuleTarget::group( 1 ),
			RuleScope::product( 15 ),
			PricingValue::fixed( '10000' )
		);

		$updated = $rule->update_value( PricingValue::fixed( '12000' ) );
		$this->assertSame( '12000.000000', $updated->pricing_value()->value() );
	}
}
