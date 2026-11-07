<?php

declare(strict_types=1);

namespace Tierza;

use Tierza\Admin\AdminMenu;
use Tierza\Infrastructure\Environment;

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
	 * Reset instance (primarily for test environments).
	 */
	public static function reset_instance(): void {
		self::$instance = null;
	}
}
