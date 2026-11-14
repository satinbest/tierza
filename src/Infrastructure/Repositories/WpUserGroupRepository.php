<?php

declare(strict_types=1);

namespace Tierza\Infrastructure\Repositories;

use DateTimeImmutable;
use Tierza\Application\Pricing\Cache\VariationPriceCacheInvalidator;
use Tierza\Domain\Entities\UserGroupAssignment;
use Tierza\Domain\Exceptions\InvalidAssignmentException;
use Tierza\Domain\Pricing\Contracts\PricingCacheInterface;
use Tierza\Domain\Repositories\UserGroupRepositoryInterface;
use Tierza\Infrastructure\Database\Schema;
use Tierza\Infrastructure\Exceptions\DatabaseQueryException;
use Tierza\Infrastructure\Validation\EntityReferenceValidator;
use wpdb;

/**
 * WordPress database implementation of UserGroupRepositoryInterface.
 */
final class WpUserGroupRepository implements UserGroupRepositoryInterface {

	private wpdb $db;
	private string $table_user_groups;
	private EntityReferenceValidator $validator;
	private ?PricingCacheInterface $cache;
	private ?VariationPriceCacheInvalidator $variation_cache_invalidator;

	/**
	 * In-memory map of user_id => ?UserGroupAssignment for current request.
	 *
	 * @var array<int, ?UserGroupAssignment>
	 */
	private array $user_assignments = [];

	public function __construct(
		?wpdb $db = null,
		?EntityReferenceValidator $validator = null,
		?PricingCacheInterface $cache = null,
		?VariationPriceCacheInvalidator $variation_cache_invalidator = null
	) {
		global $wpdb;
		$this->db                          = $db ?? $GLOBALS['wpdb'];
		$this->table_user_groups           = Schema::user_groups_table( $this->db );
		$this->validator                   = $validator ?? new EntityReferenceValidator( $this->db );
		$this->cache                       = $cache;
		$this->variation_cache_invalidator = $variation_cache_invalidator;
	}

	public function set_cache( ?PricingCacheInterface $cache ): void {
		$this->cache = $cache;
	}

	public function set_variation_cache_invalidator( ?VariationPriceCacheInvalidator $invalidator ): void {
		$this->variation_cache_invalidator = $invalidator;
	}

	public function cache(): ?PricingCacheInterface {
		return $this->cache;
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

		// Validate user and group entity references.
		$this->validator->validate_assignment( $user_id, $group_id );

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

		$inserted = $this->db->insert(
			$this->table_user_groups,
			[
				'user_id'    => $user_id,
				'group_id'   => $group_id,
				'created_at' => $now,
				'updated_at' => $now,
			],
			[ '%d', '%d', '%s', '%s' ]
		);

		if ( false === $inserted && ! empty( $this->db->last_error ) ) {
			throw new DatabaseQueryException( 'Database query failed while assigning user to group.' );
		}

		$insert_id                          = (int) $this->db->insert_id;
		$assigned                           = $this->find_by_id( $insert_id ) ?? new UserGroupAssignment(
			$user_id,
			$group_id,
			$insert_id,
			new DateTimeImmutable( $now ),
			new DateTimeImmutable( $now )
		);
		$this->user_assignments[ $user_id ] = $assigned;
		$this->cache?->invalidate_customer( $user_id );
		$this->variation_cache_invalidator?->invalidate_for_customer( $user_id );
		return $assigned;
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

		// Validate user and group entity references.
		$this->validator->validate_assignment( $user_id, $new_group_id );

		$existing = $this->find_by_user_id( $user_id );

		if ( null === $existing ) {
			return $this->assign( $user_id, $new_group_id );
		}

		$now = current_time( 'mysql', true );

		$updated = $this->db->update(
			$this->table_user_groups,
			[
				'group_id'   => $new_group_id,
				'updated_at' => $now,
			],
			[ 'user_id' => $user_id ],
			[ '%d', '%s' ],
			[ '%d' ]
		);

		if ( false === $updated && ! empty( $this->db->last_error ) ) {
			throw new DatabaseQueryException( 'Database query failed while reassigning user group.' );
		}

		unset( $this->user_assignments[ $user_id ] );
		$assigned                           = $this->find_by_user_id( $user_id ) ?? $existing->reassign_to_group( $new_group_id );
		$this->user_assignments[ $user_id ] = $assigned;
		$this->cache?->invalidate_customer( $user_id );
		$this->variation_cache_invalidator?->invalidate_for_customer( $user_id );
		return $assigned;
	}

	public function find_by_user_id( int $user_id ): ?UserGroupAssignment {
		if ( array_key_exists( $user_id, $this->user_assignments ) ) {
			return $this->user_assignments[ $user_id ];
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $this->db->get_row(
			$this->db->prepare(
				"SELECT * FROM {$this->table_user_groups} WHERE user_id = %d LIMIT 1",
				$user_id
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			$this->user_assignments[ $user_id ] = null;
			return null;
		}

		$assignment                         = $this->hydrate( $row );
		$this->user_assignments[ $user_id ] = $assignment;
		return $assignment;
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

		if ( false === $deleted && ! empty( $this->db->last_error ) ) {
			throw new DatabaseQueryException( 'Database query failed while removing user group assignment.' );
		}

		$success = false !== $deleted && $deleted > 0;
		if ( $success ) {
			unset( $this->user_assignments[ $user_id ] );
			$this->cache?->invalidate_customer( $user_id );
			$this->variation_cache_invalidator?->invalidate_for_customer( $user_id );
		}

		return $success;
	}

	public function clear_cache(): void {
		$this->user_assignments = [];
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
