<?php

declare(strict_types=1);

namespace Tierza\Tests\Unit\Application\Pricing;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tierza\Application\Pricing\AuthoritativePriceCalculator;
use Tierza\Application\Pricing\DeterministicRuleResolver;
use Tierza\Domain\Entities\PricingGroup;
use Tierza\Domain\Entities\PricingRule;
use Tierza\Domain\Exceptions\CurrencyMismatchException;
use Tierza\Domain\Exceptions\InvalidMoneyException;
use Tierza\Domain\Exceptions\InvalidPricingContextException;
use Tierza\Domain\Exceptions\InvalidRuleException;
use Tierza\Domain\Exceptions\MissingBasePriceException;
use Tierza\Domain\Exceptions\PricingCalculationException;
use Tierza\Domain\Exceptions\RuleResolutionException;
use Tierza\Domain\Pricing\Enums\PrecedenceLevel;
use Tierza\Domain\Pricing\PricingContext;
use Tierza\Domain\Pricing\ResolutionResult;
use Tierza\Domain\Repositories\GroupRepositoryInterface;
use Tierza\Domain\Repositories\RuleRepositoryInterface;
use Tierza\Domain\ValueObjects\GroupStatus;
use Tierza\Domain\ValueObjects\Money;
use Tierza\Domain\ValueObjects\PricingValue;
use Tierza\Domain\ValueObjects\RuleScope;
use Tierza\Domain\ValueObjects\RuleStatus;
use Tierza\Domain\ValueObjects\RuleTarget;
use Tierza\Infrastructure\Exceptions\DatabaseQueryException;

/**
 * Reliability and Error-Injection Test Suite (TRZ-008 Section 8).
 *
 * Injects adversarial errors, corrupted database states, and edge-case exceptions
 * to verify that failures fail-closed, are distinct from fallback, and protect sensitive data.
 */
final class ReliabilityErrorInjectionTest extends TestCase {

	private AuthoritativePriceCalculator $calculator;

	protected function setUp(): void {
		parent::setUp();
		$this->calculator = new AuthoritativePriceCalculator( default_currency_decimals: 2 );
	}

	public function test_rule_repository_database_exception_is_not_swallowed_as_fallback(): void {
		$failing_repo = $this->createMock( RuleRepositoryInterface::class );
		$failing_repo->method( 'find_active_for_targets' )
			->willThrowException( new DatabaseQueryException( 'Database execution failed: table corrupted or missing.' ) );

		$resolver = new DeterministicRuleResolver( $failing_repo );

		$context = new PricingContext(
			product_id: 10,
			base_price: Money::from( '100.00', 'USD' ),
			customer_id: 1
		);

		try {
			$resolver->resolve( $context );
			$this->fail( 'Expected RuleResolutionException was not thrown.' );
		} catch ( RuleResolutionException $e ) {
			// Invariant: Exception must be thrown, NOT a fallback result
			$this->assertStringContainsString( 'Repository failure while querying pricing rules', $e->getMessage() );
			$this->assertInstanceOf( DatabaseQueryException::class, $e->getPrevious() );
		}
	}

	public function test_group_repository_database_exception_is_not_swallowed_as_fallback(): void {
		$failing_grp_repo = $this->createMock( GroupRepositoryInterface::class );
		$failing_grp_repo->method( 'find_by_id' )
			->willThrowException( new DatabaseQueryException( 'Table wp_tierza_groups does not exist.' ) );

		$rule_repo = new InMemoryRuleRepository();
		$resolver  = new DeterministicRuleResolver( $rule_repo, $failing_grp_repo );

		$context = new PricingContext(
			product_id: 10,
			base_price: Money::from( '100.00', 'USD' ),
			customer_id: 1,
			group_id: 42
		);

		try {
			$resolver->resolve( $context );
			$this->fail( 'Expected RuleResolutionException was not thrown.' );
		} catch ( RuleResolutionException $e ) {
			$this->assertStringContainsString( 'Failed to verify referenced pricing group ID 42', $e->getMessage() );
		}
	}

	public function test_corrupted_inactive_rule_returned_by_faulty_repository_throws_resolution_exception(): void {
		$faulty_repo = $this->createMock( RuleRepositoryInterface::class );

		// Simulate faulty repository returning an inactive rule
		$inactive_rule = new PricingRule(
			target: RuleTarget::user( 1 ),
			scope: RuleScope::product( 10 ),
			pricing_value: PricingValue::percentage( '20' ),
			status: RuleStatus::inactive(),
			id: 55
		);

		$faulty_repo->method( 'find_active_for_targets' )->willReturn( [ $inactive_rule ] );

		$resolver = new DeterministicRuleResolver( $faulty_repo );
		$context  = new PricingContext(
			product_id: 10,
			base_price: Money::from( '100.00', 'USD' ),
			customer_id: 1
		);

		$this->expectException( RuleResolutionException::class );
		$this->expectExceptionMessage( 'Corrupted candidate rules: repository returned inactive rule ID 55.' );

		$resolver->resolve( $context );
	}

