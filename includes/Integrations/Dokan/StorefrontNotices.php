<?php
/**
 * What an affiliate earns, shown on the storefront.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\Integrations\Dokan;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FlyAffiliate\Contracts\Hookable;
use WC_Product;

/**
 * Tells affiliates and vendors, and nobody else, what a product pays.
 *
 * On a store page a notice above the products says the store set its own
 * terms, when it did. In the shop loop and on a product page one line gives
 * the rate and the lock of that product. A customer who is neither an
 * affiliate nor a vendor sees nothing.
 *
 * @since FLYAFFILIATE_SINCE
 */
class StorefrontNotices implements Hookable {

	/**
	 * The vendor programs.
	 *
	 * @var VendorProgram
	 */
	protected VendorProgram $program;

	/**
	 * Whether the current visitor sees the terms, once worked out.
	 *
	 * @var bool|null
	 */
	protected ?bool $visible = null;

	/**
	 * Constructor.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param VendorProgram $program The vendor programs.
	 */
	public function __construct( VendorProgram $program ) {
		$this->program = $program;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		// After Dokan's own search bar (30), so the notice sits right above the products.
		add_action( 'dokan_store_profile_frame_after', [ $this, 'render_store_notice' ], 31 );
		add_action( 'woocommerce_after_shop_loop_item_title', [ $this, 'render_loop_terms' ], 15 );
		// Between the excerpt (20) and the add to cart button (30).
		add_action( 'woocommerce_single_product_summary', [ $this, 'render_product_terms' ], 25 );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_style' ] );
	}

	/**
	 * Whether the current visitor sees the terms.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return bool
	 */
	public function is_visible(): bool {
		if ( null !== $this->visible ) {
			return $this->visible;
		}

		$user_id       = get_current_user_id();
		$this->visible = false;

		if ( $user_id > 0 && Settings::is_enabled() ) {
			$affiliate     = flyaffiliate()->affiliate->get_by_user( $user_id );
			$this->visible = ( null !== $affiliate && $affiliate->is_active() ) || dokan_is_user_seller( $user_id );
		}

		/**
		 * Filters whether the current visitor sees what affiliates earn on the storefront.
		 *
		 * @since FLYAFFILIATE_SINCE
		 *
		 * @param bool $visible Whether they do. Affiliates and vendors by default.
		 * @param int  $user_id The visitor. 0 when logged out.
		 */
		$this->visible = (bool) apply_filters( 'flyaffiliate_dokan_show_terms', $this->visible, $user_id );

		return $this->visible;
	}

	/**
	 * The stylesheet, on the pages that show the terms.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return void
	 */
	public function enqueue_style(): void {
		// The page first: it costs nothing, the visitor check a query.
		if ( ( dokan_is_store_page() || is_woocommerce() ) && $this->is_visible() ) {
			wp_enqueue_style( 'flyaffiliate-frontend' );
		}
	}

	/**
	 * The notice above a store's products, when the store set its own terms.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param \WP_User|mixed $store_user The store's owner.
	 *
	 * @return void
	 */
	public function render_store_notice( $store_user ): void {
		$vendor_id = is_object( $store_user ) && isset( $store_user->ID ) ? absint( $store_user->ID ) : 0;

		if ( $vendor_id <= 0 || ! $this->is_visible() || ! $this->program->has_override( $vendor_id ) ) {
			return;
		}

		flyaffiliate_get_template_part( 'dokan/store-notice', '', $this->get_template_args( $vendor_id ) );
	}

	/**
	 * The line under a product's title in the shop loop.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return void
	 */
	public function render_loop_terms(): void {
		$this->render_terms( 'loop' );
	}

	/**
	 * The line on the product page.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return void
	 */
	public function render_product_terms(): void {
		$this->render_terms( 'single' );
	}

	/**
	 * The terms of the current product.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string $context `loop` or `single`.
	 *
	 * @return void
	 */
	protected function render_terms( string $context ): void {
		global $product;

		if ( ! $product instanceof WC_Product || ! $this->is_visible() ) {
			return;
		}

		$vendor_id = absint( dokan_get_vendor_by_product( $product, true ) );

		if ( $vendor_id <= 0 ) {
			return;
		}

		$args = $this->get_template_args( $vendor_id, $product->get_id() );

		// A product that pays nothing has nothing to say.
		if ( $args['terms']['rate'] <= 0 ) {
			return;
		}

		flyaffiliate_get_template_part( 'dokan/product-terms', '', array_merge( $args, [ 'context' => $context ] ) );
	}

	/**
	 * What the templates are given.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param int $vendor_id  The vendor.
	 * @param int $product_id The product, for the rate of one product.
	 *
	 * @return array{terms: array<string, mixed>, rate: string, lock: string}
	 */
	protected function get_template_args( int $vendor_id, int $product_id = 0 ): array {
		$terms = $this->program->get_terms( $vendor_id, $product_id );

		return [
			'terms' => $terms,
			'rate'  => $this->program->format_number( (float) $terms['rate'] ),
			'lock'  => $terms['hold_days'] > 0
				? sprintf(
					/* translators: %d: number of days */
					_n( 'paid %d day after the sale', 'paid %d days after the sale', $terms['hold_days'], 'flyaffiliate' ),
					$terms['hold_days']
				)
				: __( 'paid as soon as the order is paid', 'flyaffiliate' ),
		];
	}
}
