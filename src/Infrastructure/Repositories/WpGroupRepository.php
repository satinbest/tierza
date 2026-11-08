<?php

declare(strict_types=1);

namespace Tierza\Infrastructure\Repositories;

use DateTimeImmutable;
use Tierza\Domain\Entities\PricingGroup;
use Tierza\Domain\Exceptions\DuplicateSlugException;
use Tierza\Domain\Exceptions\GroupDeletionSafeguardException;
use Tierza\Domain\Repositories\GroupRepositoryInterface;
use Tierza\Domain\ValueObjects\GroupSlug;
use Tierza\Domain\ValueObjects\GroupStatus;
use Tierza\Infrastructure\Database\Schema;
use wpdb;

/**
 * WordPress database implementation of GroupRepositoryInterface.
 */
final class WpGroupRepository implements GroupRepositoryInterface {

	private wpdb $db;
	private string $table_groups;
	private string $table_rules;
	private string $table_user_groups;

	public function __construct( ?wpdb $db = null ) {
		global $wpdb;
		$this->db                = $db ?? $GLOBALS['wpdb'];
		$this->table_groups      = Schema::groups_table( $this->db );
		$this->table_rules       = Schema::rules_table( $this->db );
		$this->table_user_groups = Schema::user_groups_table( $this->db );
	}

	public function save( PricingGroup $group ): PricingGroup {
		$slug_val = $group->slug()->value();
		$group_id = $group->id();

		// Check for duplicate slug on existing records.
		if ( null !== $group_id ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is derived from Schema.
			$duplicate = $this->db->get_var(
				$this->db->prepare(
					"SELECT id FROM {$this->table_groups} WHERE slug = %s AND id != %d LIMIT 1",
					$slug_val,
					$group_id
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is derived from Schema.
			$duplicate = $this->db->get_var(
				$this->db->prepare(
					"SELECT id FROM {$this->table_groups} WHERE slug = %s LIMIT 1",
					$slug_val
				)
			);
		}

		if ( null !== $duplicate ) {
			throw new DuplicateSlugException(
				sprintf( 'Pricing group slug "%s" is already in use by group ID %d.', $slug_val, (int) $duplicate )
			);
		}

		$now = current_time( 'mysql', true );

		if ( null === $group_id ) {
			// Insert new record.
			$this->db->insert(
				$this->table_groups,
				[
					'name'        => $group->name(),
					'slug'        => $slug_val,
					'description' => $group->description(),
					'status'      => $group->status()->value(),
					'sort_order'  => $group->sort_order(),
					'created_at'  => $now,
					'updated_at'  => $now,
				],
				[ '%s', '%s', '%s', '%s', '%d', '%s', '%s' ]
			);

			$insert_id = (int) $this->db->insert_id;
			return $this->find_by_id( $insert_id ) ?? $group->with_id( $insert_id );
		}

		// Update existing record.
		$this->db->update(
			$this->table_groups,
			[
				'name'        => $group->name(),
				'slug'        => $slug_val,
				'description' => $group->description(),
				'status'      => $group->status()->value(),
				'sort_order'  => $group->sort_order(),
				'updated_at'  => $now,
			],
			[ 'id' => $group_id ],
			[ '%s', '%s', '%s', '%s', '%d', '%s' ],
			[ '%d' ]
		);

		return $this->find_by_id( $group_id ) ?? $group;
	}

	public function find_by_id( int $id ): ?PricingGroup {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $this->db->get_row(
			$this->db->prepare(
				"SELECT * FROM {$this->table_groups} WHERE id = %d LIMIT 1",
				$id
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return null;
		}

		return $this->hydrate( $row );
	}

	public function find_by_slug( string $slug ): ?PricingGroup {
		$normalized = GroupSlug::normalize( $slug );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $this->db->get_row(
			$this->db->prepare(
				"SELECT * FROM {$this->table_groups} WHERE slug = %s LIMIT 1",
				$normalized
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return null;
		}

		return $this->hydrate( $row );
	}

	public function list( int $limit = 50, int $offset = 0, ?string $status = null ): array {
		$safe_limit  = max( 1, min( 500, $limit ) );
		$safe_offset = max( 0, $offset );

		if ( null !== $status ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $this->db->get_results(
				$this->db->prepare(
					"SELECT * FROM {$this->table_groups} WHERE status = %s ORDER BY sort_order ASC, name ASC LIMIT %d OFFSET %d",
					$status,
					$safe_limit,
					$safe_offset
				),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $this->db->get_results(
				$this->db->prepare(
					"SELECT * FROM {$this->table_groups} ORDER BY sort_order ASC, name ASC LIMIT %d OFFSET %d",
					$safe_limit,
					$safe_offset
				),
				ARRAY_A
			);
		}

		if ( ! is_array( $rows ) ) {
			return [];
		}

		return array_map( [ $this, 'hydrate' ], $rows );
	}

	public function count( ?string $status = null ): int {
		if ( null !== $status ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$count = $this->db->get_var(
				$this->db->prepare(
					"SELECT COUNT(*) FROM {$this->table_groups} WHERE status = %s",
					$status
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$count = $this->db->get_var( "SELECT COUNT(*) FROM {$this->table_groups}" );
		}

		return (int) $count;
	}

	public function search_by_name( string $keyword, int $limit = 50, int $offset = 0 ): array {
		$safe_limit  = max( 1, min( 500, $limit ) );
		$safe_offset = max( 0, $offset );
		$like        = '%' . $this->db->esc_like( trim( $keyword ) ) . '%';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT * FROM {$this->table_groups} WHERE name LIKE %s ORDER BY sort_order ASC, name ASC LIMIT %d OFFSET %d",
				$like,
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

	public function delete( int $id ): bool {
		if ( $this->has_assigned_users( $id ) ) {
			throw new GroupDeletionSafeguardException(
				sprintf( 'Cannot delete pricing group %d: user accounts are actively assigned to it.', $id )
			);
		}

		if ( $this->has_rules( $id ) ) {
			throw new GroupDeletionSafeguardException(
				sprintf( 'Cannot delete pricing group %d: pricing rules are attached to it.', $id )
			);
		}

		$deleted = $this->db->delete(
			$this->table_groups,
			[ 'id' => $id ],
			[ '%d' ]
		);

		return false !== $deleted && $deleted > 0;
	}

	public function has_assigned_users( int $group_id ): bool {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = $this->db->get_var(
			$this->db->prepare(
				"SELECT COUNT(*) FROM {$this->table_user_groups} WHERE group_id = %d",
				$group_id
			)
		);

		return (int) $count > 0;
	}

	public function has_rules( int $group_id ): bool {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = $this->db->get_var(
			$this->db->prepare(
				"SELECT COUNT(*) FROM {$this->table_rules} WHERE target_type = 'group' AND target_id = %d",
				$group_id
			)
		);

		return (int) $count > 0;
	}

	/**
	 * Hydrate a database row into a PricingGroup domain entity.
	 *
	 * @param array<string, mixed> $row
	 */
	private function hydrate( array $row ): PricingGroup {
		$created_at = ! empty( $row['created_at'] )
			? new DateTimeImmutable( (string) $row['created_at'] )
			: null;

		$updated_at = ! empty( $row['updated_at'] )
			? new DateTimeImmutable( (string) $row['updated_at'] )
			: null;

		return new PricingGroup(
			(string) $row['name'],
			new GroupSlug( (string) $row['slug'] ),
			(int) $row['id'],
			isset( $row['description'] ) ? (string) $row['description'] : null,
			new GroupStatus( (string) $row['status'] ),
			(int) $row['sort_order'],
			$created_at,
			$updated_at
		);
	}
}
