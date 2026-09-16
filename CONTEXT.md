# FlyAffiliate — Domain Context

Canonical vocabulary and money rules for the FlyAffiliate bounded context. Read this before naming anything or touching code that moves money. It is the FlyAffiliate equivalent of Dokan's `CONTEXT.md`.

Sources: `docs/MVP_PRD.md`, `docs/PRD.md`, and the Dokan vendor-affiliate prototype write-up (dokan-pro discussion #6051, "Vendor-level affiliate programs, bridged to SliceWP"). FlyAffiliate replaces SliceWP: it owns tracking, commissions, hold periods and payouts itself. The Dokan integration reuses everything that write-up learned about moving money out of a vendor's cut.

## Terms

| Term | Meaning | Avoid |
|---|---|---|
| Affiliate | A WordPress user with a row in `{prefix}flyaffiliate_affiliates`. One user = one affiliate, sitewide. The user also carries the `flyaffiliate_affiliate` role, a label the table drives (`Affiliate\Role`); nothing checks the role for permission. | partner, promoter, "referrer" as a noun |
| Referral link | `home_url( '/?affiliate={affiliate_id}' )`. The query variable name is a setting. | affiliate URL |
| Visit | One tracked click on a referral link. Stored in `{prefix}flyaffiliate_visits`. | click, hit |
| Commission | Money owed to an affiliate for **one order item**. Stored in `{prefix}flyaffiliate_commissions`. | referral — the prototype UI calls commission rows "referrals"; code must not |
| Base amount | The order item total the rate is applied to. Shipping and tax excluded by default (setting). | subtotal |
| Rate | Percentage (default) or fixed amount applied to the base amount. | fee |
| Rate hierarchy | product rate → vendor rate (Dokan only) → global default rate, then clamped to the global maximum rate. | |
| Hold period | Days a commission stays `pending` before it can become `unpaid`. Default 30. Configurable, can be 0. | maturation window |
| Mature | The `pending` → `unpaid` transition, done by the daily job or manually by an admin. | approve, release |
| Payout | A batch of payments, one per affiliate, created from `unpaid` commissions (`batch_key`). What the admin screens call a payout, as SliceWP does. | withdrawal (that is Dokan vendor vocabulary) |
| Payment | One row of `{prefix}flyaffiliate_payouts`: one affiliate's share of a payout. Created `unpaid`; marked `paid` by hand once the money has been sent, which is what marks its commissions `paid` (ADR-0012). | transfer |
| Vendor | Dokan store owner. Exists only when Dokan is active. `vendor_id` is 0 on a single-merchant store; the Dokan integration fills it through `flyaffiliate_order_item_vendor_id`. | seller |
| Vendor program | A vendor's opt-in to run an affiliate program for their own store. Stored as user meta. | |
| Vendor charge | The credit row FlyAffiliate writes to `{prefix}dokan_vendor_balance` so the vendor, not the marketplace, funds the commission. | deduction, fee |
| Marketplace | The site owner / admin on a Dokan install. | platform (only in gateway-fee context) |

## Commission statuses

`pending` → `unpaid` → `paid`. Either of the first two → `rejected`, and `rejected` → `pending` or `unpaid` again (SliceWP parity). None of it applies while the commission is attached to a payment: see rule 7.

Order status drives the WooCommerce commissions, as in SliceWP: an order that fails, is cancelled or is trashed rejects its pending and unpaid commissions; a refunded order does the same only when the `reject_commissions_on_refund` switch is on (off by default); an order reaching processing or completed (cash on delivery: completed) restores its rejected commissions to pending, whoever rejected them, and the hold period then matures them; an order leaving failed, cancelled or refunded for any other status restores them too. A commission already inside a payment is the exception: the order changing status leaves it alone (rule 7), and the refusal is reported rather than silently skipped.

`paid` is reached only through a payment being marked paid, and is terminal for everything but that payment: never edit, rescale, or delete a paid commission (PRD: "paid state locked"); the one way it changes status is its payment being marked unpaid again, with the commission still attached.

## Money rules (non-negotiable)

1. Commission is calculated **per order item**, never per order. Formula: `base_amount × rate`.
2. Rate resolution: product meta `_flyaffiliate_rate` → vendor rate (Dokan) → global default. Always clamp to the global maximum. Never let a stored rate exceed the max at calculation time even if it was saved before the max changed.
3. Commission rows are created when the order is created, as `pending`. Nothing moves money at that point.
4. A commission matures only when `created_at + hold_days <= now` **and** the order is `processing` or `completed` (cash on delivery: `completed`). It matures as soon as both hold: on the order status change, right after checkout, or from the daily job when the hold ends later. Changing `hold_days` reschedules every pending commission. With a hold of 0 this is SliceWP's behaviour: the order completes, the commission is unpaid.
5. Refund before payout: rescale each commission by the unrefunded fraction of its own item. Full refund → `rejected`. Refund after payout: no reversal — whoever paid it absorbs it. The hold period exists to make this rare. *Built so far: the `reject_commissions_on_refund` switch (off by default, SliceWP's rule) rejects the order's pending and unpaid commissions on a full refund; partial-refund rescaling is not built and refunds are otherwise handled by hand.*
6. Compare and sum money in integer cents: `(int) round( $amount * 100 )`. Never compare floats.
7. Every write that touches money is idempotent: keyed on `order_item_id` (commissions), on order meta `_flyaffiliate_vendor_charged` (vendor charge), and on `payout_id` (a commission attached to a payment is never put into another; marking the payment paid again changes nothing). A hook firing twice must not double anything. `payout_id` is also a lock: while it points at a payment, nothing changes the commission's status, amount or existence — not an admin, not an order status change, not the maturation job. Taking it out of the payment (`remove_commission()`) or deleting the payment frees it. Marking a payment paid pays only the commissions still `unpaid`; marking it unpaid again returns only the ones it paid (ADR-0012).
8. One affiliate per order. A referred cart is attributed to exactly one affiliate — the one whose link was clicked last within the cookie window.
9. Self-referral is blocked by default (affiliate buying through their own link earns nothing).

## Dokan rules (learned in the prototype — do not relearn them)

- **The vendor pays.** The vendor's earning drops by the commission; the marketplace share is untouched. $100 sale, 20% marketplace, 15% affiliate: vendor withdraws $65, affiliate is paid $15, marketplace keeps $20.
- **Never write a smaller number into `dokan_orders` earnings.** `VendorBalanceUpdateHandler` recalculates on every order save and overwrites it. There is no filter inside the commission calculator to change the source figure.
- **Deduct in exactly two places:** (a) the `dokan_get_earning_from_order_table` and `dokan_get_vendor_earning_subtotal_by_order` filters (what gateways read), and (b) one credit row in `dokan_vendor_balance` with `trn_type = 'flyaffiliate_commission'` (what withdrawals check).
- **Cache trap:** `dokan_get_earning_from_order_table()` caches before running filters and returns early on a cache hit. Write the adjusted figure into that cache key from inside the filter. Leave the raw internal entry alone or the overwrite fight starts again.
- Apply the vendor charge when the commission **matures**, not at order placement. An order that never completes never costs the vendor anything.
- Do **not** hook `dokan_order_net_amount` (double-deducts) or `dokan_refund_approve_vendor_refund_amount` (double-refunds).
- Reverse withdrawal is the wrong mechanism. It does not reduce the withdrawable balance and it is punitive.
- On refund, **one mechanism only:** the vendor charge tracks the commission; Dokan's own clawback is left alone. Two safety nets that each give the right answer together hand the vendor money that isn't his.
- Re-sync the charge on both `woocommerce_order_refunded` and `woocommerce_order_status_changed` (`refunded`, `cancelled`, `failed`). A refund from the WooCommerce order screen never reaches Dokan Pro's approval flow.
- Dokan splits a cart into one sub-order per vendor. Every commission row carries `vendor_id` so attribution is per sub-order; a helper resolves `product_id → post_author → vendor_id` and every money hook uses it.
- Shipping commission has no owner. Exclude shipping from the base amount.
- Gateways covered by the two filters above: Stripe Express, Razorpay, Mangopay, PayPal Marketplace (ledger read), Paystack and new Stripe Connect (fresh recalculation), COD / bank transfer / standard gateways (balance credit).
- The marketplace admin controls: whether vendor programs exist, the default rate, whether vendors may set their own rate, the maximum rate, the hold period. The vendor controls: whether their store runs a program, their store rate (if allowed), per-product rates.

## What Phase 1 is not

No automated payouts, no approval queue, no emails beyond the activation link, no UTM tracking, no multi-level commissions, no tiers, no affiliate REST API for third parties. See `docs/MVP_PRD.md` "Not in MVP".