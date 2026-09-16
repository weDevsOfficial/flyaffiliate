<?php
/**
 * Shown to a logged-in user who is not an affiliate.
 *
 * @package FlyAffiliate
 *
 * @var string $register_url The registration page.
 * @var string $notice       A status flag from a redirect.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

?>
<div class="flyaffiliate-area">
	<?php if ( 'invalid_key' === $notice ) : ?>
		<div class="flyaffiliate-notice flyaffiliate-notice--error"><?php esc_html_e( 'That activation link is not valid, or has already been used.', 'flyaffiliate' ); ?></div>
	<?php endif; ?>
	<div class="flyaffiliate-state">
		<h2 class="flyaffiliate-state__title"><?php esc_html_e( 'You are not an affiliate yet', 'flyaffiliate' ); ?></h2>
		<p class="flyaffiliate-state__text"><?php esc_html_e( 'Join the programme to get a referral link and earn a commission on every sale you send our way.', 'flyaffiliate' ); ?></p>
		<?php if ( '' !== $register_url ) : ?>
			<a class="flyaffiliate-button" href="<?php echo esc_url( $register_url ); ?>"><?php esc_html_e( 'Join the affiliate programme', 'flyaffiliate' ); ?></a>
		<?php endif; ?>
	</div>
</div>
