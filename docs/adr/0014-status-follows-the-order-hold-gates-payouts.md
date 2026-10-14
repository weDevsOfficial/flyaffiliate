# ADR-0014 — The commission status follows the order; the hold period gates payouts

**Status:** Accepted
**Date:** 2026-09-28
**Amends:** CONTEXT.md money rule 4 and ADR-0013 (the daily maturation job it mentions is gone)

## Context

Until now a WooCommerce commission became `unpaid` only when two things were
true at once: its order had reached a paid status (`processing` or `completed`,
cash on delivery `completed`) **and** `created_at + hold_days` had passed. The
first was checked on the order status change, the second by a daily job on
Action Scheduler (WP-Cron without WooCommerce). A commission on an order paid
today with a 30-day hold sat `pending` for a month, and the affiliate saw a
sale that was not counted.

The product owner asked for the two conditions to be separated: an order being
paid is what approves a commission, and the hold period is a payout rule.
"This holding period is related to payouts — when we generate ready-to-pay
commissions we check that commission's holding period. It is not related to
the commission status."

SliceWP, the behaviour reference, has no hold period at all: its WooCommerce
integration marks a commission `unpaid` the moment the order completes, and a
payout takes every `unpaid` commission. FlyAffiliate's hold is its own
addition, and it belongs on the side that SliceWP does not have — the payout —
not in the status lifecycle SliceWP already defines.

## Decision

1. **`pending` means the order is not paid yet. `unpaid` means the commission is
   approved.** `Integrations\WooCommerce\OrderStatusSync` is the only automatic
   status mover: an order reaching a paid status moves its `pending` and
   `rejected` WooCommerce commissions to `unpaid` on the spot, whatever the
   hold; the same happens right after checkout attribution when the order is
   already paid. Failing, cancelling, trashing and (optionally) refunding still
   reject; an order leaving one of those for a status that is not paid still
   restores to `pending`.
2. **The hold period gates payouts.** `matures_at` is `created_at + hold_days`
   on every commission, whatever its status. `Payout\Manager::preview()` — and
   so `create()` — takes an `unpaid` commission only once `matures_at` is in the
   past (`Commission::is_matured()`; a row with no date has nothing to wait
   for). The preview reports what it left out as *held*, next to the *pending*
   figure it already reported, so the payout screen can say why an unpaid
   commission is not in the batch.
3. **Changing `hold_days` reschedules** every `pending`, `unpaid` and
   `rejected` row to `created_at + hold_days`; `paid` rows keep their dates.
   Nothing else happens on the save: no status changes.
4. **No maturation job.** `Commission\HoldPeriod` keeps only the hold: the
   reschedule and the settings hook. `HoldPeriod::run()` and its listener are
   removed. The installer still schedules its daily hook
   (`Installer::MATURATION_HOOK`), as Dokan's installer schedules one, with
   nothing listening; deactivation still clears it. The order rule (which statuses
   count as paid, cash on delivery waits for `completed`) moves to
   `OrderStatusSync::order_is_paid()`, behind the
   `flyaffiliate_paid_order_statuses` filter, so nothing under
   `Commission\` references WooCommerce any more.
5. **A hand-entered commission keeps the status it is given**, as before and as
   in SliceWP. A manual-origin row is never moved by an order. A WooCommerce-
   origin row entered by hand follows its order like an attributed one.

## Consequences

- With a hold of 30 days the admin sees the commission as `unpaid` the day the
  order is paid, and the payout preview leaves it out for a month, saying so.
  With a hold of 0 nothing changes from SliceWP's behaviour.
- A `pending` commission that already exists on an order paid before this
  change is not touched by the deploy: it becomes `unpaid` on the order's next
  status change, or when an admin marks it unpaid. The sites running the
  plugin at this point are development sites; no upgrader is written for it.
- `Matures` in the lists is now labelled *Payable from*. The copy that said
  "pending until the hold period ends" says "pending until the order is paid".
- The `flyaffiliate_commissions_matured` action and the
  `flyaffiliate_mature_order_statuses` filter are gone (never released).
- CONTEXT.md money rule 4 and the glossary entries for *Hold period* and
  *Mature* are rewritten to match.
