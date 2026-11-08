<?php

declare(strict_types=1);

namespace Tierza\Infrastructure\Repositories;

use DateTimeImmutable;
use RuntimeException;
use Tierza\Domain\Entities\PricingRule;
use Tierza\Domain\Exceptions\DuplicateRuleException;
use Tierza\Domain\Repositories\RuleRepositoryInterface;
use Tierza\Domain\ValueObjects\PricingValue;
use Tierza\Domain\ValueObjects\RuleScope;
use Tierza\Domain\ValueObjects\RuleStatus;
use Tierza\Domain\ValueObjects\RuleTarget;
use Tierza\Infrastructure\Database\Schema;
use Tierza\Infrastructure\Validation\EntityReferenceValidator;
use wpdb;

/**
 * WordPress database implementation of RuleRepositoryInterface.
 */
final class WpRuleRepository implements RuleRepositoryInterface {

	private wpdb $db;
	private string $table_rules;
	private EntityReferenceValidator $validator;

	public function __construct( ?wpdb $db = null, ?EntityReferenceValidator $validator = null ) {
		global $wpdb;
		$this->db          = $db ?? $GLOBALS['wpdb'];
		$this->table_rules = Schema::rules_table( $this->db );
		$this->validator   = $validator ?? new EntityReferenceValidator( $this->db );
	}

	public function save( PricingRule $rule ): PricingRule {
		// Validate target and scope entity references before persistence.
		$this->validator->validate_target( $rule->target() );
		$this->validator->validate_scope( $rule->scope() );

		// Enforce active rule logical uniqueness at application layer.
		if ( $rule->is_active() ) {
			$conflict = $this->find_conflicting_active_rule( $rule );
			if ( null !== $conflict ) {
				throw new DuplicateRuleException(
					sprintf(
						'Conflicting active rule already exists (Rule ID %d) for target %s:%d and scope %s:%d.',
						(int) $conflict->id(),
						$rule->target()->type(),
						$rule->target()->id(),
						$rule->scope()->type(),
						$rule->scope()->id()
					)
				);
			}
		}

		$now        = current_time( 'mysql', true );
		$rule_id    = $rule->id();
		$active_key = $rule->active_key();

		if ( null === $rule_id ) {
			// Insert new record with database-enforced unique active_key.
			$inserted = $this->db->insert(
				$this->table_rules,
				[
					'target_type'  => $rule->target()->type(),
					'target_id'    => $rule->target()->id(),
					'scope_type'   => $rule->scope()->type(),
					'scope_id'     => $rule->scope()->id(),
					'pricing_type' => $rule->pricing_value()->type(),
					'value'        => $rule->pricing_value()->value(),
					'status'       => $rule->status()->value(),
					'active_key'   => $active_key,
					'created_at'   => $now,
					'updated_at'   => $now,
				],
				[ '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' ]
			);

			if ( false === $inserted ) {
				if ( ! empty( $this->db->last_error ) && preg_match( '/uq_active_rule|duplicate/i', $this->db->last_error ) ) {
					throw new DuplicateRuleException(
						sprintf(
							'Concurrent active rule conflict detected at database engine level for target %s:%d and scope %s:%d.',
							$rule->target()->type(),
							$rule->target()->id(),
							$rule->scope()->type(),
							$rule->scope()->id()
						)
					);
				}
				throw new RuntimeException( sprintf( 'Failed to insert rule: %s', $this->db->last_error ) );
			}

			$insert_id = (int) $this->db->insert_id;
			return $this->find_by_id( $insert_id ) ?? $rule->with_id( $insert_id );
		}

		// Update existing record.
		$updated = $this->db->update(
			$this->table_rules,
			[
				'target_type'  => $rule->target()->type(),
				'target_id'    => $rule->target()->id(),
				'scope_type'   => $rule->scope()->type(),
				'scope_id'     => $rule->scope()->id(),
				'pricing_type' => $rule->pricing_value()->type(),
				'value'        => $rule->pricing_value()->value(),
				'status'       => $rule->status()->value(),
				'active_key'   => $active_key,
				'updated_at'   => $now,
			],
			[ 'id' => $rule_id ],
			[ '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s' ],
			[ '%d' ]
		);

		if ( false === $updated && ! empty( $this->db->last_error ) && preg_match( '/uq_active_rule|duplicate/i', $this->db->last_error ) ) {
			throw new DuplicateRuleException(
				sprintf(
					'Concurrent active rule conflict detected on update for target %s:%d and scope %s:%d.',
					$rule->target()->type(),
					$rule->target()->id(),
					$rule->scope()->type(),
					$rule->scope()->id()
				)
			);
		}

		return $this->find_by_id( $rule_id ) ?? $rule;
	}

	public function find_by_id( int $id ): ?PricingRule {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $this->db->get_row(
			$this->db->prepare(
				"SELECT * FROM {$this->table_rules} WHERE id = %d LIMIT 1",
				$id
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return null;
		}

		return $this->hydrate( $row );
	}

	public function find_active_by_target( RuleTarget $target ): array {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT * FROM {$this->table_rules} WHERE target_type = %s AND target_id = %d AND status = %s ORDER BY id ASC",
				$target->type(),
				$target->id(),
				RuleStatus::ACTIVE
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return [];
		}

		return array_map( [ $this, 'hydrate' ], $rows );
	}

	public function find_active_by_scope( RuleScope $scope ): array {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT * FROM {$this->table_rules} WHERE scope_type = %s AND scope_id = %d AND status = %s ORDER BY id ASC",
				$scope->type(),
				$scope->id(),
				RuleStatus::ACTIVE
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return [];
		}

