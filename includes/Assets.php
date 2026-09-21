<?php
/**
 * Script and style registration.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FlyAffiliate\Admin\Menu;
use FlyAffiliate\Admin\PayoutExport;
use FlyAffiliate\Admin\SetupWizard;
use FlyAffiliate\Contracts\Hookable;
use FlyAffiliate\Models\Affiliate;
use FlyAffiliate\Models\Commission;
use FlyAffiliate\Models\Payout;

/**
 * Registers every FlyAffiliate script and stylesheet, and enqueues them only on
 * the screens that need them.
 *
 * Two React bundles ship: the admin app (mounted on the FlyAffiliate menu
 * page, the setup wizard included) and the affiliate dashboard app (mounted by
 * its shortcode). The registration form and the dashboard's PHP-rendered
 * states share one plain stylesheet. Nothing is loaded from a remote host, and
 * nothing is emitted as an inline `<style>` or `<script>` block — both are
 * rejected by WordPress.org. Data reaches JavaScript through
 * `wp_add_inline_script()`, attached to a handle that was registered here.
 *
 * @since FLYAFFILIATE_SINCE
 */
class Assets implements Hookable {

	/**
	 * Prefix every handle carries.
	 *
	 * @var string
	 */
	const HANDLE_PREFIX = 'flyaffiliate-';

	/**
	 * {@inheritDoc}
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'admin_enqueue_scripts', [ $this, 'register_assets' ], 5 );
		add_action( 'wp_enqueue_scripts', [ $this, 'register_assets' ], 5 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
	}

	/**
	 * Register — but do not enqueue — everything this plugin ships.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return void
	 */
	public function register_assets(): void {
		foreach ( $this->get_styles() as $handle => $style ) {
			wp_register_style( $handle, $style['src'], $style['deps'], $style['version'] );

			// The build writes an `-rtl.css` twin next to a stylesheet; WordPress
			// swaps it in on right-to-left sites once told it exists.
			if ( ! empty( $style['rtl'] ) ) {
				wp_style_add_data( $handle, 'rtl', 'replace' );
			}
		}

		foreach ( $this->get_scripts() as $handle => $script ) {
			wp_register_script( $handle, $script['src'], $script['deps'], $script['version'], true );
			wp_set_script_translations( $handle, 'flyaffiliate', FLYAFFILIATE_DIR . '/languages' );
		}
	}

	/**
	 * The stylesheets this plugin ships.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return array<string, array{src: string, deps: string[], version: string, rtl: bool}>
	 */
	public function get_styles(): array {
		$styles = [
			// The admin app's stylesheet is extracted from the JS bundle by the
			// build, which is why it sits next to admin.js.
			self::HANDLE_PREFIX . 'admin'    => [
				'src'     => FLYAFFILIATE_ASSETS_URL . '/css/admin.css',
				'deps'    => [ 'wp-components' ],
				'version' => $this->get_version( '/assets/css/admin.css' ),
				'rtl'     => file_exists( FLYAFFILIATE_DIR . '/assets/css/admin-rtl.css' ),
			],
			self::HANDLE_PREFIX . 'frontend' => [
				'src'     => FLYAFFILIATE_ASSETS_URL . '/css/frontend.css',
				'deps'    => [],
				'version' => $this->get_version( '/assets/css/frontend.css' ),
				'rtl'     => file_exists( FLYAFFILIATE_DIR . '/assets/css/frontend-rtl.css' ),
			],
			// The affiliate dashboard app's stylesheet, extracted like the admin one.
			self::HANDLE_PREFIX . 'dashboard' => [
				'src'     => FLYAFFILIATE_ASSETS_URL . '/css/dashboard.css',
				'deps'    => [ 'wp-components' ],
				'version' => $this->get_version( '/assets/css/dashboard.css' ),
				'rtl'     => file_exists( FLYAFFILIATE_DIR . '/assets/css/dashboard-rtl.css' ),
			],
		];

		/**
		 * Filters the stylesheets FlyAffiliate registers.
		 *
		 * @since FLYAFFILIATE_SINCE
		 *
		 * @param array $styles Handle => definition.
		 */
		return apply_filters( 'flyaffiliate_styles', $styles );
	}

