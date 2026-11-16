<?php

declare(strict_types=1);

namespace Tierza\Tests\Unit\Integration\WooCommerce;

use PHPUnit\Framework\TestCase;
use Tierza\Application\Pricing\AuthoritativePriceCalculator;
use Tierza\Application\Pricing\Batch\PricingBatchPrefetcher;
use Tierza\Application\Pricing\Cache\RequestPricingCache;
use Tierza\Application\Pricing\Cache\VariationPriceCacheInvalidator;
use Tierza\Application\Pricing\DeterministicRuleResolver;
use Tierza\Application\Pricing\PricingFailureTracker;
use Tierza\Domain\Entities\PricingGroup;
use Tierza\Domain\Entities\PricingRule;
use Tierza\Domain\Entities\UserGroupAssignment;
use Tierza\Domain\Repositories\UserGroupRepositoryInterface;
use Tierza\Domain\ValueObjects\PricingValue;
use Tierza\Domain\ValueObjects\RuleScope;
use Tierza\Domain\ValueObjects\RuleTarget;
use Tierza\Integration\WooCommerce\CartPricingAdapter;
use Tierza\Integration\WooCommerce\CartPricingIntegration;
use Tierza\Integration\WooCommerce\ProductPricingAdapter;
use Tierza\Tests\Unit\Application\Pricing\InMemoryGroupRepository;
use Tierza\Tests\Unit\Application\Pricing\InMemoryRuleRepository;
use WC_Cart;
use WC_Product;
use WP_Error;
use WP_User;

/**
 * Unit tests for CartPricingIntegration.
 */
class CartPricingIntegrationTest extends TestCase {

	private InMemoryRuleRepository $rule_repo;
	private InMemoryGroupRepository $group_repo;
	private UserGroupRepositoryInterface $user_group_repo;
	private RequestPricingCache $cache;
	private DeterministicRuleResolver $resolver;
	private AuthoritativePriceCalculator $calculator;
	private PricingBatchPrefetcher $batch_prefetcher;
	private ProductPricingAdapter $product_adapter;
	private PricingFailureTracker $failure_tracker;
	private CartPricingAdapter $adapter;
	private CartPricingIntegration $integration;

	/** @var array<int, UserGroupAssignment> */
	private array $assignments = [];

	protected function setUp(): void {
		parent::setUp();

		global $current_user_id_mock, $wc_currency_mock, $wc_decimals_mock, $wp_actions, $wp_filters, $wc_notices;
		$current_user_id_mock = 0;
		$wc_currency_mock     = 'USD';
		$wc_decimals_mock     = 2;
		$wp_actions           = [];
		$wp_filters           = [];
		$wc_notices           = [];

		$this->cache      = new RequestPricingCache();
		$this->rule_repo  = new InMemoryRuleRepository();
		$this->group_repo = new InMemoryGroupRepository();

		$this->assignments = [];
		$assignments_ref   = &$this->assignments;

		$this->user_group_repo = new class( $assignments_ref ) implements UserGroupRepositoryInterface {
			/** @param array<int, UserGroupAssignment> $assignments */
			public function __construct( private array &$assignments ) {}

			public function find_by_user_id( int $user_id ): ?UserGroupAssignment {
				return $this->assignments[ $user_id ] ?? null;
			}

			public function assign( int $user_id, int $group_id ): UserGroupAssignment {
				$assignment                     = new UserGroupAssignment( $user_id, $group_id );
				$this->assignments[ $user_id ] = $assignment;
				return $assignment;
			}

			public function reassign( int $user_id, int $new_group_id ): UserGroupAssignment {
				return $this->assign( $user_id, $new_group_id );
			}

			public function list_by_group_id( int $group_id, int $limit = 50, int $offset = 0 ): array {
				return [];
			}

			public function count_by_group_id( int $group_id ): int {
				return 0;
			}

			public function remove( int $user_id ): bool {
				unset( $this->assignments[ $user_id ] );
				return true;
			}
		};

		$this->resolver         = new DeterministicRuleResolver( $this->rule_repo, $this->group_repo, $this->cache );
		$this->calculator       = new AuthoritativePriceCalculator();
		$this->batch_prefetcher = new PricingBatchPrefetcher( $this->rule_repo, $this->cache );
		$invalidator            = new VariationPriceCacheInvalidator();

		$this->product_adapter = new ProductPricingAdapter(
			$this->resolver,
			$this->calculator,
			$this->group_repo,
			$this->user_group_repo,
			$this->cache,
			$this->batch_prefetcher,
			$invalidator,
			'pure_php'
		);
		$this->product_adapter->enable_test_mode( true );

		$this->failure_tracker = new PricingFailureTracker();

		$this->adapter = new CartPricingAdapter(
			$this->product_adapter,
			$this->calculator,
			$this->resolver,
			$this->cache,
			$this->failure_tracker,
			$this->batch_prefetcher,
			'pure_php'
		);

		$this->integration = new CartPricingIntegration( $this->adapter, $this->failure_tracker );
	}

