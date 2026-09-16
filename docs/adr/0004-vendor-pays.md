# ADR-0004 — On Dokan, the vendor funds the commission

**Status:** Accepted
**Date:** 2026-09-07

## Context

On a Dokan marketplace an order's money is already split: the vendor takes an
earning, the marketplace takes its commission. Introducing an affiliate
commission raises the question of whose share it comes out of.

The answer is settled in [`CONTEXT.md`](../../CONTEXT.md) ("Dokan rules") and was
learned the expensive way in the Dokan vendor-affiliate prototype
(dokan-pro discussion #6051). This ADR records it so that the reasoning survives
outside that discussion thread.

## Decision

**The vendor pays.** The affiliate commission reduces the vendor's earning; the
marketplace's share is untouched.

Worked example — a $100 sale, 20% marketplace commission, 15% affiliate rate:

| Party | Amount |
|---|---|
| Vendor withdraws | $65 |
| Affiliate is paid | $15 |
| Marketplace keeps | $20 |

The deduction happens in exactly two places, and nowhere else:

1. **What gateways read.** The filters
   `dokan_get_earning_from_order_table` and
   `dokan_get_vendor_earning_subtotal_by_order`. `dokan_get_earning_from_order_table()`
   caches *before* running its filters and returns early on a cache hit, so the
   adjusted figure is written into that cache key from inside the filter. The raw
   internal entry is left alone.
2. **What withdrawals check.** One credit row in `{prefix}dokan_vendor_balance`
   with `trn_type = 'flyaffiliate_commission'`, written when the commission
   **matures** — not when the order is placed. An order that never completes never
   costs the vendor anything. The write is keyed on the order meta
   `_flyaffiliate_vendor_charged` so a hook firing twice cannot double-charge.

Explicitly rejected:

- **Writing a smaller number into `dokan_orders`.** Dokan's
  `VendorBalanceUpdateHandler` recalculates on every order save and overwrites it.
- **Hooking `dokan_order_net_amount`.** Double-deducts.
- **Hooking `dokan_refund_approve_vendor_refund_amount`.** Double-refunds.
- **Reverse withdrawal.** It does not reduce the withdrawable balance, and it is
  a punitive mechanism aimed at unpaid marketplace fees, not at a cost the vendor
  agreed to.
- **Two refund safety nets.** On refund the vendor charge tracks the commission
  and Dokan's own clawback is left alone. Two mechanisms that are each correct in
  isolation together hand the vendor money that is not theirs.

## Consequences

- A vendor who opts into an affiliate program sees their earning drop by exactly
  the commission on their own items, and the vendor dashboard shows it as a line
  item rather than an unexplained shortfall.
- Refunds re-sync the charge on both `woocommerce_order_refunded` and
  `woocommerce_order_status_changed` — a refund issued from the WooCommerce order
  screen never reaches Dokan Pro's approval flow, so one hook is not enough.
- A commission that has already been **paid** to the affiliate is never reversed.
  Whoever paid it absorbs it; the hold period exists to make that rare.
- Every commission row carries `vendor_id` (0 without Dokan) so a multi-vendor
  cart attributes each item to the right sub-order.
