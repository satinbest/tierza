<?php
/**
 * Real Database Integration Test Suite for Tierza.
 *
 * Runs against live WordPress and MariaDB/MySQL inside Docker.
 * Validates tables, indexes, CRUD, uniqueness constraints, decimal round-trips,
 * safeguards, and migrations on actual database services.
 *
 * @package Tierza\Tests\Live
 */

namespace Tierza\Tests\Live;

use wpdb;
use Throwable;
use RuntimeException;

use Tierza\Domain\Entities\PricingGroup;
use Tierza\Domain\Entities\PricingRule;
use Tierza\Domain\Exceptions\DuplicateRuleException;
use Tierza\Domain\Exceptions\DuplicateSlugException;
use Tierza\Domain\Exceptions\GroupDeletionSafeguardException;
use Tierza\Domain\Exceptions\InvalidAssignmentException;
use Tierza\Domain\ValueObjects\GroupSlug;
use Tierza\Domain\ValueObjects\GroupStatus;
use Tierza\Domain\ValueObjects\PricingValue;
use Tierza\Domain\ValueObjects\RuleScope;
use Tierza\Domain\ValueObjects\RuleStatus;
use Tierza\Domain\ValueObjects\RuleTarget;
use Tierza\Infrastructure\Database\Schema;
use Tierza\Infrastructure\Repositories\WpGroupRepository;
use Tierza\Infrastructure\Repositories\WpRuleRepository;
use Tierza\Infrastructure\Repositories\WpUserGroupRepository;

class DatabaseIntegrationLiveTest {

	private wpdb $db;
	private WpGroupRepository $group_repo;
	private WpRuleRepository $rule_repo;
	private WpUserGroupRepository $user_group_repo;

	private int $assertions = 0;
	private int $tests_passed = 0;
	/** @var array<string> */
	private array $failures = [];

	public function __construct() {
		global $wpdb;
		$this->db              = $wpdb;
		$this->group_repo      = new WpGroupRepository( $this->db );
		$this->rule_repo       = new WpRuleRepository( $this->db );
		$this->user_group_repo = new WpUserGroupRepository( $this->db );
	}

	public function run(): int {
		echo "==========================================================" . PHP_EOL;
		echo " Tierza Real Database Integration Test Suite (MariaDB/WP) " . PHP_EOL;
		echo "==========================================================" . PHP_EOL;

		$methods = [
			'test_schema_installation_and_idempotence',
			'test_tables_exist_with_correct_prefix',
			'test_schema_version_tracking',
			'test_group_crud_and_persian_support',
			'test_duplicate_group_slug_rejection',
			'test_rule_crud_and_precision_roundtrip',
			'test_explicit_zero_rules_are_preserved',
			'test_conflicting_active_rules_rejection',
			'test_multiple_inactive_rules_allowed',
			'test_user_group_assignment_and_reassignment',
			'test_user_group_safeguards_invalid_group',
			'test_group_deletion_safeguards_with_users_and_rules',
			'test_group_deletion_success_when_isolated',
			'test_repeated_migrations_preserve_existing_records',
		];

		foreach ( $methods as $method ) {
			try {
				$this->$method();
				$this->tests_passed++;
				echo " [PASS] {$method}" . PHP_EOL;
			} catch ( Throwable $e ) {
				$this->failures[] = "{$method}: " . $e->getMessage() . " (" . $e->getFile() . ":" . $e->getLine() . ")";
				echo " [FAIL] {$method}: " . $e->getMessage() . PHP_EOL;
			}
		}

		echo "==========================================================" . PHP_EOL;
		echo sprintf(
			" Summary: %d/%d tests passed (%d assertions, %d failures)",
			$this->tests_passed,
			count( $methods ),
			$this->assertions,
			count( $this->failures )
		) . PHP_EOL;
		echo "==========================================================" . PHP_EOL;

		return empty( $this->failures ) ? 0 : 1;
	}

	private function assert( bool $condition, string $message ): void {
		$this->assertions++;
		if ( ! $condition ) {
			throw new RuntimeException( "Assertion failed: {$message}" );
		}
	}

	public function test_schema_installation_and_idempotence(): void {
		// Run fresh install.
		Schema::install( $this->db );
		$this->assert( Schema::tables_exist( $this->db ), 'Tables must exist after Schema::install().' );

		// Run second install to confirm idempotence.
		Schema::install( $this->db );
		$this->assert( Schema::tables_exist( $this->db ), 'Tables must remain intact after idempotent install.' );
	}

