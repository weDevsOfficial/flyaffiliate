<?php
/**
 * The notice above a store's products: the store set its own affiliate terms.
 *
 * Shown to affiliates and vendors only. Override it by copying it to
 * yourtheme/flyaffiliate/dokan/store-notice.php.
 *
 * @package FlyAffiliate
 *
 * @since FLYAFFILIATE_SINCE
 *
 * @var array<string, mixed> $args {
 *     @type array  $terms The vendor's terms: `rate`, `hold_days`, `overridden`, `store_name`.
 *     @type string $rate  The rate, formatted, without the percent sign.
 *     @type string $lock  When the commission is paid, as a phrase.
 * }
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="dokan-alert dokan-alert-info flyaffiliate-store-notice">
	<p>
		<strong><?php esc_html_e( 'Affiliate program', 'flyaffiliate' ); ?></strong>
		<?php
		printf(
			/* translators: 1: store name, 2: the rate, e.g. 15, 3: when the commission is paid, e.g. "paid 30 days after the sale" */
			esc_html__( '%1$s set its own terms: affiliates earn %2$s%% on its products, %3$s.', 'flyaffiliate' ),
			esc_html( (string) $args['terms']['store_name'] ),
			esc_html( (string) $args['rate'] ),
			esc_html( (string) $args['lock'] )
		);
		?>
	</p>
</div>
