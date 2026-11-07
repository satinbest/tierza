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

		// Check critical files inside the ZIP
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
		];

		foreach ( $expected_files as $file ) {
			$stat = $zip->statName( $file );
			$this->assertIsArray( $stat, "Expected file $file missing from generated ZIP archive." );
			$this->assertGreaterThan( 0, $stat['size'], "File $file inside ZIP is empty." );
		}

		$zip->close();
	}
}
