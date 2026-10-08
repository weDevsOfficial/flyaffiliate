<?php
/**
 * The referral link model.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\Models;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One row of `{prefix}flyaffiliate_referral_links`: a page of the site an
 * affiliate generated a referral link for.
 *
 * The row is the affiliate's list, nothing more. Tracking reads the referral
 * variable on any page, so the link works whether or not a row exists, and
 * its visits are counted from the visits table rather than stored here.
 *
 * @since FLYAFFILIATE_SINCE
 */
class ReferralLink extends BaseModel {

	/**
	 * {@inheritDoc}
	 *
	 * @var string
	 */
	protected static string $table = 'flyaffiliate_referral_links';

	/**
	 * {@inheritDoc}
	 *
	 * `url` is the page without the referral variable; `url_hash` is its MD5,
	 * which carries the unique key a TEXT column cannot.
	 *
	 * @var array<string, string>
	 */
	protected static array $columns = [
		'id'           => 'int',
		'affiliate_id' => 'int',
		'url'          => 'string',
		'url_hash'     => 'string',
		'created_at'   => 'datetime',
	];

	/**
	 * {@inheritDoc}
	 *
	 * A link is never edited: a different page is a different link.
	 *
	 * @var string[]
	 */
	protected static array $updated_at_columns = [];
}
