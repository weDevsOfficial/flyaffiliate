<?php
/**
 * Procedural helpers.
 *
 * Loaded on every request by `init_plugin()`, and by `activate()`. Everything here is a
 * thin, prefixed wrapper over a class; behaviour lives in the classes.
 *
 * @package FlyAffiliate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FlyAffiliate\Install\Installer as FlyAffiliate_Installer;

if ( ! function_exists( 'flyaffiliate_get_option' ) ) {
	/**
	 * Read one FlyAffiliate setting.
	 *
	 * Always use this rather than `get_option()`: every setting lives in the
	 * single `flyaffiliate_settings` option, keyed by field id, and the schema's
	 * default applies when a site has never saved the field.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string $id       Field id, as declared in `SettingsSchema`.
	 * @param mixed  $fallback Value to return when the field is neither stored nor declared.
	 *
	 * @return mixed
	 */
	function flyaffiliate_get_option( string $id, $fallback = null ) {
		return flyaffiliate()->settings->get( $id, $fallback );
	}
}

if ( ! function_exists( 'flyaffiliate_option_enabled' ) ) {
	/**
	 * Whether a switch setting is on.
	 *
	 * Switches store `'on'` / `'off'`, so a plain boolean cast would read every
	 * stored switch as true.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string $id Field id.
	 *
	 * @return bool
	 */
	function flyaffiliate_option_enabled( string $id ): bool {
		return flyaffiliate()->settings->is_enabled( $id );
	}
}

if ( ! function_exists( 'flyaffiliate_update_option' ) ) {
	/**
	 * Write one FlyAffiliate setting, through the field's rules and sanitizer.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string $id    Field id.
	 * @param mixed  $value Value.
	 *
	 * @return bool Whether the value was accepted.
	 */
	function flyaffiliate_update_option( string $id, $value ): bool {
		return true === flyaffiliate()->settings->update( $id, $value );
	}
}

if ( ! function_exists( 'flyaffiliate_template_path' ) ) {
	/**
	 * The directory inside a theme that overrides this plugin's templates.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return string Path fragment with a trailing slash.
	 */
	function flyaffiliate_template_path(): string {
		/**
		 * Filters the theme directory FlyAffiliate templates are overridden from.
		 *
		 * @since FLYAFFILIATE_SINCE
		 *
		 * @param string $path Path fragment with a trailing slash. Default `flyaffiliate/`.
		 */
		return apply_filters( 'flyaffiliate_template_path', 'flyaffiliate/' );
	}
}

if ( ! function_exists( 'flyaffiliate_locate_template' ) ) {
	/**
	 * Find a template, preferring the theme's copy.
	 *
	 * Lookup order: the child theme, then the parent theme, then the plugin.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string $template_name Template file name, relative to the templates directory.
	 *
	 * @return string Absolute path, or an empty string when the template does not exist.
	 */
	function flyaffiliate_locate_template( string $template_name ): string {
		$template_name = ltrim( $template_name, '/' );

		$template = locate_template( [ flyaffiliate_template_path() . $template_name ] );

		if ( '' === $template ) {
			$fallback = FLYAFFILIATE_TEMPLATE_DIR . '/' . $template_name;
			$template = file_exists( $fallback ) ? $fallback : '';
		}

		/**
		 * Filters the resolved path of a FlyAffiliate template.
		 *
		 * @since FLYAFFILIATE_SINCE
		 *
		 * @param string $template      Absolute path, or an empty string when not found.
		 * @param string $template_name The template that was asked for.
		 */
		return apply_filters( 'flyaffiliate_locate_template', $template, $template_name );
	}
}

if ( ! function_exists( 'flyaffiliate_get_template' ) ) {
	/**
	 * Render a template.
	 *
	 * The template receives `$args` as local variables. It renders — it does not
	 * query. The caller prepares the data.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string $template_name Template file name, relative to the templates directory.
	 * @param array  $args          Variables to extract into the template's scope.
	 *
	 * @return void
	 */
	function flyaffiliate_get_template( string $template_name, array $args = [] ): void {
		$template = flyaffiliate_locate_template( $template_name );

		if ( '' === $template ) {
			return;
		}

		if ( [] !== $args ) {
			// phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- the template contract is that $args become local variables, and the keys come from the calling code, never from a request.
			extract( $args, EXTR_SKIP );
		}

		/**
		 * Fires before a FlyAffiliate template is rendered.
		 *
		 * @since FLYAFFILIATE_SINCE
		 *
		 * @param string $template_name The template being rendered.
		 * @param array  $args          The variables passed to it.
		 */
		do_action( 'flyaffiliate_before_template', $template_name, $args );

		include $template;

		/**
		 * Fires after a FlyAffiliate template is rendered.
		 *
		 * @since FLYAFFILIATE_SINCE
		 *
		 * @param string $template_name The template that was rendered.
		 * @param array  $args          The variables passed to it.
		 */
		do_action( 'flyaffiliate_after_template', $template_name, $args );
	}
}

