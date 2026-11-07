<?php

declare(strict_types=1);

namespace Tierza\Infrastructure;

/**
 * Handles environment requirement checks and compatibility verifications.
 */
class Environment {

	public const MIN_PHP_VERSION = '8.0.0';
	public const MIN_WP_VERSION  = '6.2';
	public const MIN_WC_VERSION  = '8.0.0';

	/**
	 * Verify if the current PHP version satisfies the minimum requirement.
	 */
	public static function is_php_compatible(): bool {
		return version_compare( PHP_VERSION, self::MIN_PHP_VERSION, '>=' );
	}

	/**
	 * Verify if WordPress satisfies the minimum requirement.
	 */
	public static function is_wp_compatible(): bool {
		global $wp_version;
		$current_wp = $wp_version ?? '0.0.0';
		return version_compare( $current_wp, self::MIN_WP_VERSION, '>=' );
	}

	/**
	 * Check if WooCommerce is currently installed and active.
	 */
	public static function is_woocommerce_active(): bool {
		if ( class_exists( 'WooCommerce' ) ) {
			return true;
		}

		if ( ! function_exists( 'is_plugin_active' ) ) {
			$admin_includes = ABSPATH . 'wp-admin/includes/plugin.php';
			if ( file_exists( $admin_includes ) ) {
				require_once $admin_includes;
			}
		}

		if ( function_exists( 'is_plugin_active' ) ) {
			return is_plugin_active( 'woocommerce/woocommerce.php' );
		}

		return false;
	}

	/**
	 * Check if the active WooCommerce version satisfies the minimum version.
	 */
	public static function is_woocommerce_compatible(): bool {
		if ( ! self::is_woocommerce_active() ) {
			return false;
		}

		$wc_version = self::get_woocommerce_version();
		if ( null === $wc_version ) {
			return false;
		}

		return version_compare( $wc_version, self::MIN_WC_VERSION, '>=' );
	}

	/**
	 * Retrieve current WooCommerce version if available.
	 */
	public static function get_woocommerce_version(): ?string {
		if ( defined( 'WC_VERSION' ) ) {
			return WC_VERSION;
		}

		if ( function_exists( 'WC' ) && isset( WC()->version ) ) {
			return (string) WC()->version;
		}

		return null;
	}

	/**
	 * Check whether all environment requirements are met.
	 */
	public static function is_environment_compatible(): bool {
		return self::is_php_compatible() && self::is_wp_compatible() && self::is_woocommerce_compatible();
	}

	/**
	 * Get an array of unmet requirement error messages (Persian and English).
	 *
	 * @return array<int, string>
	 */
	public static function get_unmet_requirements_notices(): array {
		$notices = [];

		if ( ! self::is_php_compatible() ) {
			$notices[] = sprintf(
				/* translators: 1: Required PHP version, 2: Current PHP version */
				__( 'تیرزا نیازمند پی‌اچ‌پی نسخه %1$s یا بالاتر است. نسخه فعلی سرور شما: %2$s', 'tierza' ),
				self::MIN_PHP_VERSION,
				PHP_VERSION
			);
		}

		if ( ! self::is_wp_compatible() ) {
			global $wp_version;
			$notices[] = sprintf(
				/* translators: 1: Required WP version, 2: Current WP version */
				__( 'تیرزا نیازمند وردپرس نسخه %1$s یا بالاتر است. نسخه فعلی وردپرس شما: %2$s', 'tierza' ),
				self::MIN_WP_VERSION,
				$wp_version ?? 'unknown'
			);
		}

		if ( ! self::is_woocommerce_active() ) {
			$notices[] = __( 'تیرزا برای فعالیت به افزونه ووکامرس (WooCommerce) نیاز دارد. لطفاً ووکامرس را نصب و فعال نمایید.', 'tierza' );
		} elseif ( ! self::is_woocommerce_compatible() ) {
			$notices[] = sprintf(
				/* translators: 1: Required WooCommerce version, 2: Current WooCommerce version */
				__( 'تیرزا نیازمند ووکامرس نسخه %1$s یا بالاتر است. نسخه فعلی ووکامرس شما: %2$s', 'tierza' ),
				self::MIN_WC_VERSION,
				self::get_woocommerce_version() ?? 'unknown'
			);
		}

		return $notices;
	}
}
