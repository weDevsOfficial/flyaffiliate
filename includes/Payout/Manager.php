<?php
/**
 * Payout batches.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\Payout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FlyAffiliate\Contracts\PayoutMethod;
use FlyAffiliate\Models\Affiliate;
use FlyAffiliate\Models\Commission;
use FlyAffiliate\Models\Payout;
use FlyAffiliate\PayoutMethod\Manager as PayoutMethods;
use FlyAffiliate\Utilities\Money;
use WP_Error;

/**
 * Builds payout batches and takes their payments through to paid, the way
 * SliceWP's payouts and payments work (ADR-0012).
 *
 * A batch is one payment run: one `Payout` row — a payment — per affiliate,
 * covering that affiliate's `unpaid` commissions, never `pending` ones, which
 * have not matured. Creating a batch writes the rows `unpaid` and sets each
 * commission's `payout_id`, which is the key that keeps it out of the next
 * batch; the commission itself stays `unpaid` until the store has sent the
 * money and marks the payment paid, which is the only thing that marks a
 * commission `paid`. An unpaid payment can still be corrected: a commission
 * removed from it, or the payment deleted, goes back into the next batch.
 *
 * `preview()` and `create()` select the same rows through the same code, so what
 * the admin confirms is what gets paid.
 *
 * Each payment is paid by its affiliate's payout method (ADR-0016). Manual
 * payments wait for the admin as before; an automatic method is handed its
 * payments as soon as the payout is recorded, and marks each one paid itself
 * when the money arrives. While its transfer is in flight a payment is
 * *locked*: nothing here changes it by hand.
 *
 * @since FLYAFFILIATE_SINCE
 */
class Manager {

	/**
	 * Get a payout row by id.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param int $payout_id Payout id.
	 *
	 * @return Payout|null
	 */
	public function get( int $payout_id ): ?Payout {
		return Payout::find( $payout_id );
	}

	/**
	 * Query payout rows.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param array $args See {@see \FlyAffiliate\Models\BaseModel::query()}.
	 *
	 * @return Payout[]
	 */
	public function query( array $args = [] ): array {
		return Payout::query( $args );
	}

	/**
	 * Count payout rows.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param array $args See {@see \FlyAffiliate\Models\BaseModel::count()}.
	 *
	 * @return int
	 */
	public function count( array $args = [] ): int {
		return Payout::count( $args );
	}

	/**
	 * Every row in a batch.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string $batch_key The batch key.
	 *
	 * @return Payout[]
	 */
	public function get_batch( string $batch_key ): array {
		if ( '' === $batch_key ) {
			return [];
		}

		return Payout::query(
			[
				'where'    => [ 'batch_key' => $batch_key ],
				'orderby'  => 'id',
				'order'    => 'ASC',
				'per_page' => -1,
			]
		);
	}

