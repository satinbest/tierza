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
use Tierza\Domain\Exceptions\InvalidMoneyException;
use Tierza\Domain\Repositories\UserGroupRepositoryInterface;
use Tierza\Domain\ValueObjects\DecimalMath;
use Tierza\Domain\ValueObjects\PricingValue;
use Tierza\Domain\ValueObjects\RuleScope;
use Tierza\Domain\ValueObjects\RuleTarget;
use Tierza\Integration\WooCommerce\CartPricingAdapter;
use Tierza\Integration\WooCommerce\ProductPricingAdapter;
use Tierza\Tests\Unit\Application\Pricing\InMemoryGroupRepository;
use Tierza\Tests\Unit\Application\Pricing\InMemoryRuleRepository;
use WC_Cart;
use WC_Product;
use WC_Product_Variation;

/**
 * Unit tests for CartPricingAdapter.
 */
class CartPricingAdapterTest extends TestCase {

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

	/** @var array<int, UserGroupAssignment> */
	private array $assignments = [];

	protected function setUp(): void {
		parent::setUp();

		global $current_user_id_mock, $wc_currency_mock, $wc_decimals_mock, $wp_terms_mock, $wp_ancestors_mock;
		$current_user_id_mock = 0;
		$wc_currency_mock     = 'USD';
		$wc_decimals_mock     = 2;
		$wp_terms_mock        = [];
		$wp_ancestors_mock    = [];

		$this->cache      = new RequestPricingCache();
		$this->rule_repo  = new InMemoryRuleRepository();
		$this->group_repo = new InMemoryGroupRepository();
		CartPricingAdapter::clear_cart_priced_registry();

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

		$this->cart_adapter = new CartPricingAdapter(
			$this->product_adapter,
			$this->calculator,
			$this->resolver,
			$this->cache,
			$this->failure_tracker,
			$this->batch_prefetcher,
			'pure_php'
		);
	}

	protected function tearDown(): void {
		CartPricingAdapter::clear_cart_priced_registry();
		parent::tearDown();
	}

	public function test_get_cart_item_pricing_context_builds_valid_context_with_quantity(): void {
		$product = new WC_Product( 101, [ 'price' => '100.00', 'regular_price' => '100.00' ] );
		$cart_item = [
			'data'     => $product,
			'quantity' => 3,
		];

		$context = $this->cart_adapter->get_cart_item_pricing_context( $cart_item, 0 );

		$this->assertNotNull( $context );
		$this->assertSame( 101, $context->product_id() );
		$this->assertSame( '100.000000', $context->base_price()->amount() );
		$this->assertSame( 3, $context->quantity() );
		$this->assertSame( 'USD', $context->base_price()->currency() );
	}

	public function test_apply_cart_item_pricing_calculates_and_sets_price(): void {
		$group = $this->group_repo->save( new PricingGroup( 'Wholesale', 'wholesale', 1, 'active' ) );
		$this->user_group_repo->assign( 10, (int) $group->id() );

		// 20% discount on product 101 for Wholesale group.
		$this->rule_repo->save( new PricingRule(
			RuleTarget::group( (int) $group->id() ),
			RuleScope::product( 101 ),
			PricingValue::percentage( '20.000000' )
		) );

		$product = new WC_Product( 101, [ 'price' => '100.00', 'regular_price' => '100.00' ] );
		$cart_item = [
			'data'     => $product,
			'quantity' => 2,
		];

		$result = $this->cart_adapter->apply_cart_item_pricing( $cart_item, 10 );

		$this->assertSame( '80.000000', $result->final_price()->amount() );
		$this->assertSame( '80.00', $product->get_price() );
		$this->assertArrayHasKey( 'tierza_pricing', $cart_item );
		$this->assertSame( '100.000000', $cart_item['tierza_pricing']['base_price'] );
		$this->assertSame( '80.000000', $cart_item['tierza_pricing']['calculated_price'] );
		$this->assertSame( 2, $cart_item['tierza_pricing']['quantity'] );
		$this->assertFalse( $cart_item['tierza_pricing']['is_fallback'] );
	}