if ( ! function_exists( 'flyaffiliate_get_template_part' ) ) {
	/**
	 * Render a template by slug and optional name.
	 *
	 * `flyaffiliate_get_template_part( 'affiliate-dashboard/summary', 'compact' )`
	 * renders `affiliate-dashboard/summary-compact.php` when it exists, and
	 * `affiliate-dashboard/summary.php` otherwise.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string $slug Template slug.
	 * @param string $name Template variant.
	 * @param array  $args Variables to extract into the template's scope.
	 *
	 * @return void
	 */
	function flyaffiliate_get_template_part( string $slug, string $name = '', array $args = [] ): void {
		$template = '';

		if ( '' !== $name ) {
			$candidate = flyaffiliate_locate_template( "{$slug}-{$name}.php" );
			$template  = '' !== $candidate ? "{$slug}-{$name}.php" : '';
		}

		if ( '' === $template ) {
			$template = "{$slug}.php";
		}

		flyaffiliate_get_template( $template, $args );
	}
}

if ( ! function_exists( 'flyaffiliate_get_page_id' ) ) {
	/**
	 * The id of a page the installer created.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string $key Page key, `affiliate_dashboard` or `affiliate_register`.
	 *
	 * @return int The page id, or 0 when the page does not exist.
	 */
	function flyaffiliate_get_page_id( string $key ): int {
		$pages = get_option( FlyAffiliate_Installer::PAGES_OPTION, [] );
		$pages = is_array( $pages ) ? $pages : [];

		return isset( $pages[ $key ] ) ? (int) $pages[ $key ] : 0;
	}
}

if ( ! function_exists( 'flyaffiliate_get_page_url' ) ) {
	/**
	 * The permalink of a page the installer created.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string $key Page key.
	 *
	 * @return string The permalink, or an empty string when the page does not exist.
	 */
	function flyaffiliate_get_page_url( string $key ): string {
		$page_id = flyaffiliate_get_page_id( $key );

		if ( 0 === $page_id ) {
			return '';
		}

		return (string) get_permalink( $page_id );
	}
}

if ( ! function_exists( 'flyaffiliate_admin_capability' ) ) {
	/**
	 * The capability required to administer FlyAffiliate.
	 *
	 * `manage_options`: every administrator has it on every WordPress site, and
	 * nothing is written to roles on activation (ADR-0008, amended by ADR-0013).
	 * A store that wants shop managers in returns `manage_woocommerce` from the
	 * filter.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return string
	 */
	function flyaffiliate_admin_capability(): string {
		/**
		 * Filters the capability required to administer FlyAffiliate.
		 *
		 * @since FLYAFFILIATE_SINCE
		 *
		 * @param string $capability Capability name. Default `manage_options`.
		 */
		return (string) apply_filters( 'flyaffiliate_admin_capability', 'manage_options' );
	}
}

if ( ! function_exists( 'flyaffiliate_get_currency' ) ) {
	/**
	 * The currency FlyAffiliate shows amounts in.
	 *
	 * FlyAffiliate's own Currency setting, not WooCommerce's: the store currency
	 * is only the value it starts from.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return string An ISO 4217 code.
	 */
	function flyaffiliate_get_currency(): string {
		$code = strtoupper( (string) flyaffiliate_get_option( 'currency', '' ) );

		if ( '' === $code ) {
			$code = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD';
		}

		/**
		 * Filters the currency FlyAffiliate shows amounts in.
		 *
		 * @since FLYAFFILIATE_SINCE
		 *
		 * @param string $code The currency code.
		 */
		return (string) apply_filters( 'flyaffiliate_currency', $code );
	}
}

if ( ! function_exists( 'flyaffiliate_get_currency_symbol' ) ) {
	/**
	 * The symbol of a currency, as plain text.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string $code A currency code. Defaults to FlyAffiliate's currency.
	 *
	 * @return string
	 */
	function flyaffiliate_get_currency_symbol( string $code = '' ): string {
		$code   = '' !== $code ? $code : flyaffiliate_get_currency();
		$symbol = function_exists( 'get_woocommerce_currency_symbol' )
			? get_woocommerce_currency_symbol( $code )
			: ( \FlyAffiliate\Admin\Settings\Schema\SettingsSchema::builtin_currencies()[ $code ][1] ?? '' );

		return '' !== $symbol ? html_entity_decode( $symbol, ENT_QUOTES, 'UTF-8' ) : $code;
	}
}

if ( ! function_exists( 'flyaffiliate_get_currency_decimals' ) ) {
	/**
	 * How many decimal places a currency is written with.
	 *
	 * Two for most currencies; none for the yen and the others without minor
	 * units, three for the dinars that divide into a thousand.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string $code A currency code. Defaults to FlyAffiliate's currency.
	 *
	 * @return int
	 */
	function flyaffiliate_get_currency_decimals( string $code = '' ): int {
		$code     = '' !== $code ? $code : flyaffiliate_get_currency();
		$decimals = 2;

		if ( in_array( $code, [ 'BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF' ], true ) ) {
			$decimals = 0;
		} elseif ( in_array( $code, [ 'BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND' ], true ) ) {
			$decimals = 3;
		}

		/**
		 * Filters the decimal places of a currency.
		 *
		 * @since FLYAFFILIATE_SINCE
		 *
		 * @param int    $decimals Decimal places.
		 * @param string $code     The currency code.
		 */
		return (int) apply_filters( 'flyaffiliate_currency_decimals', $decimals, $code );
	}
}
