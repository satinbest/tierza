<?php

declare(strict_types=1);

namespace Tierza\Tests\Unit\Application\Pricing;

use PHPUnit\Framework\TestCase;
use Tierza\Application\Pricing\PricingFailureTracker;

/**
 * @covers \Tierza\Application\Pricing\PricingFailureTracker
 */
class PricingFailureTrackerTest extends TestCase {

	private PricingFailureTracker $tracker;

	protected function setUp(): void {
		parent::setUp();
		$this->tracker = new PricingFailureTracker();
	}

	public function test_guest_versus_authenticated_customer_isolation(): void {
		// Failure recorded for guest (customer ID 0):
		$this->tracker->record_failure( 101, PricingFailureTracker::CODE_CALCULATION_FAILED, 'Guest failure', 0, 'line_guest' );

		// Guest has failed:
		$this->assertTrue( $this->tracker->has_failed( 101, 0, 'line_guest' ) );
		$this->assertTrue( $this->tracker->has_failed( 101, 0 ) );

		// Authenticated customer (ID 12) must NOT see guest failure:
		$this->assertFalse( $this->tracker->has_failed( 101, 12, 'line_guest' ) );
		$this->assertFalse( $this->tracker->has_failed( 101, 12 ) );
		$this->assertNull( $this->tracker->get_failure( 101, 12, 'line_guest' ) );
		$this->assertNull( $this->tracker->get_failure( 101, 12 ) );

		// Vice-versa: Authenticated customer failure must NOT affect guest:
		$this->tracker->record_failure( 102, PricingFailureTracker::CODE_REPOSITORY_UNAVAILABLE, 'User failure', 12, 'line_user' );
		$this->assertTrue( $this->tracker->has_failed( 102, 12, 'line_user' ) );
		$this->assertFalse( $this->tracker->has_failed( 102, 0, 'line_user' ) );
		$this->assertFalse( $this->tracker->has_failed( 102, 0 ) );
	}

	public function test_customer_id_12_versus_123_isolation(): void {
		$this->tracker->record_failure( 200, PricingFailureTracker::CODE_CALCULATION_FAILED, 'User 12 failure', 12, 'line_1' );

		$this->assertTrue( $this->tracker->has_failed( 200, 12, 'line_1' ) );
		$this->assertFalse( $this->tracker->has_failed( 200, 123, 'line_1' ) );
		$this->assertFalse( $this->tracker->has_failed( 200, 123 ) );

		$this->assertNotNull( $this->tracker->get_failure( 200, 12, 'line_1' ) );
		$this->assertNull( $this->tracker->get_failure( 200, 123, 'line_1' ) );
	}

	public function test_same_product_in_two_separate_cart_lines(): void {
		// Product 300 present in line_a and line_b:
		$this->tracker->record_failure( 300, PricingFailureTracker::CODE_CALCULATION_FAILED, 'Line A error', 10, 'line_a' );

		// Line A has failed, but Line B is clean:
		$this->assertTrue( $this->tracker->has_failed( 300, 10, 'line_a' ) );
		$this->assertFalse( $this->tracker->has_failed( 300, 10, 'line_b' ) );

		// Clearing Line A does not affect anything else:
		$this->tracker->clear_failure( 300, 10, 'line_a' );
		$this->assertFalse( $this->tracker->has_failed( 300, 10, 'line_a' ) );
		$this->assertFalse( $this->tracker->has_failed( 300, 10, 'line_b' ) );
	}

	public function test_two_products_within_one_cart(): void {
		$this->tracker->record_failure( 401, PricingFailureTracker::CODE_REPOSITORY_UNAVAILABLE, 'P401 error', 10, 'line_1' );
		$this->tracker->record_failure( 402, PricingFailureTracker::CODE_CALCULATION_FAILED, 'P402 error', 10, 'line_2' );

		$this->assertTrue( $this->tracker->has_failed( 401, 10, 'line_1' ) );
		$this->assertTrue( $this->tracker->has_failed( 402, 10, 'line_2' ) );

		// Clearing P401 leaves P402 intact:
		$this->tracker->clear_failure( 401, 10, 'line_1' );
		$this->assertFalse( $this->tracker->has_failed( 401, 10, 'line_1' ) );
		$this->assertTrue( $this->tracker->has_failed( 402, 10, 'line_2' ) );
	}

