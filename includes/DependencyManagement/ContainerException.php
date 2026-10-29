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
 * @since 1.0.0
 */
class ContainerException extends RuntimeException {
}
