<?php

declare(strict_types=1);

namespace Tierza\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests verifying translation integrity, RTL rendering, and English fallback behavior.
 */
class LocalizationTest extends TestCase {

	/**
	 * Parses binary MO file and returns key-value translations.
	 *
	 * @return array<string, string>
	 */
	private function parse_mo_file( string $filepath ): array {
		$content = file_get_contents( $filepath );
		$this->assertIsString( $content );

		$header = unpack( 'Vmagic/Vformat/Vcount/Vorig_offset/Vtrans_offset', substr( $content, 0, 20 ) );
		$this->assertSame( 0x950412de, $header['magic'] );

		$count        = $header['count'];
		$orig_offset  = $header['orig_offset'];
		$trans_offset = $header['trans_offset'];

		$translations = [];

		for ( $i = 0; $i < $count; $i++ ) {
			$orig_info  = unpack( 'Vlength/Voffset', substr( $content, $orig_offset + ( $i * 8 ), 8 ) );
			$trans_info = unpack( 'Vlength/Voffset', substr( $content, $trans_offset + ( $i * 8 ), 8 ) );

			$original    = substr( $content, $orig_info['offset'], $orig_info['length'] );
			$translation = substr( $content, $trans_info['offset'], $trans_info['length'] );

			$translations[ $original ] = $translation;
		}

		return $translations;
	}

	public function test_persian_mo_file_has_translations(): void {
		$mo_file = TIERZA_PATH . 'languages/tierza-fa_IR.mo';
		$this->assertFileExists( $mo_file );

		$translations = $this->parse_mo_file( $mo_file );

		// Verify translation exists for key strings.
		$this->assertArrayHasKey( 'Tierza — B2B & Wholesale Pricing for WooCommerce', $translations );
		$this->assertSame( 'تیرزا — قیمت‌گذاری B2B و همکار ووکامرس', $translations['Tierza — B2B & Wholesale Pricing for WooCommerce'] );

		$this->assertArrayHasKey( 'Tierza Warning', $translations );
		$this->assertSame( 'هشدار تیرزا (Tierza)', $translations['Tierza Warning'] );

		$this->assertArrayHasKey( 'Tierza Plugin Error', $translations );
		$this->assertSame( 'خطای افزونه تیرزا (Tierza)', $translations['Tierza Plugin Error'] );
	}

	public function test_english_fallback_when_translation_missing(): void {
		// When a string is not translated or locale is English, the original text is returned.
		$source = 'This is an untranslated string';
		$this->assertSame( $source, __( $source, 'tierza' ) );
	}

	public function test_dashboard_renders_rtl_class_when_is_rtl_true(): void {
		global $wp_is_rtl;
		$wp_is_rtl = true;

		ob_start();
		require TIERZA_PATH . 'templates/admin/dashboard.php';
		$output = ob_get_clean();

		$this->assertIsString( $output );
		$this->assertStringContainsString( 'tierza-rtl', $output );
	}

	public function test_dashboard_renders_ltr_when_is_rtl_false(): void {
		global $wp_is_rtl;
		$wp_is_rtl = false;

		ob_start();
		require TIERZA_PATH . 'templates/admin/dashboard.php';
		$output = ob_get_clean();

		$this->assertIsString( $output );
		$this->assertStringNotContainsString( 'tierza-rtl', $output );
	}
}