	public function test_tables_exist_with_correct_prefix(): void {
		$expected_groups = $this->db->prefix . 'tierza_groups';
		$expected_rules  = $this->db->prefix . 'tierza_rules';
		$expected_ug     = $this->db->prefix . 'tierza_user_groups';

		$found_groups = $this->db->get_var( $this->db->prepare( "SHOW TABLES LIKE %s", $expected_groups ) );
		$found_rules  = $this->db->get_var( $this->db->prepare( "SHOW TABLES LIKE %s", $expected_rules ) );
		$found_ug     = $this->db->get_var( $this->db->prepare( "SHOW TABLES LIKE %s", $expected_ug ) );

		$this->assert( $found_groups === $expected_groups, "Groups table must match {$expected_groups}" );
		$this->assert( $found_rules === $expected_rules, "Rules table must match {$expected_rules}" );
		$this->assert( $found_ug === $expected_ug, "User groups table must match {$expected_ug}" );
	}

	public function test_schema_version_tracking(): void {
		$version = get_option( Schema::DB_VERSION_OPTION );
		$this->assert( '1.0.0' === $version, "DB version option must be 1.0.0, got: " . var_export( $version, true ) );
	}

	public function test_group_crud_and_persian_support(): void {
		// Create group with Persian name and Unicode slug.
		$group = new PricingGroup(
			'همکاران طلایی مشهد',
			'همکاران-طلایی-مشهد',
			null,
			'توضیحات تستی گروه همکاران طلایی',
			GroupStatus::ACTIVE,
			15
		);

		$saved = $this->group_repo->save( $group );
		$group_id = $saved->id();
		$this->assert( null !== $group_id && $group_id > 0, 'Saved group must receive a positive auto-increment ID.' );

		// Read by ID.
		$found = $this->group_repo->find_by_id( $group_id );
		$this->assert( null !== $found, 'Group must be found by ID.' );
		$this->assert( 'همکاران طلایی مشهد' === $found->name(), 'Persian name must round-trip accurately.' );
		$this->assert( 'همکاران-طلایی-مشهد' === $found->slug()->value(), 'Persian slug must round-trip accurately.' );
		$this->assert( 15 === $found->sort_order(), 'Sort order must be preserved.' );
		$this->assert( $found->is_active(), 'Group must be active.' );

		// Read by Slug.
		$found_by_slug = $this->group_repo->find_by_slug( 'همکاران-طلایی-مشهد' );
		$this->assert( null !== $found_by_slug && $found_by_slug->id() === $group_id, 'Group must be found by slug.' );

		// Update group.
		$updated = $found->update_details( 'همکاران طلایی مشهد - ویرایش شده', 'همکاران-طلایی-مشهد', 'توضیحات ویرایش‌شده', 20 );
		$this->group_repo->save( $updated );

		$verified = $this->group_repo->find_by_id( $group_id );
		$this->assert( null !== $verified, 'Updated group must be found.' );
		$this->assert( 'همکاران طلایی مشهد - ویرایش شده' === $verified->name(), 'Updated name must persist.' );
		$this->assert( 20 === $verified->sort_order(), 'Updated sort order must persist.' );

		// Search by name.
		$search_results = $this->group_repo->search_by_name( 'طلایی' );
		$this->assert( ! empty( $search_results ), 'Search by Persian keyword must return results.' );

		// Cleanup.
		$this->group_repo->delete( $group_id );
		$this->assert( null === $this->group_repo->find_by_id( $group_id ), 'Deleted group must no longer exist.' );
	}

	public function test_duplicate_group_slug_rejection(): void {
		$group1 = new PricingGroup( 'Gold 1', 'gold-tier-test' );
		$saved1 = $this->group_repo->save( $group1 );
		$this->assert( null !== $saved1->id(), 'First group must save successfully.' );

		$group2 = new PricingGroup( 'Gold 2', 'gold-tier-test' );
		$threw = false;
		try {
			$this->group_repo->save( $group2 );
		} catch ( DuplicateSlugException $e ) {
			$threw = true;
		}

		$this->assert( $threw, 'Saving group with duplicate slug must throw DuplicateSlugException.' );

		// Cleanup.
		if ( null !== $saved1->id() ) {
			$this->group_repo->delete( $saved1->id() );
		}
	}