	/**
	 * Work out what a batch would pay, without writing anything.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param array $args {
	 *     Batch selection.
	 *
	 *     @type string $mode           `all`, `selected` or `except`. Default `all`.
	 *     @type int[]  $affiliate_ids  The affiliates `selected` includes or `except` excludes.
	 *     @type float  $minimum_amount Leave out an affiliate whose unpaid total is below this. Default the payout setting.
	 *     @type string $period_start   Only commissions created on or after this `Y-m-d`.
	 *     @type string $period_end     Only commissions created on or before this `Y-m-d`.
	 *     @type array  $methods        Affiliate id => payout method id, for this payout only ("Switch to Manual").
	 * }
	 *
	 * @return array{rows: array<int, array{affiliate: Affiliate, commissions: Commission[], amount: float, method: PayoutMethod, recipient: string, warning: string}>, total: float, count: int, groups: array<string, array{label: string, automatic: bool, count: int, total: float}>}
	 */
	public function preview( array $args ): array {
		$args = wp_parse_args(
			$args,
			[
				'mode'           => 'all',
				'affiliate_ids'  => [],
				'minimum_amount' => (float) flyaffiliate_get_option( 'minimum_amount', 0 ),
				'period_start'   => '',
				'period_end'     => '',
				'methods'        => [],
			]
		);

		$affiliate_ids = array_values( array_filter( array_map( 'absint', (array) $args['affiliate_ids'] ) ) );
		$query         = [
			'where'    => [ 'status' => Commission::STATUS_UNPAID ],
			'orderby'  => 'id',
			'order'    => 'ASC',
			'per_page' => -1,
		];

		if ( 'selected' === $args['mode'] ) {
			$query['where']['affiliate_id'] = $affiliate_ids;
		}

		if ( '' !== $args['period_start'] ) {
			$query['after'] = $args['period_start'] . ' 00:00:00';
		}

		if ( '' !== $args['period_end'] ) {
			$query['before'] = $args['period_end'] . ' 23:59:59';
		}

		$by_affiliate = [];

		foreach ( Commission::query( $query ) as $commission ) {
			$affiliate_id = (int) $commission->get( 'affiliate_id' );

			if ( 'except' === $args['mode'] && in_array( $affiliate_id, $affiliate_ids, true ) ) {
				continue;
			}

			// A commission already in a batch is never paid twice, whatever its status says.
			if ( (int) $commission->get( 'payout_id', 0 ) > 0 ) {
				continue;
			}

			$by_affiliate[ $affiliate_id ][] = $commission;
		}

		$minimum_cents = Money::to_cents( (float) $args['minimum_amount'] );
		$overrides     = $this->sanitize_method_overrides( (array) $args['methods'] );
		$rows          = [];
		$total_cents   = 0;
		$group_cents   = [];

		foreach ( $by_affiliate as $affiliate_id => $commissions ) {
			$affiliate = Affiliate::find( $affiliate_id );

			if ( null === $affiliate ) {
				continue;
			}

			$cents = 0;

			foreach ( $commissions as $commission ) {
				$cents += $commission->get_amount_in_cents();
			}

			if ( $cents <= 0 || $cents < $minimum_cents ) {
				continue;
			}

			$method = $this->methods()->resolve_for( $affiliate, $overrides[ $affiliate_id ] ?? '' );
			$check  = $method->check_affiliate( $affiliate );

			$rows[ $affiliate_id ] = [
				'affiliate'   => $affiliate,
				'commissions' => $commissions,
				'amount'      => Money::from_cents( $cents ),
				'method'      => $method,
				'recipient'   => $method->get_recipient( $affiliate ),
				'warning'     => is_wp_error( $check ) ? $check->get_error_message() : '',
			];

			$total_cents                       += $cents;
			$group_cents[ $method->get_id() ][] = $cents;
		}

		// Grouped in the registry's order, so Manual comes first and the screen is stable.
		$groups = [];

		foreach ( $this->methods()->all() as $id => $method ) {
			if ( isset( $group_cents[ $id ] ) ) {
				$groups[ $id ] = [
					'label'     => $method->get_label(),
					'automatic' => $method->is_automatic(),
					'count'     => count( $group_cents[ $id ] ),
					'total'     => Money::from_cents( array_sum( $group_cents[ $id ] ) ),
				];
			}
		}

		return [
			'rows'    => $rows,
			'total'   => Money::from_cents( $total_cents ),
			'count'   => count( $rows ),
			'groups'  => $groups,
			'pending' => $this->count_pending( $args ),
		];
	}

	/**
	 * The per-payout method choices that name a method switched on.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param array $overrides Affiliate id => method id.
	 *
	 * @return array<int, string>
	 */
	protected function sanitize_method_overrides( array $overrides ): array {
		$enabled = $this->methods()->enabled();
		$clean   = [];

		foreach ( $overrides as $affiliate_id => $method_id ) {
			$method_id = sanitize_key( (string) $method_id );

			if ( absint( $affiliate_id ) > 0 && isset( $enabled[ $method_id ] ) ) {
				$clean[ absint( $affiliate_id ) ] = $method_id;
			}
		}

		return $clean;
	}

	/**
	 * The commissions the same selection would have caught, were they unpaid.
	 *
	 * A payout only ever pays unpaid commissions. When the preview comes back
	 * empty it is usually because the money is still pending, so the screen can
	 * say so instead of leaving the admin guessing.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param array $args The parsed selection.
	 *
	 * @return array{count:int, amount:float} How many are still pending, and how much.
	 */
	protected function count_pending( array $args ): array {
		$affiliate_ids = array_values( array_filter( array_map( 'absint', (array) $args['affiliate_ids'] ) ) );
		$query         = [
			'where'    => [ 'status' => Commission::STATUS_PENDING ],
			'orderby'  => 'id',
			'order'    => 'ASC',
			'per_page' => -1,
		];

		if ( 'selected' === $args['mode'] ) {
			$query['where']['affiliate_id'] = $affiliate_ids;
		}

		if ( '' !== $args['period_start'] ) {
			$query['after'] = $args['period_start'] . ' 00:00:00';
		}

		if ( '' !== $args['period_end'] ) {
			$query['before'] = $args['period_end'] . ' 23:59:59';
		}

		$count = 0;
		$cents = 0;

		foreach ( Commission::query( $query ) as $commission ) {
			if ( 'except' === $args['mode'] && in_array( (int) $commission->get( 'affiliate_id' ), $affiliate_ids, true ) ) {
				continue;
			}

			++$count;
			$cents += $commission->get_amount_in_cents();
		}

		return [
			'count'  => $count,
			'amount' => Money::from_cents( $cents ),
		];
	}