	public function test_on_before_calculate_totals_applies_prices_to_cart_items(): void {
		$group = $this->group_repo->save( new PricingGroup( 'B2B', 'b2b', 1, 'active' ) );
		$this->user_group_repo->assign( 10, (int) $group->id() );

		// 20% discount on product 100
		$this->rule_repo->save( new PricingRule(
			RuleTarget::group( (int) $group->id() ),
			RuleScope::product( 100 ),
			PricingValue::percentage( '20.000000' )
		) );

		global $current_user_id_mock;
		$current_user_id_mock = 10;

		$product = new WC_Product( 100, [ 'price' => '100.00', 'regular_price' => '100.00' ] );
		$cart = new WC_Cart();
		$cart->set_cart_contents( [
			'item_1' => [
				'data'     => $product,
				'quantity' => 2,
			],
		] );

		$this->integration->on_before_calculate_totals( $cart );

		$this->assertSame( '80.00', $product->get_price() );
		$contents = $cart->get_cart();
		$this->assertArrayHasKey( 'tierza_pricing', $contents['item_1'] );
		$this->assertSame( '80.000000', $contents['item_1']['tierza_pricing']['calculated_price'] );
	}

	public function test_on_before_calculate_totals_records_failure_and_leaves_price_when_exception_occurs(): void {
		// Product with unparsable regular price triggers InvalidMoneyException
		$product = new WC_Product( 200, [ 'price' => 'invalid-price', 'regular_price' => 'invalid-price' ] );
		$cart = new WC_Cart();
		$cart->set_cart_contents( [
			'item_bad' => [
				'data'     => $product,
				'quantity' => 1,
			],
		] );

		$this->integration->on_before_calculate_totals( $cart );

		$this->assertTrue( $this->failure_tracker->has_failed( 200 ) );
		$failure = $this->failure_tracker->get_failure( 200 );
		$this->assertNotNull( $failure );
		$this->assertSame( PricingFailureTracker::CODE_INVALID_CONTEXT, $failure['error_code'] );
	}

	public function test_on_after_checkout_validation_blocks_on_unresolved_failure(): void {
		$product = new WC_Product( 300, [ 'price' => '100.00', 'regular_price' => '100.00' ] );
		$cart = new WC_Cart();
		$cart->set_cart_contents( [
			'item_300' => [
				'data'     => $product,
				'quantity' => 1,
			],
		] );

		// Set global WC()->cart
		WC()->cart = $cart;

		$this->failure_tracker->record_failure( 300, PricingFailureTracker::CODE_CALCULATION_FAILED, 'Calculation error' );

		$errors = new WP_Error();
		$this->integration->on_after_checkout_validation( [], $errors );

		$this->assertTrue( $errors->has_errors() );
		$this->assertContains( 'tierza_checkout_pricing_error', $errors->get_error_codes() );
	}

