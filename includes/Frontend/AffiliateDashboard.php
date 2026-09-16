<?php
/**
 * The affiliate dashboard shortcode.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FlyAffiliate\Abstracts\Shortcode;

/**
 * `[flyaffiliate_dashboard]`: the logged-in affiliate's area.
 *
 * The states around the dashboard (logged out, not an affiliate, not yet
 * active) are PHP templates. The dashboard itself is a React app built on
 * plugin-ui, the same DataViews lists as the admin, reading the self-scoped
 * `me` REST routes; this shortcode only mounts it and hands it the tab to
 * open. Everything shown is scoped to the affiliate behind the logged-in
 * user; there is no parameter to pick another.
 *
 * @since FLYAFFILIATE_SINCE
 */
class AffiliateDashboard extends Shortcode {

	/**
	 * {@inheritDoc}
	 *
	 * @var string
	 */
	protected string $tag = 'flyaffiliate_dashboard';

	/**
	 * The tabs.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return array<string, string> Tab key => label.
	 */
	public function get_tabs(): array {
		return [
			'overview'    => __( 'Overview', 'flyaffiliate' ),
			'commissions' => __( 'Commissions', 'flyaffiliate' ),
			'visits'      => __( 'Visits', 'flyaffiliate' ),
			'payouts'     => __( 'Payouts', 'flyaffiliate' ),
			'settings'    => __( 'Settings', 'flyaffiliate' ),
		];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param array|string $atts Shortcode attributes.
	 *
	 * @return string
	 */
	public function render( $atts = [] ): string {
		wp_enqueue_style( 'flyaffiliate-frontend' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading a status flag from a redirect for display.
		$notice = isset( $_GET['flyaffiliate_activated'] ) ? 'activated' : ( isset( $_GET['flyaffiliate_error'] ) ? sanitize_key( wp_unslash( $_GET['flyaffiliate_error'] ) ) : '' );

		if ( ! is_user_logged_in() ) {
			return $this->template(
				'affiliate-dashboard/login.php',
				[
					'login_url' => wp_login_url( get_permalink() ),
					'notice'    => $notice,
				]
			);
		}

		$affiliate = flyaffiliate()->affiliate->get_by_user( get_current_user_id() );

		if ( null === $affiliate ) {
			return $this->template(
				'affiliate-dashboard/not-affiliate.php',
				[
					'register_url' => flyaffiliate_get_page_url( 'affiliate_register' ),
					'notice'       => $notice,
				]
			);
		}

		if ( ! $affiliate->is_active() ) {
			return $this->template(
				'affiliate-dashboard/inactive.php',
				[
					'affiliate' => $affiliate,
					'notice'    => $notice,
				]
			);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- choosing which tab to open.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview';
		$tab = isset( $this->get_tabs()[ $tab ] ) ? $tab : 'overview';

		flyaffiliate()->assets->enqueue_dashboard_assets( $tab, $notice );

		return $this->template(
			'affiliate-dashboard/dashboard.php',
			[
				'affiliate' => $affiliate,
				'tab'       => $tab,
			]
		);
	}

	/**
	 * The URL of a tab.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string $tab Tab key.
	 *
	 * @return string
	 */
	public function get_tab_url( string $tab ): string {
		return add_query_arg( 'tab', $tab, remove_query_arg( [ 'tab', 'tab_page', 'flyaffiliate_saved', 'flyaffiliate_error', 'flyaffiliate_activated' ] ) );
	}
}
