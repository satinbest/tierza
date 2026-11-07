<?php

declare(strict_types=1);

namespace Tierza\Infrastructure\Repositories;

use DateTimeImmutable;
use Tierza\Domain\Entities\UserGroupAssignment;
use Tierza\Domain\Exceptions\InvalidAssignmentException;
use Tierza\Domain\Repositories\UserGroupRepositoryInterface;
use Tierza\Infrastructure\Database\Schema;
use wpdb;

/**
 * WordPress database implementation of UserGroupRepositoryInterface.
 */
final class WpUserGroupRepository implements UserGroupRepositoryInterface {

	private wpdb $db;
	private string $table_user_groups;
	private string $table_groups;

	public function __construct( ?wpdb $db = null ) {
		global $wpdb;
		$this->db                = $db ?? $GLOBALS['wpdb'];
		$this->table_user_groups = Schema::user_groups_table( $this->db );
		$this->table_groups      = Schema::groups_table( $this->db );
	}

	public function assign( int $user_id, int $group_id ): UserGroupAssignment {
		if ( $user_id <= 0 ) {
			throw new InvalidAssignmentException(
				sprintf( 'Invalid user ID %d.', $user_id )
			);
		}

		if ( $group_id <= 0 ) {
			throw new InvalidAssignmentException(
				sprintf( 'Invalid group ID %d.', $group_id )
			);
		}

		// Verify target group exists.
		if ( ! $this->group_exists( $group_id ) ) {
			throw new InvalidAssignmentException(
				sprintf( 'Cannot assign user to non-existent pricing group %d.', $group_id )
			);
		}

		// Enforce single primary group per user in MVP.
		$existing = $this->find_by_user_id( $user_id );
		if ( null !== $existing ) {
			throw new InvalidAssignmentException(
				sprintf(
					'User %d is already assigned to group %d. Use reassign() to switch groups.',
					$user_id,
					$existing->group_id()
				)
			);
		}

		$now = current_time( 'mysql', true );

		$this->db->insert(
			$this->table_user_groups,
			[
				'user_id'    => $user_id,
				'group_id'   => $group_id,
				'created_at' => $now,
				'updated_at' => $now,
			],
			[ '%d', '%d', '%s', '%s' ]
		);

		$insert_id = (int) $this->db->insert_id;
		return $this->find_by_id( $insert_id ) ?? new UserGroupAssignment(
			$user_id,
			$group_id,
			$insert_id,
			new DateTimeImmutable( $now ),
			new DateTimeImmutable( $now )
		);
	}

	public function reassign( int $user_id, int $new_group_id ): UserGroupAssignment {
		if ( $user_id <= 0 ) {
			throw new InvalidAssignmentException(
				sprintf( 'Invalid user ID %d.', $user_id )
			);
		}

		if ( $new_group_id <= 0 ) {
			throw new InvalidAssignmentException(
				sprintf( 'Invalid group ID %d.', $new_group_id )
			);
		}

		// Verify target group exists.
		if ( ! $this->group_exists( $new_group_id ) ) {
			throw new InvalidAssignmentException(
				sprintf( 'Cannot reassign user to non-existent pricing group %d.', $new_group_id )
			);
		}

		$existing = $this->find_by_user_id( $user_id );

		if ( null === $existing ) {
			return $this->assign( $user_id, $new_group_id );
		}

		$now = current_time( 'mysql', true );

		$this->db->update(
			$this->table_user_groups,
			[
				'group_id'   => $new_group_id,
				'updated_at' => $now,
			],
			[ 'user_id' => $user_id ],
			[ '%d', '%s' ],
			[ '%d' ]
		);

		return $this->find_by_user_id( $user_id ) ?? $existing->reassign_to_group( $new_group_id );
	}

	public function find_by_user_id( int $user_id ): ?UserGroupAssignment {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $this->db->get_row(
			$this->db->prepare(
				"SELECT * FROM {$this->table_user_groups} WHERE user_id = %d LIMIT 1",
				$user_id
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return null;
		}

		return $this->hydrate( $row );
	}

	public function list_by_group_id( int $group_id, int $limit = 50, int $offset = 0 ): array {
		$safe_limit  = max( 1, min( 500, $limit ) );
		$safe_offset = max( 0, $offset );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT * FROM {$this->table_user_groups} WHERE group_id = %d ORDER BY id ASC LIMIT %d OFFSET %d",
				$group_id,
				$safe_limit,
				$safe_offset
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return [];
		}

		return array_map( [ $this, 'hydrate' ], $rows );
	}

	public function count_by_group_id( int $group_id ): int {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = $this->db->get_var(
			$this->db->prepare(
				"SELECT COUNT(*) FROM {$this->table_user_groups} WHERE group_id = %d",
				$group_id
			)
		);

		return (int) $count;
	}

	public function remove( int $user_id ): bool {
		$deleted = $this->db->delete(
			$this->table_user_groups,
			[ 'user_id' => $user_id ],
			[ '%d' ]
		);

		return false !== $deleted && $deleted > 0;
	}

	private function find_by_id( int $id ): ?UserGroupAssignment {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $this->db->get_row(
			$this->db->prepare(
				"SELECT * FROM {$this->table_user_groups} WHERE id = %d LIMIT 1",
				$id
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return null;
		}

		return $this->hydrate( $row );
	}

	private function group_exists( int $group_id ): bool {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$found = $this->db->get_var(
			$this->db->prepare(
				"SELECT id FROM {$this->table_groups} WHERE id = %d LIMIT 1",
				$group_id
			)
		);

		return null !== $found;
	}

	/**
	 * Hydrate a database row into a UserGroupAssignment domain entity.
	 *
	 * @param array<string, mixed> $row
	 */
	private function hydrate( array $row ): UserGroupAssignment {
		$created_at = ! empty( $row['created_at'] )
			? new DateTimeImmutable( (string) $row['created_at'] )
			: null;

		$updated_at = ! empty( $row['updated_at'] )
			? new DateTimeImmutable( (string) $row['updated_at'] )
			: null;

		return new UserGroupAssignment(
			(int) $row['user_id'],
			(int) $row['group_id'],
			(int) $row['id'],
			$created_at,
			$updated_at
		);
	}
}