	public function test_on_after_checkout_validation_passes_when_cart_is_valid(): void {
		$product = new WC_Product( 400, [ 'price' => '100.00', 'regular_price' => '100.00' ] );
		$cart = new WC_Cart();
		$cart->set_cart_contents( [
			'item_400' => [
				'data'     => $product,
				'quantity' => 1,
			],
		] );

		WC()->cart = $cart;
		$errors    = new WP_Error();

		$this->integration->on_after_checkout_validation( [], $errors );
		$this->assertFalse( $errors->has_errors() );
	}

	public function test_on_check_cart_items_adds_notice_when_pricing_failure_exists(): void {
		$product = new WC_Product( 500, [ 'price' => '100.00', 'regular_price' => '100.00' ] );
		$cart = new WC_Cart();
		$cart->set_cart_contents( [
			'item_500' => [
				'data'     => $product,
				'quantity' => 1,
			],
		] );

		WC()->cart = $cart;
		$this->failure_tracker->record_failure( 500, PricingFailureTracker::CODE_REPOSITORY_UNAVAILABLE, 'DB error' );

		$this->integration->on_check_cart_items();

		$notices = wc_get_notices( 'error' );
		$this->assertNotEmpty( $notices );
		$this->assertStringContainsString( 'pricing calculation error', $notices[0] );
	}

	public function test_user_login_and_logout_triggers_reset(): void {
		$this->failure_tracker->record_failure( 600, PricingFailureTracker::CODE_UNEXPECTED, 'error' );
		$this->assertTrue( $this->failure_tracker->has_failed( 600 ) );

		$user = $this->createMock( WP_User::class );
		$this->integration->on_user_login( 'testuser', $user );

		$this->assertFalse( $this->failure_tracker->has_failed( 600 ) );

		$this->failure_tracker->record_failure( 600, PricingFailureTracker::CODE_UNEXPECTED, 'error' );
		$this->integration->on_user_logout();

		$this->assertFalse( $this->failure_tracker->has_failed( 600 ) );
	}

	public function test_on_get_cart_item_from_session_preserves_tierza_metadata(): void {
		$session_data = [ 'key' => 'abc' ];
		$values       = [
			'tierza_pricing' => [
				'base_price'       => '100.00',
				'calculated_price' => '80.00',
			],
		];

		$result = $this->integration->on_get_cart_item_from_session( $session_data, $values, 'abc' );
		$this->assertArrayHasKey( 'tierza_pricing', $result );
		$this->assertSame( '80.00', $result['tierza_pricing']['calculated_price'] );
	}

	public function test_filter_cart_item_subtotal_formats_sale_markup_when_discounted(): void {
		$product = new WC_Product( 700, [ 'price' => '80.00', 'regular_price' => '100.00' ] );
		$cart_item = [
			'data'     => $product,
			'quantity' => 2,
		];

		$html = $this->integration->filter_cart_item_subtotal( '$160.00', $cart_item, 'item_700' );
		$this->assertStringContainsString( '$200.00', $html );
		$this->assertStringContainsString( '$160.00', $html );
	}

