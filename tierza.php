<?php
/**
 * Plugin Name:       Tierza
 * Plugin URI:        https://github.com/tierza/tierza
 * Description:       افزونه قیمت‌گذاری پیشرفته B2B و همکار برای ووکامرس، ویژه کسب‌وکارهای ایرانی.
 * Version:           1.0.0
 * Author:            Tierza Team
 * Author URI:        https://tierza.org
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       tierza
 * Domain Path:       /languages
 * Requires at least: 6.2
 * Requires PHP:      8.0
 * WC requires at least: 8.0
 * WC tested up to:   9.4
 *
 * @package Tierza
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define core constants.
define( 'TIERZA_VERSION', '1.0.0' );
define( 'TIERZA_FILE', __FILE__ );
define( 'TIERZA_PATH', plugin_dir_path( __FILE__ ) );
define( 'TIERZA_URL', plugin_dir_url( __FILE__ ) );
define( 'TIERZA_BASENAME', plugin_basename( __FILE__ ) );

// Autoload dependencies: Composer vendor autoloader if present, plus fallback PSR-4 autoloader.
if ( file_exists( TIERZA_PATH . 'vendor/autoload.php' ) ) {
	require_once TIERZA_PATH . 'vendor/autoload.php';
} else {
	spl_autoload_register(
		static function ( string $class ): void {
			$prefix   = 'Tierza\\';
			$base_dir = TIERZA_PATH . 'src/';

			$len = strlen( $prefix );
			if ( strncmp( $prefix, $class, $len ) !== 0 ) {
				return;
			}

			$relative_class = substr( $class, $len );
			$file           = $base_dir . str_replace( '\\', '/', $relative_class ) . '.php';

			if ( file_exists( $file ) ) {
				require_once $file;
			}
		}
	);
}

// Register activation and deactivation hooks.
register_activation_hook( __FILE__, [ 'Tierza\\Plugin', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'Tierza\\Plugin', 'deactivate' ] );

// Hook into WordPress bootstrap.
add_action( 'plugins_loaded', [ 'Tierza\\Plugin', 'instance' ], 10 );
