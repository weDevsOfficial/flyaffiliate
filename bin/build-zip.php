<?php
/**
 * Build the WordPress.org release archive.
 *
 * Stages the shipping files into build/flyaffiliate/ honouring .distignore, then
 * zips that directory to build/flyaffiliate-v{version}.zip with `flyaffiliate/` as the single
 * top-level entry — the layout the wp.org SVN trunk expects.
 *
 * The staged directory is left in place on purpose: `npm run plugin-check` runs
 * Plugin Check against it, so the gate inspects exactly what ships.
 *
 * Usage: php bin/build-zip.php [--no-zip]
 *
 * @package FlyAffiliate
 */

declare( strict_types = 1 );

/**
 * The plugin slug: the archive's top-level directory and the zip's base name.
 *
 * @var string
 */
const FLYAFFILIATE_SLUG = 'flyaffiliate';

$root       = dirname( __DIR__ );
$build_dir  = $root . '/build';
$stage_dir  = $build_dir . '/' . FLYAFFILIATE_SLUG;
$zip_path   = ''; // Set once the version is known: build/flyaffiliate-v{version}.zip, as Dokan names its zip.
$entry_file = $root . '/' . FLYAFFILIATE_SLUG . '.php';
$no_zip     = in_array( '--no-zip', $argv, true );

if ( ! file_exists( $entry_file ) ) {
	fwrite( STDERR, "error: {$entry_file} does not exist — there is nothing to package yet.\n" );
	exit( 1 );
}

/*
 * The version from the plugin header. New code is documented with the literal
 * `@since FLYAFFILIATE_SINCE`; the zip is where it becomes the version it
 * first shipped in, so a docblock in the repository never guesses one.
 */
preg_match( '/^\s*\*\s*Version:\s*(\S+)/m', (string) file_get_contents( $entry_file ), $version_match );
$version = $version_match[1] ?? '';

if ( '' === $version ) {
	fwrite( STDERR, "error: no Version header in {$entry_file}.\n" );
	exit( 1 );
}

$zip_path = $build_dir . '/' . FLYAFFILIATE_SLUG . '-v' . $version . '.zip';

/**
 * Read .distignore into a list of patterns.
 *
 * @since FLYAFFILIATE_SINCE
 *
 * @param string $file Path to the .distignore file.
 *
 * @return string[]
 */
function flyaffiliate_read_distignore( string $file ): array {
	if ( ! is_readable( $file ) ) {
		return [];
	}

	$patterns = [];

	foreach ( file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) as $line ) {
		$line = trim( $line );

		if ( '' === $line || 0 === strpos( $line, '#' ) ) {
			continue;
		}

		$patterns[] = rtrim( $line, '/' );
	}

	return $patterns;
}

/**
 * Decide whether a repository-relative path is excluded from the archive.
 *
 * Mirrors the semantics WP-CLI's dist-archive command uses: a pattern anchored
 * with a leading slash matches from the plugin root, an unanchored pattern
 * matches any file or directory of that name anywhere in the tree.
 *
 * @since FLYAFFILIATE_SINCE
 *
 * @param string   $relative_path Path relative to the plugin root, forward slashes, no leading slash.
 * @param string[] $patterns      Patterns from .distignore.
 *
 * @return bool
 */
function flyaffiliate_is_ignored( string $relative_path, array $patterns ): bool {
	$base = basename( $relative_path );

	foreach ( $patterns as $pattern ) {
		// `/**/name` means "a file or directory called name, anywhere".
		if ( 0 === strpos( $pattern, '/**/' ) ) {
			$pattern = substr( $pattern, 4 );
		} elseif ( 0 === strpos( $pattern, '/' ) ) {
			// Anchored at the plugin root.
			$anchored = substr( $pattern, 1 );

			if ( $relative_path === $anchored
				|| 0 === strpos( $relative_path, $anchored . '/' )
				|| fnmatch( $anchored, $relative_path )
			) {
				return true;
			}

			continue;
		}

		if ( fnmatch( $pattern, $base ) || fnmatch( $pattern, $relative_path ) ) {
			return true;
		}

		// Unanchored directory name: exclude everything beneath it.
		foreach ( explode( '/', dirname( $relative_path ) ) as $segment ) {
			if ( fnmatch( $pattern, $segment ) ) {
				return true;
			}
		}
	}

	return false;
}

/**
 * Recursively delete a directory.
 *
 * @since FLYAFFILIATE_SINCE
 *
 * @param string $dir Directory to remove.
 *
 * @return void
 */
function flyaffiliate_rmdir( string $dir ): void {
	if ( ! is_dir( $dir ) ) {
		return;
	}

	$items = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);

	foreach ( $items as $item ) {
		$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
	}

	rmdir( $dir );
}

$patterns = flyaffiliate_read_distignore( $root . '/.distignore' );

flyaffiliate_rmdir( $stage_dir );

if ( ! is_dir( $stage_dir ) && ! mkdir( $stage_dir, 0755, true ) && ! is_dir( $stage_dir ) ) {
	fwrite( STDERR, "error: could not create {$stage_dir}\n" );
	exit( 1 );
}