	public function test_rule_crud_and_precision_roundtrip(): void {
		$rule = new PricingRule(
			RuleTarget::group( 999 ),
			RuleScope::product( 1001 ),
			PricingValue::fixed( '123456789.123456' )
		);

		$saved = $this->rule_repo->save( $rule );
		$rule_id = $saved->id();
		$this->assert( null !== $rule_id && $rule_id > 0, 'Saved rule must receive positive ID.' );

		$found = $this->rule_repo->find_by_id( $rule_id );
		$this->assert( null !== $found, 'Rule must be found by ID.' );
		$this->assert( '123456789.123456' === $found->pricing_value()->value(), 'High decimal precision must round-trip exactly without float loss.' );
		$this->assert( $found->pricing_value()->is_fixed(), 'Pricing type must be fixed.' );
		$this->assert( $found->scope()->is_product(), 'Scope must be product.' );
		$this->assert( 1001 === $found->scope()->id(), 'Product ID must be 1001.' );

		// Update value.
		$updated = $found->update_value( PricingValue::fixed( '999999999.654321' ) );
		$this->rule_repo->save( $updated );

		$verified = $this->rule_repo->find_by_id( $rule_id );
		$this->assert( null !== $verified, 'Updated rule must exist.' );
		$this->assert( '999999999.654321' === $verified->pricing_value()->value(), 'Updated decimal must persist.' );

		// Cleanup.
		$this->rule_repo->delete( $rule_id );
		$this->assert( null === $this->rule_repo->find_by_id( $rule_id ), 'Deleted rule must not exist.' );
	}

	public function test_explicit_zero_rules_are_preserved(): void {
		$rule = new PricingRule(
			RuleTarget::group( 888 ),
			RuleScope::global(),
			PricingValue::percentage( '0' )
		);

		$saved = $this->rule_repo->save( $rule );
		$rule_id = $saved->id();
		$this->assert( null !== $rule_id, '0% rule must save.' );

		$found = $this->rule_repo->find_by_id( $rule_id );
		$this->assert( null !== $found, '0% rule must be retrievable.' );
		$this->assert( $found->pricing_value()->is_zero(), 'is_zero() must be true.' );
		$this->assert( '0.000000' === $found->pricing_value()->value(), 'Value must be exact 0.000000.' );

		// Cleanup.
		$this->rule_repo->delete( $rule_id );
	}

	public function test_conflicting_active_rules_rejection(): void {
		$rule1 = new PricingRule(
			RuleTarget::group( 777 ),
			RuleScope::category( 50 ),
			PricingValue::percentage( '15' ),
			null,
			RuleStatus::ACTIVE
		);
		$saved1 = $this->rule_repo->save( $rule1 );

		// Second active rule with identical logical signature (target, scope, pricing_type).
		$rule2 = new PricingRule(
			RuleTarget::group( 777 ),
			RuleScope::category( 50 ),
			PricingValue::percentage( '25' ),
			null,
			RuleStatus::ACTIVE
		);

		$threw = false;
		try {
			$this->rule_repo->save( $rule2 );
		} catch ( DuplicateRuleException $e ) {
			$threw = true;
		}

		$this->assert( $threw, 'Conflicting active rule must throw DuplicateRuleException.' );

		// Cleanup.
		if ( null !== $saved1->id() ) {
			$this->rule_repo->delete( $saved1->id() );
		}
	}

	public function test_multiple_inactive_rules_allowed(): void {
		// Create rule 1 inactive.
		$rule1 = new PricingRule(
			RuleTarget::group( 666 ),
			RuleScope::global(),
			PricingValue::percentage( '10' ),
			null,
			RuleStatus::INACTIVE
		);
		$saved1 = $this->rule_repo->save( $rule1 );

		// Create rule 2 inactive with same target/scope.
		$rule2 = new PricingRule(
			RuleTarget::group( 666 ),
			RuleScope::global(),
			PricingValue::percentage( '20' ),
			null,
			RuleStatus::INACTIVE
		);
		$saved2 = $this->rule_repo->save( $rule2 );

		$this->assert( null !== $saved1->id() && null !== $saved2->id(), 'Multiple inactive rules with same signature must be permitted for history.' );

		// Cleanup.
		if ( null !== $saved1->id() ) {
			$this->rule_repo->delete( $saved1->id() );
		}
		if ( null !== $saved2->id() ) {
			$this->rule_repo->delete( $saved2->id() );
		}
	}