	/**
	 * Create a batch: one unpaid payment per affiliate, with every commission in it attached.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param array $args Batch selection, as for {@see preview()}, plus:
	 *
	 *     @type string $note      A description of the run, shown as its title.
	 *     @type string $reference A payment reference recorded on every row.
	 *     @type array  $expected  Affiliate id => the amount the admin saw on the preview. When
	 *                             given, a payout whose amounts have changed since is refused.
	 *
	 * @return array{batch_key: string, payouts: Payout[], total: float}|WP_Error
	 */
	public function create( array $args ) {
		$preview = $this->preview( $args );

		if ( 0 === $preview['count'] ) {
			return new WP_Error( 'flyaffiliate_empty_payout', __( 'There is nothing to pay: no unpaid commission matches that selection.', 'flyaffiliate' ), [ 'status' => 400 ] );
		}

		if ( ! empty( $args['expected'] ) && ! $this->matches_expected( $preview['rows'], (array) $args['expected'] ) ) {
			return new WP_Error(
				'flyaffiliate_payout_changed',
				__( 'What is owed changed since the preview: another payout may have taken some of these commissions. Check the preview again before paying.', 'flyaffiliate' ),
				[ 'status' => 409 ]
			);
		}

		$batch_key = wp_generate_uuid4();
		$now       = current_time( 'mysql', true );
		$payouts   = [];

		foreach ( $preview['rows'] as $affiliate_id => $row ) {
			$payout = new Payout();

			$payout->fill(
				[
					'batch_key'    => $batch_key,
					'affiliate_id' => $affiliate_id,
					'amount'       => $row['amount'],
					'currency'     => flyaffiliate_get_currency(),
					'method'       => $row['method']->get_id(),
					'status'       => Payout::STATUS_UNPAID,
					'reference'    => sanitize_text_field( (string) ( $args['reference'] ?? '' ) ),
					'note'         => sanitize_textarea_field( (string) ( $args['note'] ?? '' ) ),
					'period_start' => ! empty( $args['period_start'] ) ? $args['period_start'] . ' 00:00:00' : null,
					'period_end'   => ! empty( $args['period_end'] ) ? $args['period_end'] . ' 23:59:59' : null,
					'created_by'   => get_current_user_id(),
					'created_at'   => $now,
				]
			);

			if ( 0 === $payout->save() ) {
				return new WP_Error( 'flyaffiliate_payout_failed', __( 'The payout could not be saved.', 'flyaffiliate' ), [ 'status' => 500 ] );
			}

			// Attached, not paid: the status follows the payment's. An attach
			// that does not stick — the write failed, or another payout took
			// the commission first — would leave the payment promising money
			// for a commission paid elsewhere, so the whole batch is unwound.
			foreach ( $row['commissions'] as $commission ) {
				if ( ! $this->attach( $commission, $payout->get_id() ) ) {
					$this->unwind( $payouts, $payout );

					return new WP_Error(
						'flyaffiliate_payout_attach_failed',
						__( 'The payout could not be recorded: a commission is already in another payment, or would not attach. Nothing was saved; check the preview again.', 'flyaffiliate' ),
						[ 'status' => 409 ]
					);
				}
			}

			$payouts[] = $payout;

			/**
			 * Fires after an affiliate's payment is created, unpaid, with its commissions attached.
			 *
			 * @since FLYAFFILIATE_SINCE
			 *
			 * @param Payout       $payout      The payout row.
			 * @param Commission[] $commissions The commissions it covers.
			 */
			do_action( 'flyaffiliate_payout_created', $payout, $row['commissions'] );
		}

		/**
		 * Fires after a whole payout batch is recorded.
		 *
		 * @since FLYAFFILIATE_SINCE
		 *
		 * @param string   $batch_key The batch key.
		 * @param Payout[] $payouts   Every row in the batch.
		 */
		do_action( 'flyaffiliate_payout_batch_created', $batch_key, $payouts );

		$this->send( $batch_key, $payouts );

		return [
			'batch_key' => $batch_key,
			'payouts'   => $payouts,
			'total'     => $preview['total'],
		];
	}

