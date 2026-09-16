<?php
/**
 * The mount point of the FlyAffiliate admin app.
 *
 * Everything under the FlyAffiliate menu is one React application routed by
 * URL hash (`#/affiliates`, `#/settings`, …). This template only provides the
 * element it mounts on; the app itself is `assets/js/admin.js`.
 *
 * Override this template by copying it to `flyaffiliate/admin/app.php` in
 * your theme.
 *
 * @package FlyAffiliate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

?>
<div class="wrap flyaffiliate-wrap">
	<hr class="wp-header-end">
	<div id="flyaffiliate-admin-app" class="flyaffiliate-app flyaffiliate-admin-app">
		<p class="flyaffiliate-admin-app__loading"><?php esc_html_e( 'Loading FlyAffiliate…', 'flyaffiliate' ); ?></p>
	</div>
</div>
