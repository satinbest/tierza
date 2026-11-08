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

if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( string $option ): bool {
		global $wp_options;
		unset( $wp_options[ $option ] );
		return true;
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

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

if ( ! class_exists( 'WP_User' ) ) {
	class WP_User {
		public int $ID = 0;

		public function exists(): bool {
			return $this->ID > 0;
		}
	}
}

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = 'wp_';
		public int $insert_id = 0;
		public string $last_error = '';

		public function get_charset_collate(): string {
			return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci';
		}

		/**
		 * @param string $query
		 * @param mixed ...$args
		 */
		public function prepare( string $query, ...$args ): string {
			foreach ( $args as $arg ) {
				$val   = is_string( $arg ) ? "'" . addslashes( $arg ) . "'" : (string) $arg;
				$query = preg_replace( '/%[sddfF]/', $val, $query, 1 ) ?? $query;
			}
			return $query;
		}

		public function get_var( ?string $query = null ): mixed {
			if ( null !== $query && str_contains( $query, 'SHOW TABLES LIKE' ) ) {
				if ( preg_match( "/'([^']+)'/", $query, $matches ) ) {
					return $matches[1];
				}
			}
			return null;
		}

		/**
		 * @return mixed
		 */
		public function get_row( ?string $query = null, string $output = ARRAY_A ): mixed {
			return null;
		}

		/**
		 * @return mixed
		 */
		public function get_results( ?string $query = null, string $output = ARRAY_A ): mixed {
			if ( null !== $query && str_contains( $query, 'SHOW FULL COLUMNS' ) ) {
				if ( str_contains( $query, 'tierza_groups' ) ) {
					return [
						[ 'Field' => 'id' ],
						[ 'Field' => 'name' ],
						[ 'Field' => 'slug' ],
						[ 'Field' => 'description' ],
						[ 'Field' => 'status' ],
						[ 'Field' => 'sort_order' ],
						[ 'Field' => 'created_at' ],
						[ 'Field' => 'updated_at' ],
					];
				}
				if ( str_contains( $query, 'tierza_rules' ) ) {
					return [
						[ 'Field' => 'id' ],
						[ 'Field' => 'target_type' ],
						[ 'Field' => 'target_id' ],
						[ 'Field' => 'scope_type' ],
						[ 'Field' => 'scope_id' ],
						[ 'Field' => 'pricing_type' ],
						[ 'Field' => 'value' ],
						[ 'Field' => 'status' ],
						[ 'Field' => 'active_key' ],
						[ 'Field' => 'created_at' ],
						[ 'Field' => 'updated_at' ],
					];
				}
				if ( str_contains( $query, 'tierza_user_groups' ) ) {
					return [
						[ 'Field' => 'id' ],
						[ 'Field' => 'user_id' ],
						[ 'Field' => 'group_id' ],
						[ 'Field' => 'created_at' ],
						[ 'Field' => 'updated_at' ],
					];
				}
			}

			if ( null !== $query && str_contains( $query, 'SHOW INDEX' ) ) {
				if ( str_contains( $query, 'tierza_groups' ) ) {
					return [
						[ 'Key_name' => 'PRIMARY' ],
						[ 'Key_name' => 'uq_slug' ],
						[ 'Key_name' => 'idx_status_sort' ],
					];
				}
				if ( str_contains( $query, 'tierza_rules' ) ) {
					return [
						[ 'Key_name' => 'PRIMARY' ],
						[ 'Key_name' => 'uq_active_rule' ],
						[ 'Key_name' => 'idx_target' ],
						[ 'Key_name' => 'idx_scope' ],
						[ 'Key_name' => 'idx_lookup' ],
					];
				}
				if ( str_contains( $query, 'tierza_user_groups' ) ) {
					return [
						[ 'Key_name' => 'PRIMARY' ],
						[ 'Key_name' => 'uq_user_id' ],
						[ 'Key_name' => 'idx_group_id' ],
					];
				}
			}

			return [];
		}

		public function query( string $query ): int|bool {
			return true;
		}

		/**
		 * @param array<string, mixed> $data
		 * @param array<string>|null $format
		 */
		public function insert( string $table, array $data, ?array $format = null ): int|bool {
			$this->insert_id++;
			return 1;
		}

		/**
		 * @param array<string, mixed> $data
		 * @param array<string, mixed> $where
		 * @param array<string>|null $format
		 * @param array<string>|null $where_format
		 */
		public function update( string $table, array $data, array $where, ?array $format = null, ?array $where_format = null ): int|bool {
			return 1;
		}

		/**
		 * @param array<string, mixed> $where
		 * @param array<string>|null $where_format
		 */
		public function delete( string $table, array $where, ?array $where_format = null ): int|bool {
			return 1;
		}

		public function esc_like( string $text ): string {
			return addcslashes( $text, '_%\\' );
		}
	}

	global $wpdb;
	$wpdb = new wpdb();
}

if ( ! function_exists( 'current_time' ) ) {
	function current_time( string $type, bool $gmt = false ): string {
		return gmdate( 'Y-m-d H:i:s' );
	}
}

if ( ! function_exists( 'dbDelta' ) ) {
	/**
	 * @param string|array<string> $queries
	 * @return array<string, string>
	 */
	function dbDelta( $queries = '', bool $execute = true ): array {
		return [];
	}
}

// Include plugin entry point to declare constants.
require_once dirname( __DIR__ ) . '/tierza.php';
