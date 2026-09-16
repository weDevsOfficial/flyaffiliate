<?php
/**
 * The visit model.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\Models;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One row of `{prefix}flyaffiliate_visits`: one tracked click on a referral link.
 *
 * The visitor's IP address and user agent are stored as `wp_hash()` digests and
 * never as the raw values, so a visit holds no personal data (ADR-0007).
 *
 * @since FLYAFFILIATE_SINCE
 */
class Visit extends BaseModel {

	/**
	 * {@inheritDoc}
	 *
	 * @var string
	 */
	protected static string $table = 'flyaffiliate_visits';

	/**
	 * {@inheritDoc}
	 *
	 * A visit is written once and only ever updated to mark it converted, so
	 * there is no `updated_at`.
	 *
	 * @var array<string, string>
	 */
	protected static array $columns = [
		'id'              => 'int',
		'affiliate_id'    => 'int',
		'url'             => 'string',
		'referrer'        => 'string',
		'ip_hash'         => 'string',
		'user_agent_hash' => 'string',
		'converted'       => 'int',
		'order_id'        => 'int',
		'created_at'      => 'datetime',
	];

	/**
	 * {@inheritDoc}
	 *
	 * @var string[]
	 */
	protected static array $updated_at_columns = [];

	/**
	 * Whether this visit led to an order.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return bool
	 */
	public function is_converted(): bool {
		return 1 === (int) $this->get( 'converted', 0 );
	}
}
