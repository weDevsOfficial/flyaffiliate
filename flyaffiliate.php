<?php
/**
 * Plugin Name: FlyAffiliate
 * Plugin URI: https://wedevs.com/flyaffiliate/
 * Description: Affiliate marketing for WooCommerce. Referral tracking, per-item commissions, hold periods, refund handling and manual payouts.
 * Version: 1.0.0
 * Author: weDevs
 * Author URI: https://wedevs.com/
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: flyaffiliate
 * Domain Path: /languages
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Requires Plugins: woocommerce
 * WC requires at least: 8.5
 * WC tested up to: 11.1
 *
 * @package FlyAffiliate
 */

// Copyright (c) 2026 weDevs Pte Ltd. All rights reserved.
//
// This program is free software; you can redistribute it and/or modify it under
// the terms of the GNU General Public License as published by the Free Software
// Foundation; either version 2 of the License, or (at your option) any later
// version.
//
// This program is distributed in the hope that it will be useful, but WITHOUT
// ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS
// FOR A PARTICULAR PURPOSE. See the GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License along with
// this program; if not, write to the Free Software Foundation, Inc., 51 Franklin
// St, Fifth Floor, Boston, MA 02110-1301 USA.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

defined( 'FLYAFFILIATE_FILE' ) || define( 'FLYAFFILIATE_FILE', __FILE__ );

require_once __DIR__ . '/includes/Autoloader.php';
require_once __DIR__ . '/flyaffiliate-class.php';

FlyAffiliate\Autoloader::register( __DIR__ . '/includes' );

/**
 * The container, created before anything asks for a service.
 *
 * Global rather than a static, so that a test can replace it, and so that the
 * accessor below reads the same object the bootstrap wired.
 *
 * @var FlyAffiliate\DependencyManagement\Container $flyaffiliate_container
 */
global $flyaffiliate_container;

$flyaffiliate_container = new FlyAffiliate\DependencyManagement\Container();

// The root provider registers the named services and adds the rest of the
// providers. This happens while the plugin file loads — before `plugins_loaded`
// — so any listener a provider attaches is in place before other plugins load.
$flyaffiliate_container->addServiceProvider( new FlyAffiliate\DependencyManagement\Providers\ServiceProvider() );

if ( ! function_exists( 'flyaffiliate_get_container' ) ) {
	/**
	 * The dependency injection container.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return FlyAffiliate\DependencyManagement\Container
	 */
	function flyaffiliate_get_container(): FlyAffiliate\DependencyManagement\Container {
		global $flyaffiliate_container;

		return $flyaffiliate_container;
	}
}

if ( ! function_exists( 'flyaffiliate' ) ) {
	/**
	 * The plugin instance.
	 *
	 * Services are reachable through it by name: `flyaffiliate()->affiliate`,
	 * `flyaffiliate()->settings`, and so on.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return FlyAffiliate_Plugin
	 */
	function flyaffiliate(): FlyAffiliate_Plugin {
		return FlyAffiliate_Plugin::init();
	}
}

flyaffiliate();