	public function test_prefetch_cart_failure_is_caught_classified_and_releases_reentrancy_guard(): void {
		$mock_prefetcher = $this->createMock( \Tierza\Application\Pricing\Batch\PricingBatchPrefetcherInterface::class );
		$mock_prefetcher->method( 'prefetch_contexts' )
			->willThrowException( new \Tierza\Infrastructure\Exceptions\DatabaseQueryException( 'SQLSTATE[HY000]: Lost connection' ) );

		$adapter = new CartPricingAdapter(
			$this->product_adapter,
			$this->calculator,
			$this->resolver,
			$this->cache,
			$this->failure_tracker,
			$mock_prefetcher
		);
		$integration = new CartPricingIntegration( $adapter, $this->failure_tracker );

		$product = new WC_Product( 750, [ 'price' => '100.00', 'regular_price' => '100.00' ] );
		$cart = new WC_Cart();
		$cart->set_cart_contents( [
			'line_750' => [
				'data'     => $product,
				'quantity' => 1,
			],
		] );

		// Execute calculation: prefetch failure must not crash with unhandled exception
		$integration->on_before_calculate_totals( $cart );

		// Failure must be classified as repository unavailable and recorded in tracker
		$this->assertTrue( $this->failure_tracker->has_failed( 750 ) );
		$failure = $this->failure_tracker->get_failure( 750 );
		$this->assertSame( PricingFailureTracker::CODE_REPOSITORY_UNAVAILABLE, $failure['error_code'] );

		// Reentrancy guard must be released: a subsequent call must not be blocked by mutex
		$ref = new \ReflectionClass( $integration );
		$prop = $ref->getProperty( 'is_calculating' );
		$prop->setAccessible( true );
		$this->assertFalse( $prop->getValue( $integration ), 'Reentrancy guard must be cleanly released.' );
	}

	public function test_failure_recovery_lifecycle_sequence(): void {
		global $current_user_id_mock;
		$current_user_id_mock = 42;

		$product_1 = new WC_Product( 810, [ 'price' => '100.00', 'regular_price' => '100.00' ] );
		$product_2 = new WC_Product( 820, [ 'price' => '50.00', 'regular_price' => '50.00' ] );

		$cart = new WC_Cart();
		$cart->set_cart_contents( [
			'line_1' => [ 'data' => $product_1, 'quantity' => 1 ],
			'line_2' => [ 'data' => $product_2, 'quantity' => 2 ],
		] );
		WC()->cart = $cart;

		// 1. Initial state: simulate repository failure on product 810 for customer 42
		$this->failure_tracker->record_failure( 810, PricingFailureTracker::CODE_REPOSITORY_UNAVAILABLE, 'DB error', 42, 'line_1' );

		// 2. Checkout must be blocked
		$errors_step1 = new WP_Error();
		$this->integration->on_after_checkout_validation( [], $errors_step1 );
		$this->assertTrue( $errors_step1->has_errors(), 'Step 2: Checkout must be blocked on failure.' );

		// 3. Partial recalculation: product 820 succeeds, but 810 remains failed
		$this->failure_tracker->clear_failure( 820, 42, 'line_2' );
		$errors_step3 = new WP_Error();
		$this->integration->on_after_checkout_validation( [], $errors_step3 );
		$this->assertTrue( $errors_step3->has_errors(), 'Step 3: Partial recalculation must still block checkout.' );

		// 4. Recovery: product 810 authoritatively recalculates and clears failure
		$this->failure_tracker->clear_failure( 810, 42, 'line_1' );
		$errors_step4 = new WP_Error();
		$this->integration->on_after_checkout_validation( [], $errors_step4 );
		$this->assertFalse( $errors_step4->has_errors(), 'Step 4: Authoritative recovery must restore checkout approval.' );

		// 5. Customer identity isolation: failure for customer 42 must not block customer 99
		$this->failure_tracker->record_failure( 810, PricingFailureTracker::CODE_CALCULATION_FAILED, 'Err', 42, 'line_1' );
		$this->assertTrue( $this->failure_tracker->has_failed( 810, 42 ), 'Customer 42 has failed product.' );
		$this->assertFalse( $this->failure_tracker->has_failed( 810, 99 ), 'Customer 99 must remain isolated and clean.' );

		// 6. New failure occurs on recovered cart for customer 42
		$this->failure_tracker->record_failure( 820, PricingFailureTracker::CODE_CALCULATION_FAILED, 'Precision err', 42, 'line_2' );
		$errors_step6 = new WP_Error();
		$this->integration->on_after_checkout_validation( [], $errors_step6 );
		$this->assertTrue( $errors_step6->has_errors(), 'Step 6: New failure must block checkout again.' );

		$current_user_id_mock = 0;
	}

