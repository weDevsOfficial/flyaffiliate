<?php
/**
 * A single container entry: what it builds, how, and under which tags.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\DependencyManagement;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Closure;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use Throwable;

/**
 * Describes how the container produces one entry.
 *
 * The public API mirrors league/container's `Definition` so that patterns
 * carried over from Dokan read the same here (ADR-0002). Unlike Dokan, this
 * implementation autowires **constructors** by type hint rather than an `init()`
 * method: FlyAffiliate has no installed base whose subclasses a constructor
 * signature change could break.
 *
 * @since FLYAFFILIATE_SINCE
 */
class Definition {

	/**
	 * The identifier this definition answers to.
	 *
	 * @var string
	 */
	protected string $id;

	/**
	 * What to build: a class name, a closure, a ready-made object, or a scalar.
	 *
	 * @var mixed
	 */
	protected $concrete;

	/**
	 * Whether `get()` returns the same instance every time.
	 *
	 * @var bool
	 */
	protected bool $shared = false;

	/**
	 * Tags this entry carries.
	 *
	 * @var string[]
	 */
	protected array $tags = [];

	/**
	 * Constructor arguments supplied explicitly, in order.
	 *
	 * @var array
	 */
	protected array $arguments = [];

	/**
	 * The cached instance of a shared entry.
	 *
	 * @var mixed
	 */
	protected $resolved = null;

	/**
	 * Whether `$resolved` holds a value. Distinguishes "not built" from "built null".
	 *
	 * @var bool
	 */
	protected bool $is_resolved = false;

	/**
	 * The container this definition resolves dependencies through.
	 *
	 * @var Container|null
	 */
	protected ?Container $container = null;

	/**
	 * Construct a definition.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string $id       Identifier, typically a class or interface name or a short alias.
	 * @param mixed  $concrete What to build. Null means "build the class named by $id".
	 */
	public function __construct( string $id, $concrete = null ) {
		$this->id       = $id;
		$this->concrete = $concrete ?? $id;
	}

	/**
	 * Get the identifier.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return string
	 */
	public function get_id(): string {
		return $this->id;
	}

	/**
	 * Set the container used to resolve this definition's dependencies.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param Container $container The container.
	 *
	 * @return static
	 */
	public function setContainer( Container $container ): self {
		$this->container = $container;

		return $this;
	}

	/**
	 * Mark the entry shared, so every `get()` returns the same instance.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param bool $shared Whether the entry is shared.
	 *
	 * @return static
	 */
	public function setShared( bool $shared = true ): self {
		$this->shared = $shared;

		return $this;
	}

	/**
	 * Whether the entry is shared.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return bool
	 */
	public function isShared(): bool {
		return $this->shared;
	}

	/**
	 * Add a tag to the entry.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string $tag Tag name — a service group such as `common-service`, or an interface name.
	 *
	 * @return static
	 */
	public function addTag( string $tag ): self {
		if ( ! in_array( $tag, $this->tags, true ) ) {
			$this->tags[] = $tag;
		}

		return $this;
	}

	/**
	 * Whether the entry carries a tag.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string $tag Tag name.
	 *
	 * @return bool
	 */
	public function hasTag( string $tag ): bool {
		return in_array( $tag, $this->tags, true );
	}

	/**
	 * Get every tag on the entry.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return string[]
	 */
	public function getTags(): array {
		return $this->tags;
	}

	/**
	 * Supply one constructor argument explicitly.
	 *
	 * A string that names a container entry is resolved through the container;
	 * anything else is passed through as the literal value.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param mixed $argument The argument.
	 *
	 * @return static
	 */
	public function addArgument( $argument ): self {
		$this->arguments[] = $argument;

		return $this;
	}

	/**
	 * Supply several constructor arguments explicitly, in order.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param array $arguments The arguments.
	 *
	 * @return static
	 */
	public function addArguments( array $arguments ): self {
		foreach ( $arguments as $argument ) {
			$this->addArgument( $argument );
		}

		return $this;
	}

	/**
	 * Build the entry.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param bool $fresh Force a fresh instance even when the entry is shared.
	 *
	 * @return mixed
	 *
	 * @throws ContainerException When the concrete cannot be built.
	 */
	public function resolve( bool $fresh = false ) {
		if ( $this->shared && $this->is_resolved && ! $fresh ) {
			return $this->resolved;
		}

		$resolved = $this->build();

		if ( $this->shared && ! $fresh ) {
			$this->resolved    = $resolved;
			$this->is_resolved = true;
		}

		return $resolved;
	}

