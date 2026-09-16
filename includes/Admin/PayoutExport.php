<?php
/**
 * The payout CSV download.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FlyAffiliate\Contracts\Hookable;
use FlyAffiliate\Payout\CsvExporter;

/**
 * Streams a payout batch as CSV.
 *
 * A file download needs a full-page request the browser can save, which a
 * REST call cannot give it, so this is the one admin-post handler the React
 * app links to. The link carries a nonce the app reads from its script data.
 *
 * @since FLYAFFILIATE_SINCE
 */
class PayoutExport implements Hookable {

	/**
	 * The admin-post action.
	 *
	 * @var string
	 */
	const ACTION = 'flyaffiliate_payout_csv';

	/**
	 * {@inheritDoc}
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'admin_post_' . self::ACTION, [ $this, 'download' ] );
	}

	/**
	 * The download URL of a batch, nonce included.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string $batch_key The batch. Empty yields a base URL the app appends the key to.
	 *
	 * @return string
	 */
	public static function get_url( string $batch_key = '' ): string {
		$args = [
			'action'   => self::ACTION,
			'_wpnonce' => wp_create_nonce( self::ACTION ),
		];

		if ( '' !== $batch_key ) {
			$args['batch'] = $batch_key;
		}

		return add_query_arg( $args, admin_url( 'admin-post.php' ) );
	}

	/**
	 * Verify the request and stream the file.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return void
	 */
	public function download(): void {
		if ( ! current_user_can( flyaffiliate_admin_capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'flyaffiliate' ), 403 );
		}

		check_admin_referer( self::ACTION );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified just above.
		$batch_key = isset( $_GET['batch'] ) ? sanitize_text_field( wp_unslash( $_GET['batch'] ) ) : '';
		$payouts   = '' === $batch_key ? [] : flyaffiliate()->payout->get_batch( $batch_key );

		if ( [] === $payouts ) {
			wp_die( esc_html__( 'No payout batch by that key.', 'flyaffiliate' ), 404 );
		}

		( new CsvExporter() )->download( $payouts, $batch_key );
	}
}
