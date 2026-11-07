<?php

declare(strict_types=1);

namespace Tierza\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Tierza\Admin\AdminMenu;
use Tierza\Infrastructure\Environment;
use Tierza\Plugin;

/**
 * Integration test suite for Tierza bootstrap, lifecycle, and WooCommerce integration.
 */
class BootstrapIntegrationTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		global $wp_version, $wp_actions;
		$wp_version = '6.7.0';
		$wp_actions = [];
		Plugin::reset_instance();
	}

	public function test_plugin_initialization_with_woocommerce_active(): void {
		global $wp_actions, $wp_menu_pages, $wp_is_admin;
		$wp_is_admin   = true;
		$wp_actions    = [];
		$wp_menu_pages = [];

		$loaded_fired = false;
		add_action( 'tierza_loaded', function ( Plugin $plugin ) use ( &$loaded_fired ) {
			$loaded_fired = true;
		} );

		// Simulate WooCommerce active
		if ( ! class_exists( 'WooCommerce' ) ) {
			eval( 'class WooCommerce { public static function instance() { return new self(); } }' );
		}
		if ( ! defined( 'WC_VERSION' ) ) {
			define( 'WC_VERSION', '8.5.2' );
		}

		$plugin = Plugin::instance();

		$this->assertTrue( $loaded_fired, 'tierza_loaded action must fire on successful bootstrap with WC' );
		$this->assertInstanceOf( AdminMenu::class, $plugin->get_admin_menu() );
	}

	public function test_admin_menu_registered_with_correct_capability(): void {
		global $wp_menu_pages;
		$wp_menu_pages = [];

		$menu = new AdminMenu();
		$menu->add_menu_pages();

		$this->assertArrayHasKey( 'tierza', $wp_menu_pages );
		$this->assertSame( 'manage_woocommerce', $wp_menu_pages['tierza']['capability'] );
		$this->assertSame( 56, $wp_menu_pages['tierza']['position'] );
	}

	public function test_unauthorized_user_cannot_access_dashboard(): void {
		global $wp_mock_user_caps;
		$wp_mock_user_caps = [ 'manage_woocommerce' => false ];

		$menu = new AdminMenu();

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'wp_die: You do not have sufficient permissions to access this page.' );

		$menu->render_dashboard();
	}

	public function test_dashboard_renders_cleanly_without_notices_or_warnings(): void {
		global $wp_mock_user_caps, $wp_is_rtl;
		$wp_mock_user_caps = [ 'manage_woocommerce' => true ];
		$wp_is_rtl         = true;

		$menu = new AdminMenu();

		// Capture output and verify no PHP errors/warnings leaked
		ob_start();
		$menu->render_dashboard();
		$output = ob_get_clean();

		$this->assertIsString( $output );
		$this->assertStringContainsString( 'tierza-wrap', $output );
		$this->assertStringContainsString( 'tierza-rtl', $output );
		$this->assertStringNotContainsString( 'Notice:', $output );
		$this->assertStringNotContainsString( 'Warning:', $output );
	}

	public function test_clean_deactivation_lifecycle(): void {
		$deactivated_fired = false;
		add_action( 'tierza_deactivated', function () use ( &$deactivated_fired ) {
			$deactivated_fired = true;
		} );

		Plugin::deactivate();
		$this->assertTrue( $deactivated_fired, 'tierza_deactivated hook should fire on deactivation' );
	}
}
