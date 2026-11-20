<?php

declare(strict_types=1);

namespace Tierza\Tests\Unit\Integration\WooCommerce;

use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;
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
use Tierza\Integration\WooCommerce\StoreApiPricingIntegration;
use Tierza\Tests\Unit\Application\Pricing\InMemoryGroupRepository;
use Tierza\Tests\Unit\Application\Pricing\InMemoryRuleRepository;
use WC_Cart;
use WC_Order;
use WC_Order_Item_Product;
use WC_Product;
use WC_Product_Variation;
use WP_Error;

/**
 * Unit tests for StoreApiPricingIntegration (TRZ-012).
 */
class StoreApiPricingIntegrationTest extends TestCase {

	private InMemoryRuleRepository $rule_repo;
	private InMemoryGroupRepository $group_repo;
	private UserGroupRepositoryInterface $user_group_repo;
	private RequestPricingCache $cache;
	private DeterministicRuleResolver $resolver;
	private AuthoritativePriceCalculator $calculator;
	private PricingBatchPrefetcher $batch_prefetcher;
	private ProductPricingAdapter $product_adapter;
	private PricingFailureTracker $failure_tracker;
	private CartPricingAdapter $cart_adapter;
	private StoreApiPricingIntegration $integration;

	/** @var array<int, UserGroupAssignment> */
	private array $assignments = [];

	protected function setUp(): void {
		parent::setUp();

		global $current_user_id_mock, $wc_currency_mock, $wc_decimals_mock, $wc_prices_include_tax_mock, $wp_actions, $wp_filters, $wc_notices;
		$current_user_id_mock       = 0;
		$wc_currency_mock           = 'USD';
		$wc_decimals_mock           = 2;
		$wc_prices_include_tax_mock = false;
		$wp_actions                 = [];
		$wp_filters           = [];
		$wc_notices           = [];

		$this->assignments = [];

		$this->rule_repo       = new InMemoryRuleRepository();
		$this->group_repo      = new InMemoryGroupRepository();
		$this->user_group_repo = new class( $this->assignments ) implements UserGroupRepositoryInterface {
			/** @var array<int, UserGroupAssignment> */
			private array $store;

			/** @param array<int, UserGroupAssignment> $store */
			public function __construct( array &$store ) {
				$this->store = &$store;
			}

			public function find_by_user_id( int $user_id ): ?UserGroupAssignment {
				return $this->store[ $user_id ] ?? null;
			}

			public function assign( int $user_id, int $group_id ): UserGroupAssignment {
				$assignment              = new UserGroupAssignment( $user_id, $group_id );
				$this->store[ $user_id ] = $assignment;
				return $assignment;
			}

			public function reassign( int $user_id, int $new_group_id ): UserGroupAssignment {
				return $this->assign( $user_id, $new_group_id );
			}

			public function list_by_group_id( int $group_id, int $limit = 50, int $offset = 0 ): array {
				$res = [];
				foreach ( $this->store as $a ) {
					if ( $a->group_id() === $group_id ) {
						$res[] = $a;
					}
				}
				return array_slice( $res, $offset, $limit );
			}

			public function remove( int $user_id ): bool {
				unset( $this->store[ $user_id ] );
				return true;
			}

			public function count_by_group_id( int $group_id ): int {
				$count = 0;
				foreach ( $this->store as $a ) {
					if ( $a->group_id() === $group_id ) {
						++$count;
					}
				}
				return $count;
			}
		};

		$this->cache            = new RequestPricingCache();
		$this->resolver         = new DeterministicRuleResolver( $this->rule_repo, $this->group_repo, $this->cache );
		$this->calculator       = new AuthoritativePriceCalculator();
		$this->batch_prefetcher = new PricingBatchPrefetcher( $this->rule_repo, $this->cache, $this->group_repo );

		$this->product_adapter = new ProductPricingAdapter(
			$this->resolver,
			$this->calculator,
			$this->group_repo,
			$this->user_group_repo,
			$this->cache,
			$this->batch_prefetcher
		);
		$this->product_adapter->enable_test_mode( true );

		$invalidator = new VariationPriceCacheInvalidator();
		$this->product_adapter->set_variation_cache_invalidator( $invalidator );

		$this->failure_tracker = new PricingFailureTracker();

		$this->cart_adapter = new CartPricingAdapter(
			$this->product_adapter,
			$this->calculator,
			$this->resolver,
			$this->cache,
			$this->failure_tracker,
			$this->batch_prefetcher
		);

		$this->integration = new StoreApiPricingIntegration( $this->cart_adapter, $this->failure_tracker );
		CartPricingAdapter::clear_cart_priced_registry();
	}

