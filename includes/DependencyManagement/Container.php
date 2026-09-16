<?php
/**
 * The dependency injection container.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\DependencyManagement;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * FlyAffiliate's dependency injection container.
 *
 * Written here rather than vendored, because nothing third-party ships in the
 * release zip (ADR-0002). The public API deliberately mirrors
 * league/container's, which is the container Dokan extends, so that the wiring
 * patterns transfer between the two codebases unchanged:
 *
 *     $container->add( $id, $concrete )->setShared( true )->addTag( $tag );
 *     $container->addShared( $id, $concrete );
 *     $container->addServiceProvider( new ServiceProvider() );
 *     $container->get( $id );   // one entry
 *     $container->get( $tag );  // every entry carrying that tag, as an array
 *
 * Those method names are camelCase because they are League's names. They are the
 * one exception to this codebase's snake_case rule.
 *
 * @since FLYAFFILIATE_SINCE
 */
class Container {

	/**
	 * Registered definitions, keyed by identifier.
	 *
	 * @var Definition[]
	 */
	protected array $definitions = [];

	/**
	 * Identifiers of the providers already added, so none is registered twice.
	 *
	 * @var array<string, bool>
	 */
	protected array $providers = [];

	/**
	 * Identifiers currently being resolved, used to detect a dependency cycle.
	 *
	 * @var array<string, bool>
	 */
	protected array $resolving = [];

	/**
	 * Register an entry.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string $id       Identifier, typically a class or interface name or a short alias.
	 * @param mixed  $concrete What to build. Null means "build the class named by $id".
	 *
	 * @return Definition The definition, for chaining `setShared()` and `addTag()`.
	 */
	public function add( string $id, $concrete = null ): Definition {
		$definition = ( new Definition( $id, $concrete ) )->setContainer( $this );

		$this->definitions[ $id ] = $definition;

		return $definition;
	}

	/**
	 * Register a shared entry: every `get()` returns the same instance.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string $id       Identifier.
	 * @param mixed  $concrete What to build.
	 *
	 * @return Definition
	 */
	public function addShared( string $id, $concrete = null ): Definition {
		return $this->add( $id, $concrete )->setShared( true );
	}

	/**
	 * Whether the container knows an identifier or a tag.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string $id Identifier or tag.
	 *
	 * @return bool
	 */
	public function has( string $id ): bool {
		if ( isset( $this->definitions[ $id ] ) ) {
			return true;
		}

		return [] !== $this->get_definitions_tagged( $id );
	}

	/**
	 * Resolve an entry, or every entry carrying a tag.
	 *
	 * When `$id` names a definition, that entry is returned. When it names a tag,
	 * an array of every entry carrying it is returned — which is how the service
	 * groups (`common-service`, `admin-service`, …) and the `Hookable` interface
	 * tag are read at bootstrap.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string $id  Identifier or tag.
	 * @param bool   $fresh Force fresh instances even for shared entries.
	 *
	 * @return mixed The entry, or an array of entries when `$id` is a tag.
	 *
	 * @throws NotFoundException  When the identifier is unknown.
	 * @throws ContainerException When a dependency cycle is detected.
	 */
	public function get( string $id, bool $fresh = false ) {
		if ( isset( $this->definitions[ $id ] ) ) {
			return $this->resolve_definition( $this->definitions[ $id ], $fresh );
		}

		if ( [] !== $this->get_definitions_tagged( $id ) ) {
			return $this->get_tagged( $id, $fresh );
		}

		throw new NotFoundException( esc_html( sprintf( 'The container has no entry or tag named "%s".', $id ) ) );
	}

	/**
	 * Resolve every entry carrying a tag.
	 *
	 * Unlike `get()`, an unknown tag is an empty result rather than an error. A
	 * service group with nothing in it yet is a normal state during bootstrap, not
	 * a wiring mistake.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string $tag Tag name.
	 * @param bool   $fresh Force fresh instances even for shared entries.
	 *
	 * @return array<string, mixed> Identifier => resolved entry.
	 */
	public function get_tagged( string $tag, bool $fresh = false ): array {
		$resolved = [];

		foreach ( $this->get_definitions_tagged( $tag ) as $definition ) {
			$resolved[ $definition->get_id() ] = $this->resolve_definition( $definition, $fresh );
		}

		return $resolved;
	}

	/**
	 * Get an existing definition so it can be further configured.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string $id Identifier.
	 *
	 * @return Definition
	 *
	 * @throws NotFoundException When the identifier is unknown.
	 */
	public function extend( string $id ): Definition {
		if ( ! isset( $this->definitions[ $id ] ) ) {
			throw new NotFoundException( esc_html( sprintf( 'The container has no entry named "%s" to extend.', $id ) ) );
		}

		return $this->definitions[ $id ];
	}

	/**
	 * Add a service provider, registering its entries immediately.
	 *
	 * A bootable provider's `boot()` runs straight after its `register()`, and may
	 * add further providers — which is how the root provider assembles the rest.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param ServiceProviderInterface $provider The provider.
	 *
	 * @return static
	 */
	public function addServiceProvider( ServiceProviderInterface $provider ): self {
		$identifier = $provider->get_identifier();

		if ( isset( $this->providers[ $identifier ] ) ) {
			return $this;
		}

		$this->providers[ $identifier ] = true;

		$provider->setContainer( $this );
		$provider->register();

		if ( $provider instanceof BootableServiceProviderInterface ) {
			$provider->boot();
		}

		return $this;
	}

	/**
	 * Whether a provider has already been added.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string $identifier The provider identifier, normally its class name.
	 *
	 * @return bool
	 */
	public function has_service_provider( string $identifier ): bool {
		return isset( $this->providers[ $identifier ] );
	}

	/**
	 * Every identifier the container knows.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return string[]
	 */
	public function get_identifiers(): array {
		return array_keys( $this->definitions );
	}

	/**
	 * Resolve one definition, guarding against a dependency cycle.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param Definition $definition The definition.
	 * @param bool       $fresh      Force a fresh instance.
	 *
	 * @return mixed
	 *
	 * @throws ContainerException When the entry depends on itself, directly or through a chain.
	 */
	protected function resolve_definition( Definition $definition, bool $fresh ) {
		$id = $definition->get_id();

		if ( isset( $this->resolving[ $id ] ) ) {
			throw new ContainerException(
				esc_html(
					sprintf(
						'Circular dependency while resolving "%1$s". Chain: %2$s.',
						$id,
						implode( ' -> ', array_keys( $this->resolving ) ) . ' -> ' . $id
					)
				)
			);
		}

		$this->resolving[ $id ] = true;

		try {
			return $definition->resolve( $fresh );
		} finally {
			unset( $this->resolving[ $id ] );
		}
	}

	/**
	 * Every definition carrying a tag.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string $tag Tag name.
	 *
	 * @return Definition[]
	 */
	protected function get_definitions_tagged( string $tag ): array {
		$tagged = [];

		foreach ( $this->definitions as $definition ) {
			if ( $definition->hasTag( $tag ) ) {
				$tagged[] = $definition;
			}
		}

		return $tagged;
	}
}
