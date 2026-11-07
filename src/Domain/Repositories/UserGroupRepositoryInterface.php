<?php

declare(strict_types=1);

namespace Tierza\Domain\Repositories;

use Tierza\Domain\Entities\UserGroupAssignment;
use Tierza\Domain\Exceptions\InvalidAssignmentException;

/**
 * Interface for user-to-group assignment persistence.
 */
interface UserGroupRepositoryInterface {

	/**
	 * Assign a user to a group (creates a new assignment).
	 * In MVP, each user may have only one primary group.
	 *
	 * @throws InvalidAssignmentException If assignment cannot be made.
	 */
	public function assign( int $user_id, int $group_id ): UserGroupAssignment;

	/**
	 * Reassign a user to a new group (updates existing or inserts if none).
	 */
	public function reassign( int $user_id, int $new_group_id ): UserGroupAssignment;

	/**
	 * Find assignment by WordPress user ID.
	 */
	public function find_by_user_id( int $user_id ): ?UserGroupAssignment;

	/**
	 * List user assignments for a given group with pagination.
	 *
	 * @return UserGroupAssignment[]
	 */
	public function list_by_group_id( int $group_id, int $limit = 50, int $offset = 0 ): array;

	/**
	 * Count users assigned to a group.
	 */
	public function count_by_group_id( int $group_id ): int;

	/**
	 * Remove assignment for a given user ID.
	 */
	public function remove( int $user_id ): bool;
}
