# Changelog

All notable changes to FlyAffiliate are recorded here. The user-facing copy of
this list lives in `readme.txt` under `== Changelog ==`.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and
the project uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-09-17

The first release.

### Added

- **Affiliates** — email-only signup with a one-time activation link, an
  Affiliate user role, pending/active/inactive/suspended statuses, and an admin
  form with the payment email, website, promotion method and an optional
  welcome email.
- **Tracking** — a referral link per affiliate, a visit log with hashed visitor
  data, and a signed first-party cookie for the attribution window.
- **Commissions** — one commission per order item at checkout (classic and
  block), a product → default rate hierarchy clamped to a maximum, shipping
  and tax excluded by choice, and self-referral blocked by default.
- **Hold period** — a commission matures when the hold has passed and the
  order is paid, from the order status change or the daily background job;
  changing the hold reschedules every pending commission.
- **Order sync** — commissions follow the order: failed, cancelled and
  (optionally) refunded orders reject them, a recovered order restores them,
  a paid one is never reversed.
- **Hand-entered commissions** — affiliate, amount, reference order (checked
  against WooCommerce), reference amount, origin, date, type and status, on
  their own page, with the same fields on edit.
- **Payouts** — preview, create one payment per affiliate, mark paid or
  unpaid, take a commission out, delete an unpaid payment, export CSV.
- **Admin** — a React admin on weDevs' plugin UI: dashboard, affiliates,
  commissions, visits, payouts, settings and a setup wizard.
- **Affiliate dashboard** — `[flyaffiliate_dashboard]` with the referral link,
  balance, commissions, visits and payouts; `[flyaffiliate_register]` for
  signup.
- **REST API** under `flyaffiliate/v1` for affiliates, commissions, visits,
  payouts, settings and the affiliate's own data.
- **WP-CLI** `wp flyaffiliate seed` for demo data.
- Repository tooling: Composer and npm dependency sets, PHPCS ruleset, PHPUnit
  configuration, wp-env environments, webpack build, release archiver, Plugin
  Check runner, and CI for PHPCS, PHPUnit (PHP 8.1/8.3) and Plugin Check.

[1.0.0]: https://github.com/weDevsOfficial/flyaffiliate/releases/tag/v1.0.0
