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
}
