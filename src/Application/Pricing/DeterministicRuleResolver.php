<?php

declare(strict_types=1);

namespace Tierza\Application\Pricing;

use Throwable;
use Tierza\Domain\Entities\PricingRule;
use Tierza\Domain\Exceptions\RuleResolutionException;
use Tierza\Domain\Pricing\Contracts\RuleResolverInterface;
use Tierza\Domain\Pricing\Enums\PrecedenceLevel;
use Tierza\Domain\Pricing\Enums\PricingReasonCode;
use Tierza\Domain\Pricing\PricingContext;
use Tierza\Domain\Pricing\ResolutionResult;
use Tierza\Domain\Repositories\GroupRepositoryInterface;
use Tierza\Domain\Repositories\RuleRepositoryInterface;
use Tierza\Domain\ValueObjects\DecimalMath;
use Tierza\Domain\ValueObjects\RuleTarget;

/**
 * Deterministic Pricing Rule Resolver (TRZ-006).
 *
 * Implements the authoritative 9-level precedence hierarchy for Tierza B2B pricing:
 *
 * 1. Customer Variation (CUSTOMER_VARIATION)
 * 2. Customer Product   (CUSTOMER_PRODUCT)
 * 3. Customer Category  (CUSTOMER_CATEGORY)
 * 4. Customer Global    (CUSTOMER_GLOBAL)
 * 5. Group Variation    (GROUP_VARIATION)
 * 6. Group Product      (GROUP_PRODUCT)
 * 7. Group Category     (GROUP_CATEGORY)
 * 8. Group Global       (GROUP_GLOBAL)
 * 9. WooCommerce Base   (WOOCOMMERCE_FALLBACK)
 *
 * Strict Invariants:
 * - Deterministic: Same input context + ruleset = exact same outcome, independent of DB order or PHP version.
 * - Non-Stacking: Only a single winning rule is selected in v1.
 * - Explicit Zero Preserved: 0% discount and 0.00 fixed prices are valid authoritative rules.
 * - Customer Isolation: Anonymous / logged-out users NEVER receive customer-specific rules.
 * - Group Inactive Safeguard: Inactive or deleted groups never yield active group rules.
 * - Parent Inheritance: Variations inherit parent product rules within the same customer/group priority tier.
 * - Category Hierarchy Tie-Break:
 *     1. Greatest hierarchy depth wins.
 *     2. Lowest final price (highest discount percentage in v1) wins.
 *     3. Smallest category ID (category_id ASC) wins.
 */
final class DeterministicRuleResolver implements RuleResolverInterface {

	private RuleRepositoryInterface $rule_repository;
	private ?GroupRepositoryInterface $group_repository;

	public function __construct(
		RuleRepositoryInterface $rule_repository,
		?GroupRepositoryInterface $group_repository = null
	) {
		$this->rule_repository  = $rule_repository;
		$this->group_repository = $group_repository;
	}

	/**
	 * Resolves the single winning pricing rule for the given context.
	 *
	 * @throws RuleResolutionException If an unrecoverable validation error, data corruption, or DB failure occurs.
	 */
	public function resolve( PricingContext $context ): ResolutionResult {
		$this->validate_context( $context );

		// Step 1: Identify eligible active targets.
		$targets = $this->determine_eligible_targets( $context );

		// If no targets are eligible (e.g. anonymous user without an active group), fallback immediately.
		if ( empty( $targets ) ) {
			$reason = $context->is_anonymous()
				? PricingReasonCode::FALLBACK_ANONYMOUS_NO_RULE->value
				: PricingReasonCode::FALLBACK_NO_MATCHING_RULE->value;

			return ResolutionResult::fallback( $reason, [ 'targets' => [] ] );
		}

		// Step 2: Fetch active candidate rules from repository.
		$candidate_rules = $this->fetch_candidate_rules( $targets );

		// If no active rules exist for eligible targets, return clean fallback.
		if ( empty( $candidate_rules ) ) {
			$reason = $context->is_anonymous()
				? PricingReasonCode::FALLBACK_ANONYMOUS_NO_RULE->value
				: PricingReasonCode::FALLBACK_NO_MATCHING_RULE->value;

			return ResolutionResult::fallback( $reason );
		}

		// Step 3: Validate rule data integrity.
		$this->validate_rules_integrity( $candidate_rules, $context, $targets );

		// Step 4: Partition rules into the 8 active precedence levels.
		$buckets = $this->partition_rules( $candidate_rules, $context );

		// Step 5: Evaluate levels in strict precedence order (1 through 8).
		foreach ( PrecedenceLevel::cases() as $level ) {
			if ( PrecedenceLevel::WOOCOMMERCE_FALLBACK === $level ) {
				continue;
			}

			$level_rules = $buckets[ $level->value ] ?? [];
			if ( empty( $level_rules ) ) {
				continue;
			}

			// Level matched! Select the single winning rule for this level.
			$winning_rule = $this->select_winning_rule_for_level( $level, $level_rules, $context );

			return ResolutionResult::matched(
				$winning_rule,
				$level,
				null,
				[
					'target_type' => $winning_rule->target()->type(),
					'target_id'   => $winning_rule->target()->id(),
					'scope_type'  => $winning_rule->scope()->type(),
					'scope_id'    => $winning_rule->scope()->id(),
				]
			);
		}

		// Level 9: Fallback when no eligible rule matched.
		$reason = $context->is_anonymous()
			? PricingReasonCode::FALLBACK_ANONYMOUS_NO_RULE->value
			: PricingReasonCode::FALLBACK_NO_MATCHING_RULE->value;

		return ResolutionResult::fallback( $reason );
	}

