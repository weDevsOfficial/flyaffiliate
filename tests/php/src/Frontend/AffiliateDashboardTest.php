<?php
/**
 * Tests for the affiliate dashboard shortcode.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\Test\Frontend;

use FlyAffiliate\Assets;
use FlyAffiliate\Test\FlyAffiliateTestCase;
use WP_Scripts;
use WP_Styles;

/**
 * The dashboard shortcode and the data it hands its app.
 *
 * @since FLYAFFILIATE_SINCE
 */
class AffiliateDashboardTest extends FlyAffiliateTestCase {

	/**
	 * The script registry the test process had before a test replaced it.
	 *
	 * @var WP_Scripts|null
	 */
	private ?WP_Scripts $saved_scripts = null;

	/**
	 * The style registry the test process had before a test replaced it.
	 *
	 * @var WP_Styles|null
	 */
	private ?WP_Styles $saved_styles = null;

	/**
	 * {@inheritDoc}
	 */
	public function set_up() {
		parent::set_up();

		// A fresh request: nothing registered, nothing enqueued, `wp_enqueue_scripts` not fired.
		$this->saved_scripts = $GLOBALS['wp_scripts'] ?? null;
		$this->saved_styles  = $GLOBALS['wp_styles'] ?? null;

		$GLOBALS['wp_scripts'] = new WP_Scripts(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the test isolates the registries.
		$GLOBALS['wp_styles']  = new WP_Styles(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	}

	/**
	 * {@inheritDoc}
	 */
	public function tear_down() {
		$GLOBALS['wp_scripts'] = $this->saved_scripts; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$GLOBALS['wp_styles']  = $this->saved_styles; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		parent::tear_down();
	}

	/**
	 * The bundles are registered on `init`, before any template can render.
	 *
	 * A block theme renders the page content — the shortcode with it — before
	 * `wp_head()`, so `wp_enqueue_scripts` is too late to register a handle
	 * the shortcode attaches data to.
	 */
	public function test_the_bundles_are_registered_on_init(): void {
		$this->assertSame( 10, has_action( 'init', [ flyaffiliate()->assets, 'register_assets' ] ) );
		$this->assertFalse( has_action( 'wp_enqueue_scripts', [ flyaffiliate()->assets, 'register_assets' ] ) );
	}

	/**
	 * The dashboard keeps its data when the shortcode renders before `wp_enqueue_scripts`.
	 */
	public function test_the_dashboard_data_survives_an_early_render(): void {
		$affiliate = $this->factory()->affiliate->create_and_get_model();
		$this->acting_as( (int) $affiliate->get( 'user_id' ) );

		// What `init` does on a real request; `wp_enqueue_scripts` has not fired.
		flyaffiliate()->assets->register_assets();
		$this->assertSame( 0, did_action( 'wp_enqueue_scripts' ) );

		$output = do_shortcode( '[flyaffiliate_dashboard]' );

		$this->assertStringContainsString( 'id="flyaffiliate-dashboard"', $output );
		$this->assertTrue( wp_script_is( Assets::HANDLE_PREFIX . 'dashboard', 'enqueued' ) );
		$this->assertTrue( wp_style_is( Assets::HANDLE_PREFIX . 'dashboard', 'enqueued' ) );

		$inline = implode( "\n", (array) wp_scripts()->get_data( Assets::HANDLE_PREFIX . 'dashboard', 'before' ) );

		$this->assertStringContainsString( 'window.flyaffiliate = ', $inline );
		$this->assertStringContainsString( '"program"', $inline );
		$this->assertStringContainsString( '"restNonce"', $inline );
	}

	/**
	 * Inline data attached to an unregistered handle is dropped by WordPress —
	 * the failure a block theme exposed, kept here so the ordering stays fixed.
	 */
	public function test_wordpress_drops_inline_data_on_an_unregistered_handle(): void {
		$this->assertFalse( wp_add_inline_script( Assets::HANDLE_PREFIX . 'dashboard', 'window.flyaffiliate = {};', 'before' ) );
		$this->assertFalse( wp_scripts()->get_data( Assets::HANDLE_PREFIX . 'dashboard', 'before' ) );
	}
}