	public function test_product_wide_failure_for_one_customer(): void {
		// Product-wide failure (empty cart_item_key) for customer 15:
		$this->tracker->record_failure( 500, PricingFailureTracker::CODE_REPOSITORY_UNAVAILABLE, 'Database down', 15, '' );

		// Affects all lines of product 500 for customer 15:
		$this->assertTrue( $this->tracker->has_failed( 500, 15, 'line_x' ) );
		$this->assertTrue( $this->tracker->has_failed( 500, 15, 'line_y' ) );
		$this->assertTrue( $this->tracker->has_failed( 500, 15 ) );

		// Does NOT affect customer 16:
		$this->assertFalse( $this->tracker->has_failed( 500, 16, 'line_x' ) );
		$this->assertFalse( $this->tracker->has_failed( 500, 16 ) );
	}

	public function test_partial_resolution_of_multiple_failures(): void {
		$this->tracker->record_failure( 601, PricingFailureTracker::CODE_CALCULATION_FAILED, 'Err 1', 20, 'line_1' );
		$this->tracker->record_failure( 601, PricingFailureTracker::CODE_CALCULATION_FAILED, 'Err 2', 20, 'line_2' );

		// Clear only line_1:
		$this->tracker->clear_failure( 601, 20, 'line_1' );

		$this->assertFalse( $this->tracker->has_failed( 601, 20, 'line_1' ) );
		$this->assertTrue( $this->tracker->has_failed( 601, 20, 'line_2' ) );
		// Product 601 still has unresolved failures for customer 20:
		$this->assertTrue( $this->tracker->has_failed( 601, 20 ) );
	}

	public function test_clearing_resolved_error_does_not_erase_unresolved_product_wide_failure(): void {
		// Both a product-wide failure AND a line-specific failure exist for product 700:
		$this->tracker->record_failure( 700, PricingFailureTracker::CODE_REPOSITORY_UNAVAILABLE, 'Global DB err', 25, '' );
		$this->tracker->record_failure( 700, PricingFailureTracker::CODE_CALCULATION_FAILED, 'Line err', 25, 'line_1' );

		// Clearing line_1 must NOT erase the product-wide failure:
		$this->tracker->clear_failure( 700, 25, 'line_1' );

		// line_1 still fails because product-wide failure is unresolved:
		$this->assertTrue( $this->tracker->has_failed( 700, 25, 'line_1' ) );
		$this->assertTrue( $this->tracker->has_failed( 700, 25, 'line_2' ) );
		$this->assertTrue( $this->tracker->has_failed( 700, 25 ) );

		// Clearing product-wide failure now resolves product 700:
		$this->tracker->clear_failure( 700, 25, '' );
		$this->assertFalse( $this->tracker->has_failed( 700, 25, 'line_1' ) );
		$this->assertFalse( $this->tracker->has_failed( 700, 25 ) );
	}

	public function test_customer_reset_without_affecting_other_customers(): void {
		$this->tracker->record_failure( 801, PricingFailureTracker::CODE_CALCULATION_FAILED, 'Guest err', 0, 'line_g' );
		$this->tracker->record_failure( 802, PricingFailureTracker::CODE_CALCULATION_FAILED, 'User 30 err', 30, 'line_u' );
		$this->tracker->record_failure( 803, PricingFailureTracker::CODE_CALCULATION_FAILED, 'User 31 err', 31, 'line_v' );

		// Reset customer 30:
		$this->tracker->reset_customer( 30 );

		$this->assertFalse( $this->tracker->has_failed( 802, 30, 'line_u' ) );
		// Guest and User 31 remain untouched:
		$this->assertTrue( $this->tracker->has_failed( 801, 0, 'line_g' ) );
		$this->assertTrue( $this->tracker->has_failed( 803, 31, 'line_v' ) );

		// Reset guest (0):
		$this->tracker->reset_customer( 0 );
		$this->assertFalse( $this->tracker->has_failed( 801, 0, 'line_g' ) );
		$this->assertTrue( $this->tracker->has_failed( 803, 31, 'line_v' ) );
	}

