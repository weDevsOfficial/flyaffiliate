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
| Hold period | Days a commission stays `pending` before it can become `unpaid`. Default 30. Configurable, can be 0. | maturation window |
| Mature | The `pending` → `unpaid` transition, done by the daily job or manually by an admin. | approve, release |
| Payout | A batch of payments, one per affiliate, created from `unpaid` commissions (`batch_key`). What the admin screens call a payout, as SliceWP does. | withdrawal |
| Payment | One row of `{prefix}flyaffiliate_payouts`: one affiliate's share of a payout. Created `unpaid`; marked `paid` by hand once the money has been sent, which is what marks its commissions `paid` (ADR-0012). | transfer |
| Vendor | The store a product belongs to on a marketplace. `vendor_id` is 0 on a single-merchant store; a marketplace integration fills it through `flyaffiliate_order_item_vendor_id`. | seller |

## Commission statuses

`pending` → `unpaid` → `paid`. Either of the first two → `rejected`, and `rejected` → `pending` or `unpaid` again (SliceWP parity). None of it applies while the commission is attached to a payment: see rule 7.

Order status drives the WooCommerce commissions, as in SliceWP: an order that fails, is cancelled or is trashed rejects its pending and unpaid commissions; a refunded order does the same only when the `reject_commissions_on_refund` switch is on (off by default); an order reaching processing or completed (cash on delivery: completed) restores its rejected commissions to pending, whoever rejected them, and the hold period then matures them; an order leaving failed, cancelled or refunded for any other status restores them too. A commission already inside a payment is the exception: the order changing status leaves it alone (rule 7), and the refusal is reported rather than silently skipped.

`paid` is reached only through a payment being marked paid, and is terminal for everything but that payment: never edit, rescale, or delete a paid commission (PRD: "paid state locked"); the one way it changes status is its payment being marked unpaid again, with the commission still attached.

## Money rules (non-negotiable)

1. Commission is calculated **per order item**, never per order. Formula: `base_amount × rate`.
2. Rate resolution: product meta `_flyaffiliate_rate` → vendor rate (filter) → global default. Always clamp to the global maximum. Never let a stored rate exceed the max at calculation time even if it was saved before the max changed.
3. Commission rows are created when the order is created, as `pending`. Nothing moves money at that point.
4. A commission matures only when `created_at + hold_days <= now` **and** the order is `processing` or `completed` (cash on delivery: `completed`). It matures as soon as both hold: on the order status change, right after checkout, or from the daily job when the hold ends later. Changing `hold_days` reschedules every pending commission. With a hold of 0 this is SliceWP's behaviour: the order completes, the commission is unpaid.
5. Refund before payout: rescale each commission by the unrefunded fraction of its own item. Full refund → `rejected`. Refund after payout: no reversal — whoever paid it absorbs it. The hold period exists to make this rare. *Built so far: the `reject_commissions_on_refund` switch (off by default, SliceWP's rule) rejects the order's pending and unpaid commissions on a full refund; partial-refund rescaling is not built and refunds are otherwise handled by hand.*
6. Compare and sum money in integer cents: `(int) round( $amount * 100 )`. Never compare floats.
7. Every write that touches money is idempotent: keyed on `order_item_id` (commissions) and on `payout_id` (a commission attached to a payment is never put into another; marking the payment paid again changes nothing). A hook firing twice must not double anything. `payout_id` is also a lock: while it points at a payment, nothing changes the commission's status, amount or existence — not an admin, not an order status change, not the maturation job. Taking it out of the payment (`remove_commission()`) or deleting the payment frees it. Marking a payment paid pays only the commissions still `unpaid`; marking it unpaid again returns only the ones it paid (ADR-0012).
8. One affiliate per order. A referred cart is attributed to exactly one affiliate — the one whose link was clicked last within the cookie window.
9. Self-referral is blocked by default (affiliate buying through their own link earns nothing).

