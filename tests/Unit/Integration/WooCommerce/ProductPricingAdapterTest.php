<?php

declare(strict_types=1);

namespace Tierza\Tests\Unit\Integration\WooCommerce;

use DateTime;
use PHPUnit\Framework\TestCase;
use Tierza\Application\Pricing\AuthoritativePriceCalculator;
use Tierza\Application\Pricing\Batch\PricingBatchPrefetcher;
use Tierza\Application\Pricing\Cache\RequestPricingCache;
use Tierza\Application\Pricing\DeterministicRuleResolver;
use Tierza\Domain\Entities\PricingGroup;
use Tierza\Domain\Entities\PricingRule;
use Tierza\Domain\Entities\UserGroupAssignment;
use Tierza\Domain\Pricing\PricingContext;
use Tierza\Domain\Pricing\PricingResult;
use Tierza\Domain\Repositories\GroupRepositoryInterface;
use Tierza\Domain\Repositories\UserGroupRepositoryInterface;
use Tierza\Domain\ValueObjects\Money;
use Tierza\Domain\ValueObjects\PricingValue;
use Tierza\Domain\ValueObjects\RuleScope;
use Tierza\Domain\ValueObjects\RuleTarget;
use Tierza\Integration\WooCommerce\ProductPricingAdapter;
use Tierza\Tests\Unit\Application\Pricing\InMemoryGroupRepository;
use Tierza\Tests\Unit\Application\Pricing\InMemoryRuleRepository;
use WC_Product;
use WC_Product_Variable;
use WC_Product_Variation;
use WP_Term;

/**
 * Unit tests for ProductPricingAdapter.
 */
class ProductPricingAdapterTest extends TestCase {

	private InMemoryRuleRepository $rule_repo;
	private InMemoryGroupRepository $group_repo;
	private UserGroupRepositoryInterface $user_group_repo;
	private RequestPricingCache $cache;
	private DeterministicRuleResolver $resolver;
	private AuthoritativePriceCalculator $calculator;
	private PricingBatchPrefetcher $batch_prefetcher;
	private ProductPricingAdapter $adapter;

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

		$this->assignments = [];
		$assignments_ref   = &$this->assignments;