	/**
	 * The scripts this plugin ships.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return array<string, array{src: string, deps: string[], version: string}>
	 */
	public function get_scripts(): array {
		$scripts = [
			self::HANDLE_PREFIX . 'admin'    => [
				'src'     => FLYAFFILIATE_ASSETS_URL . '/js/admin.js',
				'deps'    => $this->get_script_dependencies( '/assets/js/admin.asset.php' ),
				'version' => $this->get_version( '/assets/js/admin.js', '/assets/js/admin.asset.php' ),
			],
			self::HANDLE_PREFIX . 'dashboard' => [
				'src'     => FLYAFFILIATE_ASSETS_URL . '/js/dashboard.js',
				'deps'    => $this->get_script_dependencies( '/assets/js/dashboard.asset.php' ),
				'version' => $this->get_version( '/assets/js/dashboard.js', '/assets/js/dashboard.asset.php' ),
			],
		];

		/**
		 * Filters the scripts FlyAffiliate registers.
		 *
		 * @since FLYAFFILIATE_SINCE
		 *
		 * @param array $scripts Handle => definition.
		 */
		return apply_filters( 'flyaffiliate_scripts', $scripts );
	}

	/**
	 * Enqueue the admin app on its page.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return void
	 */
	public function enqueue_admin_assets(): void {
		$page = $this->get_current_page();

		if ( Menu::PARENT_SLUG !== $page ) {
			return;
		}

		wp_enqueue_media();
		wp_enqueue_style( self::HANDLE_PREFIX . 'admin' );
		wp_enqueue_script( self::HANDLE_PREFIX . 'admin' );

		wp_add_inline_script(
			self::HANDLE_PREFIX . 'admin',
			'window.flyaffiliate = ' . wp_json_encode( $this->get_admin_script_data() ) . ';',
			'before'
		);
	}

	/**
	 * Enqueue the affiliate dashboard app.
	 *
	 * Called by the dashboard shortcode, so the bundle only loads on the page
	 * that renders it and only for an affiliate who can see it.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string $tab    The tab to open first.
	 * @param string $notice A status flag from a redirect, or an empty string.
	 *
	 * @return void
	 */
	public function enqueue_dashboard_assets( string $tab = 'overview', string $notice = '' ): void {
		wp_enqueue_style( self::HANDLE_PREFIX . 'dashboard' );
		wp_enqueue_script( self::HANDLE_PREFIX . 'dashboard' );

		wp_add_inline_script(
			self::HANDLE_PREFIX . 'dashboard',
			'window.flyaffiliate = ' . wp_json_encode( $this->get_dashboard_script_data( $tab, $notice ) ) . ';',
			'before'
		);
	}

	/**
	 * The data the affiliate dashboard needs before it can render.
	 *
	 * A subset of the admin data: no admin URLs, nothing about other affiliates.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string $tab    The tab to open first.
	 * @param string $notice A status flag from a redirect, or an empty string.
	 *
	 * @return array<string, mixed>
	 */
	public function get_dashboard_script_data( string $tab = 'overview', string $notice = '' ): array {
		$admin = $this->get_admin_script_data();
		$data  = [
			'version'    => $admin['version'],
			'restNonce'  => $admin['restNonce'],
			'currency'   => $admin['currency'],
			'statuses'   => $admin['statuses'],
			'sources'    => $admin['sources'],
			'types'      => $admin['types'],
			'dashboard'  => [
				'tab'    => $tab,
				'notice' => $notice,
			],
			'program'    => [
				'rate'          => (float) flyaffiliate_get_option( 'default_rate', 10 ),
				'rateType'      => (string) flyaffiliate_get_option( 'rate_type', 'percentage' ),
				'cookieDays'    => absint( flyaffiliate_get_option( 'cookie_duration', 30 ) ),
				'holdDays'      => absint( flyaffiliate_get_option( 'hold_days', 30 ) ),
				'payoutMinimum' => (float) flyaffiliate_get_option( 'minimum_amount', 0 ),
			],
		];

		/**
		 * Filters the data exposed to the affiliate dashboard as `window.flyaffiliate`.
		 *
		 * @since FLYAFFILIATE_SINCE
		 *
		 * @param array $data The data.
		 */
		return apply_filters( 'flyaffiliate_dashboard_script_data', $data );
	}

