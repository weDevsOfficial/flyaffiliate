<?php
/**
 * Admin notices.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\Admin\Notices;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FlyAffiliate\Admin\Menu;
use FlyAffiliate\Contracts\Hookable;

/**
 * Collects and renders the plugin's admin notices, and keeps every other
 * notice off FlyAffiliate's screen.
 *
 * Every notice is dismissible, appears on FlyAffiliate's own screen only, and
 * is shown only to a user who can act on it: a store owner who cannot fix the
 * thing being complained about should not be told about it.
 *
 * Other plugins' notices are hidden on that screen, as Dokan's and
 * WooCommerce's admin hide them on theirs (ADR-0017): they print inside a
 * hidden container that also holds the page's only `.wp-header-end`, so
 * WordPress's own script moves the rest in there too. FlyAffiliate's own print
 * above the app instead (`flyaffiliate_before_admin_app`).
 *
 * @since FLYAFFILIATE_SINCE
 */
class Manager implements Hookable {

	/**
	 * User meta prefix recording that a user dismissed a notice.
	 *
	 * @var string
	 */
	const DISMISSED_META_PREFIX = '_flyaffiliate_dismissed_';

	/**
	 * The action a dismissal request posts to.
	 *
	 * @var string
	 */
	const DISMISS_ACTION = 'flyaffiliate_dismiss_notice';

	/**
	 * Whether the hidden container for other plugins' notices is open.
	 *
	 * @var bool
	 */
	protected bool $hiding = false;

	/**
	 * {@inheritDoc}
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'admin_notices', [ $this, 'start_hiding' ], PHP_INT_MIN );
		add_action( 'all_admin_notices', [ $this, 'stop_hiding' ], PHP_INT_MAX );
		add_action( 'flyaffiliate_before_admin_app', [ $this, 'render' ] );
		add_action( 'admin_post_' . self::DISMISS_ACTION, [ $this, 'handle_dismiss' ] );
	}

	/**
	 * Open the hidden container every notice on FlyAffiliate's screen prints
	 * into, before any other `admin_notices` callback runs.
	 *
	 * WordPress's admin script moves `.notice` boxes printed anywhere else
	 * after the first `.wp-header-end`; the one in here is the page's only
	 * one, so those end up hidden too.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return void
	 */
	public function start_hiding(): void {
		if ( ! $this->is_flyaffiliate_screen() ) {
			return;
		}

		$this->hiding = true;

		echo '<div class="flyaffiliate-hidden-notices" hidden>';
		echo '<div class="wp-header-end"></div>';
	}

	/**
	 * Close the hidden container, after every `all_admin_notices` callback.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return void
	 */
	public function stop_hiding(): void {
		if ( ! $this->hiding ) {
			return;
		}

		$this->hiding = false;

		echo '</div>';
	}

	/**
	 * The notices to show on this request.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return array<string, array{message: string, type: string, dismissible: bool}>
	 */
	public function get_notices(): array {
		/**
		 * Filters the FlyAffiliate admin notices for the current request.
		 *
		 * @since FLYAFFILIATE_SINCE
		 *
		 * @param array $notices Notice id => definition. `type` is one of
		 *                       `info`, `success`, `warning`, `error`.
		 */
		return apply_filters( 'flyaffiliate_admin_notices', [] );
	}

	/**
	 * Render the notices, above the admin app.
	 *
	 * `inline` keeps WordPress's admin script from moving them into the
	 * hidden container with everyone else's.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( flyaffiliate_admin_capability() ) ) {
			return;
		}

		// FlyAffiliate's notices appear on FlyAffiliate's screen only, never on
		// another screen of the admin.
		if ( ! $this->is_flyaffiliate_screen() ) {
			return;
		}

		$user_id = get_current_user_id();

		foreach ( $this->get_notices() as $notice_id => $notice ) {
			if ( $this->is_dismissed( $notice_id, $user_id ) ) {
				continue;
			}

			$notice = wp_parse_args(
				$notice,
				[
					'message'     => '',
					'type'        => 'info',
					'dismissible' => true,
				]
			);

			if ( '' === $notice['message'] ) {
				continue;
			}

			$classes = 'notice inline notice-' . sanitize_html_class( $notice['type'] );

			if ( $notice['dismissible'] ) {
				$classes .= ' is-dismissible';
			}

			printf(
				'<div class="%1$s"><p>%2$s</p>%3$s</div>',
				esc_attr( $classes ),
				wp_kses_post( $notice['message'] ),
				$notice['dismissible'] ? $this->get_dismiss_link( $notice_id ) : '' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_dismiss_link() escapes its own output.
			);
		}
	}

	/**
	 * A link that dismisses a notice for the current user, for good.
	 *
	 * The `is-dismissible` class hides a notice for the current page load only.
	 * This link is what makes the dismissal stick.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string $notice_id Notice id.
	 *
	 * @return string
	 */
	protected function get_dismiss_link( string $notice_id ): string {
		$url = wp_nonce_url(
			add_query_arg(
				[
					'action' => self::DISMISS_ACTION,
					'notice' => rawurlencode( $notice_id ),
				],
				admin_url( 'admin-post.php' )
			),
			self::DISMISS_ACTION . $notice_id
		);

		return sprintf(
			'<p><a href="%1$s">%2$s</a></p>',
			esc_url( $url ),
			esc_html__( 'Do not show this again', 'flyaffiliate' )
		);
	}

	/**
	 * Record that the current user dismissed a notice.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return void
	 */
	public function handle_dismiss(): void {
		if ( ! current_user_can( flyaffiliate_admin_capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'flyaffiliate' ), 403 );
		}

		$notice_id = isset( $_GET['notice'] ) ? sanitize_key( wp_unslash( $_GET['notice'] ) ) : '';

		check_admin_referer( self::DISMISS_ACTION . $notice_id );

		if ( '' !== $notice_id ) {
			update_user_meta( get_current_user_id(), self::DISMISSED_META_PREFIX . $notice_id, 1 );
		}

		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}

	/**
	 * Whether a user has dismissed a notice.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string $notice_id Notice id.
	 * @param int    $user_id   User id.
	 *
	 * @return bool
	 */
	public function is_dismissed( string $notice_id, int $user_id ): bool {
		return (bool) get_user_meta( $user_id, self::DISMISSED_META_PREFIX . $notice_id, true );
	}

	/**
	 * Whether the current screen is FlyAffiliate's admin app.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return bool
	 */
	protected function is_flyaffiliate_screen(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		return $screen && 'toplevel_page_' . Menu::PARENT_SLUG === $screen->id;
	}
}
