<?php
/**
 * Container tests.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\Test\DependencyManagement;

use FlyAffiliate\Contracts\Hookable;
use FlyAffiliate\DependencyManagement\Container;
use FlyAffiliate\DependencyManagement\ContainerException;
use FlyAffiliate\DependencyManagement\NotFoundException;
use FlyAffiliate\Test\FlyAffiliateTestCase;

// The fixture classes below are not PSR-4 addressable and are only needed here.
// Loading them from the test file rather than the Composer autoloader keeps them
// out of the bootstrap, where the plugin's ABSPATH guards have not run yet.
require_once __DIR__ . '/fixtures.php';

/**
 * The container is where every wiring mistake shows up first, so it is tested
 * on its own terms rather than through whatever happens to use it.
 *
 * @group container
 *
 * @since FLYAFFILIATE_SINCE
 */
class ContainerTest extends FlyAffiliateTestCase {

	/**
	 * These tests need neither the REST server nor users.
	 *
	 * @var bool
	 */
	protected bool $is_unit_test = true;

	/**
	 * A container with nothing registered.
	 *
	 * @var Container
	 */
	protected Container $container;

	/**
	 * {@inheritDoc}
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->container = new Container();
	}

	/**
	 * A transient entry is built fresh every time.
	 *
	 * @return void
	 */
	public function test_a_transient_entry_is_a_new_instance_each_time(): void {
		$this->container->add( TestPlainService::class );

		$first  = $this->container->get( TestPlainService::class );
		$second = $this->container->get( TestPlainService::class );

		$this->assertInstanceOf( TestPlainService::class, $first );
		$this->assertNotSame( $first, $second );
	}

	/**
	 * A shared entry is built once.
	 *
	 * @return void
	 */
	public function test_a_shared_entry_is_the_same_instance_every_time(): void {
		$this->container->addShared( TestPlainService::class );

		$this->assertSame(
			$this->container->get( TestPlainService::class ),
			$this->container->get( TestPlainService::class )
		);
	}

	/**
	 * A shared entry can still be asked for a fresh instance.
	 *
	 * @return void
	 */
	public function test_a_shared_entry_can_be_forced_to_rebuild(): void {
		$this->container->addShared( TestPlainService::class );

		$shared = $this->container->get( TestPlainService::class );

		$this->assertNotSame( $shared, $this->container->get( TestPlainService::class, true ) );
		$this->assertSame( $shared, $this->container->get( TestPlainService::class ) );
	}

	/**
	 * A constructor dependency is resolved from its type hint.
	 *
	 * @return void
	 */
	public function test_a_constructor_dependency_is_autowired_by_type_hint(): void {
		$this->container->addShared( TestPlainService::class );
		$this->container->addShared( TestDependentService::class );

		$dependent = $this->container->get( TestDependentService::class );

		$this->assertInstanceOf( TestDependentService::class, $dependent );
		$this->assertSame( $this->container->get( TestPlainService::class ), $dependent->dependency );
	}

	/**
	 * A type hint the container does not know is still built, if the class exists.
	 *
	 * @return void
	 */
	public function test_an_unregistered_class_dependency_is_still_built(): void {
		$this->container->addShared( TestDependentService::class );

		$this->assertInstanceOf( TestPlainService::class, $this->container->get( TestDependentService::class )->dependency );
	}

	/**
	 * A scalar constructor parameter uses its default.
	 *
	 * @return void
	 */
	public function test_a_scalar_parameter_falls_back_to_its_default(): void {
		$this->container->addShared( TestConfigurableService::class );

		$this->assertSame( 'default', $this->container->get( TestConfigurableService::class )->label );
	}

	/**
	 * An explicit argument beats the default.
	 *
	 * @return void
	 */
	public function test_an_explicit_argument_is_used(): void {
		$this->container->addShared( TestConfigurableService::class )->addArgument( 'explicit' );

		$this->assertSame( 'explicit', $this->container->get( TestConfigurableService::class )->label );
	}

	/**
	 * A closure is called with the container.
	 *
	 * @return void
	 */
	public function test_a_closure_concrete_receives_the_container(): void {
		$this->container->addShared(
			'made_by_closure',
			static function ( Container $container ): TestConfigurableService {
				return new TestConfigurableService( 'from closure' );
			}
		);

		$this->assertSame( 'from closure', $this->container->get( 'made_by_closure' )->label );
	}

