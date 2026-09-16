<?php
/**
 * The FlyAffiliate section on the user profile.
 *
 * @package FlyAffiliate
 *
 * @var \WP_User                            $user      The user being edited.
 * @var \FlyAffiliate\Models\Affiliate|null $affiliate Their affiliate record, if any.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FlyAffiliate\Models\Affiliate;
?>
<h2><?php esc_html_e( 'FlyAffiliate', 'flyaffiliate' ); ?></h2>
<input type="hidden" name="flyaffiliate_profile_section" value="1" />
<table class="form-table" role="presentation">
	<tr>
		<th scope="row"><label for="flyaffiliate-is-affiliate"><?php esc_html_e( 'Affiliate', 'flyaffiliate' ); ?></label></th>
		<td>
			<label><input type="checkbox" id="flyaffiliate-is-affiliate" name="flyaffiliate_is_affiliate" value="1" <?php checked( null !== $affiliate ); ?> /> <?php esc_html_e( 'This user is an affiliate', 'flyaffiliate' ); ?></label>
			<?php if ( null !== $affiliate ) : ?>
				<p class="description">
					<?php
					printf(
						// translators: %d: affiliate ID.
						esc_html__( 'Affiliate #%d.', 'flyaffiliate' ),
						(int) $affiliate->get_id()
					);
					?>
					<a href="
					<?php
					echo esc_url(
						\FlyAffiliate\Admin\Menu::get_route_url( 'affiliates/' . $affiliate->get_id() )
					);
					?>
								"><?php esc_html_e( 'View affiliate', 'flyaffiliate' ); ?></a>.
					<?php esc_html_e( 'Unticking removes the affiliate record; their commissions and payouts are kept.', 'flyaffiliate' ); ?>
				</p>
			<?php endif; ?>
		</td>
	</tr>
	<tr>
		<th scope="row"><label for="flyaffiliate-status"><?php esc_html_e( 'Affiliate status', 'flyaffiliate' ); ?></label></th>
		<td>
			<select id="flyaffiliate-status" name="flyaffiliate_status">
				<?php foreach ( Affiliate::get_statuses() as $flyaffiliate_status_key => $flyaffiliate_status_label ) : ?>
					<option value="<?php echo esc_attr( $flyaffiliate_status_key ); ?>" <?php selected( null !== $affiliate ? $affiliate->get( 'status' ) : Affiliate::STATUS_ACTIVE, $flyaffiliate_status_key ); ?>><?php echo esc_html( $flyaffiliate_status_label ); ?></option>
				<?php endforeach; ?>
			</select>
			<p class="description"><?php esc_html_e( 'Applies only while the user is an affiliate.', 'flyaffiliate' ); ?></p>
		</td>
	</tr>
</table>
