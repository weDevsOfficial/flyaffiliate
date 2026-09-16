# ADR-0006 — Phase 1 pays affiliates manually; Dokan withdrawal is not an affiliate channel

**Status:** Accepted — amended by ADR-0012 (a batch now creates unpaid payments; marking a payment paid is what pays its commissions)
**Date:** 2026-09-07

## Context

Two documents disagree. `docs/MVP_PRD.md` §4 (Dokan Integration) says affiliates
"withdraw via Dokan's withdrawal methods". The prototype write-up and the Phase 1
payout screens describe the marketplace paying affiliates manually and recording
the payment.

They cannot both be built: Dokan's withdrawal system is vendor infrastructure. It
is bound to the vendor role, the vendor balance table, vendor withdrawal methods
and vendor approval flows. Routing affiliates through it would mean granting
affiliates a vendor-shaped identity they do not have, and reconciling two
independent balances against the same `dokan_vendor_balance` table.

## Decision

**Phase 1 payouts are manual and recorded, and affiliates have no access to
Dokan's withdrawal system.**

- An admin creates a payout batch from `unpaid` commissions, optionally filtered
  by a minimum amount and a date range.
- One `Payout` row per affiliate per batch. The commissions in it get
  `payout_id` set and move to `paid`.
- The batch exports to CSV so the marketplace can pay through whatever channel it
  already uses — bank transfer, PayPal, whatever the affiliate's payment email
  is for.
- The affiliate dashboard shows the balance and the payout history. It offers no
  "withdraw" button, because there is nothing on the other side of it.

The Dokan vendor charge (ADR-0004) is unaffected. It moves money from the
*vendor's* balance to the marketplace, which is what funds the payout. Who the
marketplace then pays, and how, is outside Dokan.

## Consequences

- `CONTEXT.md` keeps "payout" and "withdrawal" as different words on purpose:
  a payout is what an affiliate receives, a withdrawal is what a vendor takes.
- No payment gateway, no PCI surface, no onboarding flow in Phase 1 — which is
  the stated reason the MVP defers automated payouts at all.
- Phase 2 can add automated payouts without changing the ledger: the `Payout`
  row already carries `method`, `reference` and `status`.
- `docs/MVP_PRD.md` §4.4's "Affiliate can withdraw via Dokan's withdrawal
  methods" is superseded by this ADR.
