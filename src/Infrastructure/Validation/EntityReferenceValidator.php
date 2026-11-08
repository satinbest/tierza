<?php

declare(strict_types=1);

namespace Tierza\Infrastructure\Validation;

use Tierza\Domain\Exceptions\InvalidAssignmentException;
use Tierza\Domain\Exceptions\InvalidRuleException;
use Tierza\Domain\ValueObjects\RuleScope;
use Tierza\Domain\ValueObjects\RuleTarget;
use Tierza\Infrastructure\Database\Schema;
use WP_User;
use wpdb;

/**
 * Validates references to WordPress and WooCommerce entities (users, groups, categories, products, variations).
 */
class EntityReferenceValidator {

	private wpdb $db;

	public function __construct( ?wpdb $db = null ) {
		global $wpdb;
		$this->db = $db ?? $GLOBALS['wpdb'];
	}

	/**
	 * Check if WordPress user exists and is valid.
	 */
	public function user_exists( int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}

		if ( function_exists( 'get_userdata' ) ) {
			$user = get_userdata( $user_id );
			if ( $user instanceof WP_User && $user->exists() ) {
				return true;
			}
		}

		// Direct database check fallback.
		$table_users = $this->db->prefix . 'users';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$found = $this->db->get_var(
			$this->db->prepare(
				"SELECT ID FROM {$table_users} WHERE ID = %d LIMIT 1",
				$user_id
			)
		);

		return null !== $found;
	}

	/**
	 * Check if Tierza pricing group exists.
	 */
	public function group_exists( int $group_id ): bool {
		if ( $group_id <= 0 ) {
			return false;
		}

		$table_groups = Schema::groups_table( $this->db );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$found = $this->db->get_var(
			$this->db->prepare(
				"SELECT id FROM {$table_groups} WHERE id = %d LIMIT 1",
				$group_id
			)
		);

		return null !== $found;
	}

	/**
	 * Check if WooCommerce product category term exists.
	 */
	public function category_exists( int $category_id ): bool {
		if ( $category_id <= 0 ) {
			return false;
		}

		if ( function_exists( 'term_exists' ) ) {
			$exists = term_exists( $category_id, 'product_cat' );
			if ( ! empty( $exists ) ) {
				return true;
			}
		}

		// Direct database check on term_taxonomy table.
		$table_tt = $this->db->prefix . 'term_taxonomy';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$found = $this->db->get_var(
			$this->db->prepare(
				"SELECT term_id FROM {$table_tt} WHERE term_id = %d AND taxonomy = 'product_cat' LIMIT 1",
				$category_id
			)
		);

		return null !== $found;
	}

	/**
	 * Check if WooCommerce product exists (parent product, not a variation).
	 */
	public function product_exists( int $product_id ): bool {
		if ( $product_id <= 0 ) {
			return false;
		}

		if ( function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $product_id );
			if ( $product && ! $product->is_type( 'variation' ) ) {
				return true;
			}
		}

		// Direct database check on posts table.
		$table_posts = $this->db->prefix . 'posts';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$found = $this->db->get_var(
			$this->db->prepare(
				"SELECT ID FROM {$table_posts} WHERE ID = %d AND post_type = 'product' AND post_status != 'trash' LIMIT 1",
				$product_id
			)
		);

		return null !== $found;
	}

	/**
	 * Check if WooCommerce product variation exists.
	 */
	public function variation_exists( int $variation_id ): bool {
		if ( $variation_id <= 0 ) {
			return false;
		}

		if ( function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $variation_id );
			if ( $product && $product->is_type( 'variation' ) ) {
				return true;
			}
		}

		// Direct database check on posts table.
		$table_posts = $this->db->prefix . 'posts';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$found = $this->db->get_var(
			$this->db->prepare(
				"SELECT ID FROM {$table_posts} WHERE ID = %d AND post_type = 'product_variation' AND post_status != 'trash' LIMIT 1",
				$variation_id
			)
		);

		return null !== $found;
	}

	/**
	 * Validate a rule target against existing entities.
	 *
	 * @throws InvalidRuleException
	 */
	public function validate_target( RuleTarget $target ): void {
		if ( $target->is_group() ) {
			if ( ! $this->group_exists( $target->id() ) ) {
				throw new InvalidRuleException(
					sprintf( 'Target pricing group %d does not exist or has been deleted.', $target->id() )
				);
			}
		} elseif ( $target->is_user() ) {
			if ( ! $this->user_exists( $target->id() ) ) {
				throw new InvalidRuleException(
					sprintf( 'Target user %d does not exist or has been deleted.', $target->id() )
				);
			}
		}
	}

	/**
	 * Validate a rule scope against existing entities.
	 *
	 * @throws InvalidRuleException
	 */
	public function validate_scope( RuleScope $scope ): void {
		if ( $scope->is_global() ) {
			return;
		}

		if ( $scope->is_category() ) {
			if ( ! $this->category_exists( $scope->id() ) ) {
				throw new InvalidRuleException(
					sprintf( 'Scope product category %d does not exist or has been deleted.', $scope->id() )
				);
			}
		} elseif ( $scope->is_product() ) {
			if ( ! $this->product_exists( $scope->id() ) ) {
				throw new InvalidRuleException(
					sprintf( 'Scope product %d does not exist or has been deleted.', $scope->id() )
				);
			}
		} elseif ( $scope->is_variation() ) {
			if ( ! $this->variation_exists( $scope->id() ) ) {
				throw new InvalidRuleException(
					sprintf( 'Scope product variation %d does not exist or has been deleted.', $scope->id() )
				);
			}
		}
	}

	/**
	 * Validate user-group assignment references.
	 *
	 * @throws InvalidAssignmentException
	 */
	public function validate_assignment( int $user_id, int $group_id ): void {
		if ( ! $this->user_exists( $user_id ) ) {
			throw new InvalidAssignmentException(
				sprintf( 'Cannot assign non-existent user %d to pricing group.', $user_id )
			);
		}

		if ( ! $this->group_exists( $group_id ) ) {
			throw new InvalidAssignmentException(
				sprintf( 'Cannot assign user to non-existent pricing group %d.', $group_id )
			);
		}
	}
}
