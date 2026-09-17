# ADR-0011 — A refunded order rejects its unpaid commissions; any unpaid amount is editable

**Status:** Reverted 2026-09-15 (product decision: a WooCommerce commission's amount is not editable and the commission note is gone); the amount part was restored on 2026-09-17 for SliceWP parity — `Commission\Manager::update()` edits the amount, reference, reference amount, type and status of any commission that is not paid and not inside a payment, whatever its origin, and the admin form carries SliceWP's fields (origin, type, date, every status). The commission note stays gone. The `reject_commissions_on_refund` switch returned the same day with SliceWP's semantics — off by default, rejecting the order's pending and unpaid commissions on `refunded`, restored when the order is accepted again — in `Integrations\WooCommerce\OrderStatusSync` (this ADR's `RefundHandler` never shipped under that name). Since ADR-0012 a refund cannot reject a commission that an unpaid payment is already counting: the payment has to let it go first.
**Date:** 2026-09-14

## Context

`CONTEXT.md` money rule 5 describes the full refund model: rescale each
commission by the unrefunded fraction of its own item, reject on a full refund,
never reverse a paid one. Until now none of it was built. A refunded order's
commissions sat `pending` forever — `HoldPeriod::can_mature()` refuses to mature
a refunded order, so no money was ever paid out wrongly, but the ledger showed
earnings that would never arrive, the admin had nothing to point at, and an
affiliate saw a pending amount for a sale that no longer existed.

The product owner asked for the industry-standard behaviour first and the
rescaling later, and pointed at SliceWP as the reference. Its WooCommerce
integration was read in full before this decision (`slicewp/includes/base/
integrations/woocommerce/functions-hooks-integration-woocommerce.php`).

## Decision

### 1. `refunded` rejects, nothing else does

`Integrations\WooCommerce\RefundHandler` hooks `woocommerce_order_status_changed`
and acts only when the new status is `refunded`. It rejects every `pending` or
`unpaid` commission whose `source` is `woocommerce` and whose `order_id` is that
order, records the reason on the row, and writes one line per commission to the
WooCommerce log under the `flyaffiliate` source. `paid` rows are skipped and
logged. Manual commissions that mention the order are left alone: an admin
created them on purpose, and their amount was never derived from the order.

The behaviour sits behind one switch, `reject_commissions_on_refund`, **on by
default**.

Two things SliceWP does that this ADR deliberately does not:

- **Cancelled and failed orders are not rejected.** SliceWP rejects on those
  statuses too, but it also un-rejects — a rejected commission goes back to
  `pending` when the order leaves `failed`/`cancelled`/`refunded`. Our status
  machine has no exit from `rejected` (`CONTEXT.md`: "either of the first two →
  rejected", nothing after). Rejecting a `failed` payment that the customer then
  retries would lose the commission for good. Those commissions stay `pending`
  and never mature, which is correct, and they can be rejected by hand. If an
  un-reject transition is ever added, this is the first thing to revisit.
- **SliceWP's default is off.** The product owner chose on: a refund silently
  leaving a commission payable is the worse surprise.

### 2. A partial refund is corrected by hand

A partial refund does not change the order status, so it reaches neither this
handler nor SliceWP's. Rescaling by the unrefunded fraction (`CONTEXT.md` rule
5, first clause) is **Phase 2**. Until then the admin edits the amount.

### 3. Any unpaid commission's amount is editable

`Commission\Manager::set_amount()` (previously `set_manual_amount()`) no longer
refuses a `woocommerce` row. The old refusal existed because a future rescaler
would have overwritten the edit; with rejection instead of rescaling there is
nothing that recalculates an amount after checkout, so an edit sticks. The stored
`rate` is re-derived from `base_amount` so the row stays self-consistent. A
commission inside a payment is still untouchable, for amount as for status
(ADR-0012).

When Phase 2 rescaling lands it must scale from the **current** amount, never
recompute from the rate, or it will undo these edits.

### 4. The reason lives on the row

`{prefix}flyaffiliate_commissions` gains `note TEXT NULL`, schema version
`1.0.1`; `Upgrade\Manager` adds it on the next `admin_init` through `dbDelta`,
no upgrader class needed. `Commission\Manager::set_status()` takes an optional
`$note` and writes it in the same save, **before** `flyaffiliate_commission_status_changed`
fires, so a listener (an email, the Dokan vendor charge) sees the reason. A
commission meta table was considered and rejected: one nullable column is a
smaller change than a new table, and nothing else needs per-commission meta yet.

The note is read-only through REST in this phase. Rejecting by hand with a
custom reason is a later addition that only needs the endpoint to accept it.

## Consequences

- The ledger tells the truth after a refund: the affiliate's dashboard and the
  admin list both show `Rejected · Order #1400 was refunded.`
- A payout run can no longer include a commission whose order was fully
  refunded, whatever its `matures_at` says.
- `readme.txt` no longer promises rescaling. The FAQ says exactly what happens on
  a full and on a partial refund.
- The Dokan integration (Phase 4) gets a clean seam: the
  `flyaffiliate_commissions_rejected_on_refund( $order, $rejected )` action fires
  once per refunded order, after every rejection, with the rejected rows — the
  place to release a vendor charge.
- Tests: `Integrations/RefundHandlerTest` (rejects pending and unpaid, keeps
  paid/rejected/manual/other-order rows, idempotent, wired to the real status
  change, ignores cancelled/failed, honours the switch); `Commission/ManagerTest`
  (any unpaid amount editable, note carried on status change);
  `REST/ControllersTest` (a calculated commission's amount is editable through
  `PUT`); a Playwright spec edits an unpaid row from the kebab.
