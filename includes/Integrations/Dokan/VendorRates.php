<?php
/**
 * Where a vendor's program meets the money.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\Integrations\Dokan;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FlyAffiliate\Commission\HoldPeriod;
use FlyAffiliate\Contracts\Hookable;
use FlyAffiliate\Models\Commission;
use WC_Order;
use WC_Order_Item;

/**
 * Feeds the vendor into the neutral seams the core leaves open: which vendor
 * an order item belongs to, the vendor step of the rate hierarchy, and the
 * hold period of a vendor's sales.
 *
 * It also records the terms a commission was created under, so the lists can
 * say where a rate came from after the vendor has changed it, and reschedules
 * the waiting commissions when a lock changes.
 *
 * @since FLYAFFILIATE_SINCE
 */
class VendorRates implements Hookable {

	/**
	 * The vendor programs.
	 *
	 * @var VendorProgram
	 */
	protected VendorProgram $program;

	/**
	 * The hold period.
	 *
	 * @var HoldPeriod
	 */
	protected HoldPeriod $hold_period;

	/**
	 * Constructor.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param VendorProgram $program     The vendor programs.
	 * @param HoldPeriod    $hold_period The hold period.
	 */
	public function __construct( VendorProgram $program, HoldPeriod $hold_period ) {
		$this->program     = $program;
		$this->hold_period = $hold_period;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_filter( 'flyaffiliate_order_item_vendor_id', [ $this, 'resolve_vendor_id' ], 10, 2 );
		add_filter( 'flyaffiliate_vendor_rate', [ $this, 'filter_rate' ], 10, 2 );
		add_filter( 'flyaffiliate_hold_days', [ $this, 'filter_hold_days' ], 10, 2 );
		add_filter( 'flyaffiliate_rest_prepare_commission', [ $this, 'add_vendor_program' ], 10, 2 );

		// Before the hold period matures what is due (priority 20).
		add_action( 'flyaffiliate_order_attributed', [ $this, 'record_terms' ], 5, 3 );

		add_action( 'flyaffiliate_dokan_vendor_settings_saved', [ $this, 'reschedule_vendor' ] );
		add_action( 'dokan_after_saving_settings', [ $this, 'handle_legacy_settings_saved' ], 10, 3 );
		add_action( 'dokan_admin_settings_changed', [ $this, 'handle_settings_changed' ] );
	}

	/**
	 * The vendor an order item belongs to.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param int           $vendor_id The vendor found so far.
	 * @param WC_Order_Item $item      The order item.
	 *
	 * @return int
	 */
	public function resolve_vendor_id( $vendor_id, $item ): int {
		$vendor_id = absint( $vendor_id );

		if ( $vendor_id > 0 || ! $item instanceof WC_Order_Item || ! is_callable( [ $item, 'get_product' ] ) ) {
			return $vendor_id;
		}

		$product = $item->get_product();

		if ( ! $product ) {
			return $vendor_id;
		}

		$found = absint( dokan_get_vendor_by_product( $product, true ) );

		return $found > 0 && dokan_is_user_seller( $found ) ? $found : $vendor_id;
	}

	/**
	 * The vendor step of the rate hierarchy.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param float|null $rate      The rate found so far.
	 * @param int        $vendor_id The vendor.
	 *
	 * @return float|null Null falls through to FlyAffiliate's default rate.
	 */
	public function filter_rate( $rate, $vendor_id ) {
		if ( null !== $rate ) {
			return $rate;
		}

		return $this->program->get_rate( absint( $vendor_id ) );
	}

	/**
	 * The hold period of a vendor's sales.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param int $hold_days FlyAffiliate's own hold period.
	 * @param int $vendor_id The vendor.
	 *
	 * @return int
	 */
	public function filter_hold_days( $hold_days, $vendor_id ): int {
		return $this->program->get_hold_days( absint( $vendor_id ), absint( $hold_days ) );
	}

