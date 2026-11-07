<?php

declare(strict_types=1);

namespace Tierza\Admin;

/**
 * Handles WordPress admin menu registration and page rendering for Tierza.
 */
class AdminMenu {

	public const MENU_SLUG   = 'tierza';
	public const CAPABILITY  = 'manage_woocommerce';

	/**
	 * Register admin menu hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_menu_pages' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	/**
	 * Add Tierza top-level menu page.
	 */
	public function add_menu_pages(): void {
		$page_title = __( 'تیرزا — قیمت‌گذاری B2B و همکار ووکامرس', 'tierza' );
		$menu_title = __( 'تیرزا', 'tierza' );

		// Custom SVG icon base64 encoded for high-DPI crisp rendering.
		$icon_svg = 'data:image/svg+xml;base64,' . base64_encode(
			'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="#a7aaad"><path d="M10 2a8 8 0 100 16 8 8 0 000-16zm1 12H9v-2h2v2zm0-4H9V6h2v4z"/></svg>'
		);

		add_menu_page(
			$page_title,
			$menu_title,
			self::CAPABILITY,
			self::MENU_SLUG,
			[ $this, 'render_dashboard' ],
			$icon_svg,
			56
		);
	}

	/**
	 * Enqueue admin styles and scripts on Tierza pages.
	 *
	 * @param string $hook_suffix Current admin screen hook.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( false === strpos( $hook_suffix, self::MENU_SLUG ) ) {
			return;
		}

		$css_file = TIERZA_PATH . 'assets/css/admin.css';
		$css_url  = TIERZA_URL . 'assets/css/admin.css';
		$version  = file_exists( $css_file ) ? (string) filemtime( $css_file ) : TIERZA_VERSION;

		wp_enqueue_style(
			'tierza-admin-css',
			$css_url,
			[],
			$version
		);
	}

	/**
	 * Render the admin dashboard page.
	 */
	public function render_dashboard(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'شما اجازه دسترسی به این بخش را ندارید.', 'tierza' ) );
		}

		$template_path = TIERZA_PATH . 'templates/admin/dashboard.php';
		if ( file_exists( $template_path ) ) {
			require $template_path;
		} else {
			echo '<div class="wrap"><h1>' . esc_html__( 'تیرزا', 'tierza' ) . '</h1></div>';
		}
	}
}
