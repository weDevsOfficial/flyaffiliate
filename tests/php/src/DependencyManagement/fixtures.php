<?php
/**
 * Fixtures for the container tests.
 *
 * These live in their own file, not in the test file, so that PHPUnit's class
 * loader is not asked to find them by a name that does not match a test.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\Test\DependencyManagement;

use FlyAffiliate\Contracts\Hookable;
use FlyAffiliate\DependencyManagement\BaseServiceProvider;
use FlyAffiliate\DependencyManagement\BootableServiceProvider;

/**
 * A class with no dependencies.
 */
class TestPlainService {
}

/**
 * A class whose constructor dependency the container has to resolve.
 */
class TestDependentService {

	/**
	 * The injected dependency.
	 *
	 * @var TestPlainService
	 */
	public TestPlainService $dependency;

	/**
	 * Construct.
	 *
	 * @param TestPlainService $dependency Injected by type hint.
	 */
	public function __construct( TestPlainService $dependency ) {
		$this->dependency = $dependency;
	}
}

/**
 * A class with a scalar constructor parameter that has a default.
 */
class TestConfigurableService {

	/**
	 * The label.
	 *
	 * @var string
	 */
	public string $label;

	/**
	 * Construct.
	 *
	 * @param string $label A scalar the container cannot resolve from a type hint.
	 */
	public function __construct( string $label = 'default' ) {
		$this->label = $label;
	}
}

/**
 * A class that registers hooks.
 */
class TestHookableService implements Hookable {

	/**
	 * Whether `register_hooks()` was called.
	 *
	 * @var bool
	 */
	public bool $registered = false;

	/**
	 * {@inheritDoc}
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		$this->registered = true;
	}
}

/**
 * Half of a dependency cycle.
 */
class TestCircularA {

	/**
	 * Construct.
	 *
	 * @param TestCircularB $b The other half.
	 */
	public function __construct( TestCircularB $b ) {
	}
}

/**
 * The other half of a dependency cycle.
 */
class TestCircularB {

	/**
	 * Construct.
	 *
	 * @param TestCircularA $a The first half.
	 */
	public function __construct( TestCircularA $a ) {
	}
}

/**
 * A provider registering two classes under one service-group tag.
 */
class TestServiceProvider extends BaseServiceProvider {

	/**
	 * {@inheritDoc}
	 *
	 * @var string[]
	 */
	protected array $tags = [ 'test-service' ];

	/**
	 * {@inheritDoc}
	 *
	 * @var class-string[]
	 */
	protected array $services = [
		TestPlainService::class,
		TestHookableService::class,
	];

	/**
	 * {@inheritDoc}
	 *
	 * @return void
	 */
	public function register(): void {
		$this->register_services();
	}
}

/**
 * A provider that adds another provider from `boot()`.
 */
class TestBootableServiceProvider extends BootableServiceProvider {

	/**
	 * {@inheritDoc}
	 *
	 * @var class-string[]
	 */
	protected array $services = [
		TestConfigurableService::class,
	];

	/**
	 * {@inheritDoc}
	 *
	 * @return void
	 */
	public function register(): void {
		$this->register_services();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return void
	 */
	public function boot(): void {
		$this->getContainer()->addServiceProvider( new TestServiceProvider() );
	}
}
