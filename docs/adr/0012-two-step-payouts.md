# ADR-0012 — A payout creates unpaid payments; marking a payment paid is what pays its commissions

**Status:** Accepted
**Date:** 2026-09-15
**Amends:** ADR-0006 (which still holds: payouts are manual and recorded, never automated)

## Context

ADR-0006 made creating a batch the moment commissions became `paid`: the admin
previewed who was owed what, confirmed, and every commission in the batch was
paid at once. That collapses two events that happen at different times — the
decision to pay, and the money actually leaving — into one click, and leaves
no way back when a transfer bounces or an affiliate was included by mistake.

SliceWP, the behaviour reference for this plugin, keeps them apart: a *payout*
is a batch of *payments*, one per affiliate, created `unpaid`; each payment is
marked paid once the money has been sent, singly or for the whole payout; an
unpaid payment can lose a commission or be deleted; a paid one can be marked
unpaid again if it was marked by mistake. The product asked for the same.

## Decision

- `Payout\Manager::create()` writes one `Payout` row per affiliate with
  `status = unpaid` and sets `payout_id` on every commission in it. The
  commissions stay `unpaid`.
- `mark_paid()` moves the row and its commissions to `paid`; `mark_unpaid()`
  reverses it, and the commissions stay attached so they cannot be paid twice.
  `pay_batch()` marks every unpaid payment of a batch paid.
- `remove_commission()` takes a commission out of an *unpaid* payment
  (`payout_id = 0`, payment amount reduced); `delete()` removes an unpaid
  payment and detaches its commissions; `delete_batch()` refuses if any payment
  in it is paid.
- `preview()` keeps excluding any commission whose `payout_id` is set, so a
  commission waiting in an unpaid payment is never put into a second one.
- **A commission inside a payment holds still.** `Commission::is_locked()`
  covers `payout_id > 0`, so while a payment counts a commission towards money
  an admin is about to send, nothing changes it: not a refunded, cancelled,
  failed or trashed order, not an admin editing the amount or deleting the row,
  not the maturation job. `remove_commission()` and `delete()` are the two ways
  out, and both leave the commission as it was. `mark_paid()` pays only the
  commissions still `unpaid`; `mark_unpaid()` returns only the ones it paid.
- A commission the plugin paid is always inside its payment, so it is locked
  for as long as the payment stands: nothing edits, rescales or deletes it, and
  the only way it changes status is its payment being marked unpaid again. The
  lock is `payout_id`, not the `paid` status: a commission an admin records as
  paid by hand (SliceWP's default on its add form) has no payment and stays a
  record like any other — editable, movable, deletable.
- The admin screens call a batch a *payout* and a row a *payment*, as SliceWP
  does: Payouts (batches) → a payout (its payments, "Mark all as paid") → a
  payment (its commissions, "Remove from payment"); plus one list of every
  payment across payouts.

## Consequences

- Money rule 7 keeps `payout_id` as the idempotency key for paying; the key is
  set at creation, the status at marking, and the same key locks the row in
  between.
- **The lock is a deliberate divergence from SliceWP.** SliceWP's refund and
  order-failure handlers skip only commissions that are already `paid`, so a
  commission sitting in an *unpaid* payment is rejected; its
  `slicewp_admin_action_mark_payment_as_paid` then selects by `payment_id`
  alone and writes `paid` over that rejected row, paying an affiliate for an
  order that no longer exists. Both halves were read in
  `slicewp/includes/base/integrations/woocommerce/functions-hooks-integration-woocommerce.php:524`
  and `slicewp/includes/admin/payouts/functions-actions-payments.php:401`.
  Parity is not a reason to reopen this: money rule 7 wins.
- An admin who needs to reject a commission that a payment is already counting
  takes it out of the payment first, which is the flow SliceWP offers too.
- The affiliate dashboard shows unpaid payments as such: an affiliate can see
  a payout is on its way before the money arrives.
- `Payout::STATUS_UNPAID` joins `STATUS_PAID`; the schema's `status` column
  needs no change.
- Automated payouts (a later phase) slot in as "create, transfer, mark paid on
  success" without touching the ledger.
