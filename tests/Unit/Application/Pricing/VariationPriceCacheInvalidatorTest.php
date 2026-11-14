<?php

declare(strict_types=1);

namespace Tierza\Tests\Unit\Application\Pricing;

use PHPUnit\Framework\TestCase;
use Tierza\Application\Pricing\Cache\VariationPriceCacheInvalidator;
use Tierza\Domain\Entities\PricingRule;
use Tierza\Domain\ValueObjects\PricingValue;
use Tierza\Domain\ValueObjects\RuleScope;
use Tierza\Domain\ValueObjects\RuleTarget;
use wpdb;

/**
 * Unit tests for VariationPriceCacheInvalidator.
 */
class VariationPriceCacheInvalidatorTest extends TestCase {

	private wpdb $db;
	private VariationPriceCacheInvalidator $invalidator;

	protected function setUp(): void {
		parent::setUp();

		global $wpdb;
		$this->db          = $wpdb;
		$this->invalidator = new VariationPriceCacheInvalidator( $this->db );
	}

	public function test_initial_version_defaults_to_one(): void {
		$version = $this->invalidator->get_version();
		$this->assertGreaterThanOrEqual( 1, $version );
	}

	public function test_increment_version_advances_counter(): void {
		$initial = $this->invalidator->get_version();
		$next    = $this->invalidator->increment_version();

		$this->assertSame( $initial + 1, $next );
		$this->assertSame( $next, $this->invalidator->get_version() );
	}

	public function test_invalidate_for_product_scope_rule(): void {
		$rule = new PricingRule(
			RuleTarget::user( 10 ),
			RuleScope::product( 200 ),
			PricingValue::percentage( '10.000000' )
		);

		$initial = $this->invalidator->get_version();
		$this->invalidator->invalidate_for_rule( $rule );

		$this->assertSame( $initial + 1, $this->invalidator->get_version() );
	}

	public function test_invalidate_for_variation_scope_rule(): void {
		$rule = new PricingRule(
			RuleTarget::user( 10 ),
			RuleScope::variation( 201 ),
			PricingValue::fixed( '25.000000' )
		);

		$initial = $this->invalidator->get_version();
		$this->invalidator->invalidate_for_rule( $rule );

		$this->assertSame( $initial + 1, $this->invalidator->get_version() );
	}

	public function test_invalidate_for_global_or_category_rule(): void {
		$rule = new PricingRule(
			RuleTarget::group( 1 ),
			RuleScope::category( 5 ),
			PricingValue::percentage( '15.000000' )
		);

		$initial = $this->invalidator->get_version();
		$this->invalidator->invalidate_for_rule( $rule );

		$this->assertSame( $initial + 1, $this->invalidator->get_version() );
	}

	public function test_invalidate_for_group_and_customer(): void {
		$v1 = $this->invalidator->get_version();
		$this->invalidator->invalidate_for_group( 3 );
		$this->assertSame( $v1 + 1, $this->invalidator->get_version() );

		$v2 = $this->invalidator->get_version();
		$this->invalidator->invalidate_for_customer( 42 );
		$this->assertSame( $v2 + 1, $this->invalidator->get_version() );
	}
}
