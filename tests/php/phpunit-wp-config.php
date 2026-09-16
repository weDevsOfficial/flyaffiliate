<?php
/**
 * WordPress configuration for the PHPUnit run.
 *
 * Read by wp-phpunit. The database named here has every table with this prefix
 * dropped on each run, so it must never be a database anything else uses.
 *
 * @package FlyAffiliate
 */

$flyaffiliate_wordpress_dir = dirname( __DIR__, 2 ) . '/wordpress/';

if ( ! is_dir( $flyaffiliate_wordpress_dir ) ) {
	$flyaffiliate_wordpress_dir = dirname( __DIR__, 5 ) . '/';
}

define( 'ABSPATH', $flyaffiliate_wordpress_dir );

define( 'WP_DEFAULT_THEME', 'default' );
define( 'WP_DEBUG', true );

/**
 * Read a database setting from the environment, falling back to the wp-env default.
 *
 * @param string $name     Environment variable name.
 * @param string $fallback Value to use when the variable is unset or empty.
 *
 * @return string
 */
function flyaffiliate_tests_env( string $name, string $fallback ): string {
	$value = getenv( $name );

	return ( false !== $value && '' !== $value ) ? $value : $fallback;
}

define( 'DB_NAME', flyaffiliate_tests_env( 'WP_DB_NAME', 'wordpress' ) );
define( 'DB_USER', flyaffiliate_tests_env( 'WP_DB_USER', 'root' ) );
define( 'DB_PASSWORD', flyaffiliate_tests_env( 'WP_DB_PASS', 'password' ) );
define( 'DB_HOST', flyaffiliate_tests_env( 'WP_DB_HOST', 'mysql' ) );
define( 'DB_CHARSET', 'utf8' );
define( 'DB_COLLATE', '' );

define( 'AUTH_KEY', 'flyaffiliate tests' );
define( 'SECURE_AUTH_KEY', 'flyaffiliate tests' );
define( 'LOGGED_IN_KEY', 'flyaffiliate tests' );
define( 'NONCE_KEY', 'flyaffiliate tests' );
define( 'AUTH_SALT', 'flyaffiliate tests' );
define( 'SECURE_AUTH_SALT', 'flyaffiliate tests' );
define( 'LOGGED_IN_SALT', 'flyaffiliate tests' );
define( 'NONCE_SALT', 'flyaffiliate tests' );

$table_prefix = 'fatest_';

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'FlyAffiliate Tests' );

define( 'WP_PHP_BINARY', 'php' );
define( 'WPLANG', '' );
