<?php

declare(strict_types=1);

namespace Tierza\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * Verifies that the distribution ZIP packager generates a valid installable WordPress plugin archive.
 */
class ZipBuildTest extends TestCase {

	public function test_zip_build_produces_valid_wordpress_archive(): void {
		$dist_dir = TIERZA_PATH . 'dist';
		$zip_path = $dist_dir . '/tierza.zip';

		// Run build packager
		$cmd = escapeshellcmd( PHP_BINARY ) . ' ' . escapeshellarg( TIERZA_PATH . 'bin/build-zip.php' );
		exec( $cmd, $output, $return_code );

		$this->assertSame( 0, $return_code, 'build-zip.php should exit with 0' );
		$this->assertFileExists( $zip_path );

		// Open and verify ZIP archive contents
		$zip = new ZipArchive();
		$open_result = $zip->open( $zip_path, ZipArchive::RDONLY );
		$this->assertTrue( $open_result, 'Failed to open generated ZIP file' );

		// Verify every entry starts with 'tierza/' root directory
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$entry_name = $zip->getNameIndex( $i );
			$this->assertIsString( $entry_name );
			$this->assertStringStartsWith( 'tierza/', $entry_name, "ZIP entry '$entry_name' does not start with tierza/ root directory." );
		}

		// Check critical runtime files inside the ZIP
		$expected_files = [
			'tierza/tierza.php',
			'tierza/readme.txt',
			'tierza/README.md',
			'tierza/CHANGELOG.md',
			'tierza/LICENSE',
			'tierza/composer.json',
			'tierza/src/Plugin.php',
			'tierza/src/Infrastructure/Environment.php',
			'tierza/src/Admin/AdminMenu.php',
			'tierza/templates/admin/dashboard.php',
			'tierza/assets/css/admin.css',
			'tierza/languages/tierza-fa_IR.mo',
			'tierza/languages/tierza-fa_IR.po',
			'tierza/languages/tierza.pot',
			'tierza/vendor/autoload.php',
		];

		foreach ( $expected_files as $file ) {
			$stat = $zip->statName( $file );
			$this->assertIsArray( $stat, "Expected file $file missing from generated ZIP archive." );
			$this->assertGreaterThan( 0, $stat['size'], "File $file inside ZIP is empty." );
		}

		// Verify dev and test files are strictly excluded
		$excluded_patterns = [
			'tierza/tests/',
			'tierza/phpunit.xml',
			'tierza/phpstan.neon',
			'tierza/.gitignore',
			'tierza/bin/',
			'tierza/.git/',
		];

		foreach ( $excluded_patterns as $pattern ) {
			$stat = $zip->statName( $pattern );
			$this->assertFalse( $stat, "Dev artifact '$pattern' must NOT be in the release ZIP." );
		}

		$zip->close();
	}

	public function test_standalone_zip_extraction_and_bootstrap(): void {
		$cmd = escapeshellcmd( PHP_BINARY ) . ' ' . escapeshellarg( TIERZA_PATH . 'bin/verify-standalone.php' );
		exec( $cmd, $output, $return_code );

		$this->assertSame( 0, $return_code, 'Standalone ZIP bootstrap verification must exit with code 0' );
	}
}
