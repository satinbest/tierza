<?php

declare(strict_types=1);

namespace Tierza\Tests\Unit\Application\Pricing;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tierza\Application\Pricing\AuthoritativePriceCalculator;
use Tierza\Application\Pricing\DeterministicRuleResolver;
use Tierza\Domain\Entities\PricingGroup;
use Tierza\Domain\Entities\PricingRule;
use Tierza\Domain\Exceptions\InvalidPricingContextException;
use Tierza\Domain\Exceptions\PricingCalculationException;
use Tierza\Domain\Exceptions\RuleResolutionException;
use Tierza\Domain\Pricing\Enums\PrecedenceLevel;
use Tierza\Domain\Pricing\Enums\PricingReasonCode;
use Tierza\Domain\Pricing\Enums\PricingSource;
use Tierza\Domain\Pricing\PricingContext;
use Tierza\Domain\Pricing\ResolutionResult;
use Tierza\Domain\Repositories\GroupRepositoryInterface;
use Tierza\Domain\Repositories\RuleRepositoryInterface;
use Tierza\Domain\ValueObjects\GroupSlug;
use Tierza\Domain\ValueObjects\GroupStatus;
use Tierza\Domain\ValueObjects\Money;
use Tierza\Domain\ValueObjects\PricingValue;
use Tierza\Domain\ValueObjects\RuleScope;
use Tierza\Domain\ValueObjects\RuleTarget;

/**
 * Customer and Group Isolation Test Suite (TRZ-008 Section 2).
 *
 * Verifies strict boundary isolation between customers, active/inactive groups,
 * and ensures repository read failures fail-closed instead of silently falling back.
 */
final class CustomerGroupIsolationTest extends TestCase {

	private InMemoryRuleRepository $rule_repo;
	private InMemoryGroupRepository $group_repo;
	private DeterministicRuleResolver $resolver;
	private AuthoritativePriceCalculator $calculator;

	protected function setUp(): void {
		parent::setUp();
		$this->rule_repo  = new InMemoryRuleRepository();
		$this->group_repo = new InMemoryGroupRepository();
		$this->resolver   = new DeterministicRuleResolver( $this->rule_repo, $this->group_repo );
		$this->calculator = new AuthoritativePriceCalculator( default_currency_decimals: 2 );
	}

	public function test_anonymous_customer_without_group_never_matches_customer_or_group_rules(): void {
		// Save rules for user 10 and group 5
		$this->rule_repo->save( new PricingRule( RuleTarget::user( 10 ), RuleScope::global(), PricingValue::percentage( '20' ), id: 1 ) );
		$this->rule_repo->save( new PricingRule( RuleTarget::group( 5 ), RuleScope::global(), PricingValue::percentage( '30' ), id: 2 ) );

		$context = new PricingContext(
			product_id: 100,
			base_price: Money::from( '50.00', 'USD' ),
			customer_id: 0 // Anonymous
		);

		$resolution = $this->resolver->resolve( $context );
		$result     = $this->calculator->calculate( $context, $resolution );

		$this->assertTrue( $resolution->is_fallback() );
		$this->assertSame( PrecedenceLevel::WOOCOMMERCE_FALLBACK, $resolution->precedence_level() );
		$this->assertSame( PricingReasonCode::FALLBACK_ANONYMOUS_NO_RULE->value, $result->reason_code() );
		$this->assertSame( '50.000000', $result->final_price()->amount() );
		$this->assertFalse( $result->is_rule_applied() );
	}

	public function test_authenticated_customer_without_group_can_match_customer_rule_never_group_rule(): void {
		$this->rule_repo->save( new PricingRule( RuleTarget::user( 10 ), RuleScope::global(), PricingValue::percentage( '25' ), id: 1 ) );
		$this->rule_repo->save( new PricingRule( RuleTarget::group( 5 ), RuleScope::global(), PricingValue::percentage( '40' ), id: 2 ) );

		$context = new PricingContext(
			product_id: 100,
			base_price: Money::from( '100.00', 'USD' ),
			customer_id: 10 // Authenticated, no group
		);

		$resolution = $this->resolver->resolve( $context );
		$result     = $this->calculator->calculate( $context, $resolution );

		$this->assertSame( 1, $result->applied_rule_id() );
		$this->assertSame( PrecedenceLevel::CUSTOMER_GLOBAL, $resolution->precedence_level() );
		$this->assertSame( PricingSource::CUSTOMER, $result->source() );
		$this->assertSame( '75.000000', $result->final_price()->amount() );
	}

