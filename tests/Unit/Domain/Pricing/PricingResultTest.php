<?php

declare(strict_types=1);

namespace Tierza\Tests\Unit\Domain\Pricing;

use PHPUnit\Framework\TestCase;
use Tierza\Domain\Exceptions\CurrencyMismatchException;
use Tierza\Domain\Pricing\Enums\PricingReasonCode;
use Tierza\Domain\Pricing\Enums\PricingSource;
use Tierza\Domain\Pricing\PricingResult;
use Tierza\Domain\ValueObjects\Money;

final class PricingResultTest extends TestCase {

	public function test_pricing_result_for_discounted_rule(): void {
		$original = Money::from( '100.00', 'USD' );
		$final    = Money::from( '80.00', 'USD' );

		$result = new PricingResult(
			original_price: $original,
			final_price: $final,
			applied_rule_id: 42,
			pricing_type: 'percentage',
			scope_type: 'product',
			source: PricingSource::CUSTOMER,
			is_rule_applied: true,
			is_zero_percentage: false,
			reason_code: PricingReasonCode::RULE_APPLIED_CUSTOMER_PRODUCT->value,
			diagnostic_metadata: [ 'matched_step' => 'customer_product' ]
		);

		$this->assertSame( '100.000000', $result->original_price()->amount() );
		$this->assertSame( '80.000000', $result->final_price()->amount() );
		$this->assertSame( 42, $result->applied_rule_id() );
		$this->assertSame( 'percentage', $result->pricing_type() );
		$this->assertSame( 'product', $result->scope_type() );
		$this->assertSame( PricingSource::CUSTOMER, $result->source() );
		$this->assertTrue( $result->is_rule_applied() );
		$this->assertFalse( $result->is_zero_percentage() );
		$this->assertTrue( $result->is_discounted() );
		$this->assertSame( '20.000000', $result->discount_amount()->amount() );
		$this->assertSame( '20.000000', $result->savings_percentage() );
	}

	public function test_pricing_result_distinguishes_zero_percent_rule_from_fallback(): void {
		$price = Money::from( '100.00', 'USD' );

		// Applied 0% rule
		$zero_rule_result = new PricingResult(
			original_price: $price,
			final_price: $price,
			applied_rule_id: 10,
			pricing_type: 'percentage',
			scope_type: 'category',
			source: PricingSource::GROUP,
			is_rule_applied: true,
			is_zero_percentage: true,
			reason_code: PricingReasonCode::RULE_APPLIED_ZERO_PERCENTAGE->value
		);

		$this->assertTrue( $zero_rule_result->is_rule_applied() );
		$this->assertTrue( $zero_rule_result->is_zero_percentage() );
		$this->assertSame( 10, $zero_rule_result->applied_rule_id() );
		$this->assertFalse( $zero_rule_result->is_discounted() );
		$this->assertSame( '0.000000', $zero_rule_result->discount_amount()->amount() );
		$this->assertSame( '0.000000', $zero_rule_result->savings_percentage() );
		$this->assertSame( PricingSource::GROUP, $zero_rule_result->source() );

		// Fallback (no rule)
		$fallback_result = new PricingResult(
			original_price: $price,
			final_price: $price,
			applied_rule_id: null,
			pricing_type: null,
			scope_type: null,
			source: PricingSource::WOOCOMMERCE_FALLBACK,
			is_rule_applied: false,
			is_zero_percentage: false,
			reason_code: PricingReasonCode::FALLBACK_NO_MATCHING_RULE->value
		);

		$this->assertFalse( $fallback_result->is_rule_applied() );
		$this->assertFalse( $fallback_result->is_zero_percentage() );
		$this->assertNull( $fallback_result->applied_rule_id() );
		$this->assertSame( PricingSource::WOOCOMMERCE_FALLBACK, $fallback_result->source() );
	}

	public function test_pricing_result_currency_mismatch_throws_exception(): void {
		$usd = Money::from( '100.00', 'USD' );
		$eur = Money::from( '80.00', 'EUR' );

		$this->expectException( CurrencyMismatchException::class );
		$this->expectExceptionMessage( 'Original price currency (USD) does not match final price currency (EUR)' );

		new PricingResult(
			original_price: $usd,
			final_price: $eur,
			applied_rule_id: null,
			pricing_type: null,
			scope_type: null,
			source: PricingSource::WOOCOMMERCE_FALLBACK,
			is_rule_applied: false,
			is_zero_percentage: false,
			reason_code: PricingReasonCode::FALLBACK_NO_MATCHING_RULE->value
		);
	}

	public function test_to_array_serialization(): void {
		$price  = Money::from( '150.00', 'IRR' );
		$result = new PricingResult(
			original_price: $price,
			final_price: $price,
			applied_rule_id: null,
			pricing_type: null,
			scope_type: null,
			source: PricingSource::WOOCOMMERCE_FALLBACK,
			is_rule_applied: false,
			is_zero_percentage: false,
			reason_code: PricingReasonCode::FALLBACK_NO_MATCHING_RULE->value,
			diagnostic_metadata: [ 'latency_ms' => 0.4 ]
		);

		$array_public = $result->to_array( include_diagnostics: false );
		$this->assertArrayNotHasKey( 'diagnostics', $array_public );
		$this->assertSame( '150.000000', $array_public['original_price'] );
		$this->assertSame( 'IRR', $array_public['currency'] );
		$this->assertSame( 'woocommerce', $array_public['source'] );

		$array_diag = $result->to_array( include_diagnostics: true );
		$this->assertArrayHasKey( 'diagnostics', $array_diag );
		$this->assertSame( 0.4, $array_diag['diagnostics']['latency_ms'] );
	}
}