	protected function tearDown(): void {
		global $wc_prices_include_tax_mock;
		$wc_prices_include_tax_mock = false;
		$this->integration->unregister();
		$this->failure_tracker->reset();
		CartPricingAdapter::clear_cart_priced_registry();
		parent::tearDown();
	}

	public function test_registration_and_unregistration_lifecycle(): void {
		global $wp_actions;

		$this->integration->register();
		$this->assertArrayHasKey( 'woocommerce_store_api_cart_errors', $wp_actions );
		$this->assertArrayHasKey( 'woocommerce_store_api_checkout_update_order_meta', $wp_actions );
		$this->assertArrayHasKey( 'woocommerce_store_api_checkout_order_processed', $wp_actions );

		$this->assertSame( $this->cart_adapter, $this->integration->get_adapter() );
		$this->assertSame( $this->failure_tracker, $this->integration->get_failure_tracker() );

		$this->integration->unregister();
	}

	public function test_cart_errors_blocks_checkout_on_failure_in_tracker(): void {
		$this->integration->register();

		$group = $this->group_repo->save( new PricingGroup( 'B2B Wholesale', 'b2b-wholesale' ) );
		$gid   = (int) $group->id();
		$uid   = 42;
		$this->user_group_repo->assign( $uid, $gid );

		global $current_user_id_mock;
		$current_user_id_mock = $uid;

		$product = new WC_Product( 101, [ 'price' => '100.00', 'regular_price' => '100.00' ] );
		$cart    = new WC_Cart();
		$cart->set_cart_contents( [ 'cart_key_101' => [ 'data' => $product, 'quantity' => 1 ] ] );

		// Simulate ongoing failure in repository
		$this->rule_repo->set_should_fail_on_query( true );
		$this->failure_tracker->record_failure( 101, PricingFailureTracker::CODE_REPOSITORY_UNAVAILABLE, 'Calculation error', $uid, 'cart_key_101' );

		$cart_errors = new WP_Error();
		$this->integration->on_store_api_cart_errors( $cart_errors, $cart );

		$this->assertTrue( $cart_errors->has_errors(), 'Cart errors must be populated when unresolved failure exists.' );
		$messages = $cart_errors->get_error_messages( 'tierza_checkout_pricing_failed' );
		$this->assertNotEmpty( $messages );
		$this->assertStringContainsString( 'verifying prices', $messages[0] );
	}

	public function test_cart_errors_blocks_checkout_on_price_discrepancy(): void {
		$this->integration->register();

		$group = $this->group_repo->save( new PricingGroup( 'B2B Wholesale', 'b2b-wholesale' ) );
		$gid   = (int) $group->id();
		$uid   = 42;
		$this->user_group_repo->assign( $uid, $gid );

		global $current_user_id_mock;
		$current_user_id_mock = $uid;

		// 20% discount rule
		$this->rule_repo->save( new PricingRule(
			RuleTarget::group( $gid ),
			RuleScope::product( 101 ),
			PricingValue::percentage( '20.000000' )
		) );

		// Product in cart has manipulated/stale price 95.00 instead of authoritative 80.00
		$product = new WC_Product( 101, [ 'price' => '95.00', 'regular_price' => '100.00' ] );
		$cart    = new WC_Cart();
		$cart->set_cart_contents( [ 'cart_key_101' => [ 'data' => $product, 'quantity' => 1 ] ] );

		$cart_errors = new WP_Error();
		$this->integration->on_store_api_cart_errors( $cart_errors, $cart );

		$this->assertTrue( $cart_errors->has_errors(), 'Cart errors must be populated when price discrepancy exists.' );
		$messages = $cart_errors->get_error_messages( 'tierza_checkout_pricing_failed' );
		$this->assertNotEmpty( $messages );
		$this->assertStringContainsString( 'verifying prices', $messages[0] );
	}

