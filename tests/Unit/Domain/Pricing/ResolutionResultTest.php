<?php

declare(strict_types=1);

namespace Tierza\Tests\Unit\Domain\Pricing;

use PHPUnit\Framework\TestCase;
use Tierza\Domain\Entities\PricingRule;
use Tierza\Domain\Pricing\Enums\PrecedenceLevel;
use Tierza\Domain\Pricing\Enums\PricingReasonCode;
use Tierza\Domain\Pricing\Enums\PricingSource;
use Tierza\Domain\Pricing\ResolutionResult;
use Tierza\Domain\ValueObjects\PricingValue;
use Tierza\Domain\ValueObjects\RuleScope;
use Tierza\Domain\ValueObjects\RuleTarget;

final class ResolutionResultTest extends TestCase {

	public function test_matched_customer_rule(): void {
		$rule = new PricingRule(
			RuleTarget::customer( 15 ),
			RuleScope::product( 42 ),
			PricingValue::percentage( 20 ),
			101
		);

		$result = ResolutionResult::matched(
			$rule,
			PrecedenceLevel::CUSTOMER_PRODUCT,
			diagnostic_metadata: [ 'evaluated_rules' => 3 ]
		);

		$this->assertTrue( $result->has_rule() );
		$this->assertSame( $rule, $result->rule() );
		$this->assertSame( PrecedenceLevel::CUSTOMER_PRODUCT, $result->precedence_level() );
		$this->assertSame( PricingSource::CUSTOMER, $result->source() );
		$this->assertSame( PricingReasonCode::RULE_APPLIED_CUSTOMER_PRODUCT->value, $result->reason_code() );
		$this->assertFalse( $result->is_fallback() );
		$this->assertSame( [ 'evaluated_rules' => 3 ], $result->diagnostic_metadata() );
	}

	public function test_matched_group_rule(): void {
		$rule = new PricingRule(
			RuleTarget::group( 5 ),
			RuleScope::category( 12 ),
			PricingValue::percentage( 10 ),
			102
		);

		$result = ResolutionResult::matched(
			$rule,
			PrecedenceLevel::GROUP_CATEGORY
		);

		$this->assertTrue( $result->has_rule() );
		$this->assertSame( PrecedenceLevel::GROUP_CATEGORY, $result->precedence_level() );
		$this->assertSame( PricingSource::GROUP, $result->source() );
		$this->assertSame( PricingReasonCode::RULE_APPLIED_GROUP_CATEGORY->value, $result->reason_code() );
	}

	public function test_matched_explicit_zero_percentage_rule_is_distinguishable_from_fallback(): void {
		$zero_rule = new PricingRule(
			RuleTarget::customer( 15 ),
			RuleScope::global(),
			PricingValue::percentage( 0 ),
			103
		);

		$result = ResolutionResult::matched(
			$zero_rule,
			PrecedenceLevel::CUSTOMER_GLOBAL
		);

		$this->assertTrue( $result->has_rule() );
		$this->assertSame( $zero_rule, $result->rule() );
		$this->assertSame( PricingReasonCode::RULE_APPLIED_ZERO_PERCENTAGE->value, $result->reason_code() );
		$this->assertFalse( $result->is_fallback() );
	}

	public function test_fallback_when_no_rules_match(): void {
		$result = ResolutionResult::fallback(
			PricingReasonCode::FALLBACK_NO_MATCHING_RULE->value,
			[ 'reason' => 'no_customer_or_group_rules_found' ]
		);

		$this->assertFalse( $result->has_rule() );
		$this->assertNull( $result->rule() );
		$this->assertSame( PrecedenceLevel::WOOCOMMERCE_FALLBACK, $result->precedence_level() );
		$this->assertSame( PricingSource::WOOCOMMERCE_FALLBACK, $result->source() );
		$this->assertSame( PricingReasonCode::FALLBACK_NO_MATCHING_RULE->value, $result->reason_code() );
		$this->assertTrue( $result->is_fallback() );
	}
}