	public function test_idempotence_and_repeated_totals_recalculations_never_stack_discounts(): void {
		$group = $this->group_repo->save( new PricingGroup( 'B2B', 'b2b', 1, 'active' ) );
		$this->user_group_repo->assign( 20, (int) $group->id() );

		// 15% discount on product 202.
		$this->rule_repo->save( new PricingRule(
			RuleTarget::group( (int) $group->id() ),
			RuleScope::product( 202 ),
			PricingValue::percentage( '15.000000' )
		) );

		$product = new WC_Product( 202, [ 'price' => '200.00', 'regular_price' => '200.00' ] );
		$cart_item = [
			'data'     => $product,
			'quantity' => 1,
		];

		// Calculation 1: 200 - 15% = 170.00
		$this->cart_adapter->apply_cart_item_pricing( $cart_item, 20 );
		$this->assertSame( '170.00', $product->get_price(), 'Pass 1 price must be 170.00' );

		// Calculation 2: Must STILL be 170.00 (NOT 170 - 15% = 144.50!)
		$this->cart_adapter->apply_cart_item_pricing( $cart_item, 20 );
		$this->assertSame( '170.00', $product->get_price(), 'Pass 2 price must remain 170.00' );

		// Calculation 3: Must STILL be 170.00
		$this->cart_adapter->apply_cart_item_pricing( $cart_item, 20 );
		$this->assertSame( '170.00', $product->get_price(), 'Pass 3 price must remain 170.00' );

		// Calculation 4: Must STILL be 170.00
		$this->cart_adapter->apply_cart_item_pricing( $cart_item, 20 );
		$this->assertSame( '170.00', $product->get_price(), 'Pass 4 price must remain 170.00' );
	}

	public function test_quantity_changes_do_not_stack_or_corrupt_pricing(): void {
		$group = $this->group_repo->save( new PricingGroup( 'Wholesale', 'wholesale', 1, 'active' ) );
		$this->user_group_repo->assign( 15, (int) $group->id() );

		// 10% discount on product 303.
		$this->rule_repo->save( new PricingRule(
			RuleTarget::group( (int) $group->id() ),
			RuleScope::product( 303 ),
			PricingValue::percentage( '10.000000' )
		) );

		$product = new WC_Product( 303, [ 'price' => '50.00', 'regular_price' => '50.00' ] );
		$cart_item = [
			'data'     => $product,
			'quantity' => 1,
		];

		// Initial calculation with qty 1
		$this->cart_adapter->apply_cart_item_pricing( $cart_item, 15 );
		$this->assertSame( '45.00', $product->get_price() );

		// User changes quantity to 10 in cart table
		$cart_item['quantity'] = 10;
		$this->cart_adapter->apply_cart_item_pricing( $cart_item, 15 );

		// Unit price must remain exactly 45.00
		$this->assertSame( '45.00', $product->get_price(), 'Unit price after quantity change must remain 45.00' );
		$this->assertSame( 10, $cart_item['tierza_pricing']['quantity'] );
	}

	public function test_original_base_price_preservation_with_promotional_sale(): void {
		$group = $this->group_repo->save( new PricingGroup( 'Partner', 'partner', 1, 'active' ) );
		$this->user_group_repo->assign( 25, (int) $group->id() );

		// 10% discount on product 404.
		$this->rule_repo->save( new PricingRule(
			RuleTarget::group( (int) $group->id() ),
			RuleScope::product( 404 ),
			PricingValue::percentage( '10.000000' )
		) );

		// Product is on active promotional sale: regular = 120.00, sale = 100.00
		$product = new WC_Product( 404, [
			'price'         => '100.00',
			'regular_price' => '120.00',
			'sale_price'    => '100.00',
		] );
		$cart_item = [
			'data'     => $product,
			'quantity' => 1,
		];

		// Baseline is active sale price 100.00. 10% off 100.00 = 90.00.
		$this->cart_adapter->apply_cart_item_pricing( $cart_item, 25 );
		$this->assertSame( '90.00', $product->get_price() );

		// Repeated recalculation
		$this->cart_adapter->apply_cart_item_pricing( $cart_item, 25 );
		$this->assertSame( '90.00', $product->get_price() );
	}