	/**
	 * Record, on the order item, the terms each commission was created under.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param WC_Order     $order       The order.
	 * @param mixed        $affiliate   The affiliate.
	 * @param Commission[] $commissions The commissions created.
	 *
	 * @return void
	 */
	public function record_terms( $order, $affiliate, $commissions ): void {
		foreach ( (array) $commissions as $commission ) {
			if ( ! $commission instanceof Commission ) {
				continue;
			}

			$vendor_id = (int) $commission->get( 'vendor_id', 0 );
			$item_id   = (int) $commission->get( 'order_item_id', 0 );

			if ( $vendor_id <= 0 || $item_id <= 0 ) {
				continue;
			}

			// The lock is the gap between the commission's dates; whose terms applied is all that needs keeping.
			wc_update_order_item_meta( $item_id, VendorProgram::TERMS_META, [ 'overridden' => $this->program->has_override( $vendor_id ) ] );
		}
	}

	/**
	 * Add the vendor's terms to a commission's REST response.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param array<string, mixed> $data The response fields.
	 * @param Commission           $item The commission.
	 *
	 * @return array<string, mixed>
	 */
	public function add_vendor_program( $data, $item ): array {
		$data      = (array) $data;
		$vendor_id = $item instanceof Commission ? (int) $item->get( 'vendor_id', 0 ) : 0;
		$item_id   = $item instanceof Commission ? (int) $item->get( 'order_item_id', 0 ) : 0;

		// Only a commission made from an order line was made under a store's terms; a hand-entered one was not.
		if ( $vendor_id <= 0 || $item_id <= 0 ) {
			return $data;
		}

		$terms = wc_get_order_item_meta( $item_id, VendorProgram::TERMS_META, true );
		$terms = is_array( $terms ) ? $terms : [];

		$data['vendor_program'] = [
			'vendor_id'  => $vendor_id,
			'store_name' => $this->program->get_store_name( $vendor_id ),
			// The commission's own rate and dates are what it was created with; only the origin needs recording.
			'rate'       => (float) $item->get( 'rate', 0 ),
			'hold_days'  => $this->get_hold_days_of( $item ),
			'overridden' => isset( $terms['overridden'] ) ? (bool) $terms['overridden'] : $this->program->has_override( $vendor_id ),
		];

		return $data;
	}

	/**
	 * The days between a commission's creation and its maturity.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param Commission $commission The commission.
	 *
	 * @return int
	 */
	protected function get_hold_days_of( Commission $commission ): int {
		$created = strtotime( (string) $commission->get( 'created_at', '' ) . ' UTC' );
		$matures = strtotime( (string) $commission->get( 'matures_at', '' ) . ' UTC' );

		if ( ! $created || ! $matures || $matures <= $created ) {
			return 0;
		}

		return (int) round( ( $matures - $created ) / DAY_IN_SECONDS );
	}

	/**
	 * A vendor changed their lock: move their waiting commissions.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param int $vendor_id The vendor.
	 *
	 * @return void
	 */
	public function reschedule_vendor( $vendor_id ): void {
		$this->hold_period->reschedule_vendor( absint( $vendor_id ) );
		$this->hold_period->run();
	}

	/**
	 * The marketplace saved Selling Options on Dokan's legacy screen.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string               $option_name The section saved.
	 * @param array<string, mixed> $new_values  What was saved.
	 * @param array<string, mixed> $old_values  What was stored before.
	 *
	 * @return void
	 */
	public function handle_legacy_settings_saved( $option_name, $new_values, $old_values ): void {
		if ( Settings::SECTION !== $option_name ) {
			return;
		}

		$new_values = (array) $new_values;
		$old_values = (array) $old_values;

		foreach ( Settings::get_lock_keys() as $key ) {
			if ( (string) ( $new_values[ $key ] ?? '' ) !== (string) ( $old_values[ $key ] ?? '' ) ) {
				$this->reschedule_all();

				return;
			}
		}
	}

	/**
	 * The marketplace saved the FlyAffiliate page on Dokan's new screen.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param array<string, mixed> $changed The values that changed, keyed by field id.
	 *
	 * @return void
	 */
	public function handle_settings_changed( $changed ): void {
		if ( array_intersect( Settings::get_lock_keys(), array_keys( (array) $changed ) ) ) {
			$this->reschedule_all();
		}
	}

	/**
	 * Move every waiting commission to the lock in force now.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return void
	 */
	protected function reschedule_all(): void {
		$this->hold_period->reschedule( flyaffiliate()->commission->get_hold_days() );
		$this->hold_period->run();
	}
}
