<?php
/**
 * The affiliate registration form.
 *
 * @package FlyAffiliate
 *
 * @var string                              $action        The admin-post action.
 * @var bool                                $enabled       Whether registration is open.
 * @var string                              $registered    `active`, `pending` or empty, after a signup.
 * @var string                              $error         An error message, or empty.
 * @var \FlyAffiliate\Models\Affiliate|null $affiliate     The logged-in user's affiliate record, if any.
 * @var string                              $dashboard_url The affiliate dashboard page.
 * @var string                              $email         The logged-in user's email, to prefill.
 * @var string                              $redirect_to   Where to return after the post.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

?>
<div class="flyaffiliate-area flyaffiliate-register">
	<?php if ( 'active' === $registered ) : ?>
		<div class="flyaffiliate-state flyaffiliate-state--success">
			<h2 class="flyaffiliate-state__title"><?php esc_html_e( 'You are in', 'flyaffiliate' ); ?></h2>
			<p class="flyaffiliate-state__text"><?php esc_html_e( 'Your affiliate account is active. Grab your referral link and start sharing.', 'flyaffiliate' ); ?></p>
			<?php if ( '' !== $dashboard_url ) : ?>
				<a class="flyaffiliate-button" href="<?php echo esc_url( $dashboard_url ); ?>"><?php esc_html_e( 'Open your dashboard', 'flyaffiliate' ); ?></a>
			<?php endif; ?>
		</div>
	<?php elseif ( 'pending' === $registered ) : ?>
		<div class="flyaffiliate-state flyaffiliate-state--success">
			<h2 class="flyaffiliate-state__title"><?php esc_html_e( 'Check your email', 'flyaffiliate' ); ?></h2>
			<p class="flyaffiliate-state__text"><?php esc_html_e( 'We sent you an activation link. Follow it to open your dashboard.', 'flyaffiliate' ); ?></p>
		</div>
	<?php elseif ( null !== $affiliate ) : ?>
		<div class="flyaffiliate-state">
			<h2 class="flyaffiliate-state__title"><?php esc_html_e( 'You are already an affiliate', 'flyaffiliate' ); ?></h2>
			<p class="flyaffiliate-state__text"><?php esc_html_e( 'Your referral link and earnings are on your dashboard.', 'flyaffiliate' ); ?></p>
			<?php if ( '' !== $dashboard_url ) : ?>
				<a class="flyaffiliate-button" href="<?php echo esc_url( $dashboard_url ); ?>"><?php esc_html_e( 'Open your dashboard', 'flyaffiliate' ); ?></a>
			<?php endif; ?>
		</div>
	<?php elseif ( ! $enabled ) : ?>
		<div class="flyaffiliate-state">
			<h2 class="flyaffiliate-state__title"><?php esc_html_e( 'Registration is closed', 'flyaffiliate' ); ?></h2>
			<p class="flyaffiliate-state__text"><?php esc_html_e( 'The affiliate programme is not taking new members right now.', 'flyaffiliate' ); ?></p>
		</div>
	<?php else : ?>
		<p class="flyaffiliate-intro"><?php esc_html_e( 'Tell us where to reach you and how you plan to share us. Your referral link is ready the moment your account is active.', 'flyaffiliate' ); ?></p>

		<?php if ( '' !== $error ) : ?>
			<div class="flyaffiliate-notice flyaffiliate-notice--error"><?php echo esc_html( $error ); ?></div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="flyaffiliate-form">
			<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>" />
			<input type="hidden" name="redirect_to" value="<?php echo esc_url( $redirect_to ); ?>" />
			<?php wp_nonce_field( $action ); ?>

			<p>
				<label for="flyaffiliate-register-email"><?php esc_html_e( 'Email address', 'flyaffiliate' ); ?></label>
				<input type="email" id="flyaffiliate-register-email" name="email" value="<?php echo esc_attr( $email ); ?>" required />
			</p>
			<p>
				<label for="flyaffiliate-register-promo"><?php esc_html_e( 'How will you promote us?', 'flyaffiliate' ); ?></label>
				<textarea id="flyaffiliate-register-promo" name="promo_method" rows="4"></textarea>
			</p>
			<p>
				<button type="submit" class="flyaffiliate-button"><?php esc_html_e( 'Join the affiliate programme', 'flyaffiliate' ); ?></button>
			</p>
			<p class="flyaffiliate-muted"><?php esc_html_e( 'We will email you a link to activate your account. No password needed.', 'flyaffiliate' ); ?></p>
		</form>
	<?php endif; ?>
</div>