	/**
	 * Whether a preview still pays exactly what the admin saw.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param array $rows     The preview's rows, by affiliate id.
	 * @param array $expected Affiliate id => amount.
	 *
	 * @return bool
	 */
	protected function matches_expected( array $rows, array $expected ): bool {
		$expected_cents = [];

		foreach ( $expected as $affiliate_id => $amount ) {
			$expected_cents[ absint( $affiliate_id ) ] = Money::to_cents( (float) $amount );
		}

		if ( count( $expected_cents ) !== count( $rows ) ) {
			return false;
		}

		foreach ( $rows as $affiliate_id => $row ) {
			if ( ! isset( $expected_cents[ $affiliate_id ] ) || Money::to_cents( $row['amount'] ) !== $expected_cents[ $affiliate_id ] ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Attach a commission to a payment, unless something else holds it already.
	 *
	 * One conditional write, so two payouts recorded at the same moment cannot
	 * both take the same commission (CONTEXT.md money rule 7): the second
	 * changes no row and is unwound.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param Commission $commission The commission, unpaid and free.
	 * @param int        $payout_id  The payment.
	 *
	 * @return bool Whether this payment now holds it.
	 */
	protected function attach( Commission $commission, int $payout_id ): bool {
		global $wpdb;

		$table = Commission::get_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- FlyAffiliate's own table; the interpolation is its name, the values go through prepare().
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET payout_id = %d, updated_at = %s WHERE id = %d AND status = %s AND ( payout_id IS NULL OR payout_id = 0 )",
				$payout_id,
				current_time( 'mysql', true ),
				$commission->get_id(),
				Commission::STATUS_UNPAID
			)
		);
		// phpcs:enable

		if ( 1 !== $updated ) {
			return false;
		}

		$commission->set( 'payout_id', $payout_id );

		return true;
	}

	/**
	 * Hand each automatic method its payments from a payout just recorded.
	 *
	 * A method that throws leaves its payments unpaid with nothing sent; the
	 * payout page offers to send them again.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string   $batch_key The payout.
	 * @param Payout[] $payouts   Its payments.
	 *
	 * @return void
	 */
	protected function send( string $batch_key, array $payouts ): void {
		foreach ( $this->group_by_method( $payouts ) as $id => $group ) {
			$method = $this->methods()->get( $id );

			if ( null === $method || ! $method->is_automatic() ) {
				continue;
			}

			try {
				$method->pay( $batch_key, $group );
			} catch ( \Throwable $e ) {
				/**
				 * Fires when an automatic payout method fails to start sending a payout.
				 *
				 * Its payments stay unpaid with nothing sent, and can be sent again.
				 *
				 * @since FLYAFFILIATE_SINCE
				 *
				 * @param string     $method_id The method.
				 * @param string     $batch_key The payout.
				 * @param \Throwable $error     What went wrong.
				 */
				do_action( 'flyaffiliate_payout_method_failed', $id, $batch_key, $e );
			}
		}
	}

	/**
	 * Payments grouped by the method that pays them.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param Payout[] $payouts Payments.
	 *
	 * @return array<string, Payout[]>
	 */
	protected function group_by_method( array $payouts ): array {
		$groups = [];

		foreach ( $payouts as $payout ) {
			$groups[ (string) $payout->get( 'method' ) ][] = $payout;
		}

		return $groups;
	}

	/**
	 * Mark a payment paid: the money was sent, so its commissions are paid.
	 *
	 * A payment already paid is left alone, so a second call changes nothing.
	 * An admin cannot mark a locked payment paid: its method is sending it, or
	 * already did. An admin who pays an automatic payment some other way —
	 * after its transfer failed, say — records it as Manual, so the payment
	 * says how it was really paid.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param int  $payout_id The payment.
	 * @param bool $by_method True when the payment's own method settles it: the money arrived.
	 *
	 * @return Payout|WP_Error
	 */
	public function mark_paid( int $payout_id, bool $by_method = false ) {
		$payout = $this->get( $payout_id );

		if ( null === $payout ) {
			return $this->not_found();
		}

		if ( $payout->is_paid() ) {
			return $payout;
		}

		if ( ! $by_method ) {
			$lock = $this->methods()->get_lock( $payout );

			if ( '' !== $lock ) {
				return $this->locked( $payout, $lock );
			}

			$method = $this->methods()->get( (string) $payout->get( 'method' ) );

			if ( null !== $method && $method->is_automatic() ) {
				$payout->set( 'method', Payout::METHOD_MANUAL );
			}
		}

		$payout->set( 'status', Payout::STATUS_PAID );
		// When the money went. A payment row is otherwise only stamped with the
		// day it was created, which is not the day anyone was paid.
		$payout->set( 'paid_at', current_time( 'mysql', true ) );

		if ( 0 === $payout->save() ) {
			return $this->save_failed();
		}

		foreach ( $payout->get_commissions() as $commission ) {
			// Only a commission still owed is paid: one rejected while the
			// payment waited stays rejected, whatever the payment says.
			if ( Commission::STATUS_UNPAID !== (string) $commission->get( 'status' ) ) {
				continue;
			}

			$this->set_commission_status( $commission, Commission::STATUS_PAID );
		}

		/**
		 * Fires after a payment is marked paid and its commissions with it.
		 *
		 * @since FLYAFFILIATE_SINCE
		 *
		 * @param Payout $payout The payment.
		 */
		do_action( 'flyaffiliate_payout_paid', $payout );

		return $payout;
	}

	/**
	 * Mark a payment unpaid again: it was marked paid by mistake, so its
	 * commissions go back to unpaid. They stay attached, so nothing pays them
	 * twice.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * A payment its method sent cannot be marked unpaid by hand; the method
	 * does it when the money comes back.
	 *
	 * @param int  $payout_id The payment.
	 * @param bool $by_method True when the payment's own method reports the money returned.
	 *
	 * @return Payout|WP_Error
	 */
	public function mark_unpaid( int $payout_id, bool $by_method = false ) {
		$payout = $this->get( $payout_id );

		if ( null === $payout ) {
			return $this->not_found();
		}

		if ( ! $payout->is_paid() ) {
			return $payout;
		}

		if ( ! $by_method ) {
			$lock = $this->methods()->get_lock( $payout );

			if ( '' !== $lock ) {
				return $this->locked( $payout, $lock );
			}
		}

		$payout->set( 'status', Payout::STATUS_UNPAID );
		// The payment did not happen after all, so neither did the date.
		$payout->set( 'paid_at', null );

		if ( 0 === $payout->save() ) {
			return $this->save_failed();
		}

		foreach ( $payout->get_commissions() as $commission ) {
			// The mirror of marking paid: only what this payment paid comes back.
			if ( Commission::STATUS_PAID !== (string) $commission->get( 'status' ) ) {
				continue;
			}

			$this->set_commission_status( $commission, Commission::STATUS_UNPAID );
		}

		/**
		 * Fires after a payment is marked unpaid again and its commissions with it.
		 *
		 * @since FLYAFFILIATE_SINCE
		 *
		 * @param Payout $payout The payment.
		 */
		do_action( 'flyaffiliate_payout_unpaid', $payout );

		return $payout;
	}

	/**
	 * Mark every unpaid manual payment of a batch paid.
	 *
	 * Payments an automatic method pays are left to it: they are paid when
	 * the money arrives, or sent again from the payout page.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string $batch_key The batch.
	 *
	 * @return int How many payments were marked paid.
	 */
	public function pay_batch( string $batch_key ): int {
		$paid = 0;

		foreach ( $this->get_batch( $batch_key ) as $payout ) {
			if ( $payout->is_paid() ) {
				continue;
			}

			$method = $this->methods()->get( (string) $payout->get( 'method' ) );

			if ( null === $method || $method->is_automatic() ) {
				continue;
			}

			if ( ! is_wp_error( $this->mark_paid( $payout->get_id() ) ) ) {
				++$paid;
			}
		}

		return $paid;
	}

	/**
	 * Send one unpaid payment again through its automatic method.
	 *
	 * For a payment whose transfer failed, or was never made. The payment is
	 * re-summed first, so a commission corrected while it waited is sent at
	 * its new amount.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param int $payout_id The payment.
	 *
	 * @return Payout|WP_Error The payment, as it is after the method was asked.
	 */
	public function retry( int $payout_id ) {
		$payout = $this->get( $payout_id );

		if ( null === $payout ) {
			return $this->not_found();
		}

		if ( $payout->is_paid() ) {
			return new WP_Error( 'flyaffiliate_payout_paid', __( 'This payment has been paid already.', 'flyaffiliate' ), [ 'status' => 409 ] );
		}

		$method = $this->methods()->get( (string) $payout->get( 'method' ) );

		if ( null === $method || ! $method->is_automatic() ) {
			return new WP_Error( 'flyaffiliate_payout_not_retryable', __( 'This payment is paid by hand, so there is nothing to send again.', 'flyaffiliate' ), [ 'status' => 400 ] );
		}

		$lock = $this->methods()->get_lock( $payout );

		if ( '' !== $lock ) {
			return $this->locked( $payout, $lock );
		}

		if ( ! $this->methods()->can_retry( $payout ) ) {
			return new WP_Error( 'flyaffiliate_payout_not_retryable', __( 'This payment cannot be sent again. Pay it another way and mark it paid, or delete it.', 'flyaffiliate' ), [ 'status' => 409 ] );
		}

		$payout = $this->resync( $payout_id );

		if ( null === $payout || Money::to_cents( $payout->get( 'amount', 0 ) ) <= 0 ) {
			return new WP_Error( 'flyaffiliate_empty_payout', __( 'This payment has nothing left to pay.', 'flyaffiliate' ), [ 'status' => 400 ] );
		}

		$result = $method->retry( $payout );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		/**
		 * Fires after a payment is handed to its method to be sent again.
		 *
		 * @since FLYAFFILIATE_SINCE
		 *
		 * @param Payout $payout The payment.
		 */
		do_action( 'flyaffiliate_payout_retried', $payout );

		return $this->get( $payout_id ) ?? $payout;
	}

	/**
	 * Ask each automatic method in a payout for the latest state of its unpaid payments.
	 *
	 * The payout page calls this while transfers are in flight, so a site
	 * that does not receive the service's notifications still settles.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string $batch_key The payout.
	 *
	 * @return void
	 */
	public function refresh_batch( string $batch_key ): void {
		$unpaid = array_filter( $this->get_batch( $batch_key ), static fn( Payout $payout ) => ! $payout->is_paid() );

		foreach ( $this->group_by_method( $unpaid ) as $id => $group ) {
			$method = $this->methods()->get( $id );

			if ( null === $method || ! $method->is_automatic() ) {
				continue;
			}

			try {
				$method->refresh( $group );
			} catch ( \Throwable $e ) {
				/** This action is documented in includes/Payout/Manager.php */
				do_action( 'flyaffiliate_payout_method_failed', $id, $batch_key, $e );
			}
		}
	}

	/**
	 * Where a payout stands: every payment counted by what is happening to it.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string $batch_key The payout.
	 *
	 * @return array|null The summary (see `summarize()`), or null when there is no such payout.
	 */
	public function summarize_batch( string $batch_key ): ?array {
		$payouts = $this->get_batch( $batch_key );

		if ( [] === $payouts ) {
			return null;
		}

		$first = $payouts[0];

		return [
			'batch_key'  => $batch_key,
			'note'       => (string) $first->get( 'note', '' ),
			'reference'  => (string) $first->get( 'reference', '' ),
			'created_at' => (string) $first->get( 'created_at', '' ),
			'created_by' => (int) $first->get( 'created_by', 0 ),
		] + $this->summarize( $payouts );
	}

	/**
	 * Count a payout's payments by what is happening to them.
	 *
	 * `status` is the payout's own: `paid` once every payment is; `processing`
	 * while any transfer is in flight; `needs_attention` when a transfer failed,
	 * was never sent, or its method is gone; `unpaid` when what is left is
	 * waiting to be paid by hand.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param Payout[]   $payouts The payout's payments.
	 * @param array|null $states  Payment id => transfer state, when already loaded.
	 *
	 * @return array{affiliates: int, total: float, paid_total: float, paid: int, counts: array<string, int>, in_flight: int, status: string, methods: string[]}
	 */
	public function summarize( array $payouts, ?array $states = null ): array {
		$states = $states ?? $this->methods()->get_states( $payouts );
		$counts = [
			'paid'          => 0,
			'manual_unpaid' => 0,
			'not_sent'      => 0,
			'queued'        => 0,
			'processing'    => 0,
			'pending'       => 0,
			'failed'        => 0,
			'unavailable'   => 0,
		];

		$total_cents = 0;
		$paid_cents  = 0;
		$methods     = [];

		foreach ( $payouts as $payout ) {
			$cents        = Money::to_cents( $payout->get( 'amount', 0 ) );
			$total_cents += $cents;
			$method_id    = (string) $payout->get( 'method' );

			$methods[ $method_id ] = true;

			if ( $payout->is_paid() ) {
				++$counts['paid'];
				$paid_cents += $cents;
				continue;
			}

			$method = $this->methods()->get( $method_id );
			$state  = $states[ $payout->get_id() ] ?? null;

			if ( null === $method ) {
				++$counts['unavailable'];
			} elseif ( ! $method->is_automatic() ) {
				++$counts['manual_unpaid'];
			} elseif ( null === $state ) {
				++$counts['not_sent'];
			} elseif ( PayoutMethod::STATE_PAID === $state['status'] ) {
				// The money arrived and the ledger has not caught up yet: still on its way, as far as anyone can act.
				++$counts['processing'];
			} else {
				++$counts[ $state['status'] ];
			}
		}

		$in_flight = $counts['queued'] + $counts['processing'] + $counts['pending'];

		if ( count( $payouts ) === $counts['paid'] ) {
			$status = 'paid';
		} elseif ( $in_flight > 0 ) {
			$status = 'processing';
		} elseif ( $counts['failed'] + $counts['not_sent'] + $counts['unavailable'] > 0 ) {
			$status = 'needs_attention';
		} else {
			$status = 'unpaid';
		}

		return [
			'affiliates' => count( $payouts ),
			'total'      => Money::from_cents( $total_cents ),
			'paid_total' => Money::from_cents( $paid_cents ),
			'paid'       => $counts['paid'],
			'counts'     => $counts,
			'in_flight'  => $in_flight,
			'status'     => $status,
			'methods'    => array_keys( $methods ),
		];
	}

	/**
	 * Whether a payment is locked against changes by hand.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param Payout $payout The payment.
	 *
	 * @return bool
	 */
	public function is_locked( Payout $payout ): bool {
		return $this->methods()->is_locked( $payout );
	}

	/**
	 * Re-sum an unpaid payment from the commissions it holds.
	 *
	 * An admin edited or moved a commission inside the payment (SliceWP lets
	 * them), so the promise is recounted: the amount is the sum of the unpaid
	 * commissions still in it — the ones `mark_paid()` would pay. A paid
	 * payment is left as it was paid, and so is a locked one: its method is
	 * sending the amount it was given.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param int $payout_id The payment.
	 *
	 * @return Payout|null The payment, or null when there is none.
	 */
	public function resync( int $payout_id ): ?Payout {
		global $wpdb;

		$payout = $this->get( $payout_id );

		if ( null === $payout || $payout->is_paid() || $this->methods()->is_locked( $payout ) ) {
			return $payout;
		}

		$table = Commission::get_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- FlyAffiliate's own table; the interpolation is its name, the values go through prepare().
		$sum = (float) $wpdb->get_var(
			$wpdb->prepare( "SELECT COALESCE( SUM( amount ), 0 ) FROM {$table} WHERE payout_id = %d AND status = %s", $payout_id, Commission::STATUS_UNPAID )
		);
		// phpcs:enable

		if ( Money::to_cents( $sum ) !== Money::to_cents( (float) $payout->get( 'amount', 0 ) ) ) {
			$payout->set( 'amount', Money::round( $sum ) );
			$payout->save();
		}

		return $payout;
	}

	/**
	 * Take a commission out of an unpaid payment, so the next batch picks it up.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param int $payout_id     The payment.
	 * @param int $commission_id The commission.
	 *
	 * @return Payout|WP_Error The payment, with the amount reduced.
	 */
	public function remove_commission( int $payout_id, int $commission_id ) {
		$payout = $this->get( $payout_id );

		if ( null === $payout ) {
			return $this->not_found();
		}

		if ( $payout->is_paid() ) {
			return new WP_Error( 'flyaffiliate_payout_paid', __( 'A commission cannot be removed from a payment that has been paid.', 'flyaffiliate' ), [ 'status' => 409 ] );
		}

		$lock = $this->methods()->get_lock( $payout );

		if ( '' !== $lock ) {
			return $this->locked( $payout, $lock );
		}

		$commission = flyaffiliate()->commission->get( $commission_id );

		if ( null === $commission || (int) $commission->get( 'payout_id', 0 ) !== $payout_id ) {
			return new WP_Error( 'flyaffiliate_commission_not_in_payout', __( 'That commission is not part of this payment.', 'flyaffiliate' ), [ 'status' => 404 ] );
		}

		$commission->set( 'payout_id', 0 );
		$commission->save();

		$payout->set( 'amount', Money::from_cents( max( 0, Money::to_cents( $payout->get( 'amount', 0 ) ) - $commission->get_amount_in_cents() ) ) );

		if ( 0 === $payout->save() ) {
			return $this->save_failed();
		}

		/**
		 * Fires after a commission is taken out of an unpaid payment.
		 *
		 * @since FLYAFFILIATE_SINCE
		 *
		 * @param Payout     $payout     The payment.
		 * @param Commission $commission The commission, no longer attached.
		 */
		do_action( 'flyaffiliate_payout_commission_removed', $payout, $commission );

		return $payout;
	}

	/**
	 * Delete an unpaid payment. Its commissions are detached and stay unpaid.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param int $payout_id The payment.
	 *
	 * @return true|WP_Error
	 */
	public function delete( int $payout_id ) {
		$payout = $this->get( $payout_id );

		if ( null === $payout ) {
			return $this->not_found();
		}

		if ( $payout->is_paid() ) {
			return new WP_Error( 'flyaffiliate_payout_paid', __( 'A payment that has been paid cannot be deleted.', 'flyaffiliate' ), [ 'status' => 409 ] );
		}

		$lock = $this->methods()->get_lock( $payout );

		if ( '' !== $lock ) {
			return $this->locked( $payout, $lock );
		}

		foreach ( $payout->get_commissions() as $commission ) {
			$commission->set( 'payout_id', 0 );
			$commission->save();
		}

		$snapshot = clone $payout;

		if ( ! $payout->delete() ) {
			return $this->save_failed();
		}

		/**
		 * Fires after an unpaid payment is deleted and its commissions detached.
		 *
		 * @since FLYAFFILIATE_SINCE
		 *
		 * @param int    $payout_id The deleted payment's id.
		 * @param Payout $payout    The payment as it was.
		 */
		do_action( 'flyaffiliate_payout_deleted', $payout_id, $snapshot );

		return true;
	}

	/**
	 * Delete a whole batch, as long as none of its payments has been paid.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string $batch_key The batch.
	 *
	 * @return int|WP_Error How many payments were deleted.
	 */
	public function delete_batch( string $batch_key ) {
		$payouts = $this->get_batch( $batch_key );

		if ( [] === $payouts ) {
			return $this->not_found();
		}

		$states = $this->methods()->get_states( $payouts );

		foreach ( $payouts as $payout ) {
			if ( $payout->is_paid() ) {
				return new WP_Error( 'flyaffiliate_payout_batch_paid', __( 'A payout with a paid payment cannot be deleted.', 'flyaffiliate' ), [ 'status' => 409 ] );
			}

			$lock = $this->methods()->get_lock( $payout, $states );

			if ( '' !== $lock ) {
				return $this->locked( $payout, $lock );
			}
		}

		$deleted = 0;

		foreach ( $payouts as $payout ) {
			if ( true === $this->delete( $payout->get_id() ) ) {
				++$deleted;
			}
		}

		return $deleted;
	}

	/**
	 * Move a commission between unpaid and paid as its payment changes.
	 *
	 * Written here rather than through `Commission\Manager::set_status()`,
	 * whose transitions keep `paid` out of reach of everything but a payment.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param Commission $commission The commission.
	 * @param string     $status     `paid` or `unpaid`.
	 *
	 * @return void
	 */
	protected function set_commission_status( Commission $commission, string $status ): void {
		$from = (string) $commission->get( 'status' );

		if ( $from === $status ) {
			return;
		}

		$commission->set( 'status', $status );

		if ( 0 === $commission->save() ) {
			return;
		}

		/** This action is documented in includes/Commission/Manager.php */
		do_action( 'flyaffiliate_commission_status_changed', $commission, $status, $from );
	}

	/**
	 * Undo a half-written batch: detach every commission and drop the rows.
	 *
	 * The model layer writes one row at a time with no transaction, so a batch
	 * that fails midway is taken apart here rather than left behind.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param Payout[] $saved   The payments already written.
	 * @param Payout   $current The payment being written when it failed.
	 *
	 * @return void
	 */
	protected function unwind( array $saved, Payout $current ): void {
		foreach ( array_merge( $saved, [ $current ] ) as $payout ) {
			foreach ( $payout->get_commissions() as $commission ) {
				$commission->set( 'payout_id', 0 );
				$commission->save();
			}

			$payout->delete();
		}
	}

	/**
	 * The error for a payment that does not exist.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return WP_Error
	 */
	protected function not_found(): WP_Error {
		return new WP_Error( 'flyaffiliate_payout_not_found', __( 'No payment with that ID.', 'flyaffiliate' ), [ 'status' => 404 ] );
	}

	/**
	 * The error for a write that failed.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return WP_Error
	 */
	protected function save_failed(): WP_Error {
		return new WP_Error( 'flyaffiliate_payout_failed', __( 'The payment could not be saved.', 'flyaffiliate' ), [ 'status' => 500 ] );
	}

	/**
	 * The batches, newest first, one summary per batch key.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param int $per_page Batches per page.
	 * @param int $page     1-based page.
	 *
	 * @return array{batches: array<int, array{batch_key: string, note: string, created_at: string, created_by: int, affiliates: int, paid: int, total: float, paid_total: float}>, total: int}
	 */
	public function get_batches( int $per_page = 20, int $page = 1 ): array {
		global $wpdb;

		$table = Payout::get_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- FlyAffiliate's own table; the only interpolation is its name.
		$total = (int) $wpdb->get_var( "SELECT COUNT( DISTINCT batch_key ) FROM {$table}" );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- FlyAffiliate's own table; the only interpolation is its name, the paging values are placeholders.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT batch_key, MIN( note ) AS note, MIN( created_at ) AS created_at, MIN( created_by ) AS created_by, COUNT(*) AS affiliates,
					SUM( CASE WHEN status = %s THEN 1 ELSE 0 END ) AS paid,
					COALESCE( SUM( amount ), 0 ) AS total,
					COALESCE( SUM( CASE WHEN status = %s THEN amount ELSE 0 END ), 0 ) AS paid_total
				FROM {$table} GROUP BY batch_key ORDER BY created_at DESC, batch_key DESC LIMIT %d OFFSET %d",
				Payout::STATUS_PAID,
				Payout::STATUS_PAID,
				max( 1, $per_page ),
				max( 0, ( $page - 1 ) * $per_page )
			)
		);
		// phpcs:enable

