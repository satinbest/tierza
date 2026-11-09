<?php

declare(strict_types=1);

namespace Tierza;

use Tierza\Admin\AdminMenu;
use Tierza\Application\Pricing\AuthoritativePriceCalculator;
use Tierza\Application\Pricing\Batch\PricingBatchPrefetcher;
use Tierza\Application\Pricing\Cache\RequestPricingCache;
use Tierza\Application\Pricing\DeterministicRuleResolver;
use Tierza\Domain\Pricing\Contracts\PricingCacheInterface;
use Tierza\Domain\Repositories\GroupRepositoryInterface;
use Tierza\Domain\Repositories\RuleRepositoryInterface;
use Tierza\Domain\Repositories\UserGroupRepositoryInterface;
use Tierza\Infrastructure\Database\Schema;
use Tierza\Infrastructure\Environment;
use Tierza\Infrastructure\Repositories\WpGroupRepository;
use Tierza\Infrastructure\Repositories\WpRuleRepository;
use Tierza\Infrastructure\Repositories\WpUserGroupRepository;
use Tierza\Integration\WooCommerce\ProductPricingAdapter;
use Tierza\Integration\WooCommerce\ProductPricingIntegration;

/**
 * Main Tierza plugin singleton coordinator.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 */
	private static ?Plugin $instance = null;

	/**
	 * Admin menu service.
	 */
	private ?AdminMenu $admin_menu = null;

	/**
	 * Repository, cache, and pricing integration instances.
	 */
	private ?GroupRepositoryInterface $group_repository          = null;
	private ?RuleRepositoryInterface $rule_repository            = null;
	private ?UserGroupRepositoryInterface $user_group_repository = null;
	private ?PricingCacheInterface $pricing_cache                = null;
	private ?ProductPricingAdapter $pricing_adapter              = null;
	private ?ProductPricingIntegration $pricing_integration      = null;

	/**
	 * Whether the plugin has been initialized.
	 */
	private bool $bootstrapped = false;

	/**
	 * Get singleton instance.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor to enforce singleton.
	 */
	private function __construct() {
		$this->bootstrap();
	}

	/**
	 * Bootstrap plugin lifecycle.
	 */
	public function bootstrap(): void {
		if ( $this->bootstrapped ) {
			return;
		}

		$this->bootstrapped = true;

		// Load translations.
		$this->load_textdomain();

		// Verify core PHP and WordPress environment.
		if ( ! Environment::is_php_compatible() || ! Environment::is_wp_compatible() ) {
			add_action( 'admin_notices', [ $this, 'render_environment_notices' ] );
			return;
		}

		// Verify WooCommerce presence and compatibility.
		if ( ! Environment::is_woocommerce_active() || ! Environment::is_woocommerce_compatible() ) {
			add_action( 'admin_notices', [ $this, 'render_woocommerce_notice' ] );
			// Suspend WooCommerce-dependent services when WooCommerce is absent or incompatible.
			return;
		}

		// Initialize services.
		$this->init_services();
	}

	/**
	 * Load plugin internationalization textdomain.
	 */
	private function load_textdomain(): void {
		load_plugin_textdomain(
			'tierza',
			false,
			dirname( plugin_basename( TIERZA_FILE ) ) . '/languages'
		);
	}

	/**
	 * Initialize plugin components and integrations.
	 */
	private function init_services(): void {
		// Run idempotent database migrations if needed.
		Schema::maybe_upgrade();

		// Instantiate cache and repositories.
		$this->pricing_cache         = new RequestPricingCache();
		$this->group_repository      = new WpGroupRepository( null, $this->pricing_cache );
		$this->rule_repository       = new WpRuleRepository( null, null, $this->pricing_cache );
		$this->user_group_repository = new WpUserGroupRepository( null, null, $this->pricing_cache );

		// Instantiate pricing engine & WooCommerce product integration.
		$resolver         = new DeterministicRuleResolver( $this->rule_repository, $this->group_repository, $this->pricing_cache );
		$calculator       = new AuthoritativePriceCalculator();
		$batch_prefetcher = new PricingBatchPrefetcher( $this->rule_repository, $this->pricing_cache );

		$this->pricing_adapter     = new ProductPricingAdapter(
			$resolver,
			$calculator,
			$this->group_repository,
			$this->user_group_repository,
			$this->pricing_cache,
			$batch_prefetcher
		);
		$this->pricing_integration = new ProductPricingIntegration( $this->pricing_adapter );
		$this->pricing_integration->register();

		if ( is_admin() ) {
			$this->admin_menu = new AdminMenu();
			$this->admin_menu->register();
		}

		/**
		 * Action triggered once Tierza core has fully loaded.
		 *
		 * @param Plugin $this Tierza plugin instance.
		 */
		do_action( 'tierza_loaded', $this );
	}

	/**
	 * Display notices when PHP or WordPress environment does not satisfy requirements.
	 */
	public function render_environment_notices(): void {
		$notices = Environment::get_unmet_requirements_notices();
		foreach ( $notices as $notice ) {
			printf(
				'<div class="notice notice-error"><p><strong>%s:</strong> %s</p></div>',
				esc_html__( 'Tierza Plugin Error', 'tierza' ),
				esc_html( $notice )
			);
		}
	}

	/**
	 * Display admin notice when WooCommerce is inactive or missing.
	 */
	public function render_woocommerce_notice(): void {
		$notices = Environment::get_unmet_requirements_notices();
		foreach ( $notices as $notice ) {
			printf(
				'<div class="notice notice-warning is-dismissible"><p><strong>%s:</strong> %s</p></div>',
				esc_html__( 'Tierza Warning', 'tierza' ),
				esc_html( $notice )
			);
		}
	}

	/**
	 * Activation hook handler.
	 * Guaranteed not to modify products, orders, or customer data.
	 */
	public static function activate(): void {
		// Verify environment before activation completes.
		if ( ! Environment::is_php_compatible() ) {
			wp_die(
				esc_html(
					sprintf(
						/* translators: 1: Required PHP version, 2: Current PHP version */
						__( 'Tierza activation failed: PHP version %1$s or higher is required. Server version: %2$s.', 'tierza' ),
						Environment::MIN_PHP_VERSION,
						PHP_VERSION
					)
				)
			);
		}

		// Perform safe activation tasks (e.g. record version).
		update_option( 'tierza_version', TIERZA_VERSION );

		// Install database tables and record schema version.
		Schema::install();

		/**
		 * Action triggered on Tierza activation.
		 */
		do_action( 'tierza_activated' );
	}

	/**
	 * Deactivation hook handler.
	 * Clean teardown without destructive operations.
	 */
	public static function deactivate(): void {
		/**
		 * Action triggered on Tierza deactivation.
		 */
		do_action( 'tierza_deactivated' );
	}

	/**
	 * Get AdminMenu instance.
	 */
	public function get_admin_menu(): ?AdminMenu {
		return $this->admin_menu;
	}

	/**
	 * Get group repository.
	 */
	public function get_group_repository(): ?GroupRepositoryInterface {
		return $this->group_repository;
	}

	/**
	 * Get rule repository.
	 */
	public function get_rule_repository(): ?RuleRepositoryInterface {
		return $this->rule_repository;
	}

	/**
	 * Get user group assignment repository.
	 */
	public function get_user_group_repository(): ?UserGroupRepositoryInterface {
		return $this->user_group_repository;
	}

	/**
	 * Get request-scoped pricing cache.
	 */
	public function get_pricing_cache(): ?PricingCacheInterface {
		return $this->pricing_cache;
	}

	/**
	 * Get WooCommerce product pricing adapter.
	 */
	public function get_pricing_adapter(): ?ProductPricingAdapter {
		return $this->pricing_adapter;
	}

	/**
	 * Get WooCommerce product pricing integration.
	 */
	public function get_pricing_integration(): ?ProductPricingIntegration {
		return $this->pricing_integration;
	}

	/**
	 * Reset instance (primarily for test environments).
	 */
	public static function reset_instance(): void {
		self::$instance = null;
	}
}