	/**
	 * The data the admin app needs before it can render.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return array<string, mixed>
	 */
	public function get_admin_script_data(): array {
		$data = [
			'version'    => FLYAFFILIATE_VERSION,
			'restNonce'  => wp_create_nonce( 'wp_rest' ),
			'currency'   => $this->get_currency_data(),
			'settings'   => [
				'payoutMinimum' => (float) flyaffiliate_get_option( 'minimum_amount', 0 ),
			],
			'urls'       => [
				'app'       => Menu::get_route_url(),
				'logo'      => FLYAFFILIATE_ASSETS_URL . '/images/logo.svg',
				'wizard'    => SetupWizard::get_url(),
				'docs'      => 'https://wedevs.com/docs/flyaffiliate/',
				'support'   => 'https://wedevs.com/support/',
				'payoutCsv' => PayoutExport::get_url(),
				'users'     => admin_url( 'user-edit.php?user_id=' ),
				'orders'    => admin_url( 'admin.php?page=wc-orders&action=edit&id=' ),
				'products'  => admin_url( 'post.php?action=edit&post=' ),
			],
			'setup'      => [
				'done'  => SetupWizard::is_done(),
				'pages' => [
					'register'  => flyaffiliate_get_page_url( 'affiliate_register' ),
					'dashboard' => flyaffiliate_get_page_url( 'affiliate_dashboard' ),
				],
			],
			'statuses'   => [
				'affiliate'  => [
					Affiliate::STATUS_PENDING   => __( 'Pending', 'flyaffiliate' ),
					Affiliate::STATUS_ACTIVE    => __( 'Active', 'flyaffiliate' ),
					Affiliate::STATUS_INACTIVE  => __( 'Inactive', 'flyaffiliate' ),
					Affiliate::STATUS_SUSPENDED => __( 'Suspended', 'flyaffiliate' ),
				],
				'commission' => [
					Commission::STATUS_PENDING  => __( 'Pending', 'flyaffiliate' ),
					Commission::STATUS_UNPAID   => __( 'Unpaid', 'flyaffiliate' ),
					Commission::STATUS_PAID     => __( 'Paid', 'flyaffiliate' ),
					Commission::STATUS_REJECTED => __( 'Rejected', 'flyaffiliate' ),
				],
			],
			'sources'    => Commission::get_sources(),
			// The origins open to a new commission: manual, plus whatever integration is active.
			'availableSources' => Commission::get_available_sources(),
			'types'      => Commission::get_types(),
			'methods'    => Payout::get_methods(),
		];

		/**
		 * Filters the data exposed to the FlyAffiliate admin app as `window.flyaffiliate`.
		 *
		 * @since FLYAFFILIATE_SINCE
		 *
		 * @param array $data The data.
		 */
		return apply_filters( 'flyaffiliate_admin_script_data', $data );
	}

	/**
	 * How FlyAffiliate's Currency settings write money, so the apps do the same.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return array{code: string, symbol: string, position: string, decimals: int, decimalSeparator: string, thousandSeparator: string}
	 */
	protected function get_currency_data(): array {
		$code = flyaffiliate_get_currency();

		return [
			'code'              => $code,
			'symbol'            => flyaffiliate_get_currency_symbol( $code ),
			'position'          => (string) flyaffiliate_get_option( 'currency_position', 'left' ),
			'decimals'          => flyaffiliate_get_currency_decimals( $code ),
			'decimalSeparator'  => (string) flyaffiliate_get_option( 'currency_decimal_separator', '.' ),
			'thousandSeparator' => (string) flyaffiliate_get_option( 'currency_thousands_separator', ',' ),
		];
	}

	/**
	 * The `page` query argument of the current admin request.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return string
	 */
	protected function get_current_page(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading the page slug to decide what to enqueue changes nothing.
		return isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
	}

	/**
	 * A cache-busting version for an asset.
	 *
	 * A bundle's `.asset.php` carries a hash of its contents, which changes with
	 * every build; that is the best version there is. Without one, the built
	 * file's modification time is used while `SCRIPT_DEBUG` is on, and the
	 * plugin version otherwise.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string $relative_path Path relative to the plugin directory, with a leading slash.
	 * @param string $asset_file    Path to the bundle's `.asset.php`, with a leading slash. Optional.
	 *
	 * @return string
	 */
	protected function get_version( string $relative_path, string $asset_file = '' ): string {
		if ( '' !== $asset_file && file_exists( FLYAFFILIATE_DIR . $asset_file ) ) {
			$asset = require FLYAFFILIATE_DIR . $asset_file;

			if ( ! empty( $asset['version'] ) ) {
				return (string) $asset['version'];
			}
		}

		$file = FLYAFFILIATE_DIR . $relative_path;

		if ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG && file_exists( $file ) ) {
			return (string) filemtime( $file );
		}

		return FLYAFFILIATE_VERSION;
	}

	/**
	 * The WordPress script handles a built bundle depends on.
	 *
	 * `@wordpress/scripts` writes these into a `.asset.php` file next to the
	 * bundle. A missing file means the assets were never built, which is a
	 * development state, not a runtime one.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string $relative_path Path to the `.asset.php` file, with a leading slash.
	 *
	 * @return string[]
	 */
	protected function get_script_dependencies( string $relative_path ): array {
		$file = FLYAFFILIATE_DIR . $relative_path;

		if ( ! file_exists( $file ) ) {
			return [];
		}

		$asset = require $file;

		return isset( $asset['dependencies'] ) && is_array( $asset['dependencies'] ) ? $asset['dependencies'] : [];
	}
}
