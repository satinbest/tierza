<?php

declare(strict_types=1);

namespace Tierza\Tests\Unit\Domain\Pricing;

use PHPUnit\Framework\TestCase;
use Tierza\Domain\Entities\PricingRule;
use Tierza\Domain\Pricing\Contracts\PriceCalculatorInterface;
use Tierza\Domain\Pricing\Contracts\RuleResolverInterface;
use Tierza\Domain\Pricing\Enums\PrecedenceLevel;
use Tierza\Domain\Pricing\Enums\PricingReasonCode;
use Tierza\Domain\Pricing\Enums\PricingSource;
use Tierza\Domain\Pricing\PricingContext;
use Tierza\Domain\Pricing\PricingResult;
use Tierza\Domain\Pricing\ResolutionResult;
use Tierza\Domain\ValueObjects\Money;
use Tierza\Domain\ValueObjects\PricingValue;
use Tierza\Domain\ValueObjects\RuleScope;
use Tierza\Domain\ValueObjects\RuleTarget;

final class PricingContractsInteroperabilityTest extends TestCase {

	public function test_contracts_interoperability_with_rule_match(): void {
		$context = new PricingContext(
			product_id: 200,
			base_price: Money::from( '500.00', 'USD' ),
			customer_id: 10
		);

		$matched_rule = new PricingRule(
			RuleTarget::customer( 10 ),
			RuleScope::product( 200 ),
			PricingValue::percentage( 25 ),
			77
		);

		// Implementation of RuleResolverInterface stub
		$resolver = new class( $matched_rule ) implements RuleResolverInterface {
			public function __construct( private readonly PricingRule $rule ) {}

			public function resolve( PricingContext $context ): ResolutionResult {
				return ResolutionResult::matched(
					$this->rule,
					PrecedenceLevel::CUSTOMER_PRODUCT
				);
			}
		};

		// Implementation of PriceCalculatorInterface stub
		$calculator = new class implements PriceCalculatorInterface {
			public function calculate( PricingContext $context, ResolutionResult $resolution ): PricingResult {
				$rule = $resolution->rule();
				if ( null === $rule ) {
					return new PricingResult(
						$context->base_price(),
						$context->base_price(),
						null,
						null,
						null,
						PricingSource::WOOCOMMERCE_FALLBACK,
						false,
						false,
						PricingReasonCode::FALLBACK_NO_MATCHING_RULE->value
					);
				}

				$final_price = $context->base_price()->apply_percentage_discount(
					$rule->pricing_value()->value()
				);

				return new PricingResult(
					$context->base_price(),
					$final_price,
					$rule->id(),
					$rule->pricing_value()->type(),
					$rule->scope()->type(),
					$resolution->source(),
					true,
					$rule->pricing_value()->is_zero(),
					$resolution->reason_code()
				);
			}
		};

		$resolution = $resolver->resolve( $context );
		$this->assertTrue( $resolution->has_rule() );
		$this->assertSame( PrecedenceLevel::CUSTOMER_PRODUCT, $resolution->precedence_level() );

		$result = $calculator->calculate( $context, $resolution );
		$this->assertSame( '500.000000', $result->original_price()->amount() );
		$this->assertSame( '375.000000', $result->final_price()->amount() );
		$this->assertSame( 77, $result->applied_rule_id() );
		$this->assertTrue( $result->is_discounted() );
		$this->assertSame( '125.000000', $result->discount_amount()->amount() );
		$this->assertSame( '25.000000', $result->savings_percentage() );
	}

	public function test_contracts_interoperability_with_fallback(): void {
		$context = new PricingContext(
			product_id: 300,
			base_price: Money::from( '80.00', 'IRR' ),
			customer_id: 0
		);

		$resolver = new class implements RuleResolverInterface {
			public function resolve( PricingContext $context ): ResolutionResult {
				return ResolutionResult::fallback();
			}
		};

		$calculator = new class implements PriceCalculatorInterface {
			public function calculate( PricingContext $context, ResolutionResult $resolution ): PricingResult {
				return new PricingResult(
					$context->base_price(),
					$context->base_price(),
					null,
					null,
					null,
					$resolution->source(),
					false,
					false,
					$resolution->reason_code()
				);
			}
		};

		$resolution = $resolver->resolve( $context );
		$this->assertFalse( $resolution->has_rule() );
		$this->assertTrue( $resolution->is_fallback() );

		$result = $calculator->calculate( $context, $resolution );
		$this->assertFalse( $result->is_rule_applied() );
		$this->assertSame( '80.000000', $result->final_price()->amount() );
		$this->assertSame( PricingSource::WOOCOMMERCE_FALLBACK, $result->source() );
	}
}