	public function test_cart_errors_passes_when_cart_is_authoritative_and_valid(): void {
		$this->integration->register();

		$group = $this->group_repo->save( new PricingGroup( 'B2B Wholesale', 'b2b-wholesale' ) );
		$gid   = (int) $group->id();
		$uid   = 42;
		$this->user_group_repo->assign( $uid, $gid );

		global $current_user_id_mock;
		$current_user_id_mock = $uid;

		$this->rule_repo->save( new PricingRule(
			RuleTarget::group( $gid ),
			RuleScope::product( 101 ),
			PricingValue::percentage( '20.000000' )
		) );

		$product = new WC_Product( 101, [ 'price' => '100.00', 'regular_price' => '100.00' ] );
		$cart    = new WC_Cart();
		$cart->set_cart_contents( [ 'cart_key_101' => [ 'data' => $product, 'quantity' => 1 ] ] );

		// Apply authoritative pricing
		$classic_integration = new CartPricingIntegration( $this->cart_adapter, $this->failure_tracker );
		$classic_integration->on_before_calculate_totals( $cart );

		$cart_errors = new WP_Error();
		$this->integration->on_store_api_cart_errors( $cart_errors, $cart );

		$this->assertFalse( $cart_errors->has_errors(), 'Valid cart must produce zero errors.' );
	}

	public function test_draft_order_meta_defense_in_depth_blocks_on_failure(): void {
		$uid   = 77;
		$order = new WC_Order();
		$order->customer_id = $uid;

		$this->failure_tracker->record_failure( 201, PricingFailureTracker::CODE_CALCULATION_FAILED, 'Fatal calc', $uid );

		$threw = false;
		try {
			$this->integration->on_store_api_checkout_update_order_meta( $order );
		} catch ( RouteException $e ) {
			$threw = true;
			$this->assertSame( 'tierza_checkout_pricing_failed', $e->getErrorCode() );
			$this->assertSame( 400, $e->getCode() );
		}

		$this->assertTrue( $threw, 'Draft order update meta must throw RouteException when customer has active failure.' );
	}

	public function test_checkout_order_processed_blocks_and_throws_route_exception_on_discrepancy(): void {
		$group = $this->group_repo->save( new PricingGroup( 'VIP Club', 'vip-club' ) );
		$gid   = (int) $group->id();
		$uid   = 55;
		$this->user_group_repo->assign( $uid, $gid );

		$this->rule_repo->save( new PricingRule(
			RuleTarget::group( $gid ),
			RuleScope::product( 301 ),
			PricingValue::percentage( '30.000000' )
		) );

		$product = new WC_Product( 301, [ 'price' => '100.00', 'regular_price' => '100.00' ] );

		$order              = new WC_Order();
		$order->id          = 1001;
		$order->customer_id = $uid;

		$item               = new WC_Order_Item_Product();
		$item->product_id   = 301;
		$item->quantity     = 2;
		$item->subtotal     = '200.00'; // Tampered / regular price instead of authoritative 140.00 (70.00 * 2)
		$item->product      = $product;
		$order->items       = [ $item ];

		$hook_fired = false;
		global $wp_actions;
		add_action( 'tierza_checkout_pricing_validation_failed', function( $ctx, $errors ) use ( &$hook_fired ) {
			$hook_fired = true;
			$this->assertNotEmpty( $errors );
		}, 10, 2 );

		$threw = false;
		try {
			$this->integration->on_store_api_checkout_order_processed( $order );
		} catch ( RouteException $e ) {
			$threw = true;
			$this->assertSame( 'tierza_checkout_pricing_failed', $e->getErrorCode() );
		}

		$this->assertTrue( $threw, 'Store API checkout must reject order with price discrepancy.' );
		$this->assertTrue( $hook_fired, 'Public checkout validation failed action must fire.' );
	}

