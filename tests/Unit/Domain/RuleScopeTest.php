<?php

declare(strict_types=1);

namespace Tierza\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use Tierza\Domain\Exceptions\InvalidRuleException;
use Tierza\Domain\ValueObjects\RuleScope;

/**
 * Unit tests for RuleScope value object.
 */
class RuleScopeTest extends TestCase {

	public function test_valid_global_scope(): void {
		$scope = RuleScope::global();
		$this->assertTrue( $scope->is_global() );
		$this->assertFalse( $scope->is_category() );
		$this->assertFalse( $scope->is_product() );
		$this->assertFalse( $scope->is_variation() );
		$this->assertSame( 0, $scope->id() );
		$this->assertSame( 'global', $scope->type() );
	}

	public function test_global_scope_with_nonzero_id_throws_exception(): void {
		$this->expectException( InvalidRuleException::class );
		$this->expectExceptionMessage( 'Global scope ID must be 0, got 5.' );
		new RuleScope( 'global', 5 );
	}

	public function test_valid_category_scope(): void {
		$scope = RuleScope::category( 42 );
		$this->assertTrue( $scope->is_category() );
		$this->assertSame( 42, $scope->id() );
	}

	public function test_category_scope_with_zero_or_negative_id_throws_exception(): void {
		$this->expectException( InvalidRuleException::class );
		$this->expectExceptionMessage( 'Category scope ID must be a positive integer, got 0.' );
		RuleScope::category( 0 );
	}

	public function test_valid_product_scope(): void {
		$scope = RuleScope::product( 101 );
		$this->assertTrue( $scope->is_product() );
		$this->assertSame( 101, $scope->id() );
	}

	public function test_valid_variation_scope(): void {
		$scope = RuleScope::variation( 205 );
		$this->assertTrue( $scope->is_variation() );
		$this->assertSame( 205, $scope->id() );
	}

	public function test_invalid_scope_type_throws_exception(): void {
		$this->expectException( InvalidRuleException::class );
		$this->expectExceptionMessage( 'Invalid scope type "tag"' );
		new RuleScope( 'tag', 10 );
	}

	public function test_scope_equality(): void {
		$scope1 = RuleScope::product( 101 );
		$scope2 = new RuleScope( 'product', 101 );
		$scope3 = RuleScope::variation( 101 );

		$this->assertTrue( $scope1->equals( $scope2 ) );
		$this->assertFalse( $scope1->equals( $scope3 ) );
	}
}
