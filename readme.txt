=== FlyAffiliate ===
Contributors: wedevs, tareq1988
Tags: affiliate, woocommerce, referral, commission, payouts
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Affiliate marketing for WooCommerce: referral links, per-item commissions, hold periods and manual payouts.

== Description ==

FlyAffiliate turns your customers into a sales channel. An affiliate gets a
referral link and a dashboard; you get a commission ledger that knows the
difference between money earned, money owed, and money paid.

Commissions are calculated **per order item**, not per order, so a mixed cart
pays the right amount on the right products. A configurable hold period keeps a
commission pending until the order has settled, and it never touches a
commission you have already paid.

= What is in this version =

* Email-only affiliate signup with a one-time activation link
* A referral link per affiliate, and a visit log
* Per-item commission calculation with a product → global rate hierarchy,
  clamped to a maximum you set
* A configurable hold period, matured by a daily background job
* Payouts the way you already pay people: a payout creates one payment per
  affiliate, you send the money, then mark each payment paid — with a CSV export
* A frontend affiliate dashboard: referral link, balance, earnings, visits
* Admin screens for affiliates, commissions, visits, payouts and settings

= Privacy =

FlyAffiliate stores no personal data about a visitor. A visit record keeps a
**hashed** IP address and a **hashed** user agent — salted with your site's own
key, not reversible, and not portable to another site.

Attribution uses one first-party cookie, `flyaffiliate_ref`, which holds two
numbers: the affiliate id and the visit id. It is `HttpOnly`, it is never shared
with anyone, and its lifetime is the attribution window you configure — 30 days
by default.

The plugin makes no external requests, contains no analytics, and phones nothing
home.

== Installation ==

1. Install and activate WooCommerce.
2. Upload the plugin to `/wp-content/plugins/flyaffiliate`, or install it through
   Plugins → Add New.
3. Activate the plugin. The Affiliate Dashboard and Affiliate Registration pages
   are created for you.
4. Go to **FlyAffiliate → Settings** and set your default commission rate, your
   maximum rate, and the hold period.

== Frequently Asked Questions ==

= Do I need WooCommerce? =

Yes. FlyAffiliate calculates commissions from WooCommerce orders and does not
work without it.

= Do affiliates get a user role? =

Yes. Every affiliate's user carries the **Affiliate** role (`flyaffiliate_affiliate`),
so you can filter them on the Users screen. The role grants nothing beyond
reading the site; whether someone is an affiliate, and whether they are active,
is decided on the FlyAffiliate → Affiliates screen. Giving a user the role on
their profile makes them an active affiliate.

= Does this plugin use cookies? =

One: `flyaffiliate_ref`. It is set when someone arrives through a referral link
and holds the affiliate id and the visit id, so that a purchase made later in the
same browser can be credited to the affiliate who sent them. It is a first-party
cookie, it is `HttpOnly`, and it lasts for the attribution window you configure
(30 days by default). No third-party cookie is set and no data leaves your site.

= How is a commission calculated? =

Per order item. The rate is resolved from the product, then your global
default, and is always clamped to the maximum rate you set. Shipping and tax are excluded from the amount the rate applies to, unless
you turn that off.

= What happens when an order is refunded? =

Nothing, unless you turn on **Reject commissions on refund** under Commissions →
Rules: then a refunded order rejects its commissions that have not been paid
yet. An order that fails or is cancelled always rejects them, and an order that
is paid again puts them back. A commission that has already been paid out is
never reversed.

= How do affiliates get paid? =

You create a payout batch from the unpaid commissions, optionally filtered by a
minimum amount, and pay through whatever channel you already use. FlyAffiliate
records the payment and marks the commissions paid, and exports the batch as CSV.
There is no automated transfer in this version.

= Can an affiliate earn commission on their own purchase? =

Not by default. Self-referral is blocked, and you can allow it in Settings if
your programme works that way.

= Can I override the templates? =

Yes. Copy a file from the plugin's `templates` directory into a `flyaffiliate`
directory in your theme, keeping the same path, and your copy is used instead.

== Screenshots ==

1. The affiliates list, with status filters and per-affiliate earnings.

== Changelog ==

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.0 =
First release.