		$batches = [];

		foreach ( (array) $rows as $row ) {
			$batches[ (string) $row->batch_key ] = [
				'batch_key'  => (string) $row->batch_key,
				'note'       => (string) $row->note,
				'created_at' => (string) $row->created_at,
				'created_by' => (int) $row->created_by,
				'affiliates' => (int) $row->affiliates,
				'paid'       => (int) $row->paid,
				'total'      => (float) $row->total,
				'paid_total' => (float) $row->paid_total,
			];
		}

		// Where each payout stands needs its payments' transfer states, which
		// only their methods know: load the page's payments once, ask each
		// method once, and count.
		if ( [] !== $batches ) {
			$payouts  = Payout::query(
				[
					'where'    => [ 'batch_key' => array_keys( $batches ) ],
					'orderby'  => 'id',
					'order'    => 'ASC',
					'per_page' => -1,
				]
			);
			$states   = $this->methods()->get_states( $payouts );
			$by_batch = [];

			foreach ( $payouts as $payout ) {
				$by_batch[ (string) $payout->get( 'batch_key' ) ][] = $payout;
			}

			foreach ( $by_batch as $batch_key => $group ) {
				$summary = $this->summarize( $group, $states );

				$batches[ $batch_key ]['counts']    = $summary['counts'];
				$batches[ $batch_key ]['in_flight'] = $summary['in_flight'];
				$batches[ $batch_key ]['status']    = $summary['status'];
				$batches[ $batch_key ]['methods']   = $summary['methods'];
			}
		}

		return [
			'batches' => array_values( $batches ),
			'total'   => $total,
		];
	}

	/**
	 * The payout method registry.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return PayoutMethods
	 */
	protected function methods(): PayoutMethods {
		return flyaffiliate()->payout_method;
	}

	/**
	 * The error for a change a locked payment does not allow.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param Payout $payout The payment.
	 * @param string $lock   Why it is locked.
	 *
	 * @return WP_Error
	 */
	protected function locked( Payout $payout, string $lock ): WP_Error {
		return new WP_Error(
			'flyaffiliate_payout_locked',
			$this->methods()->get_lock_message( $payout, $lock ),
			[
				'status' => 409,
				'lock'   => $lock,
			]
		);
	}
}
