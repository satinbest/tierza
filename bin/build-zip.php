<?php
/**
 * Tierza Release ZIP Packager.
 * Builds an installable WordPress plugin ZIP file in dist/tierza.zip.
 */

declare(strict_types=1);

$project_root = dirname( __DIR__ );
$dist_dir     = $project_root . '/dist';
$zip_file     = $dist_dir . '/tierza.zip';

echo "Building Tierza release ZIP...\n";

if ( ! is_dir( $dist_dir ) ) {
	mkdir( $dist_dir, 0755, true );
}

if ( file_exists( $zip_file ) ) {
	unlink( $zip_file );
}

// 1. Compile MO files first.
require_once __DIR__ . '/compile-mo.php';

// 2. Dump clean production autoloader (no dev dependencies).
echo "Generating clean production autoloader...\n";
exec( 'composer dump-autoload --no-dev --optimize' );

$zip = new ZipArchive();
if ( true !== $zip->open( $zip_file, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
	fwrite( STDERR, "Failed to create ZIP at: $zip_file\n" );
	// Restore dev autoloader before exiting.
	exec( 'composer dump-autoload' );
	exit( 1 );
}

$included_files = [
	'tierza.php',
	'readme.txt',
	'README.md',
	'CHANGELOG.md',
	'LICENSE',
	'composer.json',
];

$included_directories = [
	'src',
	'templates',
	'assets',
	'languages',
];

// Add single files.
foreach ( $included_files as $file ) {
	$full_path = $project_root . '/' . $file;
	if ( file_exists( $full_path ) ) {
		$zip->addFile( $full_path, 'tierza/' . $file );
	}
}

// Add directory contents recursively.
foreach ( $included_directories as $dir ) {
	$dir_path = $project_root . '/' . $dir;
	if ( ! is_dir( $dir_path ) ) {
		continue;
	}

	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $dir_path, RecursiveDirectoryIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::SELF_FIRST
	);

	foreach ( $iterator as $item ) {
		$relative_path = substr( $item->getPathname(), strlen( $project_root ) + 1 );
		$relative_path = str_replace( '\\', '/', $relative_path );

		if ( $item->isDir() ) {
			$zip->addEmptyDir( 'tierza/' . $relative_path );
		} else {
			$zip->addFile( $item->getPathname(), 'tierza/' . $relative_path );
		}
	}
}

// Include production vendor autoload and class maps.
$vendor_autoload = $project_root . '/vendor/autoload.php';
$vendor_composer = $project_root . '/vendor/composer';
if ( file_exists( $vendor_autoload ) && is_dir( $vendor_composer ) ) {
	$zip->addFile( $vendor_autoload, 'tierza/vendor/autoload.php' );
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $vendor_composer, RecursiveDirectoryIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::SELF_FIRST
	);
	foreach ( $iterator as $item ) {
		$relative_path = substr( $item->getPathname(), strlen( $project_root ) + 1 );
		$relative_path = str_replace( '\\', '/', $relative_path );
		if ( ! $item->isDir() ) {
			$zip->addFile( $item->getPathname(), 'tierza/' . $relative_path );
		}
	}
}

$zip_status = $zip->close();

// Restore dev autoload for testing & analysis.
echo "Restoring development autoloader...\n";
exec( 'composer dump-autoload' );

if ( ! $zip_status ) {
	fwrite( STDERR, "Failed to close and save ZIP.\n" );
	exit( 1 );
}

$size_kb = round( filesize( $zip_file ) / 1024, 2 );
echo "Successfully generated: dist/tierza.zip ({$size_kb} KB)\n";
