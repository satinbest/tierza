<?php

declare(strict_types=1);

namespace Tierza\Tests\Unit\Integration\WooCommerce;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tierza\Application\Pricing\AuthoritativePriceCalculator;
use Tierza\Application\Pricing\Batch\PricingBatchPrefetcher;
use Tierza\Application\Pricing\Cache\RequestPricingCache;
use Tierza\Application\Pricing\DeterministicRuleResolver;
use Tierza\Domain\Entities\PricingRule;
use Tierza\Domain\Entities\UserGroupAssignment;
use Tierza\Domain\Repositories\UserGroupRepositoryInterface;
use Tierza\Domain\ValueObjects\PricingValue;
use Tierza\Domain\ValueObjects\RuleScope;
use Tierza\Domain\ValueObjects\RuleTarget;
use Tierza\Integration\WooCommerce\ProductPricingAdapter;
use Tierza\Integration\WooCommerce\ProductPricingIntegration;
use Tierza\Tests\Unit\Application\Pricing\InMemoryGroupRepository;
use Tierza\Tests\Unit\Application\Pricing\InMemoryRuleRepository;
use WC_Product;
use WC_Product_Variation;

/**
 * Unit tests for ProductPricingIntegration.
 */
class ProductPricingIntegrationTest extends TestCase {

	private InMemoryRuleRepository $rule_repo;
	private InMemoryGroupRepository $group_repo;
	private UserGroupRepositoryInterface $user_group_repo;
	private RequestPricingCache $cache;
	private DeterministicRuleResolver $resolver;
	private AuthoritativePriceCalculator $calculator;
	private PricingBatchPrefetcher $batch_prefetcher;
	private ProductPricingAdapter $adapter;
	private ProductPricingIntegration $integration;

	/** @var array<int, UserGroupAssignment> */
	private array $assignments = [];

	protected function setUp(): void {
		parent::setUp();

		global $current_user_id_mock, $wc_currency_mock, $wc_decimals_mock, $wp_filters, $wp_doing_ajax_mock, $current_user_caps_mock, $wp_is_admin;
		$current_user_id_mock   = 0;
		$wc_currency_mock       = 'USD';
		$wc_decimals_mock       = 2;
		$wp_doing_ajax_mock     = false;
		$wp_is_admin            = false;
		$current_user_caps_mock = [];

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

		$this->adapter     = new ProductPricingAdapter(
			$this->resolver,
			$this->calculator,
			$this->group_repo,
			$this->user_group_repo,
			$this->cache,
			$this->batch_prefetcher
		);
		$this->integration = new ProductPricingIntegration( $this->adapter );
	}

	protected function tearDown(): void {
		$this->integration->unregister();
		parent::tearDown();
	}

	public function test_hook_registration_and_unregistration(): void {
		global $wp_filters;

		$this->integration->register();

		$this->assertNotEmpty( $wp_filters['woocommerce_product_get_price'] ?? [] );
		$this->assertNotEmpty( $wp_filters['woocommerce_product_variation_get_price'] ?? [] );
		$this->assertNotEmpty( $wp_filters['woocommerce_variation_prices_price'] ?? [] );
		$this->assertNotEmpty( $wp_filters['woocommerce_get_variation_prices_hash'] ?? [] );
		$this->assertNotEmpty( $wp_filters['woocommerce_get_price_html'] ?? [] );

		$this->integration->unregister();

		$this->assertEmpty( $wp_filters['woocommerce_product_get_price'] ?? [] );
		$this->assertEmpty( $wp_filters['woocommerce_product_variation_get_price'] ?? [] );
		$this->assertEmpty( $wp_filters['woocommerce_variation_prices_price'] ?? [] );
		$this->assertEmpty( $wp_filters['woocommerce_get_variation_prices_hash'] ?? [] );
		$this->assertEmpty( $wp_filters['woocommerce_get_price_html'] ?? [] );
	}

	public function test_reentrancy_guard_prevents_infinite_recursion(): void {
		// Create a product that calls get_price() from inside its price retrieval (simulating buggy theme/filter).
		$recursive_product = new class( 101, $this->integration ) extends WC_Product {
			private int $call_count = 0;
			public function __construct( int $id, private ProductPricingIntegration $integration ) {
				parent::__construct( $id );
				$this->regular_price = '100.00';
				$this->price         = '100.00';
			}
			public function get_price( string $context = 'view' ): string {
				$this->call_count++;
				if ( 'view' === $context && $this->call_count < 5 ) {
					// Recursively re-invoke integration filter!
					return $this->integration->filter_product_price( '100.00', $this );
				}
				return parent::get_price( $context );
			}
		};

		global $current_user_id_mock;
		$current_user_id_mock = 42;

		// 20% discount rule
		$this->rule_repo->save(
			new PricingRule(
				RuleTarget::user( 42 ),
				RuleScope::product( 101 ),
				PricingValue::percentage( '20.000000' )
			)
		);

		// Must return 80.00 cleanly without crashing or stack overflow!
		$price = $this->integration->filter_product_price( '100.00', $recursive_product );
		$this->assertSame( '80.00', $price );
	}

