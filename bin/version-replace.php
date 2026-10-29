<?php
/**
 * Stamp the release version on the code that first ships in it.
 *
 * Replaces every `FLYAFFILIATE_SINCE` placeholder in the shipping sources with
 * the version in package.json, as Dokan's bin/version-replace.js does. Run it
 * in the release commit, after the version moved: what the next release adds
 * carries the placeholder again, so its zip stamps only its own code.
 *
 * Usage: php bin/version-replace.php
 *
 * @package FlyAffiliate
 */

declare( strict_types = 1 );

$root = dirname( __DIR__ );

$package = json_decode( (string) file_get_contents( $root . '/package.json' ), true );
$version = is_array( $package ) ? (string) ( $package['version'] ?? '' ) : '';

if ( '' === $version ) {
	fwrite( STDERR, "error: no version in package.json.\n" );
	exit( 1 );
}

// Dokan's list; bin/ and tests/ never ship, and build-zip.php needs the literal.
$sources = [ 'assets/src', 'includes', 'templates', 'src', 'flyaffiliate.php', 'flyaffiliate-class.php', 'uninstall.php' ];
$changed = 0;

foreach ( $sources as $source ) {
	$target = $root . '/' . $source;

	if ( is_file( $target ) ) {
		$files = [ new SplFileInfo( $target ) ];
	} elseif ( is_dir( $target ) ) {
		$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $target, FilesystemIterator::SKIP_DOTS ) );
	} else {
		continue;
	}

	foreach ( $files as $file ) {
		$contents = (string) file_get_contents( $file->getPathname() );

		if ( ! str_contains( $contents, 'FLYAFFILIATE_SINCE' ) ) {
			continue;
		}

		file_put_contents( $file->getPathname(), str_replace( 'FLYAFFILIATE_SINCE', $version, $contents ) );
		++$changed;
	}
}

printf( "Stamped %s on %d files\n", $version, $changed );
