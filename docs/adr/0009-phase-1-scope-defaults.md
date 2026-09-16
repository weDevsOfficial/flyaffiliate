# ADR-0009 — Three Phase 1 scope defaults

**Status:** Accepted
**Date:** 2026-09-07

Three questions the bootstrap brief left open that are too small for an ADR each,
recorded so they are not re-litigated in review.

## 1. The activation link is the only email

`docs/MVP_PRD.md` defers email notifications to Phase 2. Registration still needs
one email, because the flow is email-only signup followed by a one-time
activation link — without it there is no way to confirm the address.

The prototype's settings carried a "welcome email" toggle. That toggle becomes
the **activation email** toggle: it controls whether the activation link is sent,
not a separate welcome message. Turning it off means an admin activates
affiliates by hand.

No other email is sent in Phase 1: no payout notification, no commission
notification, no summary.

## 2. Minimum WordPress version is 6.4

`Requires at least: 6.4`, and `minimum_supported_wp_version` in
`phpcs.xml.dist` is 6.4.

The `Requires Plugins: woocommerce` header is only *enforced* by WordPress 6.5
and later. On 6.4 it is inert but harmless, and the plugin still detects a
missing WooCommerce at runtime and shows an admin notice. Requiring 6.5 to make
one header enforceable would exclude sites for no functional gain.

## 3. The schema supports fixed-amount rates; Phase 1's UI does not offer them

`{prefix}flyaffiliate_commissions` carries `rate_type` (`percentage|fixed`) and
the calculator honours both. The settings screen and the product rate field
expose **percentage only** in Phase 1, matching the PRD.

The column exists now because adding it later would mean a schema upgrade across
every install and a backfill of every historical row with an assumed value. It
costs nothing to store the truth from the first row written.

A commission row therefore always records `rate_type = 'percentage'` in Phase 1.
Anything reading the column must still handle `fixed`, because a filter can
already produce it and Phase 2 will expose it.
