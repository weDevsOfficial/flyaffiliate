<?php
/**
 * Shown to a logged-out visitor.
 *
 * @package FlyAffiliate
 *
 * @var string $login_url Where to log in.
 * @var string $notice    A status flag from a redirect.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

?>
<div class="flyaffiliate-area">
	<?php if ( 'activated' === $notice ) : ?>
		<div class="flyaffiliate-notice flyaffiliate-notice--success"><?php esc_html_e( 'Your affiliate account is active. Log in to open your dashboard.', 'flyaffiliate' ); ?></div>
	<?php endif; ?>
	<div class="flyaffiliate-state">
		<h2 class="flyaffiliate-state__title"><?php esc_html_e( 'Log in to see your dashboard', 'flyaffiliate' ); ?></h2>
		<p class="flyaffiliate-state__text"><?php esc_html_e( 'Your referral link, commissions and payouts are waiting behind your account.', 'flyaffiliate' ); ?></p>
		<a class="flyaffiliate-button" href="<?php echo esc_url( $login_url ); ?>"><?php esc_html_e( 'Log in', 'flyaffiliate' ); ?></a>
	</div>
</div>