	public function test_checkout_order_processed_passes_on_authoritative_order(): void {
		$group = $this->group_repo->save( new PricingGroup( 'VIP Club', 'vip-club' ) );
		$gid   = (int) $group->id();
		$uid   = 55;
		$this->user_group_repo->assign( $uid, $gid );

		$this->rule_repo->save( new PricingRule(
			RuleTarget::group( $gid ),
			RuleScope::product( 301 ),
			PricingValue::percentage( '30.000000' )
		) );

		$product = new WC_Product( 301, [ 'price' => '100.00', 'regular_price' => '100.00' ] );

		$order              = new WC_Order();
		$order->id          = 1002;
		$order->customer_id = $uid;

		$item               = new WC_Order_Item_Product();
		$item->product_id   = 301;
		$item->quantity     = 2;
		$item->subtotal     = '140.00'; // Exact authoritative Tierza price (70.00 * 2)
		$item->product      = $product;
		$order->items       = [ $item ];

		$threw = false;
		try {
			$this->integration->on_store_api_checkout_order_processed( $order );
		} catch ( RouteException $e ) {
			$threw = true;
		}

		$this->assertFalse( $threw, 'Store API checkout must accept order with authoritative Tierza prices.' );
	}

	public function test_sensitive_exception_details_never_leaked_to_store_api_errors_or_hooks(): void {
		$group = $this->group_repo->save( new PricingGroup( 'Sec Group', 'sec-grp' ) );
		$gid   = (int) $group->id();
		$uid   = 99;
		$this->user_group_repo->assign( $uid, $gid );

		global $current_user_id_mock;
		$current_user_id_mock = $uid;

		$product = new WC_Product( 501, [ 'price' => '100.00', 'regular_price' => '100.00' ] );
		$cart    = new WC_Cart();
		$cart->set_cart_contents( [ 'cart_key_501' => [ 'data' => $product, 'quantity' => 1 ] ] );

		// Simulate sensitive exception with SQL, table names, DB credentials, file paths
		$sensitive_msg = "Table 'wordpress.wp_tierza_rules' doesn't exist for query SELECT * FROM wp_tierza_rules WHERE user='root' pass='s3cr3t' /var/www/html/wp-content/plugins/tierza/src/repo.php:123";

		$this->rule_repo->set_should_fail_on_query( true );
		$this->failure_tracker->record_failure(
			501,
			PricingFailureTracker::CODE_REPOSITORY_UNAVAILABLE,
			$sensitive_msg,
			$uid,
			'cart_key_501'
		);

		$captured_action_errors = [];
		add_action( 'tierza_checkout_pricing_validation_failed', function( $ctx, $errors ) use ( &$captured_action_errors ) {
			$captured_action_errors = $errors;
		}, 10, 2 );

		$cart_errors = new WP_Error();
		$this->integration->on_store_api_cart_errors( $cart_errors, $cart );

		// Verify WP_Error messages:
		foreach ( $cart_errors->get_error_messages() as $msg ) {
			$this->assertStringNotContainsString( 'wp_tierza_rules', $msg );
			$this->assertStringNotContainsString( 'SELECT', $msg );
			$this->assertStringNotContainsString( 's3cr3t', $msg );
			$this->assertStringNotContainsString( '/var/www/', $msg );
		}

		// Verify action payload:
		$this->assertNotEmpty( $captured_action_errors );
		foreach ( $captured_action_errors as $err ) {
			$this->assertStringNotContainsString( 'wp_tierza_rules', $err['message'] );
			$this->assertStringNotContainsString( 's3cr3t', $err['message'] );
		}
	}

	public function test_trusted_customer_identity_ignores_spoofed_request_customer(): void {
		$group = $this->group_repo->save( new PricingGroup( 'Wholesale', 'wholesale' ) );
		$gid   = (int) $group->id();
		$uid   = 88;
		$this->user_group_repo->assign( $uid, $gid );

		// Session is guest (0)
		global $current_user_id_mock;
		$current_user_id_mock = 0;

		$resolved = $this->cart_adapter->resolve_customer_id();
		$this->assertSame( 0, $resolved, 'Unauthenticated guest must resolve to customer ID 0.' );

		// Now simulate authenticated user
		$current_user_id_mock = $uid;
		$resolved_auth        = $this->cart_adapter->resolve_customer_id();
		$this->assertSame( $uid, $resolved_auth, 'Authenticated user must resolve to trusted session ID.' );
	}