		return array_map( [ $this, 'hydrate' ], $rows );
	}

	public function find_conflicting_active_rule( PricingRule $rule ): ?PricingRule {
		$target_type = $rule->target()->type();
		$target_id   = $rule->target()->id();
		$scope_type  = $rule->scope()->type();
		$scope_id    = $rule->scope()->id();
		$rule_id     = $rule->id();

		if ( null !== $rule_id ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$row = $this->db->get_row(
				$this->db->prepare(
					"SELECT * FROM {$this->table_rules} WHERE target_type = %s AND target_id = %d AND scope_type = %s AND scope_id = %d AND status = %s AND id != %d LIMIT 1",
					$target_type,
					$target_id,
					$scope_type,
					$scope_id,
					RuleStatus::ACTIVE,
					$rule_id
				),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$row = $this->db->get_row(
				$this->db->prepare(
					"SELECT * FROM {$this->table_rules} WHERE target_type = %s AND target_id = %d AND scope_type = %s AND scope_id = %d AND status = %s LIMIT 1",
					$target_type,
					$target_id,
					$scope_type,
					$scope_id,
					RuleStatus::ACTIVE
				),
				ARRAY_A
			);
		}

		if ( ! is_array( $row ) ) {
			return null;
		}

		return $this->hydrate( $row );
	}

	public function list( array $filters = [], int $limit = 50, int $offset = 0 ): array {
		$safe_limit  = max( 1, min( 500, $limit ) );
		$safe_offset = max( 0, $offset );

		$where_clauses = [];
		$params        = [];

		if ( ! empty( $filters['target_type'] ) ) {
			$where_clauses[] = 'target_type = %s';
			$params[]        = (string) $filters['target_type'];
		}

		if ( isset( $filters['target_id'] ) ) {
			$where_clauses[] = 'target_id = %d';
			$params[]        = (int) $filters['target_id'];
		}

		if ( ! empty( $filters['scope_type'] ) ) {
			$where_clauses[] = 'scope_type = %s';
			$params[]        = (string) $filters['scope_type'];
		}

		if ( isset( $filters['scope_id'] ) ) {
			$where_clauses[] = 'scope_id = %d';
			$params[]        = (int) $filters['scope_id'];
		}

		if ( ! empty( $filters['pricing_type'] ) ) {
			$where_clauses[] = 'pricing_type = %s';
			$params[]        = (string) $filters['pricing_type'];
		}

		if ( ! empty( $filters['status'] ) ) {
			$where_clauses[] = 'status = %s';
			$params[]        = (string) $filters['status'];
		}

		$where_sql = ! empty( $where_clauses )
			? 'WHERE ' . implode( ' AND ', $where_clauses )
			: '';

		$query_sql = "SELECT * FROM {$this->table_rules} {$where_sql} ORDER BY id ASC LIMIT %d OFFSET %d";
		$params[]  = $safe_limit;
		$params[]  = $safe_offset;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$prepared = $this->db->prepare( $query_sql, ...$params );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $this->db->get_results( $prepared, ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return [];
		}

		return array_map( [ $this, 'hydrate' ], $rows );
	}

	public function count( array $filters = [] ): int {
		$where_clauses = [];
		$params        = [];

		if ( ! empty( $filters['target_type'] ) ) {
			$where_clauses[] = 'target_type = %s';
			$params[]        = (string) $filters['target_type'];
		}

		if ( isset( $filters['target_id'] ) ) {
			$where_clauses[] = 'target_id = %d';
			$params[]        = (int) $filters['target_id'];
		}

		if ( ! empty( $filters['scope_type'] ) ) {
			$where_clauses[] = 'scope_type = %s';
			$params[]        = (string) $filters['scope_type'];
		}

		if ( isset( $filters['scope_id'] ) ) {
			$where_clauses[] = 'scope_id = %d';
			$params[]        = (int) $filters['scope_id'];
		}

		if ( ! empty( $filters['pricing_type'] ) ) {
			$where_clauses[] = 'pricing_type = %s';
			$params[]        = (string) $filters['pricing_type'];
		}

		if ( ! empty( $filters['status'] ) ) {
			$where_clauses[] = 'status = %s';
			$params[]        = (string) $filters['status'];
		}

		$where_sql = ! empty( $where_clauses )
			? 'WHERE ' . implode( ' AND ', $where_clauses )
			: '';

		$query_sql = "SELECT COUNT(*) FROM {$this->table_rules} {$where_sql}";

		if ( ! empty( $params ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$prepared = $this->db->prepare( $query_sql, ...$params );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$count = $this->db->get_var( $prepared );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$count = $this->db->get_var( $query_sql );
		}

		return (int) $count;
	}

	public function delete( int $id ): bool {
		$deleted = $this->db->delete(
			$this->table_rules,
			[ 'id' => $id ],
			[ '%d' ]
		);

		return false !== $deleted && $deleted > 0;
	}

	/**
	 * Hydrate a database row into a PricingRule domain entity.
	 *
	 * @param array<string, mixed> $row
	 */
	private function hydrate( array $row ): PricingRule {
		$created_at = ! empty( $row['created_at'] )
			? new DateTimeImmutable( (string) $row['created_at'] )
			: null;

		$updated_at = ! empty( $row['updated_at'] )
			? new DateTimeImmutable( (string) $row['updated_at'] )
			: null;

		return new PricingRule(
			new RuleTarget( (string) $row['target_type'], (int) $row['target_id'] ),
			new RuleScope( (string) $row['scope_type'], (int) $row['scope_id'] ),
			new PricingValue( (string) $row['pricing_type'], (string) $row['value'] ),
			(int) $row['id'],
			new RuleStatus( (string) $row['status'] ),
			$created_at,
			$updated_at
		);
	}
}
