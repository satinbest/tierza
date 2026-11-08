<?php
/**
 * Real Database Integration Test Suite for Tierza.
 *
 * Runs against live WordPress and MariaDB/MySQL inside Docker.
 * Validates tables, indexes, CRUD, uniqueness constraints, decimal round-trips,
 * safeguards, reference validation, concurrency safety, and migrations on actual database services.
 *
 * @package Tierza\Tests\Live
 */

namespace Tierza\Tests\Live;

use wpdb;
use Throwable;
use RuntimeException;

use Tierza\Application\Pricing\AuthoritativePriceCalculator;
use Tierza\Application\Pricing\DeterministicRuleResolver;
use Tierza\Domain\Entities\PricingGroup;
use Tierza\Domain\Entities\PricingRule;
use Tierza\Domain\Exceptions\DuplicateRuleException;
use Tierza\Domain\Exceptions\DuplicateSlugException;
use Tierza\Domain\Exceptions\GroupDeletionSafeguardException;
use Tierza\Domain\Exceptions\InvalidAssignmentException;
use Tierza\Domain\Exceptions\InvalidRuleException;
use Tierza\Domain\Exceptions\RuleResolutionException;
use Tierza\Domain\Exceptions\SchemaMigrationException;
use Tierza\Infrastructure\Exceptions\DatabaseQueryException;
use Tierza\Domain\Pricing\Enums\PrecedenceLevel;
use Tierza\Domain\Pricing\Enums\PricingReasonCode;
use Tierza\Domain\Pricing\Enums\PricingSource;
use Tierza\Domain\Pricing\PricingContext;
use Tierza\Domain\Pricing\PricingResult;
use Tierza\Domain\ValueObjects\Money;
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
use Tierza\Infrastructure\Validation\EntityReferenceValidator;

class DatabaseIntegrationLiveTest {

	private wpdb $db;
	private WpGroupRepository $group_repo;
	private WpRuleRepository $rule_repo;
	private WpUserGroupRepository $user_group_repo;
	private EntityReferenceValidator $validator;

	private int $assertions = 0;
	private int $tests_passed = 0;
	/** @var array<string> */
	private array $failures = [];

	public function __construct() {
		global $wpdb;
		$this->db              = $wpdb;
		$this->validator       = new EntityReferenceValidator( $this->db );
		$this->group_repo      = new WpGroupRepository( $this->db );
		$this->rule_repo       = new WpRuleRepository( $this->db, $this->validator );
		$this->user_group_repo = new WpUserGroupRepository( $this->db, $this->validator );
	}