	public function test_authoritative_checkout_recovery_lifecycle_across_failures_and_identities(): void {
		global $current_user_id_mock;
		$current_user_id_mock = 10;

		$group = $this->group_repo->save( new PricingGroup( 'Recovery Group', 'recovery-group', 1, 'active' ) );
		$this->user_group_repo->assign( 10, (int) $group->id() );

		$rule_1 = $this->rule_repo->save( new PricingRule(
			RuleTarget::group( (int) $group->id() ),
			RuleScope::product( 10 ),
			PricingValue::percentage( '20.000000' )
		) );
		$rule_2 = $this->rule_repo->save( new PricingRule(
			RuleTarget::group( (int) $group->id() ),
			RuleScope::product( 20 ),
			PricingValue::percentage( '10.000000' )
		) );

		$p1 = new WC_Product( 10, [ 'price' => '100.00', 'regular_price' => '100.00' ] );
		$p2 = new WC_Product( 20, [ 'price' => '50.00', 'regular_price' => '50.00' ] );

		$cart = new WC_Cart();
		$cart->set_cart_contents( [
			'line_1' => [ 'data' => $p1, 'quantity' => 1 ],
			'line_2' => [ 'data' => $p2, 'quantity' => 2 ],
		] );
		WC()->cart = $cart;

		// 1. Initial valid calculation:
		$this->integration->on_before_calculate_totals( $cart );
		$this->assertSame( '80.00', $p1->get_price() );
		$this->assertSame( '45.00', $p2->get_price() );
		$this->assertFalse( $this->failure_tracker->has_failed( 10, 10, 'line_1' ) );
		$this->assertFalse( $this->failure_tracker->has_failed( 20, 10, 'line_2' ) );

		// 2. Cause repository failure (simulate fault in batch prefetch / rule resolution):
		$this->failure_tracker->record_failure( 10, PricingFailureTracker::CODE_REPOSITORY_UNAVAILABLE, 'DB connection failed', 10, 'line_1' );

		// 3. Confirm failure is recorded:
		$this->assertTrue( $this->failure_tracker->has_failed( 10, 10, 'line_1' ) );

		// 4. Attempt checkout and confirm it is blocked:
		$errors_step4 = new WP_Error();
		$this->integration->on_after_checkout_validation( [], $errors_step4 );
		$this->assertTrue( $errors_step4->has_errors(), 'Step 5: Checkout must be blocked on failure.' );
		$this->assertContains( 'tierza_checkout_pricing_error', $errors_step4->get_error_codes() );

		// 5. Customer identity isolation: failure for customer 10 must NOT block customer 20:
		$this->assertFalse( $this->failure_tracker->has_failed( 10, 20, 'line_1' ) );
		$this->assertFalse( $this->failure_tracker->has_failed( 10, 20 ) );

		// 6. Recalculate authoritative prices on recovery:
		$this->integration->on_before_calculate_totals( $cart );

		// 7. Confirm the resolved error is cleared:
		$this->assertFalse( $this->failure_tracker->has_failed( 10, 10, 'line_1' ), 'Step 8: Error must be cleared after authoritative recalculation.' );

		// 8. Confirm checkout succeeds:
		$errors_step8 = new WP_Error();
		$this->integration->on_after_checkout_validation( [], $errors_step8 );
		$this->assertFalse( $errors_step8->has_errors(), 'Step 9: Checkout must succeed after authoritative recovery.' );

		// 9. Trigger a new failure on product 20:
		$this->failure_tracker->record_failure( 20, PricingFailureTracker::CODE_CALCULATION_FAILED, 'Precision overflow', 10, 'line_2' );

		// 10. Confirm checkout is blocked again:
		$errors_step10 = new WP_Error();
		$this->integration->on_after_checkout_validation( [], $errors_step10 );
		$this->assertTrue( $errors_step10->has_errors(), 'Step 11: Checkout must be blocked on new failure.' );

		$current_user_id_mock = 0;
	}
}
