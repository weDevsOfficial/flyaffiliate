<?php
/**
 * Payout batch CSV export.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\Payout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FlyAffiliate\Models\Payout;

/**
 * Streams a payout batch as CSV: one line per affiliate, with the fields a
 * bookkeeper needs to make the payment and reconcile it afterwards.
 *
 * @since FLYAFFILIATE_SINCE
 */
class CsvExporter {

	/**
	 * The column headings, in order.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return string[]
	 */
	public function get_headings(): array {
		return [
			__( 'Payout ID', 'flyaffiliate' ),
			__( 'Affiliate ID', 'flyaffiliate' ),
			__( 'Affiliate', 'flyaffiliate' ),
			__( 'Payment email', 'flyaffiliate' ),
			__( 'Amount', 'flyaffiliate' ),
			__( 'Currency', 'flyaffiliate' ),
			__( 'Commissions', 'flyaffiliate' ),
			__( 'Method', 'flyaffiliate' ),
			__( 'Reference', 'flyaffiliate' ),
			__( 'Date', 'flyaffiliate' ),
			__( 'Status', 'flyaffiliate' ),
		];
	}

	/**
	 * The rows for a batch, as plain arrays.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param Payout[] $payouts The batch.
	 *
	 * @return array<int, array<int, string>>
	 */
	public function get_rows( array $payouts ): array {
		$rows = [];

		foreach ( $payouts as $payout ) {
			$affiliate = flyaffiliate()->affiliate->get( (int) $payout->get( 'affiliate_id' ) );

			$rows[] = array_map(
				[ $this, 'escape_cell' ],
				[
					(string) $payout->get_id(),
					(string) $payout->get( 'affiliate_id' ),
					null === $affiliate ? '' : $affiliate->get_display_name(),
					null === $affiliate ? '' : $affiliate->get_payment_email(),
					number_format( (float) $payout->get( 'amount', 0 ), 2, '.', '' ),
					(string) $payout->get( 'currency', '' ),
					(string) count( $payout->get_commissions() ),
					(string) $payout->get( 'method', '' ),
					(string) $payout->get( 'reference', '' ),
					(string) $payout->get( 'created_at', '' ),
					(string) $payout->get( 'status', '' ),
				]
			);
		}

		return $rows;
	}

	/**
	 * Send a batch to the browser as a CSV download and stop.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param Payout[] $payouts   The batch.
	 * @param string   $batch_key The batch key, for the file name.
	 *
	 * @return void
	 */
	public function download( array $payouts, string $batch_key ): void {
		$filename = 'flyaffiliate-payout-' . sanitize_file_name( substr( $batch_key, 0, 8 ) ) . '-' . gmdate( 'Y-m-d' ) . '.csv';

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		// A CSV download is streamed, not written to disk: WP_Filesystem has no
		// stream API and buffering a large batch into a string to echo it is
		// what this export exists to avoid. See docs/wporg-accepted-warnings.md.
		$output = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		fputcsv( $output, $this->get_headings() );

		foreach ( $this->get_rows( $payouts ) as $row ) {
			fputcsv( $output, $row );
		}

		fclose( $output ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		exit;
	}

	/**
	 * Neutralise a cell a spreadsheet would otherwise execute as a formula.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string $value The cell.
	 *
	 * @return string
	 */
	protected function escape_cell( string $value ): string {
		if ( '' !== $value && in_array( $value[0], [ '=', '+', '-', '@', "\t", "\r" ], true ) ) {
			return "'" . $value;
		}

		return $value;
	}
}
