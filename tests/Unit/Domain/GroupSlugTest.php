<?php

declare(strict_types=1);

namespace Tierza\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use Tierza\Domain\Exceptions\InvalidGroupException;
use Tierza\Domain\ValueObjects\GroupSlug;

/**
 * Unit tests for GroupSlug value object.
 */
class GroupSlugTest extends TestCase {

	public function test_normalizes_standard_ascii_slug(): void {
		$slug = new GroupSlug( 'Gold Wholesale Members' );
		$this->assertSame( 'gold-wholesale-members', $slug->value() );
		$this->assertSame( 'gold-wholesale-members', (string) $slug );
	}

	public function test_normalizes_persian_unicode_slug(): void {
		$slug = new GroupSlug( 'مشتریان عمده و همکار' );
		$this->assertSame( 'مشتریان-عمده-و-همکار', $slug->value() );
	}

	public function test_normalizes_mixed_persian_and_ascii_slug(): void {
		$slug = new GroupSlug( 'همکاران سطح VIP 2026' );
		$this->assertSame( 'همکاران-سطح-vip-2026', $slug->value() );
	}

	public function test_collapses_multiple_consecutive_hyphens_and_spaces(): void {
		$slug = new GroupSlug( '  --tier---b2b__pricing   ' );
		$this->assertSame( 'tier-b2b-pricing', $slug->value() );
	}

	public function test_removes_forbidden_punctuation_and_symbols(): void {
		$slug = new GroupSlug( 'VIP@Distributors!#$%*()+=~{}[]:;"<>,.?/' );
		$this->assertSame( 'vipdistributors', $slug->value() );
	}

	public function test_throws_exception_when_slug_becomes_empty(): void {
		$this->expectException( InvalidGroupException::class );
		$this->expectExceptionMessage( 'Group slug cannot be empty after normalization.' );
		new GroupSlug( '   !@#$%^&*()   ' );
	}

	public function test_throws_exception_when_slug_exceeds_190_chars(): void {
		$long_string = str_repeat( 'a', 191 );
		$this->expectException( InvalidGroupException::class );
		$this->expectExceptionMessage( 'Group slug exceeds maximum length of 190 characters.' );
		new GroupSlug( $long_string );
	}

	public function test_equals_comparison(): void {
		$slug1 = new GroupSlug( 'vip-customers' );
		$slug2 = new GroupSlug( 'VIP Customers' );
		$slug3 = new GroupSlug( 'retail' );

		$this->assertTrue( $slug1->equals( $slug2 ) );
		$this->assertFalse( $slug1->equals( $slug3 ) );
	}
}
