<?php

declare(strict_types=1);

namespace Tierza\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use Tierza\Domain\Entities\UserGroupAssignment;
use Tierza\Domain\Exceptions\InvalidAssignmentException;

/**
 * Unit tests for UserGroupAssignment domain entity.
 */
class UserGroupAssignmentTest extends TestCase {

	public function test_create_valid_assignment(): void {
		$assignment = new UserGroupAssignment( 10, 2, 5 );

		$this->assertSame( 5, $assignment->id() );
		$this->assertSame( 10, $assignment->user_id() );
		$this->assertSame( 2, $assignment->group_id() );
	}

	public function test_non_positive_user_id_throws_exception(): void {
		$this->expectException( InvalidAssignmentException::class );
		$this->expectExceptionMessage( 'User ID must be a positive integer, got 0.' );
		new UserGroupAssignment( 0, 1 );
	}

	public function test_non_positive_group_id_throws_exception(): void {
		$this->expectException( InvalidAssignmentException::class );
		$this->expectExceptionMessage( 'Group ID must be a positive integer, got -1.' );
		new UserGroupAssignment( 1, -1 );
	}

	public function test_reassign_to_new_group(): void {
		$assignment = new UserGroupAssignment( 15, 1, 100 );
		$reassigned = $assignment->reassign_to_group( 4 );

		$this->assertSame( 15, $reassigned->user_id() );
		$this->assertSame( 4, $reassigned->group_id() );
		$this->assertSame( 100, $reassigned->id() );
		$this->assertNotNull( $reassigned->updated_at() );
	}

	public function test_reassign_to_invalid_group_throws_exception(): void {
		$assignment = new UserGroupAssignment( 15, 1 );
		$this->expectException( InvalidAssignmentException::class );
		$this->expectExceptionMessage( 'New group ID must be a positive integer, got 0.' );
		$assignment->reassign_to_group( 0 );
	}
}
