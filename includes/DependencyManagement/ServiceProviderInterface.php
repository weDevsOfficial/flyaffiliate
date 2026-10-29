<?php
/**
 * Service provider contract.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\DependencyManagement;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A service provider registers a related group of entries into the container.
 *
 * @since 1.0.0
 */
interface ServiceProviderInterface {

	/**
	 * Register this provider's entries into the container.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register(): void;

	/**
	 * Whether this provider registers the given identifier or tag.
	 *
	 * @since 1.0.0
	 *
	 * @param string $alias Identifier or tag.
	 *
	 * @return bool
	 */
	public function provides( string $alias ): bool;

	/**
	 * Give the provider its container.
	 *
	 * @since 1.0.0
	 *
	 * @param Container $container The container.
	 *
	 * @return ServiceProviderInterface
	 */
	public function setContainer( Container $container ): ServiceProviderInterface;

	/**
	 * Get the container.
	 *
	 * @since 1.0.0
	 *
	 * @return Container
	 */
	public function getContainer(): Container;

	/**
	 * A stable name for this provider, used to register it only once.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_identifier(): string;
}
