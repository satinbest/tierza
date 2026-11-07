<?php
/**
 * Standalone production installation verification script.
 * Simulates extraction of the ZIP into a clean environment without any composer/git.
 */

declare(strict_types=1);

$zip_path = __DIR__ . '/../dist/tierza.zip';
if ( ! file_exists( $zip_path ) ) {
	fwrite( STDERR, "Error: dist/tierza.zip not found.\n" );
	exit( 1 );
}

$temp_dir = sys_get_temp_dir() . '/tierza_smoke_' . bin2hex( random_bytes( 4 ) );
mkdir( $temp_dir, 0755, true );

$zip = new ZipArchive();
if ( true !== $zip->open( $zip_path ) ) {
	fwrite( STDERR, "Error: Failed to open ZIP.\n" );
	exit( 1 );
}
$zip->extractTo( $temp_dir );
$zip->close();

echo "1. Extracted release ZIP to $temp_dir\n";

$plugin_entry = $temp_dir . '/tierza/tierza.php';
if ( ! file_exists( $plugin_entry ) ) {
	fwrite( STDERR, "Error: tierza/tierza.php missing from ZIP.\n" );
	exit( 1 );
}
echo "2. Found plugin entry file: tierza/tierza.php\n";

// WordPress environment mock.
define( 'ABSPATH', $temp_dir . '/tierza/' );

$wp_actions = [];
function add_action( $hook, $cb ) {
	global $wp_actions;
	$wp_actions[ $hook ][] = $cb;
}
function register_activation_hook( $file, $cb ) {}
function register_deactivation_hook( $file, $cb ) {}
function plugin_dir_path( $file ) { return dirname( $file ) . '/'; }
function plugin_dir_url( $file ) { return 'https://example.com/wp-content/plugins/tierza/'; }
function plugin_basename( $file ) { return 'tierza/' . basename( $file ); }
function load_plugin_textdomain( $domain, $deprecated = false, $plugin_rel_path = false ) { return true; }
function __( $str, $domain = 'default' ) { return $str; }
function esc_html__( $str, $domain = 'default' ) { return $str; }
function esc_html_e( $str, $domain = 'default' ) { echo $str; }
function esc_html( $str ) { return $str; }
function update_option( $k, $v ) { return true; }
function is_admin() { return true; }
function is_rtl() { return true; }
function current_user_can( $c ) { return true; }
function wp_die( $msg = '' ) { throw new RuntimeException( $msg ); }

// Load plugin entry.
require_once $plugin_entry;
echo "3. Included tierza.php with zero errors\n";

// Verify autoloader resolved Tierza\Plugin.
if ( ! class_exists( 'Tierza\\Plugin' ) ) {
	fwrite( STDERR, "Error: Tierza\\Plugin class could not be autoloaded.\n" );
	exit( 1 );
}
echo "4. Autoloader successfully resolved Tierza\\Plugin\n";

// Test bootstrap when WooCommerce is INACTIVE.
$plugin = Tierza\Plugin::instance();
echo "5. Plugin instance created cleanly without WooCommerce active\n";

// Verify warning notice was hooked.
if ( empty( $wp_actions['admin_notices'] ) ) {
	fwrite( STDERR, "Error: Expected admin_notices to be hooked when WooCommerce is missing.\n" );
	exit( 1 );
}
echo "6. Missing WooCommerce warning notice correctly hooked\n";

// Execute notice rendering to verify no fatal errors.
ob_start();
$plugin->render_woocommerce_notice();
$notice_html = ob_get_clean();
if ( false === strpos( $notice_html, 'notice-warning' ) ) {
	fwrite( STDERR, "Error: Warning notice HTML does not contain notice-warning class.\n" );
	exit( 1 );
}
echo "7. Rendered WooCommerce inactive notice safely: " . trim( strip_tags( $notice_html ) ) . "\n";

// Clean up.
$files = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( $temp_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
	RecursiveIteratorIterator::CHILD_FIRST
);
foreach ( $files as $fileinfo ) {
	$todo = ( $fileinfo->isDir() ? 'rmdir' : 'unlink' );
	$todo( $fileinfo->getRealPath() );
}
rmdir( $temp_dir );
echo "8. Standalone extraction verification complete!\n";
