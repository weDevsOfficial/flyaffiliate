<?php
/**
 * The vendor's affiliate settings, on Dokan's legacy vendor dashboard.
 *
 * Override it by copying it to yourtheme/flyaffiliate/dokan/vendor-settings.php.
 *
 * @package FlyAffiliate
 *
 * @since FLYAFFILIATE_SINCE
 *
 * @var array<string, mixed> $args {
 *     @type array  $settings      The vendor's `override`, `rate` and `lock_days`.
 *     @type array  $copy          The help texts: `override`, `rate`, `lock_days`.
 *     @type string $max_rate      The highest rate a vendor can set, formatted.
 *     @type int    $max_lock_days The longest lock in days.
 *     @type bool   $saved            Whether the form was just saved.
 *     @type string $error            Why the last save failed, if it did.
 *     @type string $nonce_action     The nonce action.
 *     @type string $nonce_field      The nonce field name.
 * }
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$flyaffiliate_settings = (array) ( $args['settings'] ?? [] );
$flyaffiliate_own      = 'on' === ( $flyaffiliate_settings['override'] ?? 'off' );
?>

<?php if ( ! empty( $args['saved'] ) ) : ?>
	<div class="dokan-alert dokan-alert-success">
		<?php esc_html_e( 'Your affiliate settings are saved.', 'flyaffiliate' ); ?>
	</div>
<?php endif; ?>

<?php if ( ! empty( $args['error'] ) ) : ?>
	<div class="dokan-alert dokan-alert-danger">
		<?php echo esc_html( $args['error'] ); ?>
	</div>
<?php endif; ?>

<div class="flyaffiliate-vendor-settings">
	<form method="post" id="flyaffiliate-vendor-settings-form" action="" class="dokan-form-horizontal">

		<div class="dokan-form-group">
			<label class="dokan-w3 dokan-control-label" for="flyaffiliate-override">
				<?php esc_html_e( 'Use my own settings', 'flyaffiliate' ); ?>
			</label>
			<div class="dokan-w5 dokan-text-left">
				<div class="checkbox">
					<label>
						<input type="checkbox" id="flyaffiliate-override" name="flyaffiliate_override" value="on" <?php checked( $flyaffiliate_own ); ?>>
						<?php esc_html_e( 'Set my own affiliate rate and commission lock', 'flyaffiliate' ); ?>
					</label>
				</div>
				<span class="dokan-page-help"><?php echo esc_html( (string) $args['copy']['override'] ); ?></span>
			</div>
		</div>

		<div class="dokan-form-group">
			<label class="dokan-w3 dokan-control-label" for="flyaffiliate-rate">
				<?php esc_html_e( 'Affiliate rate (%)', 'flyaffiliate' ); ?>
			</label>
			<div class="dokan-w5 dokan-text-left">
				<input
					type="number"
					id="flyaffiliate-rate"
					name="flyaffiliate_rate"
					class="dokan-form-control"
					min="0"
					max="<?php echo esc_attr( (string) $args['max_rate'] ); ?>"
					step="0.01"
					value="<?php echo esc_attr( (string) ( $flyaffiliate_settings['rate'] ?? '' ) ); ?>"
				>
				<span class="dokan-page-help"><?php echo esc_html( (string) $args['copy']['rate'] ); ?></span>
			</div>
		</div>

		<div class="dokan-form-group">
			<label class="dokan-w3 dokan-control-label" for="flyaffiliate-lock-days">
				<?php esc_html_e( 'Commission lock (days)', 'flyaffiliate' ); ?>
			</label>
			<div class="dokan-w5 dokan-text-left">
				<input
					type="number"
					id="flyaffiliate-lock-days"
					name="flyaffiliate_lock_days"
					class="dokan-form-control"
					min="0"
					max="<?php echo esc_attr( (string) absint( $args['max_lock_days'] ) ); ?>"
					step="1"
					value="<?php echo esc_attr( (string) absint( $flyaffiliate_settings['lock_days'] ?? 0 ) ); ?>"
				>
				<span class="dokan-page-help"><?php echo esc_html( (string) $args['copy']['lock_days'] ); ?></span>
			</div>
		</div>

		<?php wp_nonce_field( (string) $args['nonce_action'], (string) $args['nonce_field'] ); ?>

		<div class="dokan-form-group">
			<div class="dokan-w4 ajax_prev dokan-text-left" style="margin-left: 25%">
				<input type="submit" name="flyaffiliate_save_vendor_settings" class="dokan-btn dokan-btn-danger dokan-btn-theme" value="<?php esc_attr_e( 'Update Settings', 'flyaffiliate' ); ?>">
			</div>
		</div>
	</form>
</div>