	public function test_guest_vs_authenticated_customer_isolation(): void {
		$group = $this->group_repo->save( new PricingGroup( 'VIP', 'vip', 1, 'active' ) );
		$this->user_group_repo->assign( 30, (int) $group->id() );

		// VIP group rule: fixed price 60.00
		$this->rule_repo->save( new PricingRule(
			RuleTarget::group( (int) $group->id() ),
			RuleScope::product( 505 ),
			PricingValue::fixed( '60.000000' )
		) );

		// Guest user (0)
		$product_guest = new WC_Product( 505, [ 'price' => '100.00', 'regular_price' => '100.00' ] );
		$cart_guest    = [ 'data' => $product_guest, 'quantity' => 1 ];
		$this->cart_adapter->apply_cart_item_pricing( $cart_guest, 0 );
		$this->assertSame( '100.00', $product_guest->get_price(), 'Guest must receive fallback regular price.' );
		$this->assertTrue( $cart_guest['tierza_pricing']['is_fallback'] );

		// VIP customer (30)
		$product_vip = new WC_Product( 505, [ 'price' => '100.00', 'regular_price' => '100.00' ] );
		$cart_vip    = [ 'data' => $product_vip, 'quantity' => 1 ];
		$this->cart_adapter->apply_cart_item_pricing( $cart_vip, 30 );
		$this->assertSame( '60.00', $product_vip->get_price(), 'VIP customer must receive fixed price 60.00.' );
		$this->assertFalse( $cart_vip['tierza_pricing']['is_fallback'] );
	}

	public function test_user_group_reassignment_updates_cart_pricing(): void {
		$group_a = $this->group_repo->save( new PricingGroup( 'Tier A', 'tier-a', 1, 'active' ) );
		$group_b = $this->group_repo->save( new PricingGroup( 'Tier B', 'tier-b', 2, 'active' ) );

		$this->rule_repo->save( new PricingRule(
			RuleTarget::group( (int) $group_a->id() ),
			RuleScope::product( 606 ),
			PricingValue::percentage( '10.000000' )
		) );
		$this->rule_repo->save( new PricingRule(
			RuleTarget::group( (int) $group_b->id() ),
			RuleScope::product( 606 ),
			PricingValue::percentage( '30.000000' )
		) );

		// User initially in Tier A (10% off 100.00 = 90.00)
		$this->user_group_repo->assign( 40, (int) $group_a->id() );
		$product   = new WC_Product( 606, [ 'price' => '100.00', 'regular_price' => '100.00' ] );
		$cart_item = [ 'data' => $product, 'quantity' => 1 ];
		$this->cart_adapter->apply_cart_item_pricing( $cart_item, 40 );
		$this->assertSame( '90.00', $product->get_price() );

		// Admin promotes user to Tier B (30% off 100.00 = 70.00)
		$this->user_group_repo->reassign( 40, (int) $group_b->id() );
		$this->cache->clear(); // Clear cached group status

		$this->cart_adapter->apply_cart_item_pricing( $cart_item, 40 );
		$this->assertSame( '70.00', $product->get_price(), 'Cart price must update to 70.00 after group reassignment.' );
	}

	public function test_variation_inheritance_and_variation_specific_rules(): void {
		$group = $this->group_repo->save( new PricingGroup( 'Wholesale', 'wholesale', 1, 'active' ) );
		$this->user_group_repo->assign( 50, (int) $group->id() );

		// Rule 1 on Parent Product 700: 10% discount
		$this->rule_repo->save( new PricingRule(
			RuleTarget::group( (int) $group->id() ),
			RuleScope::product( 700 ),
			PricingValue::percentage( '10.000000' )
		) );

		// Variation 701 inherits parent rule (10% off 80.00 = 72.00)
		$var_701   = new WC_Product_Variation( 701, [ 'price' => '80.00', 'regular_price' => '80.00', 'parent_id' => 700 ] );
		$cart_item = [ 'data' => $var_701, 'quantity' => 1 ];
		$this->cart_adapter->apply_cart_item_pricing( $cart_item, 50 );
		$this->assertSame( '72.00', $var_701->get_price() );

		// Rule 2 on specific Variation 701: 25% discount (higher precedence)
		$this->rule_repo->save( new PricingRule(
			RuleTarget::group( (int) $group->id() ),
			RuleScope::variation( 701 ),
			PricingValue::percentage( '25.000000' )
		) );
		$this->cache->clear();

		$this->cart_adapter->apply_cart_item_pricing( $cart_item, 50 );
		$this->assertSame( '60.00', $var_701->get_price(), 'Specific variation rule must take precedence (80 - 25% = 60.00).' );
	}

