<?php

declare(strict_types=1);

namespace Tierza\Tests\Unit\Validation;

use PHPUnit\Framework\TestCase;
use Tierza\Domain\Exceptions\InvalidAssignmentException;
use Tierza\Domain\Exceptions\InvalidRuleException;
use Tierza\Domain\ValueObjects\RuleScope;
use Tierza\Domain\ValueObjects\RuleTarget;
use Tierza\Infrastructure\Validation\EntityReferenceValidator;

/**
 * Unit tests for EntityReferenceValidator with mocked database responses.
 */
class EntityReferenceValidatorTest extends TestCase {

	public function test_validate_target_rejects_missing_group(): void {
		$mock_db = $this->createMock( \wpdb::class );
		$mock_db->prefix = 'wp_';
		$mock_db->method( 'get_var' )->willReturn( null ); // Group not found.

		$validator = new EntityReferenceValidator( $mock_db );

		$this->expectException( InvalidRuleException::class );
		$this->expectExceptionMessage( 'Target pricing group 999 does not exist or has been deleted.' );
		$validator->validate_target( RuleTarget::group( 999 ) );
	}

	public function test_validate_target_rejects_missing_user(): void {
		$mock_db = $this->createMock( \wpdb::class );
		$mock_db->prefix = 'wp_';
		$mock_db->method( 'get_var' )->willReturn( null ); // User not found.

		$validator = new EntityReferenceValidator( $mock_db );

		$this->expectException( InvalidRuleException::class );
		$this->expectExceptionMessage( 'Target user 888 does not exist or has been deleted.' );
		$validator->validate_target( RuleTarget::user( 888 ) );
	}

	public function test_validate_target_accepts_existing_group(): void {
		$mock_db = $this->createMock( \wpdb::class );
		$mock_db->prefix = 'wp_';
		$mock_db->method( 'get_var' )->willReturn( '1' );

		$validator = new EntityReferenceValidator( $mock_db );
		$validator->validate_target( RuleTarget::group( 1 ) );
		$this->assertTrue( true );
	}

	public function test_validate_scope_allows_global(): void {
		$mock_db = $this->createMock( \wpdb::class );
		$validator = new EntityReferenceValidator( $mock_db );
		$validator->validate_scope( RuleScope::global() );
		$this->assertTrue( true );
	}

	public function test_validate_scope_rejects_missing_category(): void {
		$mock_db = $this->createMock( \wpdb::class );
		$mock_db->prefix = 'wp_';
		$mock_db->method( 'get_var' )->willReturn( null ); // Category not found.

		$validator = new EntityReferenceValidator( $mock_db );

		$this->expectException( InvalidRuleException::class );
		$this->expectExceptionMessage( 'Scope product category 50 does not exist or has been deleted.' );
		$validator->validate_scope( RuleScope::category( 50 ) );
	}

	public function test_validate_scope_rejects_missing_product(): void {
		$mock_db = $this->createMock( \wpdb::class );
		$mock_db->prefix = 'wp_';
		$mock_db->method( 'get_var' )->willReturn( null ); // Product not found.

		$validator = new EntityReferenceValidator( $mock_db );

		$this->expectException( InvalidRuleException::class );
		$this->expectExceptionMessage( 'Scope product 101 does not exist or has been deleted.' );
		$validator->validate_scope( RuleScope::product( 101 ) );
	}

	public function test_validate_scope_rejects_missing_variation(): void {
		$mock_db = $this->createMock( \wpdb::class );
		$mock_db->prefix = 'wp_';
		$mock_db->method( 'get_var' )->willReturn( null ); // Variation not found.

		$validator = new EntityReferenceValidator( $mock_db );

		$this->expectException( InvalidRuleException::class );
		$this->expectExceptionMessage( 'Scope product variation 202 does not exist or has been deleted.' );
		$validator->validate_scope( RuleScope::variation( 202 ) );
	}

	public function test_validate_assignment_rejects_missing_user(): void {
		$mock_db = $this->createMock( \wpdb::class );
		$mock_db->prefix = 'wp_';
		$mock_db->method( 'get_var' )->willReturn( null );

		$validator = new EntityReferenceValidator( $mock_db );

		$this->expectException( InvalidAssignmentException::class );
		$this->expectExceptionMessage( 'Cannot assign non-existent user 777 to pricing group.' );
		$validator->validate_assignment( 777, 1 );
	}

	public function test_validate_assignment_rejects_missing_group(): void {
		$mock_db = $this->createMock( \wpdb::class );
		$mock_db->prefix = 'wp_';
		// First call (user_exists) returns 1, second call (group_exists) returns null.
		$mock_db->method( 'get_var' )->willReturnOnConsecutiveCalls( '1', null );

		$validator = new EntityReferenceValidator( $mock_db );

		$this->expectException( InvalidAssignmentException::class );
		$this->expectExceptionMessage( 'Cannot assign user to non-existent pricing group 999.' );
		$validator->validate_assignment( 1, 999 );
	}
}
