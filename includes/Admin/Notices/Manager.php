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
 * Collects and renders the plugin's admin notices.
 *
 * Every notice is dismissible and only shown to a user who can act on it.
 * WordPress.org rejects a notice that cannot be dismissed, and a store owner
 * who cannot fix the thing being complained about should not be told about it.
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
	 * {@inheritDoc}
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'admin_notices', [ $this, 'render' ] );
		add_action( 'admin_post_' . self::DISMISS_ACTION, [ $this, 'handle_dismiss' ] );
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
	 * Render the notices.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( flyaffiliate_admin_capability() ) ) {
			return;
		}

		// FlyAffiliate's notices appear on FlyAffiliate's screen only. Notices
		// on someone else's screen are what WordPress.org's Guideline 11 is about.
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || 'toplevel_page_' . Menu::PARENT_SLUG !== $screen->id ) {
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

			$classes = 'notice notice-' . sanitize_html_class( $notice['type'] );

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
}
