<?php
/**
 * Base class for providers that run code when they are added.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\DependencyManagement;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A service provider whose `boot()` runs immediately after `register()`.
 *
 * @since FLYAFFILIATE_SINCE
 */
abstract class BootableServiceProvider extends BaseServiceProvider implements BootableServiceProviderInterface {
}