	public function test_duplicate_filter_application_is_idempotent(): void {
		$customer_id = 42;
		global $current_user_id_mock;
		$current_user_id_mock = $customer_id;

		$product = new WC_Product( 101 );
		$product->set_regular_price( '100.00' );
		$product->set_price( '100.00' );

		// 20% discount rule
		$this->rule_repo->save(
			new PricingRule(
				RuleTarget::user( $customer_id ),
				RuleScope::product( 101 ),
				PricingValue::percentage( '20.000000' )
			)
		);

		// First call
		$first_run = $this->integration->filter_product_price( '100.00', $product );
		$this->assertSame( '80.00', $first_run );

		// Second call with previously filtered price
		$second_run = $this->integration->filter_product_price( $first_run, $product );
		$this->assertSame( '80.00', $second_run, 'Price must not be double-discounted upon repeated filter calls!' );
	}

	public function test_variation_prices_filter_applies_rule(): void {
		$customer_id = 42;
		global $current_user_id_mock;
		$current_user_id_mock = $customer_id;

		$var = new WC_Product_Variation( 201 );
		$var->set_parent_id( 200 );
		$var->set_regular_price( '50.00' );
		$var->set_price( '50.00' );

		$parent = new WC_Product( 200 );

		// 30% discount on variation 201
		$this->rule_repo->save(
			new PricingRule(
				RuleTarget::user( $customer_id ),
				RuleScope::variation( 201 ),
				PricingValue::percentage( '30.000000' )
			)
		);

		$price = $this->integration->filter_variation_prices_price( '50.00', $var, $parent );
		$this->assertSame( '35.00', $price );
	}

	public function test_variation_prices_hash_filter_appends_identity(): void {
		$customer_id = 42;
		global $current_user_id_mock;
		$current_user_id_mock = $customer_id;

		$hash_in  = [ 'display' => 'excl', 'taxes' => [] ];
		$hash_out = $this->integration->filter_variation_prices_hash( $hash_in, new WC_Product( 200 ) );

		$this->assertArrayHasKey( 'tierza', $hash_out );
		$this->assertSame( 42, $hash_out['tierza']['tierza_customer_id'] );
		$this->assertSame( 0, $hash_out['tierza']['tierza_group_id'] );
	}

	public function test_safe_error_handling_falls_back_without_throwing(): void {
		// Mock adapter throwing an exception to verify integration error containment.
		$failing_adapter = $this->createMock( ProductPricingAdapter::class );
		$failing_adapter->method( 'get_adjusted_price' )
			->willThrowException( new RuntimeException( 'Simulated database failure during pricing' ) );

		$integration = new ProductPricingIntegration( $failing_adapter );

		$product = new WC_Product( 101 );
		$product->set_regular_price( '100.00' );
		$product->set_price( '100.00' );

		$action_fired = false;
		if ( function_exists( 'add_action' ) ) {
			add_action(
				'tierza_pricing_calculation_failed',
				function ( int $product_id, $e ) use ( &$action_fired ) {
					$action_fired = true;
				},
				10,
				2
			);
		}

		// When failure occurs, it must return original price '100.00' safely!
		$price = $integration->filter_product_price( '100.00', $product );
		$this->assertSame( '100.00', $price );
		$this->assertTrue( $action_fired, 'Failure hook tierza_pricing_calculation_failed must be fired!' );
	}

	public function test_safe_html_error_handling_falls_back(): void {
		$failing_adapter = $this->createMock( ProductPricingAdapter::class );
		$failing_adapter->method( 'get_price_html' )
			->willThrowException( new RuntimeException( 'Simulated HTML rendering exception' ) );

		$integration = new ProductPricingIntegration( $failing_adapter );

		$product = new WC_Product( 101 );
		$product->set_regular_price( '100.00' );

		$action_fired = false;
		if ( function_exists( 'add_action' ) ) {
			add_action(
				'tierza_pricing_html_failed',
				function ( int $product_id, $e ) use ( &$action_fired ) {
					$action_fired = true;
				},
				10,
				2
			);
		}

		$html = $integration->filter_price_html( '$100.00', $product );
		$this->assertSame( '$100.00', $html );
		$this->assertTrue( $action_fired, 'Failure hook tierza_pricing_html_failed must be fired!' );
	}

	public function test_scope_boundaries_protect_admin_and_import(): void {
		global $wp_is_admin, $wp_doing_ajax_mock;
		$product = new WC_Product( 101 );

		// 1. Normal frontend (is_admin = false) -> allowed
		$wp_is_admin = false;
		$this->assertTrue( $this->integration->should_apply_pricing( $product ) );

		// 2. CLI disabled -> disallowed
		$this->integration->set_allow_cli_pricing( false );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			$this->assertFalse( $this->integration->should_apply_pricing( $product ) );
		}
		$this->integration->set_allow_cli_pricing( true );

		// 3. Admin screen non-AJAX (is_admin = true, ajax = false) -> disallowed
		$wp_is_admin        = true;
		$wp_doing_ajax_mock = false;
		$this->assertFalse( $this->integration->should_apply_pricing( $product ) );

		// 4. Frontend AJAX (is_admin = true, ajax = true, normal action) -> allowed
		$wp_doing_ajax_mock = true;
		$this->assertTrue( $this->integration->should_apply_pricing( $product ) );

		// 5. Admin AJAX saving variation -> disallowed
		$_REQUEST['action'] = 'woocommerce_save_variations';
		$this->assertFalse( $this->integration->should_apply_pricing( $product ) );
		unset( $_REQUEST['action'] );

		// 6. CSV Product Export -> disallowed
		$_GET['action'] = 'export_products';
		$this->assertFalse( $this->integration->should_apply_pricing( $product ) );
		unset( $_GET['action'] );

		$wp_doing_ajax_mock = false;
		$wp_is_admin        = false;
	}
}
