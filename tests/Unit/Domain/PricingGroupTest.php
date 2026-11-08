<?php

declare(strict_types=1);

namespace Tierza\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use Tierza\Domain\Entities\PricingGroup;
use Tierza\Domain\Exceptions\InvalidGroupException;
use Tierza\Domain\ValueObjects\GroupSlug;
use Tierza\Domain\ValueObjects\GroupStatus;

/**
 * Unit tests for PricingGroup domain entity.
 */
class PricingGroupTest extends TestCase {

	public function test_create_valid_pricing_group(): void {
		$group = new PricingGroup(
			'Gold Wholesale',
			'gold-wholesale',
			1,
			'Exclusive wholesale pricing tier',
			GroupStatus::ACTIVE,
			10
		);

		$this->assertSame( 1, $group->id() );
		$this->assertSame( 'Gold Wholesale', $group->name() );
		$this->assertSame( 'gold-wholesale', $group->slug()->value() );
		$this->assertSame( 'Exclusive wholesale pricing tier', $group->description() );
		$this->assertSame( 'active', $group->status()->value() );
		$this->assertSame( 10, $group->sort_order() );
		$this->assertTrue( $group->is_active() );
	}

	public function test_persian_pricing_group(): void {
		$group = new PricingGroup(
			'مشتریان عمده تهران',
			'مشتریان-عمده-تهران'
		);

		$this->assertSame( 'مشتریان عمده تهران', $group->name() );
		$this->assertSame( 'مشتریان-عمده-تهران', $group->slug()->value() );
		$this->assertTrue( $group->is_active() );
	}

	public function test_empty_name_throws_exception(): void {
		$this->expectException( InvalidGroupException::class );
		$this->expectExceptionMessage( 'Group name cannot be empty.' );
		new PricingGroup( '   ', 'valid-slug' );
	}

	public function test_name_exceeding_190_chars_throws_exception(): void {
		$long_name = str_repeat( 'گروه ', 50 );
		$this->expectException( InvalidGroupException::class );
		$this->expectExceptionMessage( 'Group name exceeds maximum length of 190 characters.' );
		new PricingGroup( $long_name, 'valid-slug' );
	}

	public function test_activate_and_deactivate_group(): void {
		$group = new PricingGroup( 'Silver', 'silver', 2, null, GroupStatus::ACTIVE );
		$this->assertTrue( $group->is_active() );

		$deactivated = $group->deactivate();
		$this->assertFalse( $deactivated->is_active() );
		$this->assertSame( 'inactive', $deactivated->status()->value() );
		$this->assertNotNull( $deactivated->updated_at() );

		$reactivated = $deactivated->activate();
		$this->assertTrue( $reactivated->is_active() );
	}

	public function test_update_details(): void {
		$group = new PricingGroup( 'Bronze', 'bronze', 3 );
		$updated = $group->update_details( 'Bronze Tier', 'bronze-tier', 'Updated description', 5 );

		$this->assertSame( 'Bronze Tier', $updated->name() );
		$this->assertSame( 'bronze-tier', $updated->slug()->value() );
		$this->assertSame( 'Updated description', $updated->description() );
		$this->assertSame( 5, $updated->sort_order() );
		$this->assertNotNull( $updated->updated_at() );
	}

	public function test_with_id_returns_clone_with_assigned_id(): void {
		$group = new PricingGroup( 'New Group', 'new-group' );
		$this->assertNull( $group->id() );

		$persisted = $group->with_id( 99 );
		$this->assertSame( 99, $persisted->id() );
		$this->assertNull( $group->id() );
	}
}