	/**
	 * `get()` on a tag returns every entry carrying it.
	 *
	 * @return void
	 */
	public function test_a_tag_resolves_every_entry_carrying_it(): void {
		$this->container->addShared( TestPlainService::class )->addTag( 'test-group' );
		$this->container->addShared( TestHookableService::class )->addTag( 'test-group' );
		$this->container->addShared( TestConfigurableService::class );

		$tagged = $this->container->get( 'test-group' );

		$this->assertIsArray( $tagged );
		$this->assertCount( 2, $tagged );
		$this->assertArrayHasKey( TestPlainService::class, $tagged );
		$this->assertArrayHasKey( TestHookableService::class, $tagged );
	}

	/**
	 * A service group with nothing in it is empty, not an error.
	 *
	 * @return void
	 */
	public function test_an_empty_tag_resolves_to_an_empty_array(): void {
		$this->assertSame( [], $this->container->get_tagged( 'nothing-carries-this' ) );
	}

	/**
	 * An unknown identifier is an error.
	 *
	 * @return void
	 */
	public function test_an_unknown_identifier_throws(): void {
		$this->expectException( NotFoundException::class );

		$this->container->get( 'no-such-entry' );
	}

	/**
	 * `has()` answers for identifiers and for tags.
	 *
	 * @return void
	 */
	public function test_has_answers_for_identifiers_and_tags(): void {
		$this->container->addShared( TestPlainService::class )->addTag( 'test-group' );

		$this->assertTrue( $this->container->has( TestPlainService::class ) );
		$this->assertTrue( $this->container->has( 'test-group' ) );
		$this->assertFalse( $this->container->has( 'no-such-entry' ) );
	}

	/**
	 * A dependency cycle is reported, not left to exhaust the stack.
	 *
	 * @return void
	 */
	public function test_a_circular_dependency_throws(): void {
		$this->container->addShared( TestCircularA::class );
		$this->container->addShared( TestCircularB::class );

		$this->expectException( ContainerException::class );
		$this->expectExceptionMessage( 'Circular dependency' );

		$this->container->get( TestCircularA::class );
	}

	/**
	 * A class is tagged with every interface it implements, which is what makes
	 * `Hookable` self-registering.
	 *
	 * @return void
	 */
	public function test_a_provider_tags_a_class_with_its_interfaces(): void {
		$this->container->addServiceProvider( new TestServiceProvider() );

		$hookables = $this->container->get_tagged( Hookable::class );

		$this->assertCount( 1, $hookables );
		$this->assertArrayHasKey( TestHookableService::class, $hookables );
	}

	/**
	 * A provider's service-group tags reach its entries.
	 *
	 * @return void
	 */
	public function test_a_provider_applies_its_service_group_tags(): void {
		$this->container->addServiceProvider( new TestServiceProvider() );

		$this->assertCount( 2, $this->container->get_tagged( 'test-service' ) );
	}

	/**
	 * A bootable provider boots after it registers, and may add more providers.
	 *
	 * @return void
	 */
	public function test_a_bootable_provider_boots_after_registering(): void {
		$this->container->addServiceProvider( new TestBootableServiceProvider() );

		$this->assertTrue( $this->container->has( TestConfigurableService::class ), 'register() ran' );
		$this->assertTrue( $this->container->has( TestHookableService::class ), 'boot() added the second provider' );
	}

	/**
	 * The same provider added twice registers once.
	 *
	 * @return void
	 */
	public function test_a_provider_is_only_registered_once(): void {
		$provider = new TestServiceProvider();

		$this->container->addServiceProvider( $provider );

		$shared = $this->container->get( TestPlainService::class );

		$this->container->addServiceProvider( new TestServiceProvider() );

		$this->assertSame( $shared, $this->container->get( TestPlainService::class ) );
	}

	/**
	 * `extend()` reaches an existing definition.
	 *
	 * @return void
	 */
	public function test_extend_returns_the_existing_definition(): void {
		$this->container->addShared( TestPlainService::class );

		$this->container->extend( TestPlainService::class )->addTag( 'added-later' );

		$this->assertCount( 1, $this->container->get_tagged( 'added-later' ) );
	}
}
