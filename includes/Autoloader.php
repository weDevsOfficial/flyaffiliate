<?php
/**
 * PSR-4 class autoloader.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loads FlyAffiliate's own classes.
 *
 * Composer is a development tool in this project — `vendor/` never ships
 * (ADR-0002) — so the plugin autoloads itself. `FlyAffiliate\Commission\Manager`
 * resolves to `includes/Commission/Manager.php`.
 *
 * @since FLYAFFILIATE_SINCE
 */
class Autoloader {

	/**
	 * Namespace prefix this autoloader answers for, with its trailing separator.
	 *
	 * @var string
	 */
	const PREFIX = 'FlyAffiliate\\';

	/**
	 * Absolute path of the directory the prefix maps onto, with a trailing slash.
	 *
	 * @var string
	 */
	protected string $base_directory;

	/**
	 * Construct the autoloader.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string $base_directory Directory the namespace prefix maps onto. Defaults to this file's directory.
	 */
	public function __construct( string $base_directory = '' ) {
		$this->base_directory = trailingslashit( '' !== $base_directory ? $base_directory : __DIR__ );
	}

	/**
	 * Register an instance of this autoloader with SPL.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string $base_directory Directory the namespace prefix maps onto.
	 *
	 * @return bool True when the autoloader was registered.
	 */
	public static function register( string $base_directory = '' ): bool {
		$autoloader = new self( $base_directory );

		return spl_autoload_register( [ $autoloader, 'load' ] );
	}

	/**
	 * Load the file defining a class, if this autoloader is responsible for it.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string $class_name Fully qualified class name.
	 *
	 * @return bool True when a file was loaded.
	 */
	public function load( string $class_name ): bool {
		if ( 0 !== strpos( $class_name, self::PREFIX ) ) {
			return false;
		}

		$relative = substr( $class_name, strlen( self::PREFIX ) );
		$path     = $this->base_directory . str_replace( '\\', DIRECTORY_SEPARATOR, $relative ) . '.php';

		if ( ! is_readable( $path ) ) {
			return false;
		}

		require_once $path;

		return true;
	}
}
