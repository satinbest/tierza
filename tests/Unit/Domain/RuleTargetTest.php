<?php

declare(strict_types=1);

namespace Tierza\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use Tierza\Domain\Exceptions\InvalidRuleException;
use Tierza\Domain\ValueObjects\RuleTarget;

/**
 * Unit tests for RuleTarget value object.
 */
class RuleTargetTest extends TestCase {

	public function test_valid_group_target(): void {
		$target = RuleTarget::group( 5 );
		$this->assertTrue( $target->is_group() );
		$this->assertFalse( $target->is_user() );
		$this->assertSame( 'group', $target->type() );
		$this->assertSame( 5, $target->id() );
	}

	public function test_valid_user_target(): void {
		$target = RuleTarget::user( 12 );
		$this->assertTrue( $target->is_user() );
		$this->assertFalse( $target->is_group() );
		$this->assertSame( 'user', $target->type() );
		$this->assertSame( 12, $target->id() );
	}

	public function test_non_positive_target_id_throws_exception(): void {
		$this->expectException( InvalidRuleException::class );
		$this->expectExceptionMessage( 'Target ID must be a positive integer, got 0.' );
		RuleTarget::group( 0 );
	}

	public function test_invalid_target_type_throws_exception(): void {
		$this->expectException( InvalidRuleException::class );
		$this->expectExceptionMessage( 'Invalid target type "role"' );
		new RuleTarget( 'role', 1 );
	}

	public function test_target_equality(): void {
		$target1 = RuleTarget::user( 10 );
		$target2 = new RuleTarget( 'user', 10 );
		$target3 = RuleTarget::group( 10 );

		$this->assertTrue( $target1->equals( $target2 ) );
		$this->assertFalse( $target1->equals( $target3 ) );
	}
}