	public function test_idempotence_and_no_double_discounting_across_repeated_evaluations(): void {
		$group = $this->group_repo->save( new PricingGroup( 'Repeat Group', 'repeat-grp' ) );
		$gid   = (int) $group->id();
		$uid   = 33;
		$this->user_group_repo->assign( $uid, $gid );

		global $current_user_id_mock;
		$current_user_id_mock = $uid;

		$this->rule_repo->save( new PricingRule(
			RuleTarget::group( $gid ),
			RuleScope::product( 701 ),
			PricingValue::percentage( '20.000000' )
		) );

		$product = new WC_Product( 701, [ 'price' => '100.00', 'regular_price' => '100.00' ] );
		$cart    = new WC_Cart();
		$cart->set_cart_contents( [ 'cart_key_701' => [ 'data' => $product, 'quantity' => 1 ] ] );

		$classic_integration = new CartPricingIntegration( $this->cart_adapter, $this->failure_tracker );

		// Pass 1:
		$classic_integration->on_before_calculate_totals( $cart );
		$cart_contents = $cart->get_cart();
		$this->assertSame( '80.00', $cart_contents['cart_key_701']['data']->get_price() );

		// Pass 2 (e.g. Store API calculate_totals call):
		$classic_integration->on_before_calculate_totals( $cart );
		$cart_contents = $cart->get_cart();
		$this->assertSame( '80.00', $cart_contents['cart_key_701']['data']->get_price(), 'Second calculation pass must preserve exact 80.00 without double discounting.' );

		// Pass 3:
		$classic_integration->on_before_calculate_totals( $cart );
		$cart_contents = $cart->get_cart();
		$this->assertSame( '80.00', $cart_contents['cart_key_701']['data']->get_price(), 'Third pass must also preserve exact 80.00.' );
	}

	public function test_variation_pricing_in_store_api_checkout(): void {
		$group = $this->group_repo->save( new PricingGroup( 'Var Group', 'var-grp' ) );
		$gid   = (int) $group->id();
		$uid   = 44;
		$this->user_group_repo->assign( $uid, $gid );

		// 15% discount on variation 802
		$this->rule_repo->save( new PricingRule(
			RuleTarget::group( $gid ),
			RuleScope::variation( 802 ),
			PricingValue::percentage( '15.000000' )
		) );

		$var_product = new WC_Product_Variation( 802, [ 'price' => '200.00', 'regular_price' => '200.00', 'parent_id' => 801 ] );

		$order              = new WC_Order();
		$order->id          = 2001;
		$order->customer_id = $uid;

		$item               = new WC_Order_Item_Product();
		$item->product_id   = 801;
		$item->variation_id = 802;
		$item->quantity     = 1;
		$item->subtotal     = '170.00'; // 200 - 15% = 170.00
		$item->product      = $var_product;
		$order->items       = [ $item ];

		$threw = false;
		try {
			$this->integration->on_store_api_checkout_order_processed( $order );
		} catch ( RouteException $e ) {
			$threw = true;
		}

		$this->assertFalse( $threw, 'Variation order item with authoritative Tierza price must pass checkout.' );
	}

	public function test_legitimate_zero_price_product_in_store_api(): void {
		$group = $this->group_repo->save( new PricingGroup( 'Free Group', 'free-grp' ) );
		$gid   = (int) $group->id();
		$uid   = 12;
		$this->user_group_repo->assign( $uid, $gid );

		$product = new WC_Product( 901, [ 'price' => '0.00', 'regular_price' => '0.00' ] );

		$order              = new WC_Order();
		$order->id          = 3001;
		$order->customer_id = $uid;

		$item               = new WC_Order_Item_Product();
		$item->product_id   = 901;
		$item->quantity     = 1;
		$item->subtotal     = '0.00';
		$item->product      = $product;
		$order->items       = [ $item ];

		$threw = false;
		try {
			$this->integration->on_store_api_checkout_order_processed( $order );
		} catch ( RouteException $e ) {
			$threw = true;
		}

		$this->assertFalse( $threw, 'Legitimate zero-price product must remain purchasable without error.' );
	}