	public function test_corrupted_rule_with_foreign_target_throws_resolution_exception(): void {
		$faulty_repo = $this->createMock( RuleRepositoryInterface::class );

		// Repository returns rule belonging to user 999 when querying for user 1
		$foreign_rule = new PricingRule(
			target: RuleTarget::user( 999 ),
			scope: RuleScope::product( 10 ),
			pricing_value: PricingValue::percentage( '20' ),
			id: 88
		);

		$faulty_repo->method( 'find_active_for_targets' )->willReturn( [ $foreign_rule ] );

		$resolver = new DeterministicRuleResolver( $faulty_repo );
		$context  = new PricingContext(
			product_id: 10,
			base_price: Money::from( '100.00', 'USD' ),
			customer_id: 1
		);

		$this->expectException( RuleResolutionException::class );
		$this->expectExceptionMessage( 'Inconsistent rule target: rule ID 88 has target user:999, which is not an eligible context target.' );

		$resolver->resolve( $context );
	}

	public function test_conflicting_same_priority_rules_detected_and_rejected(): void {
		$repo = new InMemoryRuleRepository();

		// Two rules at customer_product precedence for the same product
		$rule1 = new PricingRule( RuleTarget::user( 1 ), RuleScope::product( 10 ), PricingValue::percentage( '10' ), id: 101 );
		$rule2 = new PricingRule( RuleTarget::user( 1 ), RuleScope::product( 10 ), PricingValue::percentage( '20' ), id: 102 );
		$repo->save( $rule1 );
		$repo->save( $rule2 );

		$resolver = new DeterministicRuleResolver( $repo );
		$context  = new PricingContext(
			product_id: 10,
			base_price: Money::from( '100.00', 'USD' ),
			customer_id: 1
		);

		$this->expectException( RuleResolutionException::class );
		$this->expectExceptionMessage( 'Conflicting same-priority rules detected: 2 active rules matched at precedence level CUSTOMER_PRODUCT.' );

		$resolver->resolve( $context );
	}

	public function test_fixed_price_rule_on_invalid_scope_rejected_by_domain(): void {
		$this->expectException( InvalidRuleException::class );
		$this->expectExceptionMessage( 'Fixed-price rules are restricted to product or variation scopes in v1. Got scope "category".' );

		// Fixed price on category scope is rejected at entity construction
		new PricingRule(
			target: RuleTarget::user( 1 ),
			scope: RuleScope::category( 5 ),
			pricing_value: PricingValue::fixed( '50.00' ),
			id: 77
		);
	}

	public function test_category_conflict_without_depth_metadata_fails_closed(): void {
		$repo = new InMemoryRuleRepository();

		// Competing category rules on categories 10 and 20
		$rule1 = new PricingRule( RuleTarget::user( 1 ), RuleScope::category( 10 ), PricingValue::percentage( '10' ), id: 1 );
		$rule2 = new PricingRule( RuleTarget::user( 1 ), RuleScope::category( 20 ), PricingValue::percentage( '20' ), id: 2 );
		$repo->save( $rule1 );
		$repo->save( $rule2 );

		$resolver = new DeterministicRuleResolver( $repo );

		// Context has category IDs but NO depth metadata
		$context = new PricingContext(
			product_id: 10,
			base_price: Money::from( '100.00', 'USD' ),
			customer_id: 1,
			category_ids: [ 10, 20 ],
			category_depths: [] // Missing depths
		);

		$this->expectException( RuleResolutionException::class );
		$this->expectExceptionMessage( 'missing category-depth metadata for category ID 10 in pricing context.' );

		$resolver->resolve( $context );
	}

	public function test_resolution_result_rejects_inactive_rule(): void {
		$inactive_rule = new PricingRule(
			target: RuleTarget::user( 1 ),
			scope: RuleScope::product( 10 ),
			pricing_value: PricingValue::percentage( '20' ),
			status: RuleStatus::inactive(),
			id: 12
		);

		$this->expectException( RuleResolutionException::class );
		$this->expectExceptionMessage( 'Cannot create ResolutionResult with an inactive rule' );

		ResolutionResult::matched( $inactive_rule, PrecedenceLevel::CUSTOMER_PRODUCT );
	}

	public function test_sensitive_data_not_leaked_in_diagnostics(): void {
		$rule = new PricingRule( RuleTarget::user( 1 ), RuleScope::product( 10 ), PricingValue::percentage( '15' ), id: 33 );
		$context = new PricingContext(
			product_id: 10,
			base_price: Money::from( '100.00', 'USD' ),
			customer_id: 1
		);

		$result      = $this->calculator->calculate( $context, ResolutionResult::matched( $rule, PrecedenceLevel::CUSTOMER_PRODUCT ) );
		$diagnostics = $result->diagnostic_metadata();

		// Convert diagnostics to JSON string and verify no sensitive credentials or keys exist
		$json = json_encode( $diagnostics );
		$this->assertIsString( $json );
		$this->assertStringNotContainsString( 'password', strtolower( $json ) );
		$this->assertStringNotContainsString( 'db_user', strtolower( $json ) );
		$this->assertStringNotContainsString( 'db_password', strtolower( $json ) );
		$this->assertStringNotContainsString( 'bearer', strtolower( $json ) );
		$this->assertStringNotContainsString( 'secret', strtolower( $json ) );
	}
}
