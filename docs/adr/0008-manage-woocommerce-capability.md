# ADR-0008 — Admin screens and admin REST routes require `manage_woocommerce`

**Status:** Accepted
**Date:** 2026-09-07

## Context

The prototype gates every screen on `manage_options`, which is an administrator-
only capability that has nothing to do with commerce. A shop manager — the role
that actually processes orders and refunds on a WooCommerce store — cannot see an
affiliate or approve a payout.

The alternative to `manage_woocommerce` is a purpose-made capability such as
`flyaffiliate_manage_affiliates`, granted to `administrator` and `shop_manager`
on activation.

## Decision

FlyAffiliate uses **`manage_woocommerce`** for the admin menu, every admin
screen, every `admin_post_flyaffiliate_*` handler, and every route on
`REST\AdminBaseController`.

`manage_options` does not appear in this codebase.

Reasons a custom capability was rejected for Phase 1:

- It requires writing to roles on activation, which means an upgrade path, a
  repair path for sites restored from a backup with stale roles, and a decision
  about what happens on deactivation.
- A site that manages roles with a membership plugin then has one more capability
  to keep in sync, and the failure mode is an admin locked out of their own
  plugin.
- `manage_woocommerce` is the capability WooCommerce itself uses for its
  settings and reports, and the one Dokan settled on for the same question. A
  user who can configure WooCommerce commissions can configure affiliate
  commissions.

Affiliate-facing REST routes are separate and self-scoped: `AffiliateBaseController`
requires a logged-in user with an active affiliate record, and every query it
runs is bound to that affiliate's own id. It never checks `manage_woocommerce`.

## Consequences

- Administrators and shop managers reach the plugin with no role plumbing at all.
- Granting a narrower role access means granting `manage_woocommerce`, which is
  broad. If a customer needs affiliate-only access, that is a Phase 2 capability
  and a deliberate decision, not a default.
- `phpcs.xml.dist` lists `manage_woocommerce` under `WordPress.WP.Capabilities`
  so the sniff recognises it.
