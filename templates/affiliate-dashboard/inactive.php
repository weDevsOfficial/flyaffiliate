<?php
/**
 * Shown to an affiliate whose account is not active.
 *
 * @package FlyAffiliate
 *
 * @var \FlyAffiliate\Models\Affiliate $affiliate The affiliate.
 * @var string                         $notice    A status flag from a redirect.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FlyAffiliate\Models\Affiliate;
?>
<div class="flyaffiliate-area">
	<div class="flyaffiliate-state">
		<?php if ( Affiliate::STATUS_PENDING === $affiliate->get( 'status' ) ) : ?>
			<h2 class="flyaffiliate-state__title"><?php esc_html_e( 'Almost there', 'flyaffiliate' ); ?></h2>
			<p class="flyaffiliate-state__text"><?php esc_html_e( 'Your affiliate account is waiting to be activated. Check your email for the activation link, or contact the site owner.', 'flyaffiliate' ); ?></p>
		<?php else : ?>
			<h2 class="flyaffiliate-state__title"><?php esc_html_e( 'Your affiliate account is not active', 'flyaffiliate' ); ?></h2>
			<p class="flyaffiliate-state__text"><?php esc_html_e( 'Contact the site owner if you think this is a mistake.', 'flyaffiliate' ); ?></p>
		<?php endif; ?>
	</div>
</div>
