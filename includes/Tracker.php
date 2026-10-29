<?php
/**
 * Usage data for Appsero, sent only once the site allows it.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FlyAffiliate\Contracts\Hookable;
use FlyAffiliate\Dependencies\Appsero\Client;
use FlyAffiliate\Dependencies\Appsero\Insights as AppseroInsights;

/**
 * Dokan Lite's `Tracker`, on FlyAffiliate: Appsero Insights, opt-in.
 *
 * Nothing leaves the site until an administrator allows it, through Share
 * Essentials in the setup wizard or Appsero's own notice (shown outside
 * FlyAffiliate's screens, which hide other notices, ADR-0017). From then on,
 * once a week: what the notice lists, plus the counts in `get_extra_data()`.
 * Not to be confused with `Tracking\Tracker`, the referral tracker.
 *
 * @since 1.0.0
 */
class Tracker implements Hookable {

	/**
	 * FlyAffiliate's product on Appsero: the hash on its usage guide.
	 *
	 * @var string
	 */
	const APPSERO_HASH = 'bc4b55f8-bb99-4a7b-bb0e-ff70256c9a0a';

	/**
	 * The SDK's insights.
	 *
	 * @var AppseroInsights|null
	 */
	protected ?AppseroInsights $insights = null;

	/**
	 * {@inheritDoc}
	 *
	 * The SDK's notice, opt-in links, weekly send and deactivation survey.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		$this->insights()->add_extra( [ $this, 'get_extra_data' ] )->init_plugin();
	}

	/**
	 * Whether the site allows usage data to be sent.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function is_allowed(): bool {
		return $this->insights()->tracking_allowed();
	}

	/**
	 * Allow sending, and send now.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function optin(): void {
		$this->insights()->optin();
	}

	/**
	 * Stop sending.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function optout(): void {
		$this->insights()->optout();
	}

	/**
	 * Resume the weekly send on activation, when the site allowed it before.
	 *
	 * The SDK does this from its own activation hook, which exists only when
	 * the SDK is set up while the plugin file loads; FlyAffiliate sets it up on
	 * `plugins_loaded`, so `FlyAffiliate_Plugin::activate()` asks here.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function activate(): void {
		$this->insights()->activate_plugin();
	}

	/**
	 * What FlyAffiliate adds to the SDK's data: counts and versions, nothing
	 * about an affiliate or a visitor.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, int|string|null>
	 */
	public function get_extra_data(): array {
		return [
			'affiliates'           => flyaffiliate()->affiliate->count(),
			'commissions'          => flyaffiliate()->commission->count(),
			'is_pro'               => flyaffiliate_is_pro_active() ? 'Yes' : 'No',
			'wc_version'           => function_exists( 'WC' ) ? WC()->version : null,
			'flyaffiliate_version' => FLYAFFILIATE_VERSION,
		];
	}

	/**
	 * The SDK's insights, built once.
	 *
	 * @since 1.0.0
	 *
	 * @return AppseroInsights
	 */
	protected function insights(): AppseroInsights {
		if ( null === $this->insights ) {
			$this->insights = ( new Client( self::APPSERO_HASH, 'FlyAffiliate', FLYAFFILIATE_FILE ) )->insights();
		}

		return $this->insights;
	}
}