	public function test_customer_a_can_never_receive_customer_b_individual_pricing(): void {
		// Customer A (ID 10) has 50% discount, Customer B (ID 20) has no discount
		$this->rule_repo->save( new PricingRule( RuleTarget::user( 10 ), RuleScope::product( 100 ), PricingValue::percentage( '50' ), id: 101 ) );

		$context_b = new PricingContext(
			product_id: 100,
			base_price: Money::from( '100.00', 'USD' ),
			customer_id: 20
		);

		$resolution = $this->resolver->resolve( $context_b );
		$result     = $this->calculator->calculate( $context_b, $resolution );

		$this->assertTrue( $resolution->is_fallback() );
		$this->assertSame( PricingReasonCode::FALLBACK_NO_MATCHING_RULE->value, $result->reason_code() );
		$this->assertSame( '100.000000', $result->final_price()->amount() );
		$this->assertFalse( $result->is_rule_applied() );
	}

	public function test_customer_in_group_a_never_receives_group_b_rules(): void {
		$group_a = new PricingGroup( 'Group A', 'group-a', id: 1, status: GroupStatus::active() );
		$group_b = new PricingGroup( 'Group B', 'group-b', id: 2, status: GroupStatus::active() );
		$this->group_repo->save( $group_a );
		$this->group_repo->save( $group_b );

		// Rule for group B only
		$this->rule_repo->save( new PricingRule( RuleTarget::group( 2 ), RuleScope::global(), PricingValue::percentage( '30' ), id: 201 ) );

		$context = new PricingContext(
			product_id: 100,
			base_price: Money::from( '100.00', 'USD' ),
			customer_id: 15,
			group: $group_a,
			group_id: 1
		);

		$resolution = $this->resolver->resolve( $context );
		$result     = $this->calculator->calculate( $context, $resolution );

		$this->assertTrue( $resolution->is_fallback() );
		$this->assertSame( '100.000000', $result->final_price()->amount() );
		$this->assertFalse( $result->is_rule_applied() );
	}

	public function test_customer_with_inactive_group_never_matches_group_rules(): void {
		$inactive_group = new PricingGroup( 'Inactive Wholesale', 'inactive-wholesale', id: 9, status: GroupStatus::inactive() );
		$this->group_repo->save( $inactive_group );

		$this->rule_repo->save( new PricingRule( RuleTarget::group( 9 ), RuleScope::global(), PricingValue::percentage( '30' ), id: 301 ) );

		$context = new PricingContext(
			product_id: 100,
			base_price: Money::from( '100.00', 'USD' ),
			customer_id: 25,
			group: $inactive_group,
			group_id: 9
		);

		$resolution = $this->resolver->resolve( $context );
		$result     = $this->calculator->calculate( $context, $resolution );

		// Group is inactive -> target is excluded -> falls back to no matching rule
		$this->assertTrue( $resolution->is_fallback() );
		$this->assertSame( '100.000000', $result->final_price()->amount() );
	}

	public function test_customer_with_deleted_assigned_group_throws_rule_resolution_exception(): void {
		// Group ID 999 does not exist in repository
		$context = new PricingContext(
			product_id: 100,
			base_price: Money::from( '100.00', 'USD' ),
			customer_id: 25,
			group_id: 999 // Non-existent group referenced by ID
		);

		$this->expectException( RuleResolutionException::class );
		$this->expectExceptionMessage( 'Referenced pricing group ID 999 does not exist or has been deleted.' );

		$this->resolver->resolve( $context );
	}