	public function test_user_group_assignment_and_reassignment(): void {
		// Create 2 test groups.
		$group_a = $this->group_repo->save( new PricingGroup( 'Test Group A', 'test-group-a' ) );
		$group_b = $this->group_repo->save( new PricingGroup( 'Test Group B', 'test-group-b' ) );

		$user_id = 9999;

		// Clean any previous assignment for user_id 9999.
		$this->user_group_repo->remove( $user_id );

		// Assign to group A.
		$assignment = $this->user_group_repo->assign( $user_id, (int) $group_a->id() );
		$this->assert( (int) $group_a->id() === $assignment->group_id(), 'User must be assigned to Group A.' );

		// Attempting duplicate assign() must fail (MVP single primary group policy).
		$threw = false;
		try {
			$this->user_group_repo->assign( $user_id, (int) $group_b->id() );
		} catch ( InvalidAssignmentException $e ) {
			$threw = true;
		}
		$this->assert( $threw, 'Direct assign() on already assigned user must throw InvalidAssignmentException.' );

		// Reassign to group B.
		$reassigned = $this->user_group_repo->reassign( $user_id, (int) $group_b->id() );
		$this->assert( (int) $group_b->id() === $reassigned->group_id(), 'User must be reassigned to Group B.' );

		// Query by user ID.
		$current = $this->user_group_repo->find_by_user_id( $user_id );
		$this->assert( null !== $current && (int) $group_b->id() === $current->group_id(), 'Query by user ID must show Group B.' );

		// List users in group B.
		$list = $this->user_group_repo->list_by_group_id( (int) $group_b->id() );
		$this->assert( ! empty( $list ), 'Group B user list must not be empty.' );

		// Cleanup.
		$this->user_group_repo->remove( $user_id );
		$this->assert( null === $this->user_group_repo->find_by_user_id( $user_id ), 'Removed user assignment must be null.' );

		$this->group_repo->delete( (int) $group_a->id() );
		$this->group_repo->delete( (int) $group_b->id() );
	}

	public function test_user_group_safeguards_invalid_group(): void {
		$threw = false;
		try {
			$this->user_group_repo->assign( 8888, 9999999 );
		} catch ( InvalidAssignmentException $e ) {
			$threw = true;
		}

		$this->assert( $threw, 'Assigning user to non-existent group must throw InvalidAssignmentException.' );
	}

	public function test_group_deletion_safeguards_with_users_and_rules(): void {
		$group = $this->group_repo->save( new PricingGroup( 'Protected Group', 'protected-group' ) );
		$group_id = (int) $group->id();

		// Add assigned user.
		$user_id = 7777;
		$this->user_group_repo->assign( $user_id, $group_id );

		// Attempt delete -> must throw.
		$threw_user = false;
		try {
			$this->group_repo->delete( $group_id );
		} catch ( GroupDeletionSafeguardException $e ) {
			$threw_user = true;
		}
		$this->assert( $threw_user, 'Deleting group with assigned users must throw GroupDeletionSafeguardException.' );

		// Remove user assignment.
		$this->user_group_repo->remove( $user_id );

		// Add rule to group.
		$rule = $this->rule_repo->save(
			new PricingRule(
				RuleTarget::group( $group_id ),
				RuleScope::global(),
				PricingValue::percentage( '5' )
			)
		);

		// Attempt delete -> must throw due to rule.
		$threw_rule = false;
		try {
			$this->group_repo->delete( $group_id );
		} catch ( GroupDeletionSafeguardException $e ) {
			$threw_rule = true;
		}
		$this->assert( $threw_rule, 'Deleting group with attached rules must throw GroupDeletionSafeguardException.' );

		// Remove rule.
		$this->rule_repo->delete( (int) $rule->id() );

		// Now delete must succeed.
		$deleted = $this->group_repo->delete( $group_id );
		$this->assert( $deleted, 'Group delete must succeed once users and rules are removed.' );
	}

	public function test_group_deletion_success_when_isolated(): void {
		$group = $this->group_repo->save( new PricingGroup( 'Isolated Group', 'isolated-group' ) );
		$deleted = $this->group_repo->delete( (int) $group->id() );
		$this->assert( $deleted, 'Deleting isolated empty group must return true.' );
		$this->assert( null === $this->group_repo->find_by_id( (int) $group->id() ), 'Deleted group must not exist.' );
	}

	public function test_repeated_migrations_preserve_existing_records(): void {
		$group = $this->group_repo->save( new PricingGroup( 'Migration Persistent Group', 'migration-persistent-group' ) );
		$group_id = (int) $group->id();

		// Execute schema migration multiple times.
		Schema::install( $this->db );
		Schema::maybe_upgrade( $this->db );

		$found = $this->group_repo->find_by_id( $group_id );
		$this->assert( null !== $found, 'Existing group must survive repeated schema migrations without data loss.' );

		// Cleanup.
		$this->group_repo->delete( $group_id );
	}
}

// Execute the test suite.
$tester = new DatabaseIntegrationLiveTest();
$exit_code = $tester->run();
exit( $exit_code );
