<?php
/**
 * Container exception.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\DependencyManagement;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use RuntimeException;

/**
 * Thrown when the container cannot build what it was asked for.
 *
 * @since FLYAFFILIATE_SINCE
 */
class ContainerException extends RuntimeException {
}