	/**
	 * Discard the cached instance of a shared entry.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return void
	 */
	public function forgetResolved(): void {
		$this->resolved    = null;
		$this->is_resolved = false;
	}

	/**
	 * Turn the concrete into a value.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return mixed
	 *
	 * @throws ContainerException When a class name cannot be instantiated.
	 */
	protected function build() {
		$concrete = $this->concrete;

		if ( $concrete instanceof Closure ) {
			return $concrete( $this->container, ...$this->resolve_arguments() );
		}

		if ( is_object( $concrete ) ) {
			return $concrete;
		}

		if ( is_string( $concrete ) && class_exists( $concrete ) ) {
			return $this->build_class( $concrete );
		}

		// A scalar or an array registered as a value.
		return $concrete;
	}

	/**
	 * Instantiate a class, autowiring its constructor.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string $class_name Fully qualified class name.
	 *
	 * @return object
	 *
	 * @throws ContainerException When the class is not instantiable or an argument cannot be resolved.
	 */
	protected function build_class( string $class_name ): object {
		try {
			$reflection = new ReflectionClass( $class_name );
		} catch ( Throwable $exception ) {
			// The caught exception is folded into the message rather than chained:
			// WordPress.Security.EscapeOutput flags every non-literal argument to an
			// exception constructor, and an escaped message loses nothing here.
			throw new ContainerException(
				esc_html(
					sprintf(
						'Cannot reflect "%1$s" while resolving "%2$s": %3$s',
						$class_name,
						$this->id,
						$exception->getMessage()
					)
				)
			);
		}

		if ( ! $reflection->isInstantiable() ) {
			throw new ContainerException(
				esc_html( sprintf( '"%s" is not instantiable, so "%s" cannot be resolved.', $class_name, $this->id ) )
			);
		}

		$constructor = $reflection->getConstructor();

		if ( null === $constructor || 0 === $constructor->getNumberOfParameters() ) {
			return $reflection->newInstance();
		}

		$supplied  = $this->resolve_arguments();
		$arguments = [];

		foreach ( $constructor->getParameters() as $position => $parameter ) {
			if ( array_key_exists( $position, $supplied ) ) {
				$arguments[] = $supplied[ $position ];
				continue;
			}

			$arguments[] = $this->resolve_parameter( $parameter, $class_name );
		}

		return $reflection->newInstanceArgs( $arguments );
	}

	/**
	 * Resolve one constructor parameter.
	 *
	 * A class or interface type hint is resolved through the container. Anything
	 * else falls back to the parameter's default, then to null when it is
	 * nullable. A parameter that is none of those is a wiring mistake and says so.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param ReflectionParameter $parameter  The parameter.
	 * @param string              $class_name The class being built, for the error message.
	 *
	 * @return mixed
	 *
	 * @throws ContainerException When the parameter cannot be satisfied.
	 */
	protected function resolve_parameter( ReflectionParameter $parameter, string $class_name ) {
		$type = $parameter->getType();

		if ( $type instanceof ReflectionNamedType && ! $type->isBuiltin() ) {
			$dependency = $type->getName();

			if ( null !== $this->container && $this->container->has( $dependency ) ) {
				return $this->container->get( $dependency );
			}

			if ( class_exists( $dependency ) ) {
				return ( new self( $dependency ) )->setContainer( $this->container )->resolve();
			}
		}

		if ( $parameter->isDefaultValueAvailable() ) {
			return $parameter->getDefaultValue();
		}

		if ( $parameter->allowsNull() ) {
			return null;
		}

		throw new ContainerException(
			esc_html(
				sprintf(
					'Cannot resolve parameter $%1$s of %2$s::__construct(). Give it a type hint the container knows, a default value, or pass it explicitly with addArgument().',
					$parameter->getName(),
					$class_name
				)
			)
		);
	}

	/**
	 * Resolve explicitly supplied arguments, looking up strings that name container entries.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return array
	 */
	protected function resolve_arguments(): array {
		return array_map(
			function ( $argument ) {
				if ( is_string( $argument ) && null !== $this->container && $this->container->has( $argument ) ) {
					return $this->container->get( $argument );
				}

				return $argument;
			},
			$this->arguments
		);
	}
}
