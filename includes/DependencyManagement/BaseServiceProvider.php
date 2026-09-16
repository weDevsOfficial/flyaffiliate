<?php
/**
 * Base class for service providers.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\DependencyManagement;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Base class every FlyAffiliate service provider extends.
 *
 * A subclass lists its classes in `$services` and its service-group tags in
 * `$tags`, then registers them in `register()`. The helpers below add the tag
 * for every interface a class implements, which is what makes
 * `Contracts\Hookable` self-registering.
 *
 * @since FLYAFFILIATE_SINCE
 */
abstract class BaseServiceProvider implements ServiceProviderInterface {

	/**
	 * The classes this provider registers.
	 *
	 * A list of class names, or an alias => class name map when the entries are
	 * meant to be reachable by a short name.
	 *
	 * @var array
	 */
	protected array $services = [];

	/**
	 * Service-group tags applied to every entry this provider registers.
	 *
	 * @var string[]
	 */
	protected array $tags = [];

	/**
	 * The container.
	 *
	 * @var Container|null
	 */
	protected ?Container $container = null;

	/**
	 * {@inheritDoc}
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param Container $container The container.
	 *
	 * @return ServiceProviderInterface
	 */
	public function setContainer( Container $container ): ServiceProviderInterface {
		$this->container = $container;

		return $this;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return Container
	 *
	 * @throws ContainerException When the provider is used before it has a container.
	 */
	public function getContainer(): Container {
		if ( null === $this->container ) {
			throw new ContainerException( esc_html( sprintf( '%s was used before it was given a container.', static::class ) ) );
		}

		return $this->container;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return string
	 */
	public function get_identifier(): string {
		return static::class;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string $alias Identifier or tag.
	 *
	 * @return bool
	 */
	public function provides( string $alias ): bool {
		if ( in_array( $alias, $this->tags, true ) ) {
			return true;
		}

		foreach ( $this->services as $key => $class_name ) {
			if ( $alias === $class_name || $alias === $key ) {
				return true;
			}

			if ( is_string( $class_name ) && class_exists( $class_name ) && in_array( $alias, class_implements( $class_name ), true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Register a class and tag it with every interface it implements.
	 *
	 * The interface tags are what let the bootstrap ask the container for every
	 * `Hookable` and call `register_hooks()` on each, without a list to maintain.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string $id       Identifier, normally the class name.
	 * @param mixed  $concrete What to build. Null means "build the class named by $id".
	 * @param bool   $shared   Whether the entry is shared.
	 *
	 * @return Definition
	 */
	protected function add_with_implements_tags( string $id, $concrete = null, bool $shared = false ): Definition {
		$definition = $this->getContainer()->add( $id, $concrete )->setShared( $shared );

		$class_name = is_string( $concrete ) ? $concrete : $id;

		if ( class_exists( $class_name ) ) {
			foreach ( class_implements( $class_name ) as $interface ) {
				$definition->addTag( $interface );
			}
		}

		return $definition;
	}

	/**
	 * Register a shared class and tag it with every interface it implements.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string $id       Identifier, normally the class name.
	 * @param mixed  $concrete What to build.
	 *
	 * @return Definition
	 */
	protected function share_with_implements_tags( string $id, $concrete = null ): Definition {
		return $this->add_with_implements_tags( $id, $concrete, true );
	}

	/**
	 * Add several tags to a definition.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param Definition $definition The definition.
	 * @param string[]   $tags       Tags to add.
	 *
	 * @return Definition
	 */
	protected function add_tags( Definition $definition, array $tags ): Definition {
		foreach ( $tags as $tag ) {
			$definition->addTag( $tag );
		}

		return $definition;
	}

	/**
	 * Register every class in `$services` as shared, with the interface tags and
	 * this provider's service-group tags.
	 *
	 * Providers whose registration is nothing more than that call this from
	 * `register()` instead of repeating the loop.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return void
	 */
	protected function register_services(): void {
		foreach ( $this->services as $key => $class_name ) {
			$id = is_string( $key ) ? $key : $class_name;

			$definition = $this->share_with_implements_tags( $id, is_string( $key ) ? $class_name : null );

			$this->add_tags( $definition, $this->tags );
		}
	}
}
