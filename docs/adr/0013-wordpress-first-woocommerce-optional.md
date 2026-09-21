# ADR-0013 — Standalone: every platform is an optional integration

**Status:** Accepted
**Date:** 2026-09-17
**Amends:** ADR-0001 (the boot order: `plugins_loaded`, not `woocommerce_loaded`) and ADR-0002 (Composer's loader ships; the in-house autoloader is gone)

## Context

Until now the plugin declared `Requires Plugins: woocommerce`, carried the
`WC requires at least` / `WC tested up to` headers, bootstrapped itself on
`woocommerce_loaded`, and showed an error notice without WooCommerce. That
made FlyAffiliate a WooCommerce add-on in the eyes of WordPress.org and of
the code.

FlyAffiliate is a standalone plugin. Affiliates, referral links, visits,
hand-entered commissions, payouts, the admin and the affiliate dashboard need
no other plugin at all. Platforms plug in as integrations, every one of them
optional: WooCommerce is the first source of order-based commissions, Dokan and
others follow, and none of them is special to the core.

## Decision

- The plugin bootstraps on `plugins_loaded`. There is no dependency header and
  no "needs WooCommerce" notice.
- `Integrations\WooCommerce` (checkout attribution, order status sync) is
  registered by `FlyAffiliate_Plugin::init_plugin()` only when
  `class_exists( 'WooCommerce' )` is true at `plugins_loaded`. Every other
  reference to a WooCommerce function is behind `function_exists()`.
- The daily maturation job uses Action Scheduler when WooCommerce provides it
  and WP-Cron otherwise; the hook is the same, so `Commission\HoldPeriod` does
  not care which.
- The currency setting lists WooCommerce's currencies when WooCommerce is
  there and a built-in set of common ones otherwise.
- The plugin header and the readme describe FlyAffiliate as affiliate marketing
  for WordPress that integrates with WooCommerce and more. WooCommerce stays the
  reference platform for the tests, the seeder and the money rules until a
  second integration exists.
- Activation runs the installer on any WordPress site; it no longer returns
  early without WooCommerce. The admin capability is `manage_options`,
  filterable to `manage_woocommerce` for stores that want shop managers in
  (ADR-0008, amended); nothing about access depends on WooCommerce.
- The build strips the remote-looking strings the bundled libraries leave
  behind (the Tailwind licence comment, the `xmlns` URL inside inlined SVG
  icons, plugin-ui's Google logo), so the shipped CSS and JS name no external
  host. WordPress.org's scanner reads any such string as a remote file.
- Other plugins' admin notices are left alone on FlyAffiliate's screens; the
  plugin adds no notices of its own outside them.
- Public signup is behind an "Open registration" setting (on by default) and
  the form carries a honeypot field.
- Classes are autoloaded by Composer's own loader (`vendor/autoload.php`,
  PSR-4 from `composer.json`), as Dokan does; the in-house
  `includes/Autoloader.php` is gone. The release zip carries a production
  `vendor/` that holds nothing but that loader: `bin/build-zip.php` runs
  `composer install --no-dev --optimize-autoloader` inside the stage and keeps
  `composer.json` and `composer.lock` beside it (Plugin Check expects the
  manifest wherever `vendor/` ships). The local `vendor/` with the development
  tools never ships, and no runtime package is ever added (ADR-0002).
- The zip is laid out and named as Dokan's: `build/flyaffiliate-v{version}.zip`
  holding `assets/`, `includes/`, `languages/`, `templates/`, `vendor/`, the
  two plugin files, `uninstall.php`, `readme.txt`, `CHANGELOG.md`,
  `composer.json`, `composer.lock` and `package.json`.

## Consequences

- A site with no integration active gets a working affiliate programme:
  signups, links, visits, commissions the admin records by hand, payouts.
- A commission with the WooCommerce origin still needs an existing order
  (`Commission\Manager::check_reference()`). Without WooCommerce the order
  cannot be checked, so a WooCommerce-origin commission with a reference is
  refused; the manual origin is the one to use.
- `Requires Plugins` is gone, so WordPress no longer blocks activation when
  WooCommerce is missing. That is the point.
- A future platform integration gets its own provider under
  `Integrations\{Platform}` and its own `init_plugin()` guard.