	/**
	 * Validates pricing context prerequisites.
	 *
	 * @throws RuleResolutionException
	 */
	private function validate_context( PricingContext $context ): void {
		if ( $context->product_id() <= 0 ) {
			throw new RuleResolutionException(
				sprintf( 'Missing or invalid product ID: %d.', $context->product_id() )
			);
		}

		if ( $context->customer_id() < 0 ) {
			throw new RuleResolutionException(
				sprintf( 'Customer ID cannot be negative: %d.', $context->customer_id() )
			);
		}

		if ( $context->is_variation() ) {
			$variation_id = $context->variation_id();
			if ( null === $variation_id || $variation_id <= 0 ) {
				throw new RuleResolutionException( 'Variation context must have a positive variation ID.' );
			}
			if ( $variation_id === $context->product_id() ) {
				throw new RuleResolutionException(
					sprintf( 'Invalid variation relationship: variation ID (%d) cannot equal product ID (%d).', $variation_id, $context->product_id() )
				);
			}
		}
	}

	/**
	 * Determines eligible target identifiers based on authentication and group status.
	 *
	 * @return RuleTarget[]
	 * @throws RuleResolutionException If an invalid or deleted group is referenced.
	 */
	private function determine_eligible_targets( PricingContext $context ): array {
		$targets = [];

		// Customer target: ONLY eligible for authenticated users.
		if ( $context->is_authenticated() ) {
			$targets[] = RuleTarget::user( $context->customer_id() );
		}

		// Group target: eligible only when group exists and is active.
		$group_id = $context->group_id();
		if ( null !== $group_id && $group_id > 0 ) {
			$is_active = $this->is_group_active( $context, $group_id );
			if ( $is_active ) {
				$targets[] = RuleTarget::group( $group_id );
			}
		}

		return $targets;
	}

	/**
	 * Checks whether the referenced group is active, and verifies reference integrity.
	 *
	 * @throws RuleResolutionException If the referenced group ID is invalid or deleted.
	 */
	private function is_group_active( PricingContext $context, int $group_id ): bool {
		$group = $context->group();
		if ( null !== $group ) {
			return $group->is_active();
		}

		// If an entity was not attached to context, verify against the group repository.
		if ( null === $this->group_repository ) {
			throw new RuleResolutionException(
				sprintf(
					'Cannot verify group eligibility for group ID %d: neither a trusted PricingGroup entity in PricingContext nor a GroupRepositoryInterface was provided.',
					$group_id
				)
			);
		}

		try {
			$loaded_group = $this->group_repository->find_by_id( $group_id );
		} catch ( Throwable $e ) {
			throw new RuleResolutionException(
				sprintf( 'Failed to verify referenced pricing group ID %d: %s', $group_id, $e->getMessage() ),
				0,
				$e
			);
		}

		if ( null === $loaded_group ) {
			throw new RuleResolutionException(
				sprintf( 'Referenced pricing group ID %d does not exist or has been deleted.', $group_id )
			);
		}

		return $loaded_group->is_active();
	}

	/**
	 * Queries active candidate rules from the repository.
	 *
	 * @param RuleTarget[] $targets
	 * @return PricingRule[]
	 * @throws RuleResolutionException
	 */
	private function fetch_candidate_rules( array $targets ): array {
		try {
			return $this->rule_repository->find_active_for_targets( $targets );
		} catch ( Throwable $e ) {
			throw new RuleResolutionException(
				sprintf( 'Repository failure while querying pricing rules: %s', $e->getMessage() ),
				0,
				$e
			);
		}
	}

