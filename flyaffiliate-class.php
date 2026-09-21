<?php
/**
 * The plugin singleton.
 *
 * @package FlyAffiliate
 */

use FlyAffiliate\Contracts\Hookable;
use FlyAffiliate\DependencyManagement\Container;
use FlyAffiliate\Install\Installer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * FlyAffiliate.
 *
 * Holds the version, defines the constants, wires the WordPress lifecycle, and
 * exposes the container's named services through a magic getter.
 *
 * @since FLYAFFILIATE_SINCE
 *
 * @property FlyAffiliate\Affiliate\Manager        $affiliate     Affiliate data access.
 * @property FlyAffiliate\Admin\Settings\Manager   $settings      Settings registration and defaults.
 * @property FlyAffiliate\Admin\Notices\Manager    $admin_notices Admin notices.
 * @property FlyAffiliate\Assets                   $assets        Script and style registration.
 * @property FlyAffiliate\REST\Manager             $api           REST API bootstrap.
 * @property FlyAffiliate\Install\Installer        $installer     Tables, options, pages, scheduled job.
 * @property FlyAffiliate\Upgrade\Manager          $upgrades      Schema and data upgrades.
 */
final class FlyAffiliate_Plugin {

	/**
	 * Plugin version.
	 *
	 * Kept equal to the `Version` header, the readme `Stable tag` and the
	 * `package.json` version. All four change together, in a release commit.
	 *
	 * @var string
	 */
	public string $version = '1.0.0';

	/**
	 * The lowest PHP version this plugin runs on.
	 *
	 * @var string
	 */
	private string $min_php = '8.1';

	/**
	 * The single instance.
	 *
	 * @var FlyAffiliate_Plugin|null
	 */
	private static ?FlyAffiliate_Plugin $instance = null;

	/**
	 * Wire the plugin into WordPress.
	 *
	 * @since FLYAFFILIATE_SINCE
	 */
	private function __construct() {
		$this->define_constants();

		register_activation_hook( FLYAFFILIATE_FILE, [ $this, 'activate' ] );
		register_deactivation_hook( FLYAFFILIATE_FILE, [ $this, 'deactivate' ] );

		add_action( 'before_woocommerce_init', [ $this, 'declare_woocommerce_feature_compatibility' ] );
		add_action( 'plugins_loaded', [ $this, 'init_plugin' ] );
	}

	/**
	 * Get the plugin instance, creating it once.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return FlyAffiliate_Plugin
	 */
	public static function init(): FlyAffiliate_Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Resolve a named service from the container.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string $property Service name, for example `affiliate` or `settings`.
	 *
	 * @return mixed The service, or null when there is no service by that name.
	 */
	public function __get( string $property ) {
		$container = $this->get_container();

		return $container->has( $property ) ? $container->get( $property ) : null;
	}

	/**
	 * Whether a named service exists.
	 *
	 * Without this, `isset( flyaffiliate()->affiliate )` is always false, because
	 * `__get()` alone does not answer `isset()`.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string $property Service name.
	 *
	 * @return bool
	 */
	public function __isset( string $property ): bool {
		return $this->get_container()->has( $property );
	}

	/**
	 * The container.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return Container
	 */
	public function get_container(): Container {
		return flyaffiliate_get_container();
	}

	/**
	 * Define the plugin constants.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return void
	 */
	public function define_constants(): void {
		defined( 'FLYAFFILIATE_VERSION' ) || define( 'FLYAFFILIATE_VERSION', $this->version );
		defined( 'FLYAFFILIATE_DIR' ) || define( 'FLYAFFILIATE_DIR', __DIR__ );
		defined( 'FLYAFFILIATE_INC_DIR' ) || define( 'FLYAFFILIATE_INC_DIR', __DIR__ . '/includes' );
		defined( 'FLYAFFILIATE_TEMPLATE_DIR' ) || define( 'FLYAFFILIATE_TEMPLATE_DIR', __DIR__ . '/templates' );
		defined( 'FLYAFFILIATE_URL' ) || define( 'FLYAFFILIATE_URL', plugins_url( '', FLYAFFILIATE_FILE ) );
		defined( 'FLYAFFILIATE_ASSETS_URL' ) || define( 'FLYAFFILIATE_ASSETS_URL', FLYAFFILIATE_URL . '/assets' );
		defined( 'FLYAFFILIATE_PLUGIN_BASENAME' ) || define( 'FLYAFFILIATE_PLUGIN_BASENAME', plugin_basename( FLYAFFILIATE_FILE ) );
	}

