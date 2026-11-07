<?php

declare(strict_types=1);

namespace Tierza\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tierza\Infrastructure\Environment;

/**
 * Tests for Environment compatibility checks.
 */
class EnvironmentTest extends TestCase {

	public function test_php_version_is_compatible(): void {
		// Minimum is 8.1, runner is >= 8.1
		$this->assertTrue( Environment::is_php_compatible() );
	}

	public function test_wp_version_compatible_when_satisfied(): void {
		global $wp_version;
		$wp_version = '6.5.0';
		$this->assertTrue( Environment::is_wp_compatible() );

		$wp_version = '6.7.0';
		$this->assertTrue( Environment::is_wp_compatible() );
	}

	public function test_wp_version_incompatible_when_below_minimum(): void {
		global $wp_version;
		$wp_version = '6.4.9';
		$this->assertFalse( Environment::is_wp_compatible() );
	}

	public function test_woocommerce_active_detection(): void {
		// In pure unit test environment without WC class or plugin, should detect inactive safely.
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->assertFalse( Environment::is_woocommerce_active() );
			$this->assertFalse( Environment::is_woocommerce_compatible() );
		}
	}

	public function test_unmet_requirements_generates_notices_when_wc_inactive(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			$notices = Environment::get_unmet_requirements_notices();
			$this->assertNotEmpty( $notices );

			$joined = implode( ' ', $notices );
			$this->assertStringContainsString( 'WooCommerce', $joined );
		}
	}
}
