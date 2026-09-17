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
- **A payment holds its commissions, the admin still edits them.**
  `Commission::is_locked()` covers `payout_id > 0`: while a payment counts a
  commission towards money an admin is about to send, no refunded, cancelled,
  failed or trashed order and no maturation job moves it (`set_status()`
  refuses the automatic callers), and it is not deleted. The admin keeps
  SliceWP's freedom — amount, reference, type and status stay open on the edit
  page — and the payment follows: unpaid, it re-sums to its unpaid commissions
  (`resync()`); paid, it keeps the amount it was paid with, so an edit after
  payment corrects the commission's own record only. `remove_commission()` and
  `delete()` are the ways out of a payment. `mark_paid()` pays only the
  commissions still `unpaid`; `mark_unpaid()` returns only the ones it paid.
- A commission an admin records as paid by hand (SliceWP's default on its add
  form) has no payment and is held by nothing — editable, movable, deletable.
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
