<?php

declare(strict_types=1);

namespace Tierza\Domain\Repositories;

use Tierza\Domain\Entities\PricingGroup;
use Tierza\Domain\Exceptions\DuplicateSlugException;
use Tierza\Domain\Exceptions\GroupDeletionSafeguardException;

/**
 * Interface for customer pricing group persistence.
 */
interface GroupRepositoryInterface {

	/**
	 * Persist a pricing group (insert or update).
	 *
	 * @throws DuplicateSlugException When slug already belongs to another group.
	 */
	public function save( PricingGroup $group ): PricingGroup;

	/**
	 * Find a group by its primary ID.
	 */
	public function find_by_id( int $id ): ?PricingGroup;

	/**
	 * Find a group by its unique slug.
	 */
	public function find_by_slug( string $slug ): ?PricingGroup;

	/**
	 * List groups with pagination.
	 *
	 * @return PricingGroup[]
	 */
	public function list( int $limit = 50, int $offset = 0, ?string $status = null ): array;

	/**
	 * Count total groups.
	 */
	public function count( ?string $status = null ): int;

	/**
	 * Search groups by name with pagination.
	 *
	 * @return PricingGroup[]
	 */
	public function search_by_name( string $keyword, int $limit = 50, int $offset = 0 ): array;

	/**
	 * Delete a group only if it has zero users and zero rules.
	 *
	 * @throws GroupDeletionSafeguardException If group has users or rules.
	 */
	public function delete( int $id ): bool;

	/**
	 * Check if group has users assigned.
	 */
	public function has_assigned_users( int $group_id ): bool;

	/**
	 * Check if group has rules attached.
	 */
	public function has_rules( int $group_id ): bool;
}
