<?php
/**
 * Root service provider.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\DependencyManagement\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FlyAffiliate\DependencyManagement\BootableServiceProvider;

/**
 * Registers the named services and assembles the rest of the providers.
 *
 * The entries here are the ones reachable by a short name, either as
 * `flyaffiliate()->settings` or as
 * `flyaffiliate()->get_container()->get( 'settings' )`. Everything registered
 * here also carries the `container-service` tag, so the bootstrap can resolve
 * the whole group in one call.
 *
 * @since FLYAFFILIATE_SINCE
 */
class ServiceProvider extends BootableServiceProvider {

	/**
	 * Tag applied to every entry this provider registers.
	 *
	 * @var string
	 */
	const TAG = 'container-service';

	/**
	 * Named services, alias => class name.
	 *
	 * Keep this list and the "Service Container" section of CLAUDE.md in step.
	 *
	 * @var array<string, class-string>
	 */
	protected array $services = [
		'installer'     => \FlyAffiliate\Install\Installer::class,
		'upgrades'      => \FlyAffiliate\Upgrade\Manager::class,
		'settings'      => \FlyAffiliate\Admin\Settings\Manager::class,
		'assets'        => \FlyAffiliate\Assets::class,
		'api'           => \FlyAffiliate\REST\Manager::class,
		'admin_notices' => \FlyAffiliate\Admin\Notices\Manager::class,
		'affiliate'     => \FlyAffiliate\Affiliate\Manager::class,
		'registration'  => \FlyAffiliate\Affiliate\Registration::class,
		'commission'    => \FlyAffiliate\Commission\Manager::class,
		'payout'        => \FlyAffiliate\Payout\Manager::class,
		'tracking'      => \FlyAffiliate\Tracking\Manager::class,
	];

	/**
	 * {@inheritDoc}
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return void
	 */
	public function register(): void {
		$container = $this->getContainer();

		// Settings storage and schema are constructor dependencies of the
		// `settings` service; bind them so autowiring can find the interface.
		$container->addShared( \FlyAffiliate\Admin\Settings\Repository\SettingsRepositoryInterface::class, \FlyAffiliate\Admin\Settings\Repository\SettingsRepository::class );
		$container->addShared( \FlyAffiliate\Admin\Settings\Schema\SettingsRegistry::class );

		foreach ( $this->services as $alias => $class_name ) {
			$definition = $this->share_with_implements_tags( $alias, $class_name );

			$definition->addTag( self::TAG );
		}
	}

	/**
	 * Add the remaining providers.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return void
	 */
	public function boot(): void {
		$container = $this->getContainer();

		$container->addServiceProvider( new CommonServiceProvider() );
		$container->addServiceProvider( new AdminServiceProvider() );
		$container->addServiceProvider( new FrontendServiceProvider() );
		$container->addServiceProvider( new AjaxServiceProvider() );
		$container->addServiceProvider( new IntegrationServiceProvider() );
		$container->addServiceProvider( new CliServiceProvider() );
	}
}
