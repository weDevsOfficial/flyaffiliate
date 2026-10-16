# ADR-0016 — Payout methods are a registry; a payment in flight is locked

**Status:** Accepted
**Date:** 2026-10-06
**Amends:** ADR-0006 (payouts are no longer manual only), ADR-0012 (which still holds for Manual)

## Context

FlyAffiliate paid affiliates one way: Manual. The admin records a payout, sends
the money outside WordPress and marks each payment paid (ADR-0012). FlyAffiliate
Pro adds methods that send the money themselves — PayPal Payouts first, Stripe
next (flyaffiliate-pro#1, #2) — and one payout must be able to span them: each
affiliate is paid by the method they chose.

Three things were missing for that: a list of methods another plugin can add
to (`Payout::get_methods()` was hard-coded and `Payout\Manager::create()` wrote
`manual` on every row), a per-affiliate choice, and a rule for what may happen
to a payment while a service is moving its money. The free plugin must stay
neutral: it names no service, and works exactly as before when nothing else is
installed.

## Decision

- **The registry.** `PayoutMethod\Manager` (service `payout_method`) lists the
  methods through the `flyaffiliate_payout_methods` filter. A method implements
  `Contracts\PayoutMethod`, usually through `Abstracts\PayoutMethod`. Manual is
  built in, always on, and cannot be replaced. The list is read fresh on every
  call, because Pro switches an extension on mid-request.
- **Who pays whom.** An affiliate chooses a method (`affiliates.payout_method`,
  empty for the default) and gives the details it asks for
  (`get_affiliate_fields()`; stored by default in the user meta
  `_flyaffiliate_payout_details_{method}`). `resolve_for()` picks the method:
  a per-payout override ("Switch to Manual" on the preview), the affiliate's
  choice, the `method` setting ("Default payout method"), then Manual — each
  only while switched on. The setting lists every registered method, never only
  the enabled ones: asking a method whether it is on may read a setting, and
  reading an unstored setting builds the settings schema again.
- **The ledger does not move.** A payment is still `unpaid` or `paid`, and only
  `Payout\Manager` moves it (ADR-0012). An automatic method keeps its own record
  of each attempt — a *transfer* — and reports its state through
  `get_states()`: `queued`, `processing`, `pending`, `paid` or `failed`.
  A state may carry keys of the method's own, which FlyAffiliate passes
  through untouched. The method shows them through two hooks, without
  FlyAffiliate knowing what they mean (amended 2026-10-16): a column in the
  payments table (`flyaffiliate_payment_columns`, a JS filter returning
  `{ id, label, text( payment ), hint?( payment ), isVisible?( payments ) }`,
  drawn with FlyAffiliate's own cells, after Status, the other columns
  narrowing to make room) and a card beside a payout's totals (`stats` in
  the summary, through `flyaffiliate_payout_summary`).
  `create()` hands each automatic method its payments (`pay()`) right after the
  payout is recorded; the method marks a payment paid with
  `mark_paid( $id, true )` once the money has arrived, and unpaid with
  `mark_unpaid( $id, true )` if it comes back.
- **Locked.** A payment whose transfer is queued, processing or pending, or
  that its method reports paid, is locked — and so is one whose method is not
  registered at all (the plugin that sent it is off), since nothing then knows
  where the money is. A locked payment refuses deletion, taking a commission
  out, re-summing, marking it paid or unpaid by hand, and edits to the
  commissions under it. Only its method settles it. A failed transfer, or none,
  leaves the payment free: the admin can send it again (`retry()`, which
  re-sums first), pay it some other way and mark it paid — it is then recorded
  as Manual — or delete it. `flyaffiliate_payout_locked` lets a site release a
  payment deliberately.
- **Nothing is attached twice.** `create()` refuses when the amounts changed
  since the preview the admin confirmed (`expected`), and attaches each
  commission with one conditional write (`… WHERE payout_id IS NULL OR
  payout_id = 0`); losing that race unwinds the whole batch. Money rule 7 is
  now enforced at the row, not by a read before the write.

## Consequences

- `Payout\Manager::pay_batch()` ("Mark manual payments as paid") marks only
  payments a non-automatic method pays.
- A payout has a status of its own, computed from its payments
  (`summarize()`): `processing` while anything is in flight, `needs_attention`
  when a transfer failed, was never sent, or its method is gone, `unpaid` when
  only payments to make by hand are left, `paid` when everything is.
- The payout screens follow the transfers: the payout page polls
  `GET /payouts/batch/{key}` while money is on its way, and every third time
  `POST …/refresh`, which asks each method for news, so a site that does not
  receive a service's notifications still settles.
- The affiliate sees a transfer's state on `/me/payouts`, never the failure
  message (it can name the store's account) or the admin's actions.
- With only Manual registered nothing changes on any screen: the preview has
  one group, there is no picker on the dashboard, and the setting has one
  option.
- Removing the plugin that sent payments leaves them locked until it is back,
  by design: the alternative is letting an admin pay twice.
