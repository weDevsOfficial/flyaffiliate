<?php
/**
 * Container not-found exception.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\DependencyManagement;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thrown when the container is asked for an identifier it does not know.
 *
 * @since 1.0.0
 */
class NotFoundException extends ContainerException {
}