	public function test_customer_override_beats_group_rule_regardless_of_discounts(): void {
		$group = new PricingGroup( 'Wholesale', 'wholesale', id: 10, status: GroupStatus::active() );
		$this->group_repo->save( $group );

		// Customer has a smaller discount (10%), but group has a larger discount (40%)
		$this->rule_repo->save( new PricingRule( RuleTarget::user( 10 ), RuleScope::product( 100 ), PricingValue::percentage( '10' ), id: 1 ) );
		$this->rule_repo->save( new PricingRule( RuleTarget::group( 10 ), RuleScope::product( 100 ), PricingValue::percentage( '40' ), id: 2 ) );

		$context = new PricingContext(
			product_id: 100,
			base_price: Money::from( '100.00', 'USD' ),
			customer_id: 10,
			group: $group,
			group_id: 10
		);

		$resolution = $this->resolver->resolve( $context );
		$result     = $this->calculator->calculate( $context, $resolution );

		// Customer product (Level 2) strictly overrides Group product (Level 6)
		$this->assertSame( 1, $result->applied_rule_id() );
		$this->assertSame( PrecedenceLevel::CUSTOMER_PRODUCT, $resolution->precedence_level() );
		$this->assertSame( '90.000000', $result->final_price()->amount() );
	}

	public function test_group_id_mismatch_with_entity_throws_invalid_pricing_context_exception(): void {
		$group = new PricingGroup( 'Wholesale', 'wholesale', id: 10, status: GroupStatus::active() );

		$this->expectException( InvalidPricingContextException::class );
		$this->expectExceptionMessage( 'Group ID mismatch: entity group ID (10) does not match supplied group ID (99).' );

		new PricingContext(
			product_id: 100,
			base_price: Money::from( '100.00', 'USD' ),
			customer_id: 10,
			group: $group,
			group_id: 99
		);
	}

	public function test_rule_repository_failure_throws_resolution_exception_never_silent_fallback(): void {
		$failing_rule_repo = $this->createMock( RuleRepositoryInterface::class );
		$failing_rule_repo->method( 'find_active_for_targets' )
			->willThrowException( new RuntimeException( 'Database connection timeout' ) );

		$resolver = new DeterministicRuleResolver( $failing_rule_repo, $this->group_repo );

		$context = new PricingContext(
			product_id: 100,
			base_price: Money::from( '100.00', 'USD' ),
			customer_id: 10
		);

		$this->expectException( RuleResolutionException::class );
		$this->expectExceptionMessage( 'Repository failure while querying pricing rules: Database connection timeout' );

		$resolver->resolve( $context );
	}

	public function test_group_repository_failure_throws_resolution_exception_never_silent_fallback(): void {
		$failing_group_repo = $this->createMock( GroupRepositoryInterface::class );
		$failing_group_repo->method( 'find_by_id' )
			->willThrowException( new RuntimeException( 'Connection dead' ) );

		$resolver = new DeterministicRuleResolver( $this->rule_repo, $failing_group_repo );

		$context = new PricingContext(
			product_id: 100,
			base_price: Money::from( '100.00', 'USD' ),
			customer_id: 10,
			group_id: 5 // Needs repo lookup
		);

		$this->expectException( RuleResolutionException::class );
		$this->expectExceptionMessage( 'Failed to verify referenced pricing group ID 5: Connection dead' );

		$resolver->resolve( $context );
	}

	public function test_calculator_rejects_customer_rule_applied_to_different_customer(): void {
		$rule = new PricingRule( RuleTarget::user( 10 ), RuleScope::product( 100 ), PricingValue::percentage( '20' ), id: 1 );

		// Context has customer ID 20, but rule was for customer 10
		$context = new PricingContext(
			product_id: 100,
			base_price: Money::from( '100.00', 'USD' ),
			customer_id: 20
		);

		$resolution = ResolutionResult::matched( $rule, PrecedenceLevel::CUSTOMER_PRODUCT );

		$this->expectException( PricingCalculationException::class );
		$this->expectExceptionMessage( 'Customer pricing rule target ID (10) does not match context customer ID (20).' );

		$this->calculator->calculate( $context, $resolution );
	}

	public function test_calculator_rejects_guest_user_receiving_customer_rule(): void {
		$rule = new PricingRule( RuleTarget::user( 10 ), RuleScope::product( 100 ), PricingValue::percentage( '20' ), id: 1 );

		$context = new PricingContext(
			product_id: 100,
			base_price: Money::from( '100.00', 'USD' ),
			customer_id: 0 // Guest
		);

		$resolution = ResolutionResult::matched( $rule, PrecedenceLevel::CUSTOMER_PRODUCT );

		$this->expectException( PricingCalculationException::class );
		$this->expectExceptionMessage( 'Guest users cannot receive customer-specific pricing.' );

		$this->calculator->calculate( $context, $resolution );
	}
}
