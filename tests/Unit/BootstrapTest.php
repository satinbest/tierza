<?php

declare(strict_types=1);

namespace Tierza\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tierza\Admin\AdminMenu;
use Tierza\Infrastructure\Environment;
use Tierza\Plugin;

/**
 * Bootstrap smoke and regression test suite for TRZ-001.
 */
class BootstrapTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Plugin::reset_instance();
	}

	public function test_plugin_constants_defined(): void {
		$this->assertTrue( defined( 'TIERZA_VERSION' ) );
		$this->assertSame( '0.1.0-dev', TIERZA_VERSION );

		$this->assertTrue( defined( 'TIERZA_FILE' ) );
		$this->assertFileExists( TIERZA_FILE );

		$this->assertTrue( defined( 'TIERZA_PATH' ) );
		$this->assertDirectoryExists( TIERZA_PATH );

		$this->assertTrue( defined( 'TIERZA_URL' ) );
		$this->assertTrue( defined( 'TIERZA_BASENAME' ) );
	}

	public function test_plugin_header_metadata(): void {
		$content = file_get_contents( TIERZA_PATH . 'tierza.php' );
		$this->assertIsString( $content );

		$this->assertStringContainsString( 'Plugin Name:       Tierza', $content );
		$this->assertStringContainsString( 'Text Domain:       tierza', $content );
		$this->assertStringContainsString( 'Version:           0.1.0-dev', $content );
		$this->assertStringContainsString( 'Requires PHP:      8.1', $content );
		$this->assertStringContainsString( 'Requires at least: 6.5', $content );
		$this->assertStringContainsString( 'WC requires at least: 8.5', $content );
		$this->assertStringContainsString( 'License:           GPL-2.0-or-later', $content );
	}

	public function test_singleton_instance_resolution(): void {
		$instance1 = Plugin::instance();
		$instance2 = Plugin::instance();

		$this->assertInstanceOf( Plugin::class, $instance1 );
		$this->assertSame( $instance1, $instance2 );
	}

	public function test_activation_handler_sets_version_safely(): void {
		global $wp_options;
		$wp_options = [];

		Plugin::activate();

		$this->assertSame( '0.1.0-dev', $wp_options['tierza_version'] ?? null );
	}

	public function test_deactivation_runs_cleanly(): void {
		// Verify deactivation does not trigger exceptions or fatal errors.
		$this->expectNotToPerformAssertions();
		Plugin::deactivate();
	}

	public function test_admin_menu_registration(): void {
		global $wp_menu_pages;
		$wp_menu_pages = [];

		$menu = new AdminMenu();
		$menu->add_menu_pages();

		$this->assertArrayHasKey( AdminMenu::MENU_SLUG, $wp_menu_pages );
		$page = $wp_menu_pages[ AdminMenu::MENU_SLUG ];

		$this->assertSame( AdminMenu::CAPABILITY, $page['capability'] );
		$this->assertSame( 56, $page['position'] );
		$this->assertSame( 'Tierza', $page['menu_title'] );
	}

	public function test_admin_assets_enqueued_only_on_tierza_pages(): void {
		global $wp_enqueued_styles;
		$wp_enqueued_styles = [];

		$menu = new AdminMenu();

		// Should not enqueue on unrelated screens.
		$menu->enqueue_assets( 'edit.php' );
		$this->assertArrayNotHasKey( 'tierza-admin-css', $wp_enqueued_styles );

		// Should enqueue on Tierza screen.
		$menu->enqueue_assets( 'toplevel_page_tierza' );
		$this->assertArrayHasKey( 'tierza-admin-css', $wp_enqueued_styles );
	}

	public function test_dashboard_template_renders_rtl_and_content(): void {
		global $wp_version;
		$wp_version = '6.7.0';

		$menu = new AdminMenu();

		ob_start();
		$menu->render_dashboard();
		$output = ob_get_clean();

		$this->assertIsString( $output );
		$this->assertStringContainsString( 'tierza-wrap', $output );
		$this->assertStringContainsString( 'tierza-rtl', $output );
		$this->assertStringContainsString( 'v0.1.0-dev', $output );
		$this->assertStringContainsString( 'TRZ-001', $output );
	}

	public function test_translation_files_integrity(): void {
		$pot_file = TIERZA_PATH . 'languages/tierza.pot';
		$po_file  = TIERZA_PATH . 'languages/tierza-fa_IR.po';
		$mo_file  = TIERZA_PATH . 'languages/tierza-fa_IR.mo';

		$this->assertFileExists( $pot_file );
		$this->assertFileExists( $po_file );
		$this->assertFileExists( $mo_file );

		// Verify MO binary magic number (little endian 0x950412de).
		$handle = fopen( $mo_file, 'rb' );
		$this->assertIsResource( $handle );
		$magic_bytes = fread( $handle, 4 );
		fclose( $handle );

		$magic_unpack = unpack( 'Vmagic', $magic_bytes );
		$this->assertSame( 0x950412de, $magic_unpack['magic'] );
	}
}
