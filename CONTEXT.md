# FlyAffiliate — Domain Context

Canonical vocabulary and money rules for the FlyAffiliate bounded context. Read this before naming anything or touching code that moves money. It is the FlyAffiliate equivalent of Dokan's `CONTEXT.md`.

Sources: `docs/MVP_PRD.md` and `docs/PRD.md`. FlyAffiliate replaces SliceWP: it owns tracking, commissions, hold periods and payouts itself, and SliceWP's behaviour is the reference when a rule here is silent. The marketplace (Dokan) integration, its vocabulary and its money rules live on the `feature/dokan-integration` branch.

## Terms

| Term | Meaning | Avoid |
|---|---|---|
| Affiliate | A WordPress user with a row in `{prefix}flyaffiliate_affiliates`. One user = one affiliate, sitewide. The user also carries the `flyaffiliate_affiliate` role, a label the table drives (`Affiliate\Role`); nothing checks the role for permission. | partner, promoter, "referrer" as a noun |
| Referral link | `home_url( '/?affiliate={affiliate_id}' )`. The query variable name is a setting. | affiliate URL |
| Visit | One tracked click on a referral link. Stored in `{prefix}flyaffiliate_visits`. | click, hit |
| Commission | Money owed to an affiliate for **one order item**. Stored in `{prefix}flyaffiliate_commissions`. | referral — the prototype UI calls commission rows "referrals"; code must not |
| Base amount | The order item total the rate is applied to. Shipping and tax excluded by default (setting). | subtotal |
| Rate | Percentage (default) or fixed amount applied to the base amount. | fee |
| Rate hierarchy | product rate → vendor rate (through the `flyaffiliate_vendor_rate` filter; nothing on this branch supplies one) → global default rate, then clamped to the global maximum rate. | |
| Hold period | Days after the sale before an `unpaid` commission can go into a payout (`matures_at` = `created_at` + hold). Default 30. Configurable, can be 0. It does not change the commission's status (ADR-0014). | maturation window |
| Mature | A commission whose hold period is over (`matures_at <= now`), so a payout may take it. Not a status: an `unpaid` commission can be immature, and a `pending` one mature. | approve, release |
| Payout | A batch of payments, one per affiliate, created from `unpaid` commissions (`batch_key`). What the admin screens call a payout, as SliceWP does. | withdrawal |
| Payment | One row of `{prefix}flyaffiliate_payouts`: one affiliate's share of a payout. Created `unpaid`; marked `paid` by hand once the money has been sent, which is what marks its commissions `paid` (ADR-0012). | transfer |
| Vendor | The store a product belongs to on a marketplace. `vendor_id` is 0 on a single-merchant store; a marketplace integration fills it through `flyaffiliate_order_item_vendor_id`. | seller |

## Commission statuses

`pending` → `unpaid` → `paid`. Either of the first two → `rejected`, and `rejected` → `pending` or `unpaid` again (SliceWP parity); an admin may move a commission between any of the four by hand. None of it applies while the commission is attached to a payment: see rule 7.

Order status drives the WooCommerce commissions, as in SliceWP: an order that fails, is cancelled or is trashed rejects its pending and unpaid commissions; a refunded order does the same only when the `reject_commissions_on_refund` switch is on (off by default); an order reaching processing or completed (cash on delivery: completed) makes its pending and rejected commissions unpaid on the spot, whoever rejected them; an order leaving failed, cancelled or refunded for a status that is not paid restores them to pending. A commission already inside a payment is the exception: the order changing status leaves it alone (rule 7), and the refusal is reported rather than silently skipped.

The plugin itself reaches `paid` only through a payment being marked paid, and every commission it paid stays inside that payment (rule 7): the automatic movers leave it alone, it is not deleted, and the payment keeps the amount it was paid with — an admin correcting the commission afterwards (allowed, as in SliceWP) changes the commission's own record only. An admin can also record a commission as `paid` by hand — money that went outside the plugin, SliceWP's default for a commission added on the admin screen — and such a row, outside any payment, is held by nothing. The order sync only ever moves `pending`, `unpaid` and `rejected` rows.

## Money rules (non-negotiable)

1. Commission is calculated **per order item**, never per order. Formula: `base_amount × rate`.
2. Rate resolution: product meta `_flyaffiliate_rate` → vendor rate (filter) → global default. Always clamp to the global maximum. Never let a stored rate exceed the max at calculation time even if it was saved before the max changed.
3. Commission rows are created when the order is created, as `pending`. Nothing moves money at that point.
4. The status follows the order; the hold period gates the payout (ADR-0014). A commission becomes `unpaid` the moment its order is `processing` or `completed` (cash on delivery: `completed`) — on the status change, or right after checkout when the order is already paid — whatever the hold. A payout takes an `unpaid` commission only once `created_at + hold_days <= now`; until then it is *held* and the preview says so. Changing `hold_days` reschedules every pending, unpaid and rejected commission; paid ones keep their dates. With a hold of 0 this is SliceWP's behaviour: the order completes, the commission is unpaid and payable. A commission an admin adds by hand keeps the status it is given — a pending one waits for its order (WooCommerce origin) or the admin, and never becomes unpaid at the moment it is created.
5. Refund before payout: rescale each commission by the unrefunded fraction of its own item. Full refund → `rejected`. Refund after payout: no reversal — whoever paid it absorbs it. The hold period exists to make this rare. *Built so far: the `reject_commissions_on_refund` switch (off by default, SliceWP's rule) rejects the order's pending and unpaid commissions on a full refund; partial-refund rescaling is not built and refunds are otherwise handled by hand.*
6. Compare and sum money in integer cents: `(int) round( $amount * 100 )`. Never compare floats.
7. Every write that touches money is idempotent: keyed on `order_item_id` (commissions) and on `payout_id` (a commission attached to a payment is never put into another; marking the payment paid again changes nothing). A hook firing twice must not double anything. `payout_id` also holds the commission: while it points at a payment, no order status change moves it, and it is not deleted. An admin can still edit or move it, as in SliceWP; an unpaid payment then re-sums to its unpaid commissions (`Payout\Manager::resync()`), a paid payment keeps the amount it was paid with. Taking it out of the payment (`remove_commission()`) or deleting the payment frees it. Marking a payment paid pays only the commissions still `unpaid`; marking it unpaid again returns only the ones it paid (ADR-0012).
8. One affiliate per order. A referred cart is attributed to exactly one affiliate — the one whose link was clicked last within the cookie window.
9. Self-referral is blocked by default (affiliate buying through their own link earns nothing).