	public function test_independent_handling_of_repeated_failure_codes(): void {
		// First: repository unavailable:
		$this->tracker->record_failure( 900, PricingFailureTracker::CODE_REPOSITORY_UNAVAILABLE, 'DB error', 40, 'line_1' );
		$failure1 = $this->tracker->get_failure( 900, 40, 'line_1' );
		$this->assertNotNull( $failure1 );
		$this->assertSame( PricingFailureTracker::CODE_REPOSITORY_UNAVAILABLE, $failure1['error_code'] );

		// Clear and recover:
		$this->tracker->clear_failure( 900, 40, 'line_1' );
		$this->assertFalse( $this->tracker->has_failed( 900, 40, 'line_1' ) );

		// Later: calculation failed:
		$this->tracker->record_failure( 900, PricingFailureTracker::CODE_CALCULATION_FAILED, 'Precision fault', 40, 'line_1' );
		$failure2 = $this->tracker->get_failure( 900, 40, 'line_1' );
		$this->assertNotNull( $failure2 );
		$this->assertSame( PricingFailureTracker::CODE_CALCULATION_FAILED, $failure2['error_code'] );
		$this->assertSame( 'Precision fault', $failure2['message'] );
	}

	public function test_semantics_of_has_failed_get_failure_and_clear_failure_strictly_agree(): void {
		$this->assertFalse( $this->tracker->has_failed( 950, 50, 'line_z' ) );
		$this->assertNull( $this->tracker->get_failure( 950, 50, 'line_z' ) );

		$this->tracker->record_failure( 950, PricingFailureTracker::CODE_INVALID_CONTEXT, 'Context missing', 50, 'line_z' );
		$this->assertTrue( $this->tracker->has_failed( 950, 50, 'line_z' ) );
		$failure = $this->tracker->get_failure( 950, 50, 'line_z' );
		$this->assertNotNull( $failure );
		$this->assertSame( PricingFailureTracker::CODE_INVALID_CONTEXT, $failure['error_code'] );

		$this->tracker->clear_failure( 950, 50, 'line_z' );
		$this->assertFalse( $this->tracker->has_failed( 950, 50, 'line_z' ) );
		$this->assertNull( $this->tracker->get_failure( 950, 50, 'line_z' ) );
	}

	public function test_get_failed_product_ids_and_wildcard_selectors(): void {
		$this->tracker->record_failure( 1001, PricingFailureTracker::CODE_CALCULATION_FAILED, 'Guest err', 0 );
		$this->tracker->record_failure( 1002, PricingFailureTracker::CODE_CALCULATION_FAILED, 'User 60 err', 60 );
		$this->tracker->record_failure( 1003, PricingFailureTracker::CODE_CALCULATION_FAILED, 'User 61 err', 61 );

		// Guest query returns ONLY guest failed product IDs (not wildcard):
		$this->assertSame( [ 1001 ], $this->tracker->get_failed_product_ids( 0 ) );

		// Customer 60 query returns ONLY customer 60 failed product IDs:
		$this->assertSame( [ 1002 ], $this->tracker->get_failed_product_ids( 60 ) );

		// Wildcard queries explicitly named:
		$this->assertEqualsCanonicalizing( [ 1001, 1002, 1003 ], $this->tracker->get_all_failed_product_ids() );
		$this->assertEqualsCanonicalizing( [ 1001, 1002, 1003 ], $this->tracker->get_failed_product_ids( null ) );

		$this->assertTrue( $this->tracker->has_any_failure() );
		$this->assertTrue( $this->tracker->has_customer_failures( 60 ) );
		$this->assertFalse( $this->tracker->has_customer_failures( 999 ) );
	}
}
