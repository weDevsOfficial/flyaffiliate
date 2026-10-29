# Changelog

All notable changes to FlyAffiliate are recorded here. The user-facing copy of
this list lives in `readme.txt` under `== Changelog ==`.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and
the project uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-10-29

The first release.

### Added

- **Affiliates** — email-only signup with a one-time activation link, an
  Affiliate user role, pending/active/inactive/suspended statuses, and an admin
  form with the payment email, website, promotion method and an optional
  welcome email.
- **Tracking** — a referral link per affiliate, a visit log with hashed visitor
  data, and a signed first-party cookie for the attribution window.
- **Referral links** — affiliates generate a link to any page of the site from
  their dashboard and keep the ones they use, each with its visit count; the
  admin sees them on the affiliate's page.
- **Commissions** — one commission per order item at checkout (classic and
  block), a product → default rate hierarchy clamped to a maximum, shipping
  and tax excluded by choice, and self-referral blocked by default.
- **Hold period** — every commission carries the date its hold ends, fixed
  when it is created from the hold at that moment; a payout takes a
  commission only once that date has passed.
- **Order sync** — commissions follow the order: pending at checkout, unpaid
  once the order is paid; failed, cancelled and (optionally) refunded orders
  reject them, a recovered order restores them, a paid one is never reversed.
- **Hand-entered commissions** — affiliate, amount, reference order (checked
  against WooCommerce), reference amount, origin, date, type and status, on
  their own page, with the same fields on edit.
- **Payouts** — preview grouped by payout method, create one payment per
  affiliate, mark paid or unpaid, take a commission out, delete an unpaid
  payment, export CSV; each payment keeps the address it was sent to.
- **Payout methods** — Manual, paid to the affiliate's payment email, and a
  registry further methods plug into, with method logos, payment columns,
  accounts connected elsewhere and a lock on payments in flight; affiliates
  choose how they are paid in their dashboard.
- **WooCommerce Analytics** — an Affiliate Commission box on Analytics →
  Overview that counts the orders the report counts and opens the
  Commissions screen.
- **Admin** — a React admin on weDevs' plugin UI: dashboard with a chart,
  affiliates, commissions, visits, payouts, settings and a setup wizard that
  opens on a first install only; a header bar with Get Support and a help
  menu, and other plugins' notices kept off its screens.
- **Extensions** — a page that previews FlyAffiliate Pro's extensions while
  Pro is not active.
- **Affiliate dashboard** — `[flyaffiliate_dashboard]` with the referral link
  and a link generator, balance, commissions, visits, payouts and settings;
  `[flyaffiliate_register]` for signup.
- **Usage data** — opt-in Appsero Insights: nothing is sent until an
  administrator turns on Share Essentials in the setup wizard or allows it on
  the SDK's notice (ADR-0018).
- **REST API** under `flyaffiliate/v1` for affiliates, commissions, visits,
  referral links, payouts, payout methods, settings and the affiliate's own
  data.
- **WP-CLI** `wp flyaffiliate seed` for demo data.
- **Extension points** for a marketplace integration: `vendor_id` on
  commissions, a note on each commission, the `flyaffiliate_vendor_rate`,
  `flyaffiliate_order_item_vendor_id`, `flyaffiliate_max_rate`,
  `flyaffiliate_commission_note`, `flyaffiliate_analytics_vendor_id` and
  `flyaffiliate_commission_hold_days` filters, and the
  `flyaffiliate_commission_deleted` action.
- Repository tooling: Composer and npm dependency sets, PHPCS ruleset, PHPUnit
  configuration, wp-env environments, webpack build, release archiver, Plugin
  Check runner, Playwright end-to-end tests, and CI for PHPCS, PHPUnit
  (PHP 8.1/8.3) and Plugin Check.

[1.0.0]: https://github.com/weDevsOfficial/flyaffiliate/releases/tag/v1.0.0