	public function run(): int {
		echo "==========================================================" . PHP_EOL;
		echo " Tierza Real Database Integration Test Suite (MariaDB/WP) " . PHP_EOL;
		echo "==========================================================" . PHP_EOL;

		// Clean up any leftover test data before starting suite.
		$this->clean_tables();

		$methods = [
			'test_schema_installation_and_idempotence',
			'test_tables_exist_with_correct_prefix',
			'test_schema_version_tracking',
			'test_schema_verification_detects_all_columns_and_indexes',
			'test_schema_migration_failure_handling',
			'test_group_crud_and_persian_support',
			'test_duplicate_group_slug_rejection',
			'test_rule_crud_and_precision_roundtrip',
			'test_explicit_zero_rules_are_preserved',
			'test_active_rule_uniqueness_percentage_vs_fixed_conflict',
			'test_updating_active_rule_does_not_conflict_with_itself',
			'test_activating_inactive_rule_checks_conflicts',
			'test_multiple_inactive_rules_allowed',
			'test_database_enforced_active_key_uniqueness_concurrency',
			'test_reference_validation_rejects_nonexistent_entities',
			'test_reference_validation_accepts_valid_entities',
			'test_user_group_assignment_and_reassignment',
			'test_user_group_safeguards_invalid_group',
			'test_group_deletion_safeguards_with_users_and_rules',
			'test_group_deletion_success_when_isolated',
			'test_repeated_migrations_preserve_existing_records',
			'test_legacy_upgrade_with_no_existing_rules',
			'test_legacy_upgrade_with_active_percentage_rules',
			'test_legacy_upgrade_with_active_fixed_price_rules',
			'test_legacy_upgrade_with_inactive_historical_rules',
			'test_legacy_upgrade_with_conflicting_active_rules_halts_safely',
			'test_legacy_upgrade_failed_upgrade_retains_previous_schema_version',
			'test_legacy_upgrade_successful_retry_after_resolving_conflict',
			'test_legacy_upgrade_repeated_after_successful_backfill_is_idempotent',
			'test_legacy_upgrade_existing_rule_ids_values_and_timestamps_preserved',
			'test_legacy_upgrade_new_uniqueness_constraint_rejects_concurrent_duplicates',
			'test_rule_resolver_real_stored_rules_in_mariadb',
			'test_rule_resolver_category_hierarchy_in_mariadb',
			'test_rule_resolver_explicit_zero_and_inactive_rules_in_mariadb',
			'test_rule_resolver_real_database_read_failure_produces_exception_not_fallback',
			'test_rule_resolver_query_counts_on_real_mariadb',
			'test_rule_resolver_benchmark_fixtures_and_execution_time',
			'test_authoritative_price_calculator_end_to_end_data_flow_in_mariadb',
			'test_full_chain_pricing_integration_and_database_immutability_in_mariadb',
			'test_pricing_engine_comprehensive_performance_baseline_in_mariadb',
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

	private function clean_tables(): void {
		if ( Schema::tables_exist( $this->db ) ) {
			$table_ug     = $this->db->prefix . 'tierza_user_groups';
			$table_rules  = $this->db->prefix . 'tierza_rules';
			$table_groups = $this->db->prefix . 'tierza_groups';

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$this->db->query( "TRUNCATE TABLE {$table_ug}" );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$this->db->query( "TRUNCATE TABLE {$table_rules}" );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$this->db->query( "TRUNCATE TABLE {$table_groups}" );
		}

		if ( defined( 'WP_CONTENT_DIR' ) && file_exists( WP_CONTENT_DIR . '/debug.log' ) ) {
			file_put_contents( WP_CONTENT_DIR . '/debug.log', '' );
		}
	}

	private function create_test_user( string $login, string $email ): int {
		if ( function_exists( 'wp_create_user' ) ) {
			$created = wp_create_user( $login, 'TierzaPass!1234', $email );
			if ( is_int( $created ) && $created > 0 ) {
				return $created;
			}
		}

		$this->db->insert(
			$this->db->prefix . 'users',
			[
				'user_login'          => $login,
				'user_pass'           => 'fakepass',
				'user_nicename'       => $login,
				'user_email'          => $email,
				'user_url'            => '',
				'user_registered'     => current_time( 'mysql', true ),
				'user_activation_key' => '',
				'user_status'         => 0,
				'display_name'        => $login,
			]
		);
		return (int) $this->db->insert_id;
	}

	private function delete_test_user( int $user_id ): void {
		if ( $user_id <= 0 ) {
			return;
		}
		if ( function_exists( 'wp_delete_user' ) ) {
			wp_delete_user( $user_id );
		} else {
			$this->db->delete( $this->db->prefix . 'users', [ 'ID' => $user_id ] );
		}
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

	public function test_schema_verification_detects_all_columns_and_indexes(): void {
		$issues = Schema::verify_schema( $this->db );
		$this->assert( empty( $issues ), 'Schema verification must report 0 issues on healthy installation: ' . implode( ', ', $issues ) );

		// Explicitly assert presence of active_key and uq_active_rule in tierza_rules.
		$table_rules = Schema::rules_table( $this->db );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$cols = $this->db->get_results( "SHOW FULL COLUMNS FROM {$table_rules}", ARRAY_A );
		$col_names = array_column( $cols, 'Field' );
		$this->assert( in_array( 'active_key', $col_names, true ), 'active_key column must exist in rules table.' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$indexes = $this->db->get_results( "SHOW INDEX FROM {$table_rules}", ARRAY_A );
		$index_names = array_column( $indexes, 'Key_name' );
		$this->assert( in_array( 'uq_active_rule', $index_names, true ), 'uq_active_rule unique index must exist in rules table.' );
	}

	public function test_schema_migration_failure_handling(): void {
		// Verify that verify_schema() detects when tables are missing under an unmigrated prefix.
		$original_prefix = $this->db->prefix;
		try {
			$this->db->prefix = 'wp_corrupted_';
			$issues = Schema::verify_schema( $this->db );
			$this->assert( ! empty( $issues ), 'verify_schema must detect missing tables on unmigrated prefix.' );
		} finally {
			$this->db->prefix = $original_prefix;
		}
	}

	public function test_group_crud_and_persian_support(): void {
		$group = new PricingGroup(
			'همکاران طلایی مشهد',
			'همکاران-طلایی-مشهد',
			null,
			'توضیحات تستی گروه همکاران طلایی',
			GroupStatus::ACTIVE,
			15
		);

		$saved = $this->group_repo->save( $group );
		$group_id = (int) $saved->id();
		$this->assert( $group_id > 0, 'Saved group must receive a positive auto-increment ID.' );

		try {
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
		} finally {
			// Cleanup.
			$this->group_repo->delete( $group_id );
			$this->assert( null === $this->group_repo->find_by_id( $group_id ), 'Deleted group must no longer exist.' );
		}
	}

	public function test_duplicate_group_slug_rejection(): void {
		$group1 = new PricingGroup( 'Gold 1', 'gold-tier-test' );
		$saved1 = $this->group_repo->save( $group1 );
		$this->assert( null !== $saved1->id(), 'First group must save successfully.' );

		try {
			$group2 = new PricingGroup( 'Gold 2', 'gold-tier-test' );
			$threw = false;
			try {
				$this->group_repo->save( $group2 );
			} catch ( DuplicateSlugException $e ) {
				$threw = true;
			}
			$this->assert( $threw, 'Saving group with duplicate slug must throw DuplicateSlugException.' );
		} finally {
			if ( null !== $saved1->id() ) {
				$this->group_repo->delete( (int) $saved1->id() );
			}
		}
	}

	public function test_rule_crud_and_precision_roundtrip(): void {
		// Create a test group and product.
		$group = $this->group_repo->save( new PricingGroup( 'Precision Group', 'precision-group-test' ) );
		$group_id = (int) $group->id();

		// Create a mock product in wp_posts for reference validation.
		$this->db->insert(
			$this->db->prefix . 'posts',
			[
				'post_title'  => 'Test Product Precision',
				'post_type'   => 'product',
				'post_status' => 'publish',
			]
		);
		$product_id = (int) $this->db->insert_id;

		try {
			$rule = new PricingRule(
				RuleTarget::group( $group_id ),
				RuleScope::product( $product_id ),
				PricingValue::fixed( '123456789.123456' )
			);

			$saved = $this->rule_repo->save( $rule );
			$rule_id = (int) $saved->id();
			$this->assert( $rule_id > 0, 'Saved rule must receive positive ID.' );

			$found = $this->rule_repo->find_by_id( $rule_id );
			$this->assert( null !== $found, 'Rule must be found by ID.' );
			$this->assert( '123456789.123456' === $found->pricing_value()->value(), 'High decimal precision must round-trip exactly without float loss.' );
			$this->assert( $found->pricing_value()->is_fixed(), 'Pricing type must be fixed.' );
			$this->assert( $found->scope()->is_product(), 'Scope must be product.' );
			$this->assert( $product_id === $found->scope()->id(), 'Product ID must match.' );

			// Update value.
			$updated = $found->update_value( PricingValue::fixed( '999999999.654321' ) );
			$this->rule_repo->save( $updated );

			$verified = $this->rule_repo->find_by_id( $rule_id );
			$this->assert( null !== $verified, 'Updated rule must exist.' );
			$this->assert( '999999999.654321' === $verified->pricing_value()->value(), 'Updated decimal must persist.' );

			// Cleanup rule.
			$this->rule_repo->delete( $rule_id );
			$this->assert( null === $this->rule_repo->find_by_id( $rule_id ), 'Deleted rule must not exist.' );
		} finally {
			$this->group_repo->delete( $group_id );
			$this->db->delete( $this->db->prefix . 'posts', [ 'ID' => $product_id ] );
		}
	}

	public function test_explicit_zero_rules_are_preserved(): void {
		$group = $this->group_repo->save( new PricingGroup( 'Zero Rule Group', 'zero-rule-group-test' ) );
		$group_id = (int) $group->id();

		try {
			$rule = new PricingRule(
				RuleTarget::group( $group_id ),
				RuleScope::global(),
				PricingValue::percentage( '0' )
			);

			$saved = $this->rule_repo->save( $rule );
			$rule_id = (int) $saved->id();
			$this->assert( $rule_id > 0, '0% rule must save.' );

			$found = $this->rule_repo->find_by_id( $rule_id );
			$this->assert( null !== $found, '0% rule must be retrievable.' );
			$this->assert( $found->pricing_value()->is_zero(), 'is_zero() must be true.' );
			$this->assert( '0.000000' === $found->pricing_value()->value(), 'Value must be exact 0.000000.' );

			$this->rule_repo->delete( $rule_id );
		} finally {
			$this->group_repo->delete( $group_id );
		}
	}

	public function test_active_rule_uniqueness_percentage_vs_fixed_conflict(): void {
		$group = $this->group_repo->save( new PricingGroup( 'Conflict Group', 'conflict-group-test' ) );
		$group_id = (int) $group->id();

		// Create mock product.
		$this->db->insert(
			$this->db->prefix . 'posts',
			[ 'post_title' => 'Conflict Product', 'post_type' => 'product', 'post_status' => 'publish' ]
		);
		$product_id = (int) $this->db->insert_id;

		try {
			// Rule 1: Active percentage rule on product.
			$rule1 = new PricingRule(
				RuleTarget::group( $group_id ),
				RuleScope::product( $product_id ),
				PricingValue::percentage( '15' ),
				null,
				RuleStatus::ACTIVE
			);
			$saved1 = $this->rule_repo->save( $rule1 );
			$this->assert( null !== $saved1->id(), 'First active percentage rule must save.' );

			// Rule 2: Active FIXED-PRICE rule on the SAME product and target.
			// In Tierza v1, logical uniqueness key is (target_type, target_id, scope_type, scope_id).
			// Percentage and Fixed MUST conflict!
			$rule2 = new PricingRule(
				RuleTarget::group( $group_id ),
				RuleScope::product( $product_id ),
				PricingValue::fixed( '200000' ),
				null,
				RuleStatus::ACTIVE
			);

			$threw = false;
			try {
				$this->rule_repo->save( $rule2 );
			} catch ( DuplicateRuleException $e ) {
				$threw = true;
			}

			$this->assert( $threw, 'Active percentage and active fixed rule on same target/scope MUST conflict and throw DuplicateRuleException.' );

			$this->rule_repo->delete( (int) $saved1->id() );
		} finally {
			$this->group_repo->delete( $group_id );
			$this->db->delete( $this->db->prefix . 'posts', [ 'ID' => $product_id ] );
		}
	}

	public function test_updating_active_rule_does_not_conflict_with_itself(): void {
		$group = $this->group_repo->save( new PricingGroup( 'Self Update Group', 'self-update-group-test' ) );
		$group_id = (int) $group->id();

		try {
			$rule = new PricingRule(
				RuleTarget::group( $group_id ),
				RuleScope::global(),
				PricingValue::percentage( '10' )
			);
			$saved = $this->rule_repo->save( $rule );
			$rule_id = (int) $saved->id();

			// Update its own value while remaining active.
			$updated = $saved->update_value( PricingValue::percentage( '12' ) );
			$saved_again = $this->rule_repo->save( $updated );

			$this->assert( $rule_id === (int) $saved_again->id(), 'Rule ID must remain identical on update.' );
			$this->assert( '12.000000' === $saved_again->pricing_value()->value(), 'Updated rule value must be 12.000000.' );

			$this->rule_repo->delete( $rule_id );
		} finally {
			if ( isset( $rule_id ) ) {
				$this->rule_repo->delete( $rule_id );
			}
			$this->group_repo->delete( $group_id );
		}
	}

	public function test_activating_inactive_rule_checks_conflicts(): void {
		$group = $this->group_repo->save( new PricingGroup( 'Activation Test Group', 'activation-test-group' ) );
		$group_id = (int) $group->id();

		try {
			// Rule 1: Active rule.
			$rule1 = $this->rule_repo->save(
				new PricingRule(
					RuleTarget::group( $group_id ),
					RuleScope::global(),
					PricingValue::percentage( '10' ),
					null,
					RuleStatus::ACTIVE
				)
			);

			// Rule 2: Inactive rule on SAME target and scope (must succeed!).
			$rule2 = $this->rule_repo->save(
				new PricingRule(
					RuleTarget::group( $group_id ),
					RuleScope::global(),
					PricingValue::percentage( '20' ),
					null,
					RuleStatus::INACTIVE
				)
			);
			$this->assert( null !== $rule2->id(), 'Inactive rule must be saved without conflict.' );

			// Activating Rule 2 must fail due to active conflict with Rule 1.
			$threw = false;
			try {
				$this->rule_repo->save( $rule2->activate() );
			} catch ( DuplicateRuleException $e ) {
				$threw = true;
			}
			$this->assert( $threw, 'Activating an inactive rule conflicting with an active rule must throw DuplicateRuleException.' );

			$this->rule_repo->delete( (int) $rule1->id() );
			$this->rule_repo->delete( (int) $rule2->id() );
		} finally {
			$this->group_repo->delete( $group_id );
		}
	}

	public function test_multiple_inactive_rules_allowed(): void {
		$group = $this->group_repo->save( new PricingGroup( 'History Group', 'history-group-test' ) );
		$group_id = (int) $group->id();

		try {
			$rule1 = $this->rule_repo->save(
				new PricingRule(
					RuleTarget::group( $group_id ),
					RuleScope::global(),
					PricingValue::percentage( '10' ),
					null,
					RuleStatus::INACTIVE
				)
			);

			$rule2 = $this->rule_repo->save(
				new PricingRule(
					RuleTarget::group( $group_id ),
					RuleScope::global(),
					PricingValue::percentage( '20' ),
					null,
					RuleStatus::INACTIVE
				)
			);

			$this->assert( null !== $rule1->id() && null !== $rule2->id(), 'Multiple inactive historical rules must be permitted.' );

			$this->rule_repo->delete( (int) $rule1->id() );
			$this->rule_repo->delete( (int) $rule2->id() );
		} finally {
			$this->group_repo->delete( $group_id );
		}
	}

	public function test_database_enforced_active_key_uniqueness_concurrency(): void {
		$group = $this->group_repo->save( new PricingGroup( 'Race Group', 'race-group-test' ) );
		$group_id = (int) $group->id();

		try {
			$table_rules = Schema::rules_table( $this->db );
			$active_key = sprintf( 'group:%d|global:0', $group_id );
			$now = current_time( 'mysql', true );

			// Thread 1 inserts row with active_key.
			$res1 = $this->db->insert(
				$table_rules,
				[
					'target_type'  => 'group',
					'target_id'    => $group_id,
					'scope_type'   => 'global',
					'scope_id'     => 0,
					'pricing_type' => 'percentage',
					'value'        => '10.000000',
					'status'       => 'active',
					'active_key'   => $active_key,
					'created_at'   => $now,
					'updated_at'   => $now,
				]
			);
			$this->assert( 1 === $res1, 'Thread 1 insert must succeed.' );
			$id1 = (int) $this->db->insert_id;

			// Thread 2 attempts simultaneous concurrent insert with identical active_key.
			// Database engine UNIQUE KEY uq_active_rule MUST reject it.
			$suppress = $this->db->suppress_errors( true );
			$res2 = $this->db->insert(
				$table_rules,
				[
					'target_type'  => 'group',
					'target_id'    => $group_id,
					'scope_type'   => 'global',
					'scope_id'     => 0,
					'pricing_type' => 'fixed',
					'value'        => '50000.000000',
					'status'       => 'active',
					'active_key'   => $active_key,
					'created_at'   => $now,
					'updated_at'   => $now,
				]
			);
			$last_error = $this->db->last_error;
			$this->db->suppress_errors( $suppress );

			$this->assert( false === $res2, 'Thread 2 concurrent duplicate insert must be rejected by database engine.' );
			$this->assert(
				str_contains( strtolower( $last_error ), 'duplicate' ) || str_contains( strtolower( $last_error ), 'uq_active_rule' ),
				'Database error must indicate unique constraint violation on uq_active_rule.'
			);

			$this->db->delete( $table_rules, [ 'id' => $id1 ] );
		} finally {
			$this->group_repo->delete( $group_id );
		}
	}

	public function test_reference_validation_rejects_nonexistent_entities(): void {
		$group = $this->group_repo->save( new PricingGroup( 'Ref Group', 'ref-group-test' ) );
		$group_id = (int) $group->id();

		try {
			// Non-existent target user.
			$threw_user = false;
			try {
				$this->rule_repo->save(
					new PricingRule(
						RuleTarget::user( 99999999 ),
						RuleScope::global(),
						PricingValue::percentage( '5' )
					)
				);
			} catch ( InvalidRuleException $e ) {
				$threw_user = true;
			}
			$this->assert( $threw_user, 'Rule for non-existent target user must be rejected.' );

			// Non-existent product.
			$threw_product = false;
			try {
				$this->rule_repo->save(
					new PricingRule(
						RuleTarget::group( $group_id ),
						RuleScope::product( 88888888 ),
						PricingValue::fixed( '1000' )
					)
				);
			} catch ( InvalidRuleException $e ) {
				$threw_product = true;
			}
			$this->assert( $threw_product, 'Rule for non-existent product must be rejected.' );

			// Non-existent category.
			$threw_cat = false;
			try {
				$this->rule_repo->save(
					new PricingRule(
						RuleTarget::group( $group_id ),
						RuleScope::category( 77777777 ),
						PricingValue::percentage( '10' )
					)
				);
			} catch ( InvalidRuleException $e ) {
				$threw_cat = true;
			}
			$this->assert( $threw_cat, 'Rule for non-existent category must be rejected.' );
		} finally {
			$this->group_repo->delete( $group_id );
		}
	}

	public function test_reference_validation_accepts_valid_entities(): void {
		$group = $this->group_repo->save( new PricingGroup( 'Valid Ref Group', 'valid-ref-group' ) );
		$group_id = (int) $group->id();

		// Create mock product in wp_posts.
		$this->db->insert(
			$this->db->prefix . 'posts',
			[ 'post_title' => 'Valid Product', 'post_type' => 'product', 'post_status' => 'publish' ]
		);
		$product_id = (int) $this->db->insert_id;

		try {
			$rule = new PricingRule(
				RuleTarget::group( $group_id ),
				RuleScope::product( $product_id ),
				PricingValue::fixed( '500000' )
			);
			$saved = $this->rule_repo->save( $rule );
			$this->assert( null !== $saved->id(), 'Rule targeting valid product must save successfully.' );

			$this->rule_repo->delete( (int) $saved->id() );
		} finally {
			$this->group_repo->delete( $group_id );
			$this->db->delete( $this->db->prefix . 'posts', [ 'ID' => $product_id ] );
		}
	}

	public function test_user_group_assignment_and_reassignment(): void {
		$group_a = $this->group_repo->save( new PricingGroup( 'Test Group A', 'test-group-a' ) );
		$group_b = $this->group_repo->save( new PricingGroup( 'Test Group B', 'test-group-b' ) );

		// Create a test user in wp_users for reference validation.
		$user_id = $this->create_test_user( 'tierza_test_user_assignment', 'test_assignment@tierza.org' );

		try {
			// Assign to group A.
			$assignment = $this->user_group_repo->assign( $user_id, (int) $group_a->id() );
			$this->assert( (int) $group_a->id() === $assignment->group_id(), 'User must be assigned to Group A.' );

			// Attempting duplicate assign() must fail.
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

			// Cleanup assignment.
			$this->user_group_repo->remove( $user_id );
			$this->assert( null === $this->user_group_repo->find_by_user_id( $user_id ), 'Removed user assignment must be null.' );
		} finally {
			$this->group_repo->delete( (int) $group_a->id() );
			$this->group_repo->delete( (int) $group_b->id() );
			$this->delete_test_user( $user_id );
		}
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

		// Create mock user.
		$user_id = $this->create_test_user( 'user_protected_del', 'prot_del@tierza.org' );

		try {
			$this->user_group_repo->assign( $user_id, $group_id );

			// Attempt delete -> must throw due to assigned user.
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
		} finally {
			$this->delete_test_user( $user_id );
		}
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

		try {
			// Execute schema migration multiple times.
			Schema::install( $this->db );
			Schema::maybe_upgrade( $this->db );

			$found = $this->group_repo->find_by_id( $group_id );
			$this->assert( null !== $found, 'Existing group must survive repeated schema migrations without data loss.' );
		} finally {
			$this->group_repo->delete( $group_id );
		}
	}

	private function create_legacy_rules_table(): void {
		$table_rules     = Schema::rules_table( $this->db );
		$charset_collate = $this->db->get_charset_collate();

		// Drop current rules table to establish pre-migration baseline.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->db->query( "DROP TABLE IF EXISTS {$table_rules}" );

		// Create legacy schema without active_key column and without uq_active_rule.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->db->query(
			"CREATE TABLE {$table_rules} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  target_type varchar(20) NOT NULL,
  target_id bigint(20) unsigned NOT NULL,
  scope_type varchar(20) NOT NULL,
  scope_id bigint(20) unsigned NOT NULL DEFAULT 0,
  pricing_type varchar(20) NOT NULL,
  value decimal(20,6) NOT NULL DEFAULT 0.000000,
  status varchar(20) NOT NULL DEFAULT 'active',
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY (id),
  KEY idx_target (target_type, target_id, status),
  KEY idx_scope (scope_type, scope_id, status),
  KEY idx_lookup (target_type, target_id, scope_type, scope_id, status)
) {$charset_collate};"
		);

		update_option( Schema::DB_VERSION_OPTION, '0.1.0' );
	}

	public function test_legacy_upgrade_with_no_existing_rules(): void {
		$this->create_legacy_rules_table();

		// Run upgrade on empty legacy table.
		Schema::maybe_upgrade( $this->db );

		$this->assert( '1.0.0' === get_option( Schema::DB_VERSION_OPTION ), 'DB version must be updated to 1.0.0.' );
		$issues = Schema::verify_schema( $this->db );
		$this->assert( empty( $issues ), 'Schema verification must pass after upgrading empty legacy table: ' . implode( ', ', $issues ) );
	}

	public function test_legacy_upgrade_with_active_percentage_rules(): void {
		$this->create_legacy_rules_table();
		$table_rules = Schema::rules_table( $this->db );

		// Insert active percentage rule without active_key.
		$this->db->insert(
			$table_rules,
			[
				'target_type'  => 'group',
				'target_id'    => 10,
				'scope_type'   => 'global',
				'scope_id'     => 0,
				'pricing_type' => 'percentage',
				'value'        => '15.000000',
				'status'       => 'active',
				'created_at'   => '2026-01-01 12:00:00',
				'updated_at'   => '2026-01-01 12:00:00',
			]
		);
		$rule_id = (int) $this->db->insert_id;

		Schema::maybe_upgrade( $this->db );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $this->db->get_row( $this->db->prepare( "SELECT * FROM {$table_rules} WHERE id = %d", $rule_id ), ARRAY_A );
		$this->assert( is_array( $row ), 'Rule must exist after upgrade.' );
		$this->assert( 'group:10|global:0' === ( $row['active_key'] ?? '' ), 'Active percentage rule must be backfilled with logical active_key.' );
		$this->assert( '15.000000' === ( $row['value'] ?? '' ), 'Rule value must be preserved.' );
		$this->assert( 'percentage' === ( $row['pricing_type'] ?? '' ), 'Pricing type must be percentage.' );
		$this->assert( '1.0.0' === get_option( Schema::DB_VERSION_OPTION ), 'DB version must be 1.0.0.' );
	}

	public function test_legacy_upgrade_with_active_fixed_price_rules(): void {
		$this->create_legacy_rules_table();
		$table_rules = Schema::rules_table( $this->db );

		// Insert active fixed-price rule without active_key.
		$this->db->insert(
			$table_rules,
			[
				'target_type'  => 'group',
				'target_id'    => 20,
				'scope_type'   => 'product',
				'scope_id'     => 555,
				'pricing_type' => 'fixed',
				'value'        => '450000.000000',
				'status'       => 'active',
				'created_at'   => '2026-02-01 15:30:00',
				'updated_at'   => '2026-02-01 15:30:00',
			]
		);
		$rule_id = (int) $this->db->insert_id;

		Schema::maybe_upgrade( $this->db );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $this->db->get_row( $this->db->prepare( "SELECT * FROM {$table_rules} WHERE id = %d", $rule_id ), ARRAY_A );
		$this->assert( is_array( $row ), 'Fixed-price rule must exist after upgrade.' );
		$this->assert( 'group:20|product:555' === ( $row['active_key'] ?? '' ), 'Active fixed rule must be backfilled with product active_key.' );
		$this->assert( '450000.000000' === ( $row['value'] ?? '' ), 'Fixed amount must be preserved.' );
		$this->assert( 'fixed' === ( $row['pricing_type'] ?? '' ), 'Pricing type must be fixed.' );
	}

	public function test_legacy_upgrade_with_inactive_historical_rules(): void {
		$this->create_legacy_rules_table();
		$table_rules = Schema::rules_table( $this->db );

		// Insert 2 inactive historical rules for the same target & scope.
		$this->db->insert(
			$table_rules,
			[
				'target_type'  => 'group',
				'target_id'    => 30,
				'scope_type'   => 'global',
				'scope_id'     => 0,
				'pricing_type' => 'percentage',
				'value'        => '10.000000',
				'status'       => 'inactive',
				'created_at'   => '2026-01-01 10:00:00',
				'updated_at'   => '2026-01-01 10:00:00',
			]
		);
		$id1 = (int) $this->db->insert_id;

		$this->db->insert(
			$table_rules,
			[
				'target_type'  => 'group',
				'target_id'    => 30,
				'scope_type'   => 'global',
				'scope_id'     => 0,
				'pricing_type' => 'percentage',
				'value'        => '20.000000',
				'status'       => 'inactive',
				'created_at'   => '2026-01-02 10:00:00',
				'updated_at'   => '2026-01-02 10:00:00',
			]
		);
		$id2 = (int) $this->db->insert_id;

		Schema::maybe_upgrade( $this->db );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row1 = $this->db->get_row( $this->db->prepare( "SELECT * FROM {$table_rules} WHERE id = %d", $id1 ), ARRAY_A );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row2 = $this->db->get_row( $this->db->prepare( "SELECT * FROM {$table_rules} WHERE id = %d", $id2 ), ARRAY_A );

		$this->assert( is_null( $row1['active_key'] ), 'Inactive historical rule 1 must have active_key = NULL.' );
		$this->assert( is_null( $row2['active_key'] ), 'Inactive historical rule 2 must have active_key = NULL.' );
		$this->assert( '1.0.0' === get_option( Schema::DB_VERSION_OPTION ), 'DB version must be 1.0.0.' );
	}

	public function test_legacy_upgrade_with_conflicting_active_rules_halts_safely(): void {
		$this->create_legacy_rules_table();
		$table_rules = Schema::rules_table( $this->db );

		// Insert 2 active conflicting rules on the same target and scope.
		$this->db->insert(
			$table_rules,
			[
				'target_type'  => 'group',
				'target_id'    => 40,
				'scope_type'   => 'product',
				'scope_id'     => 888,
				'pricing_type' => 'percentage',
				'value'        => '12.000000',
				'status'       => 'active',
				'created_at'   => '2026-01-01 00:00:00',
				'updated_at'   => '2026-01-01 00:00:00',
			]
		);
		$id1 = (int) $this->db->insert_id;

		$this->db->insert(
			$table_rules,
			[
				'target_type'  => 'group',
				'target_id'    => 40,
				'scope_type'   => 'product',
				'scope_id'     => 888,
				'pricing_type' => 'fixed',
				'value'        => '50000.000000',
				'status'       => 'active',
				'created_at'   => '2026-01-02 00:00:00',
				'updated_at'   => '2026-01-02 00:00:00',
			]
		);
		$id2 = (int) $this->db->insert_id;

		$caught     = false;
		$caught_msg = '';
		try {
			Schema::maybe_upgrade( $this->db );
		} catch ( SchemaMigrationException $e ) {
			$caught     = true;
			$caught_msg = $e->getMessage();
		}

		$this->assert( $caught, 'Upgrade must throw SchemaMigrationException when active rule conflicts exist.' );
		$this->assert( str_contains( $caught_msg, 'conflicting active pricing rule' ), 'Exception must explain active rule conflict.' );
		$this->assert( str_contains( $caught_msg, (string) $id1 ) && str_contains( $caught_msg, (string) $id2 ), 'Exception message must list conflicting rule IDs.' );

		// Verify that all data was preserved and no rules were deleted.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = (int) $this->db->get_var( "SELECT COUNT(*) FROM {$table_rules}" );
		$this->assert( 2 === $count, 'All conflicting rules must remain untouched in the database.' );
	}

	public function test_legacy_upgrade_failed_upgrade_retains_previous_schema_version(): void {
		$this->create_legacy_rules_table();
		$table_rules = Schema::rules_table( $this->db );

		// Duplicate active rules causing failure.
		$this->db->insert( $table_rules, [ 'target_type' => 'group', 'target_id' => 45, 'scope_type' => 'global', 'scope_id' => 0, 'pricing_type' => 'percentage', 'value' => '10', 'status' => 'active', 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ] );
		$this->db->insert( $table_rules, [ 'target_type' => 'group', 'target_id' => 45, 'scope_type' => 'global', 'scope_id' => 0, 'pricing_type' => 'fixed', 'value' => '20', 'status' => 'active', 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ] );

		try {
			Schema::maybe_upgrade( $this->db );
		} catch ( SchemaMigrationException $e ) {
			// Expected.
		}

		// Version must STILL be 0.1.0!
		$version = get_option( Schema::DB_VERSION_OPTION );
		$this->assert( '0.1.0' === $version, 'Failed upgrade must retain the previous database version (0.1.0).' );
	}

	public function test_legacy_upgrade_successful_retry_after_resolving_conflict(): void {
		$this->create_legacy_rules_table();
		$table_rules = Schema::rules_table( $this->db );

		// Conflicting rules.
		$this->db->insert( $table_rules, [ 'target_type' => 'group', 'target_id' => 50, 'scope_type' => 'global', 'scope_id' => 0, 'pricing_type' => 'percentage', 'value' => '10', 'status' => 'active', 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ] );
		$id1 = (int) $this->db->insert_id;

		$this->db->insert( $table_rules, [ 'target_type' => 'group', 'target_id' => 50, 'scope_type' => 'global', 'scope_id' => 0, 'pricing_type' => 'fixed', 'value' => '20', 'status' => 'active', 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ] );
		$id2 = (int) $this->db->insert_id;

		// First attempt fails.
		$failed = false;
		try {
			Schema::maybe_upgrade( $this->db );
		} catch ( SchemaMigrationException $e ) {
			$failed = true;
		}
		$this->assert( $failed, 'First migration attempt must halt.' );

		// Administrator resolves conflict by deactivating rule 2.
		$this->db->update( $table_rules, [ 'status' => 'inactive' ], [ 'id' => $id2 ] );

		// Retry migration.
		Schema::maybe_upgrade( $this->db );

		$this->assert( '1.0.0' === get_option( Schema::DB_VERSION_OPTION ), 'Version must update to 1.0.0 after conflict resolution.' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row1 = $this->db->get_row( $this->db->prepare( "SELECT * FROM {$table_rules} WHERE id = %d", $id1 ), ARRAY_A );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row2 = $this->db->get_row( $this->db->prepare( "SELECT * FROM {$table_rules} WHERE id = %d", $id2 ), ARRAY_A );

		$this->assert( 'group:50|global:0' === ( $row1['active_key'] ?? '' ), 'Active rule 1 must receive active_key.' );
		$this->assert( is_null( $row2['active_key'] ), 'Deactivated rule 2 must have NULL active_key.' );
	}

	public function test_legacy_upgrade_repeated_after_successful_backfill_is_idempotent(): void {
		$this->create_legacy_rules_table();
		$table_rules = Schema::rules_table( $this->db );

		$this->db->insert( $table_rules, [ 'target_type' => 'group', 'target_id' => 60, 'scope_type' => 'global', 'scope_id' => 0, 'pricing_type' => 'percentage', 'value' => '15', 'status' => 'active', 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ] );

		// First upgrade.
		Schema::maybe_upgrade( $this->db );
		$this->assert( '1.0.0' === get_option( Schema::DB_VERSION_OPTION ), 'First upgrade must reach 1.0.0.' );

		// Repeated upgrade calls must be completely safe and no-op.
		Schema::maybe_upgrade( $this->db );
		Schema::maybe_upgrade( $this->db );

		$this->assert( '1.0.0' === get_option( Schema::DB_VERSION_OPTION ), 'DB version must remain 1.0.0.' );
		$issues = Schema::verify_schema( $this->db );
		$this->assert( empty( $issues ), 'Schema must remain verified after repeated upgrades.' );
	}

	public function test_legacy_upgrade_existing_rule_ids_values_and_timestamps_preserved(): void {
		$this->create_legacy_rules_table();
		$table_rules = Schema::rules_table( $this->db );

		$timestamp = '2026-03-15 08:45:22';
		$value     = '123456789.987654';

		$this->db->insert(
			$table_rules,
			[
				'target_type'  => 'group',
				'target_id'    => 70,
				'scope_type'   => 'product',
				'scope_id'     => 999,
				'pricing_type' => 'fixed',
				'value'        => $value,
				'status'       => 'active',
				'created_at'   => $timestamp,
				'updated_at'   => $timestamp,
			]
		);
		$rule_id = (int) $this->db->insert_id;

		Schema::maybe_upgrade( $this->db );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $this->db->get_row( $this->db->prepare( "SELECT * FROM {$table_rules} WHERE id = %d", $rule_id ), ARRAY_A );
		$this->assert( is_array( $row ), 'Rule row must exist.' );
		$this->assert( $rule_id === (int) $row['id'], 'Primary key ID must be exactly preserved.' );
		$this->assert( $value === $row['value'], 'Exact decimal precision value must be preserved.' );
		$this->assert( $timestamp === $row['created_at'], 'Created timestamp must be preserved.' );
		$this->assert( $timestamp === $row['updated_at'], 'Updated timestamp must be preserved.' );
	}

	public function test_legacy_upgrade_new_uniqueness_constraint_rejects_concurrent_duplicates(): void {
		$this->create_legacy_rules_table();
		$table_rules = Schema::rules_table( $this->db );

		// Seed active rule.
		$this->db->insert( $table_rules, [ 'target_type' => 'group', 'target_id' => 80, 'scope_type' => 'global', 'scope_id' => 0, 'pricing_type' => 'percentage', 'value' => '10', 'status' => 'active', 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ] );

		// Upgrade.
		Schema::maybe_upgrade( $this->db );

		// Now attempt direct insert of a second active rule with same target & scope.
		$suppress = $this->db->suppress_errors( true );
		$res      = $this->db->insert(
			$table_rules,
			[
				'target_type'  => 'group',
				'target_id'    => 80,
				'scope_type'   => 'global',
				'scope_id'     => 0,
				'pricing_type' => 'fixed',
				'value'        => '20000',
				'status'       => 'active',
				'active_key'   => 'group:80|global:0',
				'created_at'   => current_time( 'mysql' ),
				'updated_at'   => current_time( 'mysql' ),
			]
		);
		$last_error = $this->db->last_error;
		$this->db->suppress_errors( $suppress );

		$this->assert( false === $res, 'New database constraint must reject concurrent duplicate active rules.' );
		$this->assert(
			str_contains( strtolower( $last_error ), 'duplicate' ) || str_contains( strtolower( $last_error ), 'uq_active_rule' ),
			'Database engine error must report duplicate entry for uq_active_rule.'
		);

		// Restore clean 1.0.0 schema at end of upgrade tests.
		Schema::install( $this->db );
		$this->clean_tables();
	}

	public function test_rule_resolver_real_stored_rules_in_mariadb(): void {
		$group = $this->group_repo->save( new PricingGroup( 'Live Resolver Group', 'live-resolver-group' ) );
		$group_id = (int) $group->id();

		// Create mock product and variation
		$this->db->insert( $this->db->prefix . 'posts', [ 'post_title' => 'Live Product', 'post_type' => 'product', 'post_status' => 'publish' ] );
		$product_id = (int) $this->db->insert_id;

		$this->db->insert( $this->db->prefix . 'posts', [ 'post_title' => 'Live Variation', 'post_type' => 'product_variation', 'post_status' => 'publish', 'post_parent' => $product_id ] );
		$variation_id = (int) $this->db->insert_id;

		$customer_id = 1; // existing admin user

		$rule1 = $this->rule_repo->save( new PricingRule( RuleTarget::group( $group_id ), RuleScope::product( $product_id ), PricingValue::percentage( '15.000000' ) ) );
		$rule2 = $this->rule_repo->save( new PricingRule( RuleTarget::user( $customer_id ), RuleScope::variation( $variation_id ), PricingValue::percentage( '25.000000' ) ) );

		try {
			$resolver = new DeterministicRuleResolver( $this->rule_repo, $this->group_repo );
			$context  = new PricingContext(
				product_id: $product_id,
				base_price: Money::from( '200.00', 'USD' ),
				customer_id: $customer_id,
				group: $group,
				variation_id: $variation_id
			);

			$result = $resolver->resolve( $context );

			$this->assert( ! $result->is_fallback(), 'Result must not be fallback.' );
			$this->assert( PrecedenceLevel::CUSTOMER_VARIATION === $result->precedence_level(), 'Customer variation must win over group product.' );
			$this->assert( $rule2->id() === $result->rule()->id(), 'Winning rule ID must match customer variation rule.' );
			$this->assert( '25.000000' === $result->rule()->pricing_value()->value(), 'Winning percentage must be 25%.' );
		} finally {
			$this->rule_repo->delete( (int) $rule1->id() );
			$this->rule_repo->delete( (int) $rule2->id() );
			$this->group_repo->delete( $group_id );
			$this->db->delete( $this->db->prefix . 'posts', [ 'ID' => $variation_id ] );
			$this->db->delete( $this->db->prefix . 'posts', [ 'ID' => $product_id ] );
		}
	}

	public function test_rule_resolver_category_hierarchy_in_mariadb(): void {
		$group = $this->group_repo->save( new PricingGroup( 'Live Category Group', 'live-cat-group' ) );
		$group_id = (int) $group->id();

		// Mock categories
		$this->db->insert( $this->db->prefix . 'terms', [ 'name' => 'Parent Cat', 'slug' => 'parent-cat-live' ] );
		$parent_cat_id = (int) $this->db->insert_id;
		$this->db->insert( $this->db->prefix . 'term_taxonomy', [ 'term_id' => $parent_cat_id, 'taxonomy' => 'product_cat', 'count' => 1 ] );

		$this->db->insert( $this->db->prefix . 'terms', [ 'name' => 'Child Cat', 'slug' => 'child-cat-live' ] );
		$child_cat_id = (int) $this->db->insert_id;
		$this->db->insert( $this->db->prefix . 'term_taxonomy', [ 'term_id' => $child_cat_id, 'taxonomy' => 'product_cat', 'parent' => $parent_cat_id, 'count' => 1 ] );

		// Parent rule (depth 1, 50% discount) vs Child rule (depth 2, 10% discount)
		$rule_parent = $this->rule_repo->save( new PricingRule( RuleTarget::group( $group_id ), RuleScope::category( $parent_cat_id ), PricingValue::percentage( '50.000000' ) ) );
		$rule_child  = $this->rule_repo->save( new PricingRule( RuleTarget::group( $group_id ), RuleScope::category( $child_cat_id ), PricingValue::percentage( '10.000000' ) ) );

		try {
			$resolver = new DeterministicRuleResolver( $this->rule_repo, $this->group_repo );
			$context  = new PricingContext(
				product_id: 10,
				base_price: Money::from( '100.00', 'USD' ),
				customer_id: 0,
				group: $group,
				category_ids: [ $parent_cat_id, $child_cat_id ],
				category_depths: [ $parent_cat_id => 1, $child_cat_id => 2 ]
			);

			$result = $resolver->resolve( $context );

			$this->assert( PrecedenceLevel::GROUP_CATEGORY === $result->precedence_level(), 'Group category must be resolved.' );
			$this->assert( $rule_child->id() === $result->rule()->id(), 'Child category rule at depth 2 must win over parent at depth 1.' );
			$this->assert( '10.000000' === $result->rule()->pricing_value()->value(), 'Winning discount must be child discount.' );
		} finally {
			$this->rule_repo->delete( (int) $rule_parent->id() );
			$this->rule_repo->delete( (int) $rule_child->id() );
			$this->group_repo->delete( $group_id );
			$this->db->delete( $this->db->prefix . 'term_taxonomy', [ 'term_id' => $child_cat_id ] );
			$this->db->delete( $this->db->prefix . 'terms', [ 'term_id' => $child_cat_id ] );
			$this->db->delete( $this->db->prefix . 'term_taxonomy', [ 'term_id' => $parent_cat_id ] );
			$this->db->delete( $this->db->prefix . 'terms', [ 'term_id' => $parent_cat_id ] );
		}
	}

	public function test_rule_resolver_explicit_zero_and_inactive_rules_in_mariadb(): void {
		$group = $this->group_repo->save( new PricingGroup( 'Live Zero Group', 'live-zero-group' ) );
		$group_id = (int) $group->id();

		// Inactive 50% rule
		$inactive_rule = new PricingRule(
			RuleTarget::group( $group_id ),
			RuleScope::global(),
			PricingValue::percentage( '50.000000' ),
			null,
			RuleStatus::INACTIVE
		);
		$saved_inactive = $this->rule_repo->save( $inactive_rule );

		// Active explicit 0% rule for user 1
		$zero_rule = new PricingRule(
			RuleTarget::user( 1 ),
			RuleScope::global(),
			PricingValue::percentage( '0.000000' )
		);
		$saved_zero = $this->rule_repo->save( $zero_rule );

		try {
			$resolver = new DeterministicRuleResolver( $this->rule_repo, $this->group_repo );
			$context  = new PricingContext(
				product_id: 10,
				base_price: Money::from( '100.00', 'USD' ),
				customer_id: 1,
				group: $group
			);

			$result = $resolver->resolve( $context );

			$this->assert( PrecedenceLevel::CUSTOMER_GLOBAL === $result->precedence_level(), 'Customer global must win.' );
			$this->assert( '0.000000' === $result->rule()->pricing_value()->value(), 'Explicit 0% rule must be winning value.' );
			$this->assert( PricingReasonCode::RULE_APPLIED_ZERO_PERCENTAGE->value === $result->reason_code(), 'Reason code must indicate zero percentage applied.' );
		} finally {
			$this->rule_repo->delete( (int) $saved_inactive->id() );
			$this->rule_repo->delete( (int) $saved_zero->id() );
			$this->group_repo->delete( $group_id );
		}
	}

	public function test_rule_resolver_real_database_read_failure_produces_exception_not_fallback(): void {
		// Create a repository pointing to a non-existent table to trigger real SQL error in MariaDB
		$broken_repo = new WpRuleRepository( $this->db );
		$ref         = new \ReflectionClass( $broken_repo );
		$prop        = $ref->getProperty( 'table_rules' );
		$prop->setAccessible( true );
		$prop->setValue( $broken_repo, $this->db->prefix . 'tierza_nonexistent_table' );

		// 1. Direct repo call must throw DatabaseQueryException (not return [])
		$caught_db_exception = false;
		$suppress            = $this->db->suppress_errors( true );
		try {
			$broken_repo->find_active_for_targets( [ RuleTarget::user( 1 ) ] );
		} catch ( DatabaseQueryException $e ) {
			$caught_db_exception = true;
			$this->assert( str_contains( $e->getMessage(), 'Database query failed' ), 'Exception message must be sanitized.' );
		} finally {
			$this->db->suppress_errors( $suppress );
		}
		$this->assert( $caught_db_exception, 'Intentionally broken query must throw DatabaseQueryException on real MariaDB.' );

		// 2. Resolver must translate this to RuleResolutionException (not silently return fallback)
		$resolver = new DeterministicRuleResolver( $broken_repo, $this->group_repo );
		$context  = new PricingContext( product_id: 10, base_price: Money::from( '100.00', 'USD' ), customer_id: 1 );

		$caught_resolution_exception = false;
		$suppress                    = $this->db->suppress_errors( true );
		try {
			$resolver->resolve( $context );
		} catch ( RuleResolutionException $e ) {
			$caught_resolution_exception = true;
			$this->assert( $e->getPrevious() instanceof DatabaseQueryException, 'Causal chain must preserve DatabaseQueryException.' );
		} finally {
			$this->db->suppress_errors( $suppress );
		}
		$this->assert( $caught_resolution_exception, 'Resolver must not convert database read failure into fallback.' );

		// 3. In contrast, a clean query finding 0 rules must return valid fallback
		$clean_resolver = new DeterministicRuleResolver( $this->rule_repo, $this->group_repo );
		$clean_result   = $clean_resolver->resolve( $context );
		$this->assert( $clean_result->is_fallback(), 'Empty results from valid table must return clean fallback.' );
		$this->assert( PrecedenceLevel::WOOCOMMERCE_FALLBACK === $clean_result->precedence_level(), 'Must return WOOCOMMERCE_FALLBACK.' );
	}

	public function test_rule_resolver_query_counts_on_real_mariadb(): void {
		$group = $this->group_repo->save( new PricingGroup( 'Query Count Group', 'query-count-group' ) );
		$group_id = (int) $group->id();

		$rule = $this->rule_repo->save( new PricingRule( RuleTarget::group( $group_id ), RuleScope::global(), PricingValue::percentage( '10.000000' ) ) );

		try {
			$resolver = new DeterministicRuleResolver( $this->rule_repo, $this->group_repo );

			// Scenario 1: Anonymous user without group -> exactly 0 queries!
			$anon_context = new PricingContext( product_id: 10, base_price: Money::from( '100.00', 'USD' ), customer_id: 0 );
			$q_before     = $this->db->num_queries;
			$anon_res     = $resolver->resolve( $anon_context );
			$q_after      = $this->db->num_queries;
			$this->assert( 0 === ( $q_after - $q_before ), 'Anonymous user without group must execute 0 DB queries.' );
			$this->assert( $anon_res->is_fallback(), 'Anonymous user must fallback.' );

			// Scenario 2: Authenticated user with trusted group entity attached -> exactly 1 query!
			$auth_context = new PricingContext( product_id: 10, base_price: Money::from( '100.00', 'USD' ), customer_id: 1, group: $group );
			$q_before     = $this->db->num_queries;
			$auth_res     = $resolver->resolve( $auth_context );
			$q_after      = $this->db->num_queries;
			$this->assert( 1 === ( $q_after - $q_before ), 'Authenticated user with trusted group entity must execute exactly 1 DB query.' );
			$this->assert( ! $auth_res->is_fallback(), 'Authenticated user with group must match rule.' );

			// Scenario 3: Authenticated user with numeric group_id only -> exactly 2 queries (1 group verify + 1 rule lookup)!
			$numeric_context = new PricingContext( product_id: 10, base_price: Money::from( '100.00', 'USD' ), customer_id: 1, group_id: $group_id );
			$q_before        = $this->db->num_queries;
			$num_res         = $resolver->resolve( $numeric_context );
			$q_after         = $this->db->num_queries;
			$this->assert( 2 === ( $q_after - $q_before ), 'Authenticated user with numeric group ID must execute 2 DB queries (1 group verification + 1 rule lookup).' );
			$this->assert( ! $num_res->is_fallback(), 'Must resolve rule.' );

			// Scenario 4: Product listing across 10 products with trusted group entity -> exactly 10 rule queries (0 group queries)!
			$q_before = $this->db->num_queries;
			for ( $pid = 1; $pid <= 10; $pid++ ) {
				$item_context = new PricingContext( product_id: $pid, base_price: Money::from( '50.00', 'USD' ), customer_id: 1, group: $group );
				$resolver->resolve( $item_context );
			}
			$q_after = $this->db->num_queries;
			$this->assert( 10 === ( $q_after - $q_before ), 'Product listing of 10 items with trusted group entity must execute exactly 10 rule queries (1 per product, 0 group queries).' );
		} finally {
			$this->rule_repo->delete( (int) $rule->id() );
			$this->group_repo->delete( $group_id );
		}
	}

	public function test_rule_resolver_benchmark_fixtures_and_execution_time(): void {
		$table_rules  = Schema::rules_table( $this->db );
		$table_groups = Schema::groups_table( $this->db );
		$now          = current_time( 'mysql', true );

		// 1. Seed 10 groups
		$group_ids = [];
		for ( $g = 1; $g <= 10; $g++ ) {
			$this->db->insert(
				$table_groups,
				[
					'name'       => "Benchmark Group {$g}",
					'slug'       => "benchmark-group-{$g}",
					'status'     => 'active',
					'sort_order' => $g,
					'created_at' => $now,
					'updated_at' => $now,
				]
			);
			$group_ids[] = (int) $this->db->insert_id;
		}

		// 2. Seed 100 products
		$product_ids = [];
		for ( $p = 1; $p <= 100; $p++ ) {
			$this->db->insert(
				$this->db->prefix . 'posts',
				[
					'post_title'  => "Benchmark Product {$p}",
					'post_type'   => 'product',
					'post_status' => 'publish',
				]
			);
			$product_ids[] = (int) $this->db->insert_id;
		}

		// 3. Seed 500 rules in 5 chunks of 100
		for ( $chunk = 0; $chunk < 5; $chunk++ ) {
			$rule_placeholders = [];
			$rule_values       = [];
			for ( $k = 1; $k <= 100; $k++ ) {
				$r            = $chunk * 100 + $k;
				$target_group = $group_ids[ ( $r - 1 ) % 10 ];
				if ( $r <= 10 ) {
					$scope_type = 'global';
					$scope_id   = 0;
				} elseif ( $r <= 110 ) {
					$scope_type = 'product';
					$scope_id   = $product_ids[ $r - 11 ];
				} else {
					$scope_type = 'product';
					$scope_id   = $product_ids[ ( $r - 1 ) % 100 ];
				}

				if ( $r > 110 ) {
					$status              = 'inactive';
					$rule_placeholders[] = '(%s, %d, %s, %d, %s, %s, %s, NULL, %s, %s)';
					$rule_values[]       = 'group';
					$rule_values[]       = $target_group;
					$rule_values[]       = $scope_type;
					$rule_values[]       = $scope_id;
					$rule_values[]       = 'percentage';
					$rule_values[]       = sprintf( '%02d.000000', ( $r % 50 ) + 1 );
					$rule_values[]       = $status;
					$rule_values[]       = $now;
					$rule_values[]       = $now;
				} else {
					$status              = 'active';
					$active_key          = sprintf( 'group:%d|%s:%d', $target_group, $scope_type, $scope_id );
					$rule_placeholders[] = '(%s, %d, %s, %d, %s, %s, %s, %s, %s, %s)';
					$rule_values[]       = 'group';
					$rule_values[]       = $target_group;
					$rule_values[]       = $scope_type;
					$rule_values[]       = $scope_id;
					$rule_values[]       = 'percentage';
					$rule_values[]       = sprintf( '%02d.000000', ( $r % 50 ) + 1 );
					$rule_values[]       = $status;
					$rule_values[]       = $active_key;
					$rule_values[]       = $now;
					$rule_values[]       = $now;
				}
			}
			$sql = "INSERT INTO {$table_rules} (target_type, target_id, scope_type, scope_id, pricing_type, value, status, active_key, created_at, updated_at) VALUES " . implode( ', ', $rule_placeholders );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$res = $this->db->query( $this->db->prepare( $sql, ...$rule_values ) );
			$this->assert( false !== $res, 'Seeding rules batch must succeed: ' . $this->db->last_error );
		}

		try {
			$resolver      = new DeterministicRuleResolver( $this->rule_repo, $this->group_repo );
			$trusted_group = $this->group_repo->find_by_id( $group_ids[0] );
			$this->assert( null !== $trusted_group, 'Trusted group must exist.' );

			// Benchmark resolution over 100 products
			$start_time = microtime( true );
			$q_before   = $this->db->num_queries;

			for ( $i = 0; $i < 100; $i++ ) {
				$context = new PricingContext(
					product_id: $product_ids[ $i ],
					base_price: Money::from( '100.00', 'USD' ),
					customer_id: 1,
					group: $trusted_group
				);
				$res = $resolver->resolve( $context );
				$this->assert( ! $res->is_fallback(), 'Resolved rule must match.' );
			}

			$elapsed_ms    = ( microtime( true ) - $start_time ) * 1000;
			$total_queries = $this->db->num_queries - $q_before;

			$this->assert( 100 === $total_queries, 'Listing resolution of 100 products with trusted group must execute 100 rule queries.' );
			$this->assert( $elapsed_ms < 5000, '100 product resolutions against 500 rules should complete comfortably in < 5000ms.' );
		} finally {
			// Clean up benchmark data
			$this->db->query( "DELETE FROM {$table_rules} WHERE target_type = 'group' AND target_id IN (" . implode( ',', $group_ids ) . ')' );
			$this->db->query( "DELETE FROM {$table_groups} WHERE id IN (" . implode( ',', $group_ids ) . ')' );
			$this->db->query( "DELETE FROM {$this->db->prefix}posts WHERE ID IN (" . implode( ',', $product_ids ) . ')' );
		}
	}

	public function test_authoritative_price_calculator_end_to_end_data_flow_in_mariadb(): void {
		$resolver   = new DeterministicRuleResolver( $this->rule_repo, $this->group_repo );
		$calculator = new AuthoritativePriceCalculator();

		// 1. Create test group and user
		$group = $this->group_repo->save( new PricingGroup( 'Calc Flow Group', 'calc-flow-group' ) );
		$group_id = (int) $group->id();
		$customer_id = 1; // existing admin user

		// 2. Create products in wp_posts
		// Simple product
		$this->db->insert( $this->db->prefix . 'posts', [ 'post_title' => 'TRZ-007 Simple Product', 'post_type' => 'product', 'post_status' => 'publish' ] );
		$simple_pid = (int) $this->db->insert_id;

		// Variable parent and child variation
		$this->db->insert( $this->db->prefix . 'posts', [ 'post_title' => 'TRZ-007 Variable Parent', 'post_type' => 'product', 'post_status' => 'publish' ] );
		$variable_pid = (int) $this->db->insert_id;

		$this->db->insert( $this->db->prefix . 'posts', [ 'post_title' => 'TRZ-007 Variation A', 'post_type' => 'product_variation', 'post_status' => 'publish', 'post_parent' => $variable_pid ] );
		$variation_id = (int) $this->db->insert_id;

		// Product on Sale
		$this->db->insert( $this->db->prefix . 'posts', [ 'post_title' => 'TRZ-007 Sale Product', 'post_type' => 'product', 'post_status' => 'publish' ] );
		$sale_pid = (int) $this->db->insert_id;

		// Product in Category
		$this->db->insert( $this->db->prefix . 'posts', [ 'post_title' => 'TRZ-007 Category Product', 'post_type' => 'product', 'post_status' => 'publish' ] );
		$cat_pid = (int) $this->db->insert_id;

		// Create category in wp_terms & wp_term_taxonomy
		$this->db->insert( $this->db->prefix . 'terms', [ 'name' => 'TRZ-007 Category', 'slug' => 'trz-007-category' ] );
		$cat_id = (int) $this->db->insert_id;
		$this->db->insert( $this->db->prefix . 'term_taxonomy', [ 'term_id' => $cat_id, 'taxonomy' => 'product_cat', 'count' => 1 ] );

		// 3. Insert real rules into MariaDB
		// Customer percentage rule on simple product: 20%
		$rule_simple = $this->rule_repo->save( new PricingRule(
			target: RuleTarget::user( $customer_id ),
			scope: RuleScope::product( $simple_pid ),
			pricing_value: PricingValue::percentage( '20.000000' )
		) );

		// Group percentage rule on variation: 25%
		$rule_variation = $this->rule_repo->save( new PricingRule(
			target: RuleTarget::group( $group_id ),
			scope: RuleScope::variation( $variation_id ),
			pricing_value: PricingValue::percentage( '25.000000' )
		) );

		// Group percentage rule on category: 15%
		$rule_category = $this->rule_repo->save( new PricingRule(
			target: RuleTarget::group( $group_id ),
			scope: RuleScope::category( $cat_id ),
			pricing_value: PricingValue::percentage( '15.000000' )
		) );

		// Customer fixed price rule on sale product: 85.00
		$rule_fixed = $this->rule_repo->save( new PricingRule(
			target: RuleTarget::user( $customer_id ),
			scope: RuleScope::product( $sale_pid ),
			pricing_value: PricingValue::fixed( '85.000000' )
		) );

		try {
			// Case A: Real DB Rule -> Resolver -> Calculator (Customer Percentage on Simple Product)
			$context_simple = new PricingContext(
				product_id: $simple_pid,
				base_price: Money::from( '100.00', 'USD' ),
				customer_id: $customer_id,
				group: $group
			);
			$res_simple  = $resolver->resolve( $context_simple );
			$calc_simple = $calculator->calculate( $context_simple, $res_simple );

			$this->assert( $calc_simple->is_rule_applied(), 'Rule must be applied for simple product.' );
			$this->assert( '80.000000' === $calc_simple->final_price()->amount(), '20% on 100.00 must produce 80.00.' );
			$this->assert( '80.00' === $calc_simple->formatted_final_price(), 'Formatted price must be 80.00.' );
			$this->assert( $calc_simple->is_discounted(), 'Result must be discounted.' );
			$this->assert( '20.000000' === $calc_simple->discount_amount()->amount(), 'Discount amount must be 20.00.' );
			$this->assert( $rule_simple->id() === $calc_simple->applied_rule_id(), 'Applied rule ID must match DB rule.' );

			// Case B: Real DB Rule -> Resolver -> Calculator (Group Percentage on Variation)
			$context_var = new PricingContext(
				product_id: $variable_pid,
				base_price: Money::from( '160.00', 'USD' ),
				customer_id: 2, // Another user in the group
				group: $group,
				variation_id: $variation_id
			);
			$res_var  = $resolver->resolve( $context_var );
			$calc_var = $calculator->calculate( $context_var, $res_var );

			$this->assert( $calc_var->is_rule_applied(), 'Group variation rule must apply.' );
			$this->assert( '120.000000' === $calc_var->final_price()->amount(), '25% on 160.00 must produce 120.00.' );
			$this->assert( PrecedenceLevel::GROUP_VARIATION === $calc_var->precedence_level(), 'Precedence level must be GROUP_VARIATION.' );
			$this->assert( $rule_variation->id() === $calc_var->applied_rule_id(), 'Applied rule ID must match variation rule.' );

			// Case C: Active Sale Price (Active price used as discount base, regular price untouched)
			// Product has regular = 150.00, active sale = 120.00. Rule gives 10% group discount.
			// Rule Target = group, scope = product($sale_pid), value = 10%
			$rule_sale_percent = $this->rule_repo->save( new PricingRule(
				target: RuleTarget::group( $group_id ),
				scope: RuleScope::product( $sale_pid ),
				pricing_value: PricingValue::percentage( '10.000000' )
			) );

			$context_sale = new PricingContext(
				product_id: $sale_pid,
				base_price: Money::from( '120.00', 'USD' ), // Active price is sale price
				customer_id: 2, // Group member (not user 1 so rule_fixed won't match)
				group: $group,
				regular_price: Money::from( '150.00', 'USD' ),
				sale_price: Money::from( '120.00', 'USD' ),
				quantity: 1,
				group_id: null,
				currency_decimals: 2,
				is_on_sale: true
			);
			$res_sale  = $resolver->resolve( $context_sale );
			$calc_sale = $calculator->calculate( $context_sale, $res_sale );

			$this->assert( $calc_sale->is_rule_applied(), 'Sale product group rule must apply.' );
			$this->assert( '108.000000' === $calc_sale->final_price()->amount(), '10% on active sale price 120.00 must produce 108.00.' );
			$this->assert( 'confirmed_sale_price' === $calc_sale->diagnostic_metadata()['base_price_type'], 'Diagnostics must record confirmed_sale_price as base.' );

			$this->rule_repo->delete( (int) $rule_sale_percent->id() );

			// Case D: Customer Fixed Product Price on Sale Product
			$context_fixed = new PricingContext(
				product_id: $sale_pid,
				base_price: Money::from( '120.00', 'USD' ),
				customer_id: $customer_id, // User 1
				group: $group,
				regular_price: Money::from( '150.00', 'USD' ),
				sale_price: Money::from( '120.00', 'USD' )
			);
			$res_fixed  = $resolver->resolve( $context_fixed );
			$calc_fixed = $calculator->calculate( $context_fixed, $res_fixed );

			$this->assert( '85.000000' === $calc_fixed->final_price()->amount(), 'Fixed price of 85.00 must be applied directly.' );
			$this->assert( 'fixed' === $calc_fixed->pricing_type(), 'Pricing type must be fixed.' );
			$this->assert( $rule_fixed->id() === $calc_fixed->applied_rule_id(), 'Rule ID must match fixed rule.' );

			// Case E: Category Percentage Rule on Group
			$context_cat = new PricingContext(
				product_id: $cat_pid,
				base_price: Money::from( '50.00', 'USD' ),
				customer_id: 2,
				group: $group,
				category_ids: [ $cat_id ]
			);
			$res_cat  = $resolver->resolve( $context_cat );
			$calc_cat = $calculator->calculate( $context_cat, $res_cat );

			$this->assert( '42.500000' === $calc_cat->final_price()->amount(), '15% on 50.00 category product must produce 42.50.' );
			$this->assert( PrecedenceLevel::GROUP_CATEGORY === $calc_cat->precedence_level(), 'Precedence level must be GROUP_CATEGORY.' );

			// Case F: Fallback without matching rule (Anonymous guest)
			$context_guest = new PricingContext(
				product_id: $simple_pid,
				base_price: Money::from( '100.00', 'USD' ),
				customer_id: 0 // Guest
			);
			$res_guest  = $resolver->resolve( $context_guest );
			$calc_guest = $calculator->calculate( $context_guest, $res_guest );

			$this->assert( ! $calc_guest->is_rule_applied(), 'Fallback result must not be reported as applied rule.' );
			$this->assert( '100.000000' === $calc_guest->final_price()->amount(), 'Fallback final price must match original base price unaltered.' );
			$this->assert( null === $calc_guest->applied_rule_id(), 'Applied rule ID must be null on fallback.' );
			$this->assert( PricingReasonCode::FALLBACK_ANONYMOUS_NO_RULE->value === $calc_guest->reason_code(), 'Reason code must indicate anonymous fallback.' );
		} finally {
			// Clean up fixtures reliably
			$this->rule_repo->delete( (int) $rule_simple->id() );
			$this->rule_repo->delete( (int) $rule_variation->id() );
			$this->rule_repo->delete( (int) $rule_category->id() );
			$this->rule_repo->delete( (int) $rule_fixed->id() );
			$this->group_repo->delete( $group_id );

			$this->db->delete( $this->db->prefix . 'term_taxonomy', [ 'term_id' => $cat_id ] );
			$this->db->delete( $this->db->prefix . 'terms', [ 'term_id' => $cat_id ] );

			$this->db->delete( $this->db->prefix . 'posts', [ 'ID' => $simple_pid ] );
			$this->db->delete( $this->db->prefix . 'posts', [ 'ID' => $variation_id ] );
			$this->db->delete( $this->db->prefix . 'posts', [ 'ID' => $variable_pid ] );
			$this->db->delete( $this->db->prefix . 'posts', [ 'ID' => $sale_pid ] );
			$this->db->delete( $this->db->prefix . 'posts', [ 'ID' => $cat_pid ] );
		}
	}

	public function test_full_chain_pricing_integration_and_database_immutability_in_mariadb(): void {
		$resolver   = new DeterministicRuleResolver( $this->rule_repo, $this->group_repo );
		$calculator = new AuthoritativePriceCalculator( default_currency_decimals: 2 );
		$now        = current_time( 'mysql', true );

		// 1. Create real WordPress customers
		$this->db->insert( $this->db->users, [
			'user_login'           => 'trz_cust_a_' . wp_rand( 1000, 9999 ),
			'user_pass'            => 'dummy_hash',
			'user_email'           => 'cust_a_' . wp_rand( 1000, 9999 ) . '@example.com',
			'user_registered'      => $now,
			'display_name'         => 'Tierza Customer A',
		] );
		$user_a_id = (int) $this->db->insert_id;

		$this->db->insert( $this->db->users, [
			'user_login'           => 'trz_cust_b_' . wp_rand( 1000, 9999 ),
			'user_pass'            => 'dummy_hash',
			'user_email'           => 'cust_b_' . wp_rand( 1000, 9999 ) . '@example.com',
			'user_registered'      => $now,
			'display_name'         => 'Tierza Customer B',
		] );
		$user_b_id = (int) $this->db->insert_id;

		// 2. Create products in wp_posts
		// Simple product
		$this->db->insert( $this->db->prefix . 'posts', [ 'post_title' => 'TRZ Full-Chain Simple', 'post_type' => 'product', 'post_status' => 'publish' ] );
		$simple_pid = (int) $this->db->insert_id;
		$this->db->insert( $this->db->prefix . 'postmeta', [ 'post_id' => $simple_pid, 'meta_key' => '_regular_price', 'meta_value' => '200.00' ] );
		$this->db->insert( $this->db->prefix . 'postmeta', [ 'post_id' => $simple_pid, 'meta_key' => '_price', 'meta_value' => '200.00' ] );

		// Variable product with 2 variations
		$this->db->insert( $this->db->prefix . 'posts', [ 'post_title' => 'TRZ Full-Chain Variable', 'post_type' => 'product', 'post_status' => 'publish' ] );
		$variable_pid = (int) $this->db->insert_id;

		$this->db->insert( $this->db->prefix . 'posts', [ 'post_title' => 'TRZ Variation 1', 'post_type' => 'product_variation', 'post_status' => 'publish', 'post_parent' => $variable_pid ] );
		$var_1_id = (int) $this->db->insert_id;
		$this->db->insert( $this->db->prefix . 'postmeta', [ 'post_id' => $var_1_id, 'meta_key' => '_price', 'meta_value' => '150.00' ] );

		$this->db->insert( $this->db->prefix . 'posts', [ 'post_title' => 'TRZ Variation 2', 'post_type' => 'product_variation', 'post_status' => 'publish', 'post_parent' => $variable_pid ] );
		$var_2_id = (int) $this->db->insert_id;
		$this->db->insert( $this->db->prefix . 'postmeta', [ 'post_id' => $var_2_id, 'meta_key' => '_price', 'meta_value' => '180.00' ] );

		// Categories: Parent (depth 1) and Child (depth 2)
		$this->db->insert( $this->db->prefix . 'terms', [ 'name' => 'TRZ Parent Cat', 'slug' => 'trz-parent-cat-' . wp_rand( 100, 999 ) ] );
		$cat_p_id = (int) $this->db->insert_id;
		$this->db->insert( $this->db->prefix . 'term_taxonomy', [ 'term_id' => $cat_p_id, 'taxonomy' => 'product_cat', 'parent' => 0, 'count' => 1 ] );

		$this->db->insert( $this->db->prefix . 'terms', [ 'name' => 'TRZ Child Cat', 'slug' => 'trz-child-cat-' . wp_rand( 100, 999 ) ] );
		$cat_c_id = (int) $this->db->insert_id;
		$this->db->insert( $this->db->prefix . 'term_taxonomy', [ 'term_id' => $cat_c_id, 'taxonomy' => 'product_cat', 'parent' => $cat_p_id, 'count' => 1 ] );

		// Record initial order count
		$orders_count_before = (int) $this->db->get_var( "SELECT COUNT(*) FROM {$this->db->prefix}posts WHERE post_type IN ('shop_order', 'shop_order_placehold')" );

		// 3. Create Tierza group and assign users
		$group = $this->group_repo->save( new PricingGroup( 'TRZ Wholesale Group', 'trz-ws-' . wp_rand( 100, 999 ) ) );
		$group_id = (int) $group->id();

		$this->user_group_repo->assign( $user_a_id, $group_id );
		$this->user_group_repo->assign( $user_b_id, $group_id );

		// 4. Save authoritative rules in MariaDB
		// Rule 1: User A percentage on Variation 1 = 30%
		$r1 = $this->rule_repo->save( new PricingRule(
			target: RuleTarget::user( $user_a_id ),
			scope: RuleScope::variation( $var_1_id ),
			pricing_value: PricingValue::percentage( '30.000000' )
		) );

		// Rule 2: Group percentage on Variable parent product = 20%
		$r2 = $this->rule_repo->save( new PricingRule(
			target: RuleTarget::group( $group_id ),
			scope: RuleScope::product( $variable_pid ),
			pricing_value: PricingValue::percentage( '20.000000' )
		) );

		// Rule 3: Group percentage on Child Category = 15%
		$r3 = $this->rule_repo->save( new PricingRule(
			target: RuleTarget::group( $group_id ),
			scope: RuleScope::category( $cat_c_id ),
			pricing_value: PricingValue::percentage( '15.000000' )
		) );

		// Rule 4: Group percentage on Parent Category = 25% (deeper child should beat this)
		$r4 = $this->rule_repo->save( new PricingRule(
			target: RuleTarget::group( $group_id ),
			scope: RuleScope::category( $cat_p_id ),
			pricing_value: PricingValue::percentage( '25.000000' )
		) );

		// Rule 5: Inactive rule on Variation 1 (must be ignored)
		$r5 = $this->rule_repo->save( new PricingRule(
			target: RuleTarget::user( $user_a_id ),
			scope: RuleScope::variation( $var_1_id ),
			pricing_value: PricingValue::percentage( '50.000000' ),
			status: RuleStatus::inactive()
		) );

		try {
			// Scenario A: Customer A on Variation 1 -> Customer Variation rule wins (30% off 150.00 = 105.00)
			$ctx_a = new PricingContext(
				product_id: $variable_pid,
				base_price: Money::from( '150.00', 'USD' ),
				customer_id: $user_a_id,
				group: $group,
				variation_id: $var_1_id,
				group_id: $group_id
			);
			$res_a = $resolver->resolve( $ctx_a );
			$out_a = $calculator->calculate( $ctx_a, $res_a );

			$this->assert( $out_a->is_rule_applied(), 'Rule must apply for Customer A on variation.' );
			$this->assert( '105.000000' === $out_a->final_price()->amount(), '30% discount on 150.00 must equal 105.00.' );
			$this->assert( PrecedenceLevel::CUSTOMER_VARIATION === $out_a->precedence_level(), 'Precedence must be CUSTOMER_VARIATION.' );
			$this->assert( $r1->id() === $out_a->applied_rule_id(), 'Applied rule ID must match Rule 1.' );

			// Scenario B: Customer B on Variation 1 -> Customer Isolation: B does NOT get A's rule.
			// Variation 1 inherits Parent Product group rule (20% off 150.00 = 120.00).
			$ctx_b = new PricingContext(
				product_id: $variable_pid,
				base_price: Money::from( '150.00', 'USD' ),
				customer_id: $user_b_id,
				group: $group,
				variation_id: $var_1_id,
				group_id: $group_id
			);
			$res_b = $resolver->resolve( $ctx_b );
			$out_b = $calculator->calculate( $ctx_b, $res_b );

			$this->assert( $out_b->is_rule_applied(), 'Rule must apply for Customer B.' );
			$this->assert( '120.000000' === $out_b->final_price()->amount(), 'Customer B must receive inherited group product rule (120.00), not Customer A rule.' );
			$this->assert( PrecedenceLevel::GROUP_PRODUCT === $out_b->precedence_level(), 'Precedence must be GROUP_PRODUCT.' );
			$this->assert( $r2->id() === $out_b->applied_rule_id(), 'Applied rule ID must match Rule 2.' );

			// Scenario C: Variation 2 without specific rule inherits parent product rule
			$ctx_v2 = new PricingContext(
				product_id: $variable_pid,
				base_price: Money::from( '180.00', 'USD' ),
				customer_id: $user_b_id,
				group: $group,
				variation_id: $var_2_id,
				group_id: $group_id
			);
			$out_v2 = $calculator->calculate( $ctx_v2, $resolver->resolve( $ctx_v2 ) );
			$this->assert( '144.000000' === $out_v2->final_price()->amount(), 'Variation 2 must inherit parent rule (180 - 20% = 144.00).' );

			// Scenario D: Simple Product in Parent (depth 1) and Child (depth 2) categories
			$ctx_cat = new PricingContext(
				product_id: $simple_pid,
				base_price: Money::from( '200.00', 'USD' ),
				customer_id: $user_b_id,
				group: $group,
				category_ids: [ $cat_p_id, $cat_c_id ],
				category_depths: [ $cat_p_id => 1, $cat_c_id => 2 ],
				group_id: $group_id
			);
			$out_cat = $calculator->calculate( $ctx_cat, $resolver->resolve( $ctx_cat ) );
			$this->assert( '170.000000' === $out_cat->final_price()->amount(), 'Child category depth 2 (15%) must win over parent cat depth 1 (25%).' );
			$this->assert( $r3->id() === $out_cat->applied_rule_id(), 'Applied rule must be child category rule.' );

			// 5. Assert database immutability: stored product prices are strictly untouched
			$price_simple = $this->db->get_var( $this->db->prepare( "SELECT meta_value FROM {$this->db->prefix}postmeta WHERE post_id = %d AND meta_key = '_price'", $simple_pid ) );
			$price_var1   = $this->db->get_var( $this->db->prepare( "SELECT meta_value FROM {$this->db->prefix}postmeta WHERE post_id = %d AND meta_key = '_price'", $var_1_id ) );
			$price_var2   = $this->db->get_var( $this->db->prepare( "SELECT meta_value FROM {$this->db->prefix}postmeta WHERE post_id = %d AND meta_key = '_price'", $var_2_id ) );

			$this->assert( '200.00' === $price_simple, 'Stored simple product price must remain 200.00 unaltered.' );
			$this->assert( '150.00' === $price_var1, 'Stored variation 1 price must remain 150.00 unaltered.' );
			$this->assert( '180.00' === $price_var2, 'Stored variation 2 price must remain 180.00 unaltered.' );

			// 6. Assert no customer order records were created or modified
			$orders_count_after = (int) $this->db->get_var( "SELECT COUNT(*) FROM {$this->db->prefix}posts WHERE post_type IN ('shop_order', 'shop_order_placehold')" );
			$this->assert( $orders_count_before === $orders_count_after, 'Pricing engine must never create or modify order records.' );
		} finally {
			// Clean up fixtures reliably
			$this->rule_repo->delete( (int) $r1->id() );
			$this->rule_repo->delete( (int) $r2->id() );
			$this->rule_repo->delete( (int) $r3->id() );
			$this->rule_repo->delete( (int) $r4->id() );
			$this->rule_repo->delete( (int) $r5->id() );

			$this->user_group_repo->remove( $user_a_id );
			$this->user_group_repo->remove( $user_b_id );
			$this->group_repo->delete( $group_id );

			$this->db->delete( $this->db->users, [ 'ID' => $user_a_id ] );
			$this->db->delete( $this->db->users, [ 'ID' => $user_b_id ] );

			$this->db->delete( $this->db->prefix . 'term_taxonomy', [ 'term_id' => $cat_p_id ] );
			$this->db->delete( $this->db->prefix . 'term_taxonomy', [ 'term_id' => $cat_c_id ] );
			$this->db->delete( $this->db->prefix . 'terms', [ 'term_id' => $cat_p_id ] );
			$this->db->delete( $this->db->prefix . 'terms', [ 'term_id' => $cat_c_id ] );

			$this->db->delete( $this->db->prefix . 'postmeta', [ 'post_id' => $simple_pid ] );
			$this->db->delete( $this->db->prefix . 'postmeta', [ 'post_id' => $var_1_id ] );
			$this->db->delete( $this->db->prefix . 'postmeta', [ 'post_id' => $var_2_id ] );
			$this->db->delete( $this->db->prefix . 'posts', [ 'ID' => $simple_pid ] );
			$this->db->delete( $this->db->prefix . 'posts', [ 'ID' => $variable_pid ] );
			$this->db->delete( $this->db->prefix . 'posts', [ 'ID' => $var_1_id ] );
			$this->db->delete( $this->db->prefix . 'posts', [ 'ID' => $var_2_id ] );
		}
	}

	public function test_pricing_engine_comprehensive_performance_baseline_in_mariadb(): void {
		$resolver   = new DeterministicRuleResolver( $this->rule_repo, $this->group_repo );
		$calculator = new AuthoritativePriceCalculator( default_currency_decimals: 2 );
		$table_rules  = Schema::rules_table( $this->db );
		$table_groups = Schema::groups_table( $this->db );
		$now          = current_time( 'mysql', true );

		// Seed 10 groups
		$group_ids = [];
		for ( $i = 1; $i <= 10; $i++ ) {
			$this->db->insert( $table_groups, [
				'name'        => sprintf( 'Perf Tier %02d', $i ),
				'slug'        => sprintf( 'perf-tier-%02d-%d', $i, wp_rand( 100, 999 ) ),
				'status'      => 'active',
				'sort_order'  => $i,
				'created_at'  => $now,
				'updated_at'  => $now,
			] );
			$group_ids[] = (int) $this->db->insert_id;
		}

		// Seed 100 products
		$product_ids = [];
		for ( $i = 1; $i <= 100; $i++ ) {
			$this->db->insert( $this->db->prefix . 'posts', [
				'post_title'   => sprintf( 'Perf Prod %03d', $i ),
				'post_type'    => 'product',
				'post_status'  => 'publish',
				'post_date'    => $now,
			] );
			$product_ids[] = (int) $this->db->insert_id;
		}

		// Seed 500 rules (100 active product rules, 10 active global rules, 390 inactive rules)
		for ( $chunk = 0; $chunk < 5; $chunk++ ) {
			$placeholders = [];
			$values       = [];
			for ( $k = 1; $k <= 100; $k++ ) {
				$r            = $chunk * 100 + $k;
				$target_group = $group_ids[ ( $r - 1 ) % 10 ];
				if ( $r <= 10 ) {
					$scope_type = 'global';
					$scope_id   = 0;
				} else {
					$scope_type = 'product';
					$scope_id   = $product_ids[ ( $r - 11 ) % 100 ];
				}

				if ( $r > 110 ) {
					$status         = 'inactive';
					$placeholders[] = '(%s, %d, %s, %d, %s, %s, %s, NULL, %s, %s)';
					$values[]       = 'group';
					$values[]       = $target_group;
					$values[]       = $scope_type;
					$values[]       = $scope_id;
					$values[]       = 'percentage';
					$values[]       = sprintf( '%02d.000000', ( $r % 40 ) + 5 );
					$values[]       = $status;
					$values[]       = $now;
					$values[]       = $now;
				} else {
					$status         = 'active';
					$active_key     = sprintf( 'group:%d|%s:%d', $target_group, $scope_type, $scope_id );
					$placeholders[] = '(%s, %d, %s, %d, %s, %s, %s, %s, %s, %s)';
					$values[]       = 'group';
					$values[]       = $target_group;
					$values[]       = $scope_type;
					$values[]       = $scope_id;
					$values[]       = 'percentage';
					$values[]       = sprintf( '%02d.000000', ( $r % 40 ) + 5 );
					$values[]       = $status;
					$values[]       = $active_key;
					$values[]       = $now;
					$values[]       = $now;
				}
			}
			$sql = "INSERT INTO {$table_rules} (target_type, target_id, scope_type, scope_id, pricing_type, value, status, active_key, created_at, updated_at) VALUES " . implode( ', ', $placeholders );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$this->db->query( $this->db->prepare( $sql, ...$values ) );
		}

		try {
			$trusted_group = $this->group_repo->find_by_id( $group_ids[0] );
			$this->assert( null !== $trusted_group, 'Trusted group must exist.' );

			// Scenario 1: 1 customer & 1 product (10 runs to measure variation)
			$single_runs = [];
			for ( $r = 0; $r < 10; $r++ ) {
				$t0 = microtime( true );
				$ctx = new PricingContext(
					product_id: $product_ids[0],
					base_price: Money::from( '100.00', 'USD' ),
					customer_id: 1,
					group: $trusted_group
				);
				$res = $resolver->resolve( $ctx );
				$calculator->calculate( $ctx, $res );
				$single_runs[] = ( microtime( true ) - $t0 ) * 1000;
			}
			$s1_avg  = array_sum( $single_runs ) / count( $single_runs );
			$s1_min  = min( $single_runs );
			$s1_max  = max( $single_runs );

			// Scenario 2: 1 customer & 100 products
			$q_before = $this->db->num_queries;
			$m_before = memory_get_usage();
			$t_start_100 = microtime( true );

			for ( $i = 0; $i < 100; $i++ ) {
				$ctx = new PricingContext(
					product_id: $product_ids[ $i ],
					base_price: Money::from( '100.00', 'USD' ),
					customer_id: 1,
					group: $trusted_group
				);
				$res = $resolver->resolve( $ctx );
				$calculator->calculate( $ctx, $res );
			}
			$s2_time    = ( microtime( true ) - $t_start_100 ) * 1000;
			$s2_queries = $this->db->num_queries - $q_before;
			$s2_mem_peak = memory_get_peak_usage() / 1024 / 1024;

			// Scenario 3: 1 customer & 500 products (simulated over 5 passes)
			$q_before_500 = $this->db->num_queries;
			$t_start_500  = microtime( true );

			for ( $pass = 0; $pass < 5; $pass++ ) {
				for ( $i = 0; $i < 100; $i++ ) {
					$ctx = new PricingContext(
						product_id: $product_ids[ $i ],
						base_price: Money::from( '100.00', 'USD' ),
						customer_id: 1,
						group: $trusted_group
					);
					$res = $resolver->resolve( $ctx );
					$calculator->calculate( $ctx, $res );
				}
			}
			$s3_time    = ( microtime( true ) - $t_start_500 ) * 1000;
			$s3_queries = $this->db->num_queries - $q_before_500;

			// Scenario 4: Repeated lookups with identical contexts (100 times)
			$ctx_fixed = new PricingContext(
				product_id: $product_ids[0],
				base_price: Money::from( '100.00', 'USD' ),
				customer_id: 1,
				group: $trusted_group
			);
			$t_start_rep = microtime( true );
			for ( $k = 0; $k < 100; $k++ ) {
				$res = $resolver->resolve( $ctx_fixed );
				$calculator->calculate( $ctx_fixed, $res );
			}
			$s4_time = ( microtime( true ) - $t_start_rep ) * 1000;

			// Assertions on performance guarantees
			$this->assert( 100 === $s2_queries, '100 product resolutions must execute exactly 100 queries without cache.' );
			$this->assert( 500 === $s3_queries, '500 product resolutions must execute exactly 500 queries without cache.' );
			$this->assert( $s2_time < 3000, '100 product resolutions must execute in < 3000ms.' );

			echo PHP_EOL;
			echo "   --- Performance Baseline Metrics (TRZ-008) ---" . PHP_EOL;
			echo sprintf( "   1 cust / 1 prod: avg = %.2f ms (min = %.2f ms, max = %.2f ms, 1 SQL query)" . PHP_EOL, $s1_avg, $s1_min, $s1_max );
			echo sprintf( "   1 cust / 100 prods: %.2f ms total (%.2f ms/prod, %d queries, peak mem = %.2f MB)" . PHP_EOL, $s2_time, $s2_time / 100, $s2_queries, $s2_mem_peak );
			echo sprintf( "   1 cust / 500 prods: %.2f ms total (%.2f ms/prod, %d queries)" . PHP_EOL, $s3_time, $s3_time / 500, $s3_queries );
			echo sprintf( "   100 identical lookups (no cache): %.2f ms total (%.2f ms/lookup)" . PHP_EOL, $s4_time, $s4_time / 100 );
			echo "   -----------------------------------------------" . PHP_EOL;
		} finally {
			// Clean up performance fixtures
			$this->db->query( "DELETE FROM {$table_rules} WHERE target_type = 'group' AND target_id IN (" . implode( ',', $group_ids ) . ')' );
			$this->db->query( "DELETE FROM {$table_groups} WHERE id IN (" . implode( ',', $group_ids ) . ')' );
			$this->db->query( "DELETE FROM {$this->db->prefix}posts WHERE ID IN (" . implode( ',', $product_ids ) . ')' );
		}
	}
}

// Execute the test suite.
$tester = new DatabaseIntegrationLiveTest();
$exit_code = $tester->run();
exit( $exit_code );
