<?php
/**
 * The affiliate dashboard: the mount point of the dashboard app.
 *
 * The app itself is `assets/js/dashboard.js`; it reads its data from the
 * `me` REST routes and opens on the tab the shortcode chose.
 *
 * @package FlyAffiliate
 *
 * @var \FlyAffiliate\Models\Affiliate $affiliate The affiliate.
 * @var string                         $tab       The tab to open first.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

?>
<div class="flyaffiliate-area flyaffiliate-dashboard">
	<div id="flyaffiliate-dashboard" class="flyaffiliate-app flyaffiliate-dashboard-app" data-tab="<?php echo esc_attr( $tab ); ?>">
		<p class="flyaffiliate-dashboard-app__loading"><?php esc_html_e( 'Loading your dashboard…', 'flyaffiliate' ); ?></p>
	</div>
</div>