		$this->user_group_repo = new class( $assignments_ref ) implements UserGroupRepositoryInterface {
			/** @param array<int, UserGroupAssignment> $assignments */
			public function __construct( private array &$assignments ) {}

			public function assign( int $user_id, int $group_id ): UserGroupAssignment {
				$assignment = new UserGroupAssignment( $user_id, $group_id );
				$this->assignments[ $user_id ] = $assignment;
				return $assignment;
			}

			public function reassign( int $user_id, int $new_group_id ): UserGroupAssignment {
				return $this->assign( $user_id, $new_group_id );
			}

			public function find_by_user_id( int $user_id ): ?UserGroupAssignment {
				return $this->assignments[ $user_id ] ?? null;
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

		$this->adapter = new ProductPricingAdapter(
			$this->resolver,
			$this->calculator,
			$this->group_repo,
			$this->user_group_repo,
			$this->cache,
			$this->batch_prefetcher
		);
	}

	public function test_simple_product_fallback_preserves_original_price(): void {
		$product = new WC_Product( 101 );
		$product->set_regular_price( '100.00' );
		$product->set_price( '100.00' );

		// No rules exist -> fallback to original WooCommerce price.
		$price = $this->adapter->get_adjusted_price( $product );
		$this->assertSame( '100.00', $price );

		$html = $this->adapter->get_price_html( '$100.00', $product );
		$this->assertSame( '$100.00', $html );
	}

	public function test_simple_product_customer_percentage_discount(): void {
		$customer_id = 42;
		$product     = new WC_Product( 101 );
		$product->set_regular_price( '100.00' );
		$product->set_price( '100.00' );

		// Save 20% discount rule for customer on product 101.
		$this->rule_repo->save(
			new PricingRule(
				RuleTarget::user( $customer_id ),
				RuleScope::product( 101 ),
				PricingValue::percentage( '20.000000' )
			)
		);

		$price = $this->adapter->get_adjusted_price( $product, $customer_id );
		$this->assertSame( '80.00', $price );

		$html = $this->adapter->get_price_html( '$100.00', $product, $customer_id );
		$this->assertStringContainsString( '<del aria-hidden="true">$100.00</del>', $html );
		$this->assertStringContainsString( '<ins>$80.00</ins>', $html );
	}

	public function test_simple_product_group_percentage_discount(): void {
		$customer_id = 42;
		$group       = $this->group_repo->save( new PricingGroup( 'VIP Wholesale', 'vip-wholesale' ) );
		$this->user_group_repo->assign( $customer_id, (int) $group->id() );

		$product = new WC_Product( 101 );
		$product->set_regular_price( '200.00' );
		$product->set_price( '200.00' );

		// 15% discount for group global.
		$this->rule_repo->save(
			new PricingRule(
				RuleTarget::group( (int) $group->id() ),
				RuleScope::global(),
				PricingValue::percentage( '15.000000' )
			)
		);

		$price = $this->adapter->get_adjusted_price( $product, $customer_id );
		$this->assertSame( '170.00', $price );

		// Another user (guest) should get fallback $200.00
		$guest_price = $this->adapter->get_adjusted_price( $product, 0 );
		$this->assertSame( '200.00', $guest_price );
	}

	public function test_fixed_product_price_and_surcharge(): void {
		$customer_id = 42;
		$product     = new WC_Product( 101 );
		$product->set_regular_price( '100.00' );
		$product->set_price( '100.00' );

		// Fixed price rule $75.00
		$this->rule_repo->save(
			new PricingRule(
				RuleTarget::user( $customer_id ),
				RuleScope::product( 101 ),
				PricingValue::fixed( '75.000000' )
			)
		);

		$price = $this->adapter->get_adjusted_price( $product, $customer_id );
		$this->assertSame( '75.00', $price );

		// Fixed surcharge exceeding base price: $125.00
		$this->rule_repo->clear();
		$this->cache->clear();

		$this->rule_repo->save(
			new PricingRule(
				RuleTarget::user( $customer_id ),
				RuleScope::product( 101 ),
				PricingValue::fixed( '125.000000' )
			)
		);

		$surcharge_price = $this->adapter->get_adjusted_price( $product, $customer_id );
		$this->assertSame( '125.00', $surcharge_price );

		// Price HTML for surcharge should display standard price (not sale style)
		$surcharge_html = $this->adapter->get_price_html( '$100.00', $product, $customer_id );
		$this->assertSame( '$125.00', $surcharge_html );
	}

	public function test_explicit_zero_percent_and_fixed_zero(): void {
		$customer_id = 42;
		$group       = $this->group_repo->save( new PricingGroup( 'B2B', 'b2b' ) );
		$this->user_group_repo->assign( $customer_id, (int) $group->id() );

		$product = new WC_Product( 101 );
		$product->set_regular_price( '100.00' );
		$product->set_price( '100.00' );

		// Group has 20% discount (Level 8)
		$this->rule_repo->save(
			new PricingRule(
				RuleTarget::group( (int) $group->id() ),
				RuleScope::global(),
				PricingValue::percentage( '20.000000' )
			)
		);

		// Customer has explicit 0% rule on product 101 (Level 2).
		// Level 2 beats Level 8: explicit 0% must win over group 20%!
		$this->rule_repo->save(
			new PricingRule(
				RuleTarget::user( $customer_id ),
				RuleScope::product( 101 ),
				PricingValue::percentage( '0.000000' )
			)
		);

		$price = $this->adapter->get_adjusted_price( $product, $customer_id );
		$this->assertSame( '100.00', $price );

		// Fixed zero price ($0.000000)
		$this->rule_repo->clear();
		$this->cache->clear();

		$this->rule_repo->save(
			new PricingRule(
				RuleTarget::user( $customer_id ),
				RuleScope::product( 101 ),
				PricingValue::fixed( '0.000000' )
			)
		);

		$zero_price = $this->adapter->get_adjusted_price( $product, $customer_id );
		$this->assertSame( '0.00', $zero_price );
	}

	public function test_native_sale_awareness_uses_sale_price_as_base(): void {
		$customer_id = 42;
		$product     = new WC_Product( 101 );
		$product->set_regular_price( '100.00' );
		$product->set_sale_price( '80.00' );
		$product->set_price( '80.00' );

		// Group has 10% discount.
		// Native sale price is $80.00 -> 10% off $80.00 = $72.00!
		$group = $this->group_repo->save( new PricingGroup( 'Partners', 'partners' ) );
		$this->user_group_repo->assign( $customer_id, (int) $group->id() );

		$this->rule_repo->save(
			new PricingRule(
				RuleTarget::group( (int) $group->id() ),
				RuleScope::global(),
				PricingValue::percentage( '10.000000' )
			)
		);

		$context = $this->adapter->get_pricing_context( $product, $customer_id );
		$this->assertNotNull( $context );
		$this->assertTrue( $context->is_on_sale() );
		$this->assertSame( '80.000000', $context->base_price()->amount() );
		$this->assertSame( '100.000000', $context->regular_price()?->amount() );
		$this->assertSame( '80.000000', $context->sale_price()?->amount() );

		$price = $this->adapter->get_adjusted_price( $product, $customer_id );
		$this->assertSame( '72.00', $price );

		$html = $this->adapter->get_price_html( '<del>$100.00</del> <ins>$80.00</ins>', $product, $customer_id );
		$this->assertStringContainsString( '<del aria-hidden="true">$100.00</del>', $html );
		$this->assertStringContainsString( '<ins>$72.00</ins>', $html );
	}

	public function test_expired_or_future_native_sale_ignored(): void {
		$customer_id = 42;
		$product     = new WC_Product( 101 );
		$product->set_regular_price( '100.00' );
		$product->set_sale_price( '80.00' );
		$product->set_price( '100.00' );

		// Set sale in the past (expired)
		$product->set_date_on_sale_to( ( new DateTime() )->modify( '-2 days' ) );

		$context = $this->adapter->get_pricing_context( $product, $customer_id );
		$this->assertNotNull( $context );
		$this->assertFalse( $context->is_on_sale() );
		$this->assertSame( '100.000000', $context->base_price()->amount() );
		$this->assertNull( $context->sale_price() );
	}

	public function test_equal_regular_and_sale_price_not_treated_as_sale(): void {
		$product = new WC_Product( 101 );
		$product->set_regular_price( '100.00' );
		$product->set_sale_price( '100.00' );
		$product->set_price( '100.00' );

		$context = $this->adapter->get_pricing_context( $product, 0 );
		$this->assertNotNull( $context );
		$this->assertFalse( $context->is_on_sale() );
	}

	public function test_variation_pricing_and_parent_rule_inheritance(): void {
		$customer_id = 42;

		// Parent variable product (ID 200)
		// Variation 1 (ID 201)
		// Variation 2 (ID 202)
		$var1 = new WC_Product_Variation( 201 );
		$var1->set_parent_id( 200 );
		$var1->set_regular_price( '100.00' );
		$var1->set_price( '100.00' );

		$var2 = new WC_Product_Variation( 202 );
		$var2->set_parent_id( 200 );
		$var2->set_regular_price( '150.00' );
		$var2->set_price( '150.00' );

		// Parent-level rule: 10% discount on parent 200 (Level 2)
		$this->rule_repo->save(
			new PricingRule(
				RuleTarget::user( $customer_id ),
				RuleScope::product( 200 ),
				PricingValue::percentage( '10.000000' )
			)
		);

		// Variation 1 specific rule: 25% discount on variation 201 (Level 1)
		$this->rule_repo->save(
			new PricingRule(
				RuleTarget::user( $customer_id ),
				RuleScope::variation( 201 ),
				PricingValue::percentage( '25.000000' )
			)
		);

		// Var 1 matches variation-specific rule (Level 1) -> 25% off 100 = 75.00
		$p1 = $this->adapter->get_adjusted_price( $var1, $customer_id );
		$this->assertSame( '75.00', $p1 );

		// Var 2 has no variation-specific rule -> inherits parent product rule 200 (Level 2) -> 10% off 150 = 135.00
		$p2 = $this->adapter->get_adjusted_price( $var2, $customer_id );
		$this->assertSame( '135.00', $p2 );
	}

	public function test_category_hierarchy_and_depth_tie_breaking(): void {
		global $wp_terms_mock, $wp_ancestors_mock;

		$customer_id = 42;
		$product     = new WC_Product( 101 );
		$product->set_regular_price( '100.00' );
		$product->set_price( '100.00' );

		// Category 10: Root category (depth 0)
		$root = new WP_Term();
		$root->term_id = 10;
		$root->parent  = 0;

		// Category 20: Child category of 10 (depth 1)
		$child = new WP_Term();
		$child->term_id = 20;
		$child->parent  = 10;

		$wp_terms_mock[101]     = [ $root, $child ];
		$wp_ancestors_mock[10]  = [];
		$wp_ancestors_mock[20]  = [ 10 ];

		// Save 10% discount on Root category (depth 0)
		$this->rule_repo->save(
			new PricingRule(
				RuleTarget::user( $customer_id ),
				RuleScope::category( 10 ),
				PricingValue::percentage( '10.000000' )
			)
		);

		// Save 15% discount on Child category (depth 1)
		$this->rule_repo->save(
			new PricingRule(
				RuleTarget::user( $customer_id ),
				RuleScope::category( 20 ),
				PricingValue::percentage( '15.000000' )
			)
		);

		// Child category has depth 1 > depth 0, so 15% must win!
		$price = $this->adapter->get_adjusted_price( $product, $customer_id );
		$this->assertSame( '85.00', $price );
	}

	public function test_currency_decimals_formatting(): void {
		global $wc_decimals_mock;

		$customer_id = 42;
		$product     = new WC_Product( 101 );
		$product->set_regular_price( '100.00' );
		$product->set_price( '100.00' );

		// 15.5% discount -> 100 - 15.5 = 84.50
		$this->rule_repo->save(
			new PricingRule(
				RuleTarget::user( $customer_id ),
				RuleScope::product( 101 ),
				PricingValue::percentage( '15.500000' )
			)
		);

		// Decimals = 2 -> 84.50
		$wc_decimals_mock = 2;
		$this->cache->clear();
		$this->assertSame( '84.50', $this->adapter->get_adjusted_price( $product, $customer_id ) );

		// Decimals = 0 (e.g. JPY, IRR) -> 85 (half-up)
		$wc_decimals_mock = 0;
		$this->cache->clear();
		$this->assertSame( '85', $this->adapter->get_adjusted_price( $product, $customer_id ) );

		// Decimals = 4 -> 84.5000
		$wc_decimals_mock = 4;
		$this->cache->clear();
		$this->assertSame( '84.5000', $this->adapter->get_adjusted_price( $product, $customer_id ) );
	}

	public function test_unpriced_product_returns_empty_string_without_error(): void {
		$product = new WC_Product( 101 );
		$product->set_regular_price( '' );
		$product->set_price( '' );

		$this->assertNull( $this->adapter->get_pricing_context( $product ) );
		$this->assertSame( '', $this->adapter->get_adjusted_price( $product ) );
		$this->assertSame( '', $this->adapter->get_price_html( '', $product ) );
	}

	public function test_variation_prices_hash_data_segregates_users_and_groups(): void {
		$group = $this->group_repo->save( new PricingGroup( 'Gold', 'gold' ) );
		$this->user_group_repo->assign( 10, (int) $group->id() );

		$hash_u10 = $this->adapter->get_variation_prices_hash_data( 10 );
		$hash_u20 = $this->adapter->get_variation_prices_hash_data( 20 );
		$hash_g0  = $this->adapter->get_variation_prices_hash_data( 0 );

		$this->assertSame( 10, $hash_u10['tierza_customer_id'] );
		$this->assertSame( (int) $group->id(), $hash_u10['tierza_group_id'] );
		$this->assertTrue( $hash_u10['tierza_is_active'] );

		$this->assertSame( 20, $hash_u20['tierza_customer_id'] );
		$this->assertSame( 0, $hash_u20['tierza_group_id'] );

		$this->assertSame( 0, $hash_g0['tierza_customer_id'] );
		$this->assertSame( 0, $hash_g0['tierza_group_id'] );

		// Verify that md5 of json-encoded hashes are completely distinct
		$md5_u10 = md5( json_encode( $hash_u10 ) ?: '' );
		$md5_u20 = md5( json_encode( $hash_u20 ) ?: '' );
		$md5_g0  = md5( json_encode( $hash_g0 ) ?: '' );

		$this->assertNotSame( $md5_u10, $md5_u20 );
		$this->assertNotSame( $md5_u10, $md5_g0 );
		$this->assertNotSame( $md5_u20, $md5_g0 );
	}

	public function test_batch_prefetching_warms_cache_for_all_products(): void {
		$customer_id = 42;

		$p1 = new WC_Product( 101 );
		$p1->set_regular_price( '100.00' );
		$p1->set_price( '100.00' );

		$p2 = new WC_Product( 102 );
		$p2->set_regular_price( '200.00' );
		$p2->set_price( '200.00' );

		$this->rule_repo->save(
			new PricingRule(
				RuleTarget::user( $customer_id ),
				RuleScope::product( 101 ),
				PricingValue::percentage( '10.000000' )
			)
		);

		// Reset query count
		$this->rule_repo->reset_query_count();

		// Prefetch both products
		$this->adapter->prefetch_products( [ $p1, $p2 ], $customer_id );

		// Exactly 1 SQL query executed during prefetch
		$this->assertSame( 1, $this->rule_repo->query_count() );

		// Resolving both products should execute 0 additional queries!
		$this->assertSame( '90.00', $this->adapter->get_adjusted_price( $p1, $customer_id ) );
		$this->assertSame( '200.00', $this->adapter->get_adjusted_price( $p2, $customer_id ) );

		$this->assertSame( 1, $this->rule_repo->query_count() );
	}
}