	public function test_zero_value_rules_explicit_zero_and_fixed_zero(): void {
		$group = $this->group_repo->save( new PricingGroup( 'Members', 'members', 1, 'active' ) );
		$this->user_group_repo->assign( 60, (int) $group->id() );

		// 1. Explicit 0% rule: price remains equal to regular price
		$this->rule_repo->save( new PricingRule(
			RuleTarget::group( (int) $group->id() ),
			RuleScope::product( 801 ),
			PricingValue::percentage( '0.000000' )
		) );

		$prod_zero_pct = new WC_Product( 801, [ 'price' => '150.00', 'regular_price' => '150.00' ] );
		$cart_zero_pct = [ 'data' => $prod_zero_pct, 'quantity' => 1 ];
		$res_zero_pct  = $this->cart_adapter->apply_cart_item_pricing( $cart_zero_pct, 60 );
		$this->assertSame( '150.00', $prod_zero_pct->get_price() );
		$this->assertTrue( $res_zero_pct->is_zero_percentage() );

		// 2. Fixed zero price: product is free for members
		$this->rule_repo->save( new PricingRule(
			RuleTarget::group( (int) $group->id() ),
			RuleScope::product( 802 ),
			PricingValue::fixed( '0.000000' )
		) );
		$this->cache->clear();

		$prod_fixed_zero = new WC_Product( 802, [ 'price' => '50.00', 'regular_price' => '50.00' ] );
		$cart_fixed_zero = [ 'data' => $prod_fixed_zero, 'quantity' => 1 ];
		$res_fixed_zero  = $this->cart_adapter->apply_cart_item_pricing( $cart_fixed_zero, 60 );
		$this->assertSame( '0.00', $prod_fixed_zero->get_price() );
		$this->assertSame( '0.000000', $res_fixed_zero->final_price()->amount() );
	}

	public function test_revalidate_checkout_passes_when_prices_match(): void {
		$group = $this->group_repo->save( new PricingGroup( 'ValidGroup', 'valid-group', 1, 'active' ) );
		$this->user_group_repo->assign( 70, (int) $group->id() );

		$this->rule_repo->save( new PricingRule(
			RuleTarget::group( (int) $group->id() ),
			RuleScope::product( 901 ),
			PricingValue::percentage( '20.000000' )
		) );

		$product = new WC_Product( 901, [ 'price' => '100.00', 'regular_price' => '100.00' ] );
		$cart_item = [ 'data' => $product, 'quantity' => 1 ];
		$this->cart_adapter->apply_cart_item_pricing( $cart_item, 70 );

		$cart = new WC_Cart();
		$cart->set_cart_contents( [ 'item_1' => $cart_item ] );

		$report = $this->cart_adapter->revalidate_checkout( $cart, 70 );
		$this->assertTrue( $report['valid'] );
		$this->assertEmpty( $report['errors'] );
		$this->assertEmpty( $report['discrepancies'] );
	}

