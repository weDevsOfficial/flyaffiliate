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
 * money has been sent — by hand, or by an automatic payout method once its
 * transfer settles — is what marks its commissions paid (ADR-0012, ADR-0016).
 *
 * @since 1.0.0
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
	 * Paid by hand, outside the plugin. Always available; other methods come
	 * through the `flyaffiliate_payout_methods` filter (`PayoutMethod\Manager`).
	 *
	 * @var string
	 */
	const METHOD_MANUAL = 'manual';

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
		'paid_at'      => 'datetime',
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
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function is_paid(): bool {
		return self::STATUS_PAID === $this->get( 'status' );
	}

	/**
	 * The commissions this payout covers.
	 *
	 * @since 1.0.0
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
