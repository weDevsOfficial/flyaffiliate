<?php
/**
 * The test factory.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\Test\Factories;

use WP_UnitTest_Factory;

/**
 * The factory every test reaches through `$this->factory()`.
 *
 * Extends WordPress's own, so `$this->factory()->user`, `->post` and the rest
 * are still there alongside the FlyAffiliate ones.
 *
 * @since FLYAFFILIATE_SINCE
 *
 * @property AffiliateFactory  $affiliate
 * @property CommissionFactory $commission
 * @property VisitFactory      $visit
 * @property PayoutFactory     $payout
 * @property ProductFactory    $product
 * @property OrderFactory      $order
 */
class FlyAffiliateFactory extends WP_UnitTest_Factory {

	/**
	 * Affiliates.
	 *
	 * @var AffiliateFactory
	 */
	public $affiliate;

	/**
	 * Commissions.
	 *
	 * @var CommissionFactory
	 */
	public $commission;

	/**
	 * Visits.
	 *
	 * @var VisitFactory
	 */
	public $visit;

	/**
	 * Payouts.
	 *
	 * @var PayoutFactory
	 */
	public $payout;

	/**
	 * Products.
	 *
	 * @var ProductFactory
	 */
	public $product;

	/**
	 * Orders.
	 *
	 * @var OrderFactory
	 */
	public $order;

	/**
	 * Construct the factory.
	 *
	 * @since FLYAFFILIATE_SINCE
	 */
	public function __construct() {
		parent::__construct();

		$this->affiliate  = new AffiliateFactory( $this );
		$this->commission = new CommissionFactory( $this );
		$this->visit      = new VisitFactory( $this );
		$this->payout     = new PayoutFactory( $this );
		$this->product    = new ProductFactory( $this );
		$this->order      = new OrderFactory( $this );
	}
}