	public function test_recovery_without_manual_tracker_clear_in_store_api(): void {
		$group = $this->group_repo->save( new PricingGroup( 'Recov Group', 'recov-grp' ) );
		$gid   = (int) $group->id();
		$uid   = 66;
		$this->user_group_repo->assign( $uid, $gid );

		global $current_user_id_mock;
		$current_user_id_mock = $uid;

		$this->rule_repo->save( new PricingRule(
			RuleTarget::group( $gid ),
			RuleScope::product( 401 ),
			PricingValue::percentage( '10.000000' )
		) );

		$product = new WC_Product( 401, [ 'price' => '100.00', 'regular_price' => '100.00' ] );
		$cart    = new WC_Cart();
		$cart->set_cart_contents( [ 'cart_key_401' => [ 'data' => $product, 'quantity' => 1 ] ] );

		// Step 1: Simulate ongoing failure (repo failure)
		$this->rule_repo->set_should_fail_on_query( true );
		$this->failure_tracker->record_failure( 401, PricingFailureTracker::CODE_REPOSITORY_UNAVAILABLE, 'DB error', $uid, 'cart_key_401' );

		$cart_errors = new WP_Error();
		$this->integration->on_store_api_cart_errors( $cart_errors, $cart );
		$this->assertTrue( $cart_errors->has_errors(), 'Checkout validation must fail while transient error exists.' );

		// Step 2: Restore repo availability
		$this->rule_repo->set_should_fail_on_query( false );

		$recovered_errors = new WP_Error();
		$this->integration->on_store_api_cart_errors( $recovered_errors, $cart );
		$this->assertFalse( $recovered_errors->has_errors(), 'Checkout validation must pass cleanly after recovery.' );
		$this->assertFalse( $this->failure_tracker->has_customer_failures( $uid ), 'Tracker must be cleared automatically upon successful recalculation.' );
	}

	public function test_cart_errors_prefetch_failure_marks_all_items_and_prevents_recovery(): void {
		$group = $this->group_repo->save( new PricingGroup( 'Prefetch Group', 'prefetch-grp' ) );
		$gid   = (int) $group->id();
		$uid   = 71;
		$this->user_group_repo->assign( $uid, $gid );

		global $current_user_id_mock;
		$current_user_id_mock = $uid;

		$p1 = new WC_Product( 601, [ 'price' => '100.00', 'regular_price' => '100.00' ] );
		$p2 = new WC_Product( 602, [ 'price' => '50.00', 'regular_price' => '50.00' ] );
		$cart = new WC_Cart();
		$cart->set_cart_contents( [
			'k1' => [ 'data' => $p1, 'quantity' => 1 ],
			'k2' => [ 'data' => $p2, 'quantity' => 2 ],
		] );

		// Simulate pre-existing failure on item 601
		$this->failure_tracker->record_failure( 601, PricingFailureTracker::CODE_CALCULATION_FAILED, 'Prior error', $uid, 'k1' );

		// Fail queries so prefetch fails
		$this->rule_repo->set_should_fail_on_query( true );

		$cart_errors = new WP_Error();
		$this->integration->on_store_api_cart_errors( $cart_errors, $cart );

		$this->assertTrue( $cart_errors->has_errors(), 'Checkout must remain blocked on prefetch failure.' );
		$this->assertTrue( $this->failure_tracker->has_failed( 601, $uid, 'k1' ), 'Item 1 must remain recorded as failed.' );
		$this->assertTrue( $this->failure_tracker->has_failed( 602, $uid, 'k2' ), 'Item 2 must be recorded as failed when batch prefetch fails.' );
	}