	/**
	 * Validates data integrity of rules returned by the repository.
	 *
	 * @param PricingRule[] $rules
	 * @param RuleTarget[]   $targets
	 * @throws RuleResolutionException If data integrity is violated.
	 */
	private function validate_rules_integrity( array $rules, PricingContext $context, array $targets ): void {
		$target_signatures = [];
		foreach ( $targets as $target ) {
			$target_signatures[] = $target->type() . ':' . $target->id();
		}

		foreach ( $rules as $rule ) {
			$rule_id = (int) $rule->id();

			// 0. Active status check (defensive against faulty repository returning inactive rules)
			if ( ! $rule->is_active() ) {
				throw new RuleResolutionException(
					sprintf( 'Corrupted candidate rules: repository returned inactive rule ID %d.', $rule_id )
				);
			}

			// 1. Target consistency check
			$rule_target = $rule->target();
			$sig         = $rule_target->type() . ':' . $rule_target->id();
			if ( ! in_array( $sig, $target_signatures, true ) ) {
				throw new RuleResolutionException(
					sprintf( 'Inconsistent rule target: rule ID %d has target %s, which is not an eligible context target.', $rule_id, $sig )
				);
			}

			// 2. Pricing value bounds check
			$val   = $rule->pricing_value();
			$scope = $rule->scope();
			if ( $val->is_percentage() ) {
				if ( DecimalMath::comp( $val->value(), '0', 6 ) < 0 || DecimalMath::comp( $val->value(), '100', 6 ) > 0 ) {
					throw new RuleResolutionException(
						sprintf( 'Invalid percentage value "%s" on rule ID %d. Percentage must be between 0 and 100.', $val->value(), $rule_id )
					);
				}
			} elseif ( $val->is_fixed() ) {
				if ( DecimalMath::comp( $val->value(), '0', 6 ) < 0 ) {
					throw new RuleResolutionException(
						sprintf( 'Invalid fixed price value "%s" on rule ID %d. Value cannot be negative.', $val->value(), $rule_id )
					);
				}

				if ( ! ( $scope->is_product() || $scope->is_variation() ) ) {
					throw new RuleResolutionException(
						sprintf( 'Invalid rule: fixed-price rules are restricted to product or variation scopes, rule ID %d has scope "%s".', $rule_id, $scope->type() )
					);
				}
			}

			// 4. Scope validity check
			if ( $scope->is_global() && 0 !== $scope->id() ) {
				throw new RuleResolutionException(
					sprintf( 'Invalid rule scope: global rule ID %d must have scope ID 0, got %d.', $rule_id, $scope->id() )
				);
			}
			if ( ! $scope->is_global() && $scope->id() <= 0 ) {
				throw new RuleResolutionException(
					sprintf( 'Invalid rule scope: non-global rule ID %d must have a positive scope ID, got %d.', $rule_id, $scope->id() )
				);
			}
		}
	}

	/**
	 * Partitions eligible rules into precedence level buckets (levels 1 through 8).
	 *
	 * @param PricingRule[] $rules
	 * @return array<int, PricingRule[]>
	 */
	private function partition_rules( array $rules, PricingContext $context ): array {
		$buckets = array_fill_keys( range( 1, 8 ), [] );

		$product_id   = $context->product_id();
		$variation_id = $context->variation_id();
		$category_ids = $context->category_ids();

		foreach ( $rules as $rule ) {
			if ( ! $rule->is_active() ) {
				continue;
			}

			$is_user = $rule->target()->is_user();
			$scope   = $rule->scope();

			// Precedence 1 & 5: Variation rules.
			if ( $scope->is_variation() ) {
				if ( $context->is_variation() && $scope->id() === $variation_id ) {
					$level               = $is_user ? PrecedenceLevel::CUSTOMER_VARIATION->value : PrecedenceLevel::GROUP_VARIATION->value;
					$buckets[ $level ][] = $rule;
				}
				continue;
			}

			// Precedence 2 & 6: Product rules (applies to simple products and as parent-product fallback for variations).
			if ( $scope->is_product() ) {
				if ( $scope->id() === $product_id ) {
					$level               = $is_user ? PrecedenceLevel::CUSTOMER_PRODUCT->value : PrecedenceLevel::GROUP_PRODUCT->value;
					$buckets[ $level ][] = $rule;
				}
				continue;
			}

			// Precedence 3 & 7: Category rules.
			if ( $scope->is_category() ) {
				if ( in_array( $scope->id(), $category_ids, true ) ) {
					$level               = $is_user ? PrecedenceLevel::CUSTOMER_CATEGORY->value : PrecedenceLevel::GROUP_CATEGORY->value;
					$buckets[ $level ][] = $rule;
				}
				continue;
			}

			// Precedence 4 & 8: Global rules.
			if ( $scope->is_global() ) {
				$level               = $is_user ? PrecedenceLevel::CUSTOMER_GLOBAL->value : PrecedenceLevel::GROUP_GLOBAL->value;
				$buckets[ $level ][] = $rule;
			}
		}

		return $buckets;
	}

