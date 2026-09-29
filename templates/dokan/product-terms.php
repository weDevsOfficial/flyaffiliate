<?php
/**
 * What an affiliate earns on one product, in the shop loop and on the product page.
 *
 * Shown to affiliates and vendors only. Override it by copying it to
 * yourtheme/flyaffiliate/dokan/product-terms.php.
 *
 * @package FlyAffiliate
 *
 * @since FLYAFFILIATE_SINCE
 *
 * @var array<string, mixed> $args {
 *     @type array  $terms   The terms: `rate`, `hold_days`, `overridden`, `store_name`.
 *     @type string $rate    The rate, formatted, without the percent sign.
 *     @type string $lock    When the commission is paid, as a phrase.
 *     @type string $context `loop` or `single`.
 * }
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="flyaffiliate-terms flyaffiliate-terms--<?php echo esc_attr( (string) $args['context'] ); ?>">
	<span class="flyaffiliate-terms__rate">
		<?php
		printf(
			/* translators: %s: the rate, e.g. 15 */
			esc_html__( 'Affiliate commission: %s%%', 'flyaffiliate' ),
			esc_html( (string) $args['rate'] )
		);
		?>
	</span>
	<span class="flyaffiliate-terms__lock"><?php echo esc_html( (string) $args['lock'] ); ?></span>
	<?php if ( ! empty( $args['terms']['overridden'] ) && 'single' === $args['context'] ) : ?>
		<span class="flyaffiliate-terms__origin">
			<?php
			printf(
				/* translators: %s: store name */
				esc_html__( 'Set by %s', 'flyaffiliate' ),
				esc_html( (string) $args['terms']['store_name'] )
			);
			?>
		</span>
	<?php endif; ?>
</div>