	public function test_revalidate_checkout_blocks_checkout_on_discrepancy(): void {
		$group = $this->group_repo->save( new PricingGroup( 'TestGroup', 'test-group', 1, 'active' ) );
		$this->user_group_repo->assign( 80, (int) $group->id() );

		$rule = $this->rule_repo->save( new PricingRule(
			RuleTarget::group( (int) $group->id() ),
			RuleScope::product( 902 ),
			PricingValue::percentage( '20.000000' )
		) );

		$product = new WC_Product( 902, [ 'price' => '100.00', 'regular_price' => '100.00' ] );
		$cart_item = [ 'data' => $product, 'quantity' => 1 ];
		$this->cart_adapter->apply_cart_item_pricing( $cart_item, 80 );
		$this->assertSame( '80.00', $product->get_price() );

		// Simulate admin modifying rule between cart display and checkout submission: 50% discount!
		$this->rule_repo->delete( (int) $rule->id() );
		$this->rule_repo->save( new PricingRule(
			RuleTarget::group( (int) $group->id() ),
			RuleScope::product( 902 ),
			PricingValue::percentage( '50.000000' )
		) );
		$this->cache->clear();

		$cart = new WC_Cart();
		$cart->set_cart_contents( [ 'item_1' => $cart_item ] );

		$report = $this->cart_adapter->revalidate_checkout( $cart, 80 );
		$this->assertFalse( $report['valid'], 'Checkout must be blocked when cart price differs from current rules.' );
		$this->assertNotEmpty( $report['discrepancies'] );
		$this->assertSame( '80.00', $report['discrepancies']['item_1']['cart_price'] );
		$this->assertSame( '50.00', $report['discrepancies']['item_1']['current_price'] );
	}

	public function test_revalidate_checkout_blocks_checkout_on_unresolved_pricing_failure(): void {
		$product = new WC_Product( 903, [ 'price' => '100.00', 'regular_price' => '100.00' ] );
		$cart_item = [ 'data' => $product, 'quantity' => 1 ];

		// Record calculation failure for product 903
		$this->failure_tracker->record_failure( 903, PricingFailureTracker::CODE_CALCULATION_FAILED, 'Precision fault' );

		$cart = new WC_Cart();
		$cart->set_cart_contents( [ 'item_1' => $cart_item ] );

		$report = $this->cart_adapter->revalidate_checkout( $cart, 0 );
		$this->assertFalse( $report['valid'], 'Unresolved pricing failure must block checkout.' );
		$this->assertContains( 903, $report['failed_product_ids'] );
	}

	public function test_format_cart_item_price_html(): void {
		$product = new WC_Product( 904, [ 'price' => '80.00', 'regular_price' => '100.00' ] );
		$cart_item = [ 'data' => $product ];

		$html = $this->cart_adapter->format_cart_item_price_html( '$80.00', $cart_item );
		// When discount is applied (80 < 100), must contain regular price strikethrough and sale price:
		$this->assertStringContainsString( '$100.00', $html );
		$this->assertStringContainsString( '$80.00', $html );

		// When price equals regular price:
		$product_regular = new WC_Product( 905, [ 'price' => '100.00', 'regular_price' => '100.00' ] );
		$cart_regular   = [ 'data' => $product_regular ];
		$html_reg       = $this->cart_adapter->format_cart_item_price_html( '$100.00', $cart_regular );
		$this->assertSame( '$100.00', $html_reg );
	}

	public function test_active_base_90_regular_100_tierza_20_percent_matches_catalog(): void {
		$group = $this->group_repo->save( new PricingGroup( 'Active Base Group', 'active-base-group' ) );
		$this->user_group_repo->assign( 950, (int) $group->id() );

		// Product has regular price 100.00, but active price is 90.00 (e.g. store promo without native sale object)
		$product = new WC_Product( 9501, [
			'price'         => '90.00',
			'regular_price' => '100.00',
			'sale_price'    => '',
		] );

		// 20% B2B discount
		$this->rule_repo->save( new PricingRule(
			RuleTarget::group( (int) $group->id() ),
			RuleScope::product( 9501 ),
			PricingValue::percentage( '20.000000' )
		) );
		$this->cache->clear();

		// Catalog adjusted price must be 90 - 20% = 72.00
		$catalog_price = $this->product_adapter->get_adjusted_price( $product, 950 );
		$this->assertSame( '72.00', $catalog_price, 'Catalog must calculate 20% discount on active base 90.00.' );

		// Cart item pricing must calculate identical 72.00
		$cart_item = [
			'data'     => $product,
			'quantity' => 1,
		];
		$result = $this->cart_adapter->apply_cart_item_pricing( $cart_item, 950 );
		$this->assertSame( '72.00', $product->get_price(), 'Cart item price must match catalog exactly at 72.00.' );
		$this->assertSame( '72.000000', $result->final_price()->amount() );

		// Idempotency: repeated passes must not stack discount (72 must not become 57.60)
		for ( $pass = 2; $pass <= 4; $pass++ ) {
			$this->cart_adapter->apply_cart_item_pricing( $cart_item, 950 );
			$this->assertSame( '72.00', $product->get_price(), sprintf( 'Pass %d must retain 72.00 without discount stacking.', $pass ) );
		}
	}