	public function test_checkout_order_processed_tax_inclusive_store(): void {
		global $wc_prices_include_tax_mock;
		$wc_prices_include_tax_mock = true;

		$group = $this->group_repo->save( new PricingGroup( 'Tax Group', 'tax-grp' ) );
		$gid   = (int) $group->id();
		$uid   = 82;
		$this->user_group_repo->assign( $uid, $gid );

		// Tierza rule: 20% discount on product 701 (base price 100.00 inc tax -> 80.00 inc tax)
		$this->rule_repo->save( new PricingRule(
			RuleTarget::group( $gid ),
			RuleScope::product( 701 ),
			PricingValue::percentage( '20.000000' )
		) );

		$product = new WC_Product( 701, [ 'price' => '100.00', 'regular_price' => '100.00' ] );

		$order              = new WC_Order();
		$order->id          = 5001;
		$order->customer_id = $uid;

		// In WooCommerce, when tax is included (e.g. 10% tax rate),
		// Unit price inc tax = 80.00. For qty 2, total inc tax = 160.00.
		// Subtotal net of tax = 145.45, subtotal tax = 14.55.
		// 145.45 + 14.55 = 160.00 = 80.00 * 2.
		$item               = new WC_Order_Item_Product();
		$item->product_id   = 701;
		$item->quantity     = 2;
		$item->subtotal     = '145.45';
		$item->subtotal_tax = '14.55';
		$item->product      = $product;
		$order->items       = [ $item ];

		$threw = false;
		try {
			$this->integration->on_store_api_checkout_order_processed( $order );
		} catch ( RouteException $e ) {
			$threw = true;
		}

		$this->assertFalse( $threw, 'Tax-inclusive store must reconcile subtotal + subtotal_tax with Tierza tax-inclusive unit price.' );

		$wc_prices_include_tax_mock = false;
	}

	public function test_checkout_order_processed_coupon_discount_does_not_cause_discrepancy(): void {
		$group = $this->group_repo->save( new PricingGroup( 'Coupon Group', 'coupon-grp' ) );
		$gid   = (int) $group->id();
		$uid   = 83;
		$this->user_group_repo->assign( $uid, $gid );

		// Tierza rule: 20% discount on product 702 (base price 100.00 -> 80.00)
		$this->rule_repo->save( new PricingRule(
			RuleTarget::group( $gid ),
			RuleScope::product( 702 ),
			PricingValue::percentage( '20.000000' )
		) );

		$product = new WC_Product( 702, [ 'price' => '100.00', 'regular_price' => '100.00' ] );

		$order              = new WC_Order();
		$order->id          = 5002;
		$order->customer_id = $uid;

		// Qty 2. Tierza price = 80.00 each -> subtotal = 160.00.
		// A coupon applied 20.00 discount, reducing line total to 140.00.
		// get_subtotal() remains 160.00 (pre-coupon), get_total() becomes 140.00.
		$item             = new WC_Order_Item_Product();
		$item->product_id = 702;
		$item->quantity   = 2;
		$item->subtotal   = '160.00';
		$item->total      = '140.00';
		$item->product    = $product;
		$order->items     = [ $item ];

		$threw = false;
		try {
			$this->integration->on_store_api_checkout_order_processed( $order );
		} catch ( RouteException $e ) {
			$threw = true;
		}

		$this->assertFalse( $threw, 'Pre-coupon subtotal verification must succeed without false discrepancy rejection when coupons are applied.' );
	}

	public function test_customer_failure_isolation_between_shoppers(): void {
		$group = $this->group_repo->save( new PricingGroup( 'Iso Group', 'iso-grp' ) );
		$gid   = (int) $group->id();
		$uid_a = 91;
		$uid_b = 92;
		$this->user_group_repo->assign( $uid_a, $gid );
		$this->user_group_repo->assign( $uid_b, $gid );

		// Record failure exclusively for customer A
		$this->failure_tracker->record_failure( 101, PricingFailureTracker::CODE_CALCULATION_FAILED, 'Fatal calc', $uid_a, 'cart_key_101' );

		$this->assertTrue( $this->failure_tracker->has_customer_failures( $uid_a ) );
		$this->assertFalse( $this->failure_tracker->has_customer_failures( $uid_b ) );

		// Customer B attempts checkout with valid cart
		global $current_user_id_mock;
		$current_user_id_mock = $uid_b;

		$product = new WC_Product( 101, [ 'price' => '100.00', 'regular_price' => '100.00' ] );
		$cart_b  = new WC_Cart();
		$cart_b->set_cart_contents( [ 'cart_key_101' => [ 'data' => $product, 'quantity' => 1 ] ] );

		$cart_errors = new WP_Error();
		$this->integration->on_store_api_cart_errors( $cart_errors, $cart_b );

		$this->assertFalse( $cart_errors->has_errors(), 'Customer A failures must never bleed into or block Customer B.' );
	}
}
