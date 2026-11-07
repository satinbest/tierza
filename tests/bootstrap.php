<?php
/**
 * PHPUnit test bootstrap for Tierza.
 */

declare(strict_types=1);

// Define ABSPATH if not defined.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

// Require Composer autoloader.
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// WordPress core globals and mock hooks registry.
global $wp_actions, $wp_filters, $wp_version, $wp_options, $wp_enqueued_styles, $wp_menu_pages;

$wp_actions         = [];
$wp_filters         = [];
$wp_version         = '6.7.0';
$wp_options         = [];
$wp_enqueued_styles = [];
$wp_menu_pages      = [];

// WordPress mock helper functions.
if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
		global $wp_actions;
		$wp_actions[ $hook ][] = [
			'callback' => $callback,
			'priority' => $priority,
			'args'     => $accepted_args,
		];
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( string $hook, ...$args ): void {
		global $wp_actions;
		if ( ! empty( $wp_actions[ $hook ] ) ) {
			foreach ( $wp_actions[ $hook ] as $item ) {
				call_user_func_array( $item['callback'], $args );
			}
		}
	}
}

if ( ! function_exists( 'plugin_dir_path' ) ) {
	function plugin_dir_path( string $file ): string {
		return trailingslashit( dirname( $file ) );
	}
}

if ( ! function_exists( 'plugin_dir_url' ) ) {
	function plugin_dir_url( string $file ): string {
		return 'https://example.com/wp-content/plugins/' . basename( dirname( $file ) ) . '/';
	}
}

if ( ! function_exists( 'plugin_basename' ) ) {
	function plugin_basename( string $file ): string {
		return basename( dirname( $file ) ) . '/' . basename( $file );
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( string $string ): string {
		return rtrim( $string, '/\\' ) . '/';
	}
}

if ( ! function_exists( 'load_plugin_textdomain' ) ) {
	function load_plugin_textdomain( string $domain, $deprecated = false, $plugin_rel_path = false ): bool {
		global $wp_loaded_textdomains;
		$wp_loaded_textdomains[ $domain ] = $plugin_rel_path;
		return true;
	}
}

if ( ! function_exists( 'register_activation_hook' ) ) {
	function register_activation_hook( string $file, $callback ): void {
		global $wp_activation_hooks;
		$wp_activation_hooks[ $file ] = $callback;
	}
}

if ( ! function_exists( 'register_deactivation_hook' ) ) {
	function register_deactivation_hook( string $file, $callback ): void {
		global $wp_deactivation_hooks;
		$wp_deactivation_hooks[ $file ] = $callback;
	}
}

if ( ! function_exists( 'add_menu_page' ) ) {
	function add_menu_page( string $page_title, string $menu_title, string $capability, string $menu_slug, $callback = '', string $icon_url = '', $position = null ): string {
		global $wp_menu_pages;
		$wp_menu_pages[ $menu_slug ] = [
			'page_title' => $page_title,
			'menu_title' => $menu_title,
			'capability' => $capability,
			'callback'   => $callback,
			'icon_url'   => $icon_url,
			'position'   => $position,
		];
		return $menu_slug;
	}
}

if ( ! function_exists( 'wp_enqueue_style' ) ) {
	function wp_enqueue_style( string $handle, string $src = '', array $deps = [], $ver = false, string $media = 'all' ): void {
		global $wp_enqueued_styles;
		$wp_enqueued_styles[ $handle ] = [
			'src'  => $src,
			'deps' => $deps,
			'ver'  => $ver,
		];
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( string $capability ): bool {
		global $wp_mock_user_caps;
		return $wp_mock_user_caps[ $capability ] ?? true;
	}
}

if ( ! function_exists( 'is_admin' ) ) {
	function is_admin(): bool {
		global $wp_is_admin;
		return $wp_is_admin ?? true;
	}
}

if ( ! function_exists( 'is_rtl' ) ) {
	function is_rtl(): bool {
		global $wp_is_rtl;
		return $wp_is_rtl ?? true;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $option, $value, $autoload = null ): bool {
		global $wp_options;
		$wp_options[ $option ] = $value;
		return true;
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $option, $default = false ) {
		global $wp_options;
		return $wp_options[ $option ] ?? $default;
	}
}

if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( string $text, string $domain = 'default' ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_html_e' ) ) {
	function esc_html_e( string $text, string $domain = 'default' ): void {
		echo htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'wp_die' ) ) {
	function wp_die( string $message = '', string $title = '', $args = [] ): void {
		throw new RuntimeException( "wp_die: $message" );
	}
}

// Include plugin entry point to declare constants.
require_once dirname( __DIR__ ) . '/tierza.php';