$iterator = new RecursiveIteratorIterator(
	new RecursiveCallbackFilterIterator(
		new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
		static function ( SplFileInfo $file ) use ( $root, $patterns ): bool {
			$relative = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $root ) + 1 ) );

			return ! flyaffiliate_is_ignored( $relative, $patterns );
		}
	),
	RecursiveIteratorIterator::SELF_FIRST
);

$file_count = 0;
$byte_count = 0;

foreach ( $iterator as $file ) {
	$relative = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $root ) + 1 ) );
	$target   = $stage_dir . '/' . $relative;

	if ( $file->isDir() ) {
		if ( ! is_dir( $target ) && ! mkdir( $target, 0755, true ) && ! is_dir( $target ) ) {
			fwrite( STDERR, "error: could not create {$target}\n" );
			exit( 1 );
		}
		continue;
	}

	$parent = dirname( $target );

	if ( ! is_dir( $parent ) && ! mkdir( $parent, 0755, true ) && ! is_dir( $parent ) ) {
		fwrite( STDERR, "error: could not create {$parent}\n" );
		exit( 1 );
	}

	if ( 'php' === strtolower( $file->getExtension() ) ) {
		// Ship a real version where the source carries the placeholder.
		file_put_contents( $target, str_replace( 'FLYAFFILIATE_SINCE', $version, (string) file_get_contents( $file->getPathname() ) ) );
	} else {
		copy( $file->getPathname(), $target );
	}

	++$file_count;
	$byte_count += $file->getSize();
}

flyaffiliate_prune_empty_dirs( $stage_dir );

/*
 * A production vendor/ for the zip: Composer's autoloader and nothing else,
 * since the plugin has no runtime packages. The local vendor/ (with the dev
 * tools) is never copied; Composer builds a fresh one inside the stage, the
 * way Dokan's bin/zip.js does.
 */
foreach ( [ 'composer.json', 'composer.lock' ] as $composer_file ) {
	if ( file_exists( $root . '/' . $composer_file ) ) {
		copy( $root . '/' . $composer_file, $stage_dir . '/' . $composer_file );
	}
}

$composer_command = sprintf(
	'composer install --no-dev --optimize-autoloader --no-interaction --no-progress --quiet --working-dir=%s 2>&1',
	escapeshellarg( $stage_dir )
);
// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- a build script on a developer's or CI machine, running Composer.
exec( $composer_command, $composer_output, $composer_status );

if ( 0 !== $composer_status || ! file_exists( $stage_dir . '/vendor/autoload.php' ) ) {
	fwrite( STDERR, "error: composer could not build the production autoloader:\n" . implode( "\n", $composer_output ) . "\n" );
	exit( 1 );
}

// Plugin Check expects composer.json wherever a vendor/ directory ships; the
// lock file is a development artefact and stays out.
if ( file_exists( $stage_dir . '/composer.lock' ) ) {
	unlink( $stage_dir . '/composer.lock' );
}

printf( "Staged %d files (%s) in %s\n", $file_count, flyaffiliate_format_bytes( $byte_count ), $stage_dir );

if ( $no_zip ) {
	exit( 0 );
}

if ( ! class_exists( 'ZipArchive' ) ) {
	fwrite( STDERR, "error: the PHP zip extension is required to build the archive.\n" );
	exit( 1 );
}

if ( file_exists( $zip_path ) ) {
	unlink( $zip_path );
}

$zip = new ZipArchive();

if ( true !== $zip->open( $zip_path, ZipArchive::CREATE ) ) {
	fwrite( STDERR, "error: could not open {$zip_path} for writing.\n" );
	exit( 1 );
}

$stage_files = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( $stage_dir, FilesystemIterator::SKIP_DOTS ),
	RecursiveIteratorIterator::SELF_FIRST
);

foreach ( $stage_files as $file ) {
	$relative = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $build_dir ) + 1 ) );

	if ( $file->isDir() ) {
		$zip->addEmptyDir( $relative );
		continue;
	}

	$zip->addFile( $file->getPathname(), $relative );
}

$zip->close();

printf( "Built %s (%s)\n", $zip_path, flyaffiliate_format_bytes( (int) filesize( $zip_path ) ) );

/**
 * Remove directories left empty by the .distignore filter.
 *
 * A directory whose only contents were excluded would otherwise be staged, and
 * then archived, as an empty entry.
 *
 * @since FLYAFFILIATE_SINCE
 *
 * @param string $dir Directory to prune, kept itself.
 *
 * @return void
 */
function flyaffiliate_prune_empty_dirs( string $dir ): void {
	$children = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);

	foreach ( $children as $child ) {
		if ( $child->isDir() && ! ( new FilesystemIterator( $child->getPathname() ) )->valid() ) {
			rmdir( $child->getPathname() );
		}
	}
}

/**
 * Format a byte count for humans.
 *
 * @since FLYAFFILIATE_SINCE
 *
 * @param int $bytes Number of bytes.
 *
 * @return string
 */
function flyaffiliate_format_bytes( int $bytes ): string {
	$units     = [ 'B', 'KB', 'MB' ];
	$max_index = count( $units ) - 1;
	$index     = 0;

	while ( $bytes >= 1024 && $index < $max_index ) {
		$bytes /= 1024;
		++$index;
	}

	return sprintf( '%.1f %s', $bytes, $units[ $index ] );
}
