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
 * @since FLYAFFILIATE_SINCE
 */
class NotFoundException extends ContainerException {
}