	public function test_native_sale_scheduled_active_and_expired(): void {
		$group = $this->group_repo->save( new PricingGroup( 'Sale Lifecycle Group', 'sale-lifecycle-group' ) );
		$this->user_group_repo->assign( 960, (int) $group->id() );

		// 10% B2B discount
		$this->rule_repo->save( new PricingRule(
			RuleTarget::group( (int) $group->id() ),
			RuleScope::product( 9601 ),
			PricingValue::percentage( '10.000000' )
		) );
		$this->cache->clear();

		// Case A: Active native sale (regular 100, sale 80) -> 80 - 10% = 72.00
		$active_sale_product = new WC_Product( 9601, [
			'price'         => '80.00',
			'regular_price' => '100.00',
			'sale_price'    => '80.00',
		] );
		$cart_item_a = [ 'data' => $active_sale_product, 'quantity' => 1 ];
		$this->cart_adapter->apply_cart_item_pricing( $cart_item_a, 960 );
		$this->assertSame( '72.00', $active_sale_product->get_price() );

		// Case B: Expired native sale (regular 100, expired sale 80 in DB, but active price is 100) -> 100 - 10% = 90.00
		$expired_sale_product = new WC_Product( 9602, [
			'price'         => '100.00',
			'regular_price' => '100.00',
			'sale_price'    => '',
		] );
		$this->rule_repo->save( new PricingRule(
			RuleTarget::group( (int) $group->id() ),
			RuleScope::product( 9602 ),
			PricingValue::percentage( '10.000000' )
		) );
		$this->cache->clear();
		$cart_item_b = [ 'data' => $expired_sale_product, 'quantity' => 1 ];
		$this->cart_adapter->apply_cart_item_pricing( $cart_item_b, 960 );
		$this->assertSame( '90.00', $expired_sale_product->get_price() );
	}

	public function test_legitimate_zero_priced_product_preserved(): void {
		$product = new WC_Product( 9701, [
			'price'         => '0.00',
			'regular_price' => '0.00',
		] );
		$cart_item = [ 'data' => $product, 'quantity' => 1 ];

		$base = $this->cart_adapter->get_unfiltered_base_price( $product, $cart_item );
		$this->assertSame( '0.00', $base );

		$res = $this->cart_adapter->apply_cart_item_pricing( $cart_item, 0 );
		$this->assertSame( '0.00', $product->get_price() );
	}

	public function test_invalid_monetary_inputs_reject_without_substituting_regular(): void {
		$invalid_product = new WC_Product( 9801, [
			'price'         => 'not-a-number',
			'regular_price' => '100.00',
		] );
		$cart_item = [ 'data' => $invalid_product, 'quantity' => 1 ];

		$this->expectException( InvalidMoneyException::class );
		$this->cart_adapter->get_unfiltered_base_price( $invalid_product, $cart_item );
	}

	public function test_revalidate_checkout_fails_closed_on_corrupted_cart_items(): void {
		$cart = new WC_Cart();
		$cart->set_cart_contents( [
			'corrupted_item' => [
				'data'     => null, // Corrupted item without WC_Product
				'quantity' => 1,
			],
		] );

		$report = $this->cart_adapter->revalidate_checkout( $cart, 0 );
		$this->assertFalse( $report['valid'], 'Checkout must fail closed when cart contains corrupted item.' );
		$this->assertNotEmpty( $report['errors'] );
	}
}