	/**
	 * Tell WooCommerce which of its features this plugin is compatible with.
	 *
	 * Declared before WooCommerce initialises, which is why it hooks
	 * `before_woocommerce_init` rather than anything later.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return void
	 */
	public function declare_woocommerce_feature_compatibility(): void {
		if ( ! class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			return;
		}

		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', FLYAFFILIATE_FILE, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', FLYAFFILIATE_FILE, true );
	}

	/**
	 * Boot the plugin, once WooCommerce is available.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return void
	 */
	public function init_plugin(): void {
		$this->includes();

		// Integrations are optional and each is registered only when its plugin
		// is there — WooCommerce (checkout attribution, order status sync) today,
		// others the same way. Everything else — affiliates, referral links,
		// hand-entered commissions, payouts, the admin and the affiliate
		// dashboard — runs on WordPress alone (ADR-0013).
		if ( $this->has_woocommerce() ) {
			$this->get_container()->addServiceProvider( new \FlyAffiliate\DependencyManagement\Providers\IntegrationServiceProvider() );
		}

		$this->init_hooks();

		/**
		 * Fires once FlyAffiliate has loaded and its hooks are attached.
		 *
		 * The point at which another plugin can safely extend FlyAffiliate.
		 *
		 * @since FLYAFFILIATE_SINCE
		 */
		do_action( 'flyaffiliate_loaded' );
	}

	/**
	 * Load the procedural helper files.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return void
	 */
	public function includes(): void {
		require_once FLYAFFILIATE_INC_DIR . '/functions.php';
	}

	/**
	 * Attach the plugin's own hooks and register every `Hookable`.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return void
	 */
	public function init_hooks(): void {
		add_action( 'init', [ $this, 'wpdb_table_shortcuts' ], 1 );
		add_action( 'init', [ $this, 'init_classes' ], 4 );

		add_filter( 'plugin_action_links_' . FLYAFFILIATE_PLUGIN_BASENAME, [ $this, 'plugin_action_links' ] );

		foreach ( $this->get_container()->get_tagged( Hookable::class ) as $hookable ) {
			$hookable->register_hooks();
		}
	}

	/**
	 * Resolve the tagged service groups.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return void
	 */
	public function init_classes(): void {
		$container = $this->get_container();

		$container->get_tagged( 'common-service' );

		if ( is_admin() ) {
			$container->get_tagged( 'admin-service' );
		} else {
			$container->get_tagged( 'frontend-service' );
		}

		$container->get_tagged( 'integration-service' );
		$container->get_tagged( 'container-service' );

		if ( wp_doing_ajax() ) {
			$container->get_tagged( 'ajax-service' );
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			$container->get_tagged( 'cli-service' );
		}
	}

	/**
	 * Put this plugin's tables on `$wpdb`, so queries do not rebuild the names.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return void
	 */
	public function wpdb_table_shortcuts(): void {
		global $wpdb;

		foreach ( Installer::get_table_names() as $table ) {
			$wpdb->{$table} = $wpdb->prefix . $table;
		}
	}

	/**
	 * Add a Settings link to the plugin's row on the Plugins screen.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string[] $links The existing links.
	 *
	 * @return string[]
	 */
	public function plugin_action_links( $links ): array {
		$links = is_array( $links ) ? $links : [];

		array_unshift(
			$links,
			sprintf(
				'<a href="%1$s">%2$s</a>',
				esc_url( admin_url( 'admin.php?page=flyaffiliate-settings' ) ),
				esc_html__( 'Settings', 'flyaffiliate' )
			)
		);

		return $links;
	}

	/**
	 * Install the plugin.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return void
	 */
	public function activate(): void {
		if ( ! $this->is_supported_php() ) {
			deactivate_plugins( FLYAFFILIATE_PLUGIN_BASENAME );

			wp_die(
				esc_html(
					sprintf(
						// translators: 1: required PHP version, 2: the PHP version running.
						__( 'FlyAffiliate needs PHP %1$s or newer. This site runs PHP %2$s.', 'flyaffiliate' ),
						$this->min_php,
						PHP_VERSION
					)
				),
				esc_html__( 'Plugin activation failed', 'flyaffiliate' ),
				[ 'back_link' => true ]
			);
		}

		// The plugin is standalone (ADR-0013): the tables, options, role, pages
		// and the daily job are created on any WordPress site, integrations or not.
		require_once FLYAFFILIATE_INC_DIR . '/functions.php';

		( new Installer() )->do_install();
	}

	/**
	 * Stop the scheduled work. Data is left alone; that is uninstall's job.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return void
	 */
	public function deactivate(): void {
		Installer::clear_scheduled_events();
	}

	/**
	 * Whether this PHP version is supported.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return bool
	 */
	public function is_supported_php(): bool {
		return version_compare( PHP_VERSION, $this->min_php, '>=' );
	}

	/**
	 * Whether WooCommerce is loaded.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return bool
	 */
	public function has_woocommerce(): bool {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * The plugin directory.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return string
	 */
	public function plugin_path(): string {
		return untrailingslashit( plugin_dir_path( FLYAFFILIATE_FILE ) );
	}

	/**
	 * The theme directory that overrides this plugin's templates.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return string
	 */
	public function template_path(): string {
		return flyaffiliate_template_path();
	}
}
