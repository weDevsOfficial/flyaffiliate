<?php
/**
 * The payout model.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\Models;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One row of `{prefix}flyaffiliate_payouts`: one payment to one affiliate,
 * covering one or more `unpaid` commissions.
 *
 * One row per affiliate per batch (a batch is what the admin screens call a
 * payout, as SliceWP does). A row is created `unpaid` — the money has not
 * left yet — and its commissions link to it through their own `payout_id`,
 * which keeps them out of the next batch. Marking the row paid, once the
 * store has sent the money by hand, is what marks its commissions paid
 * (ADR-0012; there is no automated transfer and no Dokan withdrawal for
 * affiliates, ADR-0006).
 *
 * @since FLYAFFILIATE_SINCE
 */
class Payout extends BaseModel {

	/**
	 * Created, money not sent yet.
	 *
	 * @var string
	 */
	const STATUS_UNPAID = 'unpaid';

	/**
	 * The money was sent; the commissions are paid.
	 *
	 * @var string
	 */
	const STATUS_PAID = 'paid';

	/**
	 * Paid by hand, outside the plugin. The only Phase 1 method.
	 *
	 * @var string
	 */
	const METHOD_MANUAL = 'manual';

	/**
	 * Every payout method, labelled.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return array<string, string> Method => translated label.
	 */
	public static function get_methods(): array {
		return [
			self::METHOD_MANUAL => __( 'Manual', 'flyaffiliate' ),
		];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @var string
	 */
	protected static string $table = 'flyaffiliate_payouts';

	/**
	 * {@inheritDoc}
	 *
	 * @var array<string, string>
	 */
	protected static array $columns = [
		'id'           => 'int',
		'batch_key'    => 'string',
		'affiliate_id' => 'int',
		'amount'       => 'money',
		'currency'     => 'string',
		'method'       => 'string',
		'status'       => 'string',
		'reference'    => 'string',
		'note'         => 'string',
		'period_start' => 'datetime',
		'period_end'   => 'datetime',
		'created_by'   => 'int',
		'created_at'   => 'datetime',
	];

	/**
	 * {@inheritDoc}
	 *
	 * @var string[]
	 */
	protected static array $updated_at_columns = [];

	/**
	 * Whether the money has been sent.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return bool
	 */
	public function is_paid(): bool {
		return self::STATUS_PAID === $this->get( 'status' );
	}

	/**
	 * The commissions this payout covers.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return Commission[]
	 */
	public function get_commissions(): array {
		if ( ! $this->exists() ) {
			return [];
		}

		return Commission::query(
			[
				'where'    => [ 'payout_id' => $this->get_id() ],
				'orderby'  => 'id',
				'order'    => 'ASC',
				'per_page' => -1,
			]
		);
	}
}
