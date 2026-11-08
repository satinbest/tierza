<?php

declare(strict_types=1);

namespace Tierza\Tests\Unit\Application\Pricing;

use RuntimeException;
use Tierza\Domain\Entities\PricingGroup;
use Tierza\Domain\Exceptions\DuplicateSlugException;
use Tierza\Domain\Exceptions\GroupDeletionSafeguardException;
use Tierza\Domain\Repositories\GroupRepositoryInterface;

/**
 * In-memory test double implementing GroupRepositoryInterface.
 */
final class InMemoryGroupRepository implements GroupRepositoryInterface {

	/**
	 * @var array<int, PricingGroup>
	 */
	private array $groups = [];

	private int $auto_increment = 1;

	private bool $should_fail_on_query = false;

	public function set_should_fail_on_query( bool $fail ): void {
		$this->should_fail_on_query = $fail;
	}

	public function save( PricingGroup $group ): PricingGroup {
		$id = $group->id();
		if ( null === $id ) {
			$id    = $this->auto_increment++;
			$group = $group->with_id( $id );
		}

		$this->groups[ $id ] = $group;
		return $group;
	}

	public function find_by_id( int $id ): ?PricingGroup {
		if ( $this->should_fail_on_query ) {
			throw new RuntimeException( 'Simulated database read failure in find_by_id().' );
		}

		return $this->groups[ $id ] ?? null;
	}

	public function find_by_slug( string $slug ): ?PricingGroup {
		foreach ( $this->groups as $group ) {
			if ( $group->slug()->value() === $slug ) {
				return $group;
			}
		}

		return null;
	}

	public function list( int $limit = 50, int $offset = 0, ?string $status = null ): array {
		$filtered = [];
		foreach ( $this->groups as $group ) {
			if ( null !== $status && $group->status()->value() !== $status ) {
				continue;
			}
			$filtered[] = $group;
		}

		return array_slice( $filtered, $offset, $limit );
	}

	public function count( ?string $status = null ): int {
		return count( $this->list( 1000, 0, $status ) );
	}

	public function search_by_name( string $keyword, int $limit = 50, int $offset = 0 ): array {
		$results = [];
		foreach ( $this->groups as $group ) {
			if ( str_contains( strtolower( $group->name() ), strtolower( $keyword ) ) ) {
				$results[] = $group;
			}
		}

		return array_slice( $results, $offset, $limit );
	}

	public function delete( int $id ): bool {
		if ( isset( $this->groups[ $id ] ) ) {
			unset( $this->groups[ $id ] );
			return true;
		}

		return false;
	}

	public function has_assigned_users( int $group_id ): bool {
		return false;
	}

	public function has_rules( int $group_id ): bool {
		return false;
	}

	public function clear(): void {
		$this->groups         = [];
		$this->auto_increment = 1;
	}
}