	/**
	 * Selects the single winning rule for a matched precedence level.
	 *
	 * Detects conflicting same-priority rules and executes category hierarchy tie-breaking.
	 *
	 * @param PricingRule[] $rules
	 * @throws RuleResolutionException If an unresolved conflict or missing metadata occurs.
	 */
	private function select_winning_rule_for_level(
		PrecedenceLevel $level,
		array $rules,
		PricingContext $context
	): PricingRule {
		// Category levels: execute PRD Steps 5-7 Category Hierarchy Resolution.
		if ( PrecedenceLevel::CUSTOMER_CATEGORY === $level || PrecedenceLevel::GROUP_CATEGORY === $level ) {
			return $this->resolve_winning_category_rule( $rules, $context, $level );
		}

		// Non-category levels: there must be exactly one active rule for the target and scope.
		if ( count( $rules ) > 1 ) {
			throw new RuleResolutionException(
				sprintf(
					'Conflicting same-priority rules detected: %d active rules matched at precedence level %s.',
					count( $rules ),
					$level->name
				)
			);
		}

		return $rules[0];
	}

	/**
	 * Deterministically resolves multi-category conflicts according to PRD policy:
	 * 1. Deepest hierarchy depth wins.
	 * 2. Lowest final price (highest percentage discount) wins at equal depth.
	 * 3. Smallest category ID (category_id ASC) wins sibling tie-breaks.
	 *
	 * @param PricingRule[] $rules
	 * @throws RuleResolutionException
	 */
	private function resolve_winning_category_rule(
		array $rules,
		PricingContext $context,
		PrecedenceLevel $level
	): PricingRule {
		// Single category match: no competing rules, depth comparison is not required.
		if ( 1 === count( $rules ) ) {
			return $rules[0];
		}

		// When two or more category rules compete, require valid depth metadata for every competing category.
		// A completely missing depth map or partially populated depth map is invalid for multi-category resolution.
		$depth_map = $context->category_depths();
		foreach ( $rules as $rule ) {
			$cat_id = $rule->scope()->id();
			if ( ! array_key_exists( $cat_id, $depth_map ) ) {
				throw new RuleResolutionException(
					sprintf( 'Cannot resolve competing category rules: missing category-depth metadata for category ID %d in pricing context.', $cat_id )
				);
			}
		}

		// Detect duplicate rules for the exact same category ID.
		$seen_categories = [];
		foreach ( $rules as $rule ) {
			$cat_id = $rule->scope()->id();
			if ( isset( $seen_categories[ $cat_id ] ) ) {
				throw new RuleResolutionException(
					sprintf( 'Conflicting same-priority rules detected for category ID %d at level %s.', $cat_id, $level->name )
				);
			}
			$seen_categories[ $cat_id ] = true;
		}

		// Sort candidate category rules using deterministic 3-step PRD policy.
		usort(
			$rules,
			static function ( PricingRule $a, PricingRule $b ) use ( $depth_map ): int {
				$cat_a = $a->scope()->id();
				$cat_b = $b->scope()->id();

				// Step 1: Greatest hierarchy depth wins (deeper depth comes first).
				$depth_a = $depth_map[ $cat_a ];
				$depth_b = $depth_map[ $cat_b ];
				if ( $depth_a !== $depth_b ) {
					return $depth_b <=> $depth_a;
				}

				// Step 2: Lowest final price wins (in v1, highest percentage discount wins).
				$val_a = $a->pricing_value()->value();
				$val_b = $b->pricing_value()->value();
				$cmp   = DecimalMath::comp( $val_a, $val_b, 6 );
				if ( 0 !== $cmp ) {
					// Larger discount percentage produces lower price -> comes first.
					return -$cmp;
				}

				// Step 3: Sibling tie-break: smaller category ID wins (category_id ASC).
				return $cat_a <=> $cat_b;
			}
		);

		return $rules[0];
	}
}
