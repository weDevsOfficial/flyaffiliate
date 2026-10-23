# FlyAffiliate - CLAUDE.md

## Project Overview

FlyAffiliate is a standalone affiliate-marketing plugin for WordPress, built by weDevs for distribution on WordPress.org. Requires PHP 8.1+ and WordPress 6.6+ (the built apps depend on the `react-jsx-runtime` script handle, which core registers from 6.6). Every platform integration is optional and loads only when its plugin is active (ADR-0013): WooCommerce (8.5+) today, Dokan and others later. This branch has no marketplace integration: everything Dokan-specific (the `dokan_loaded` provider, the vendor-program settings, the Dokan test leg) lives on the `feature/dokan-integration` branch, which is this branch plus that work. Keep it that way — the neutral seams stay here (`vendor_id` on commissions; the `flyaffiliate_vendor_rate`, `flyaffiliate_order_item_vendor_id`, `flyaffiliate_max_rate`, `flyaffiliate_commission_note`, `flyaffiliate_analytics_vendor_id` and `flyaffiliate_commission_hold_days` filters; the `flyaffiliate_commission_deleted` action; the dashboard's optional `program.holdNote`). FlyAffiliate Pro's Dokan extension plugs into them.

The architecture mirrors Dokan Lite (`getdokan/dokan`): DI container + service providers, `Hookable` classes, `Manager` facades, overridable templates, an `Installer`/`Upgrade` pair, `FlyAffiliateTestCase`-based PHPUnit tests. Anyone who knows the Dokan codebase should feel at home here.

**This plugin ships on WordPress.org.** Every change must pass Plugin Check (PCP) with zero errors and, by default, zero warnings. That constraint overrides any Dokan convention that conflicts with it (see `flyaffiliate-wporg-compliance`).

## Domain Model

- **`CONTEXT.md`** — the canonical glossary and the money rules. Read it before naming things or touching commission, refund, or payout code. Use its terms (Commission not Referral, Affiliate not Partner, Vendor not Seller) and respect its *Avoid* column.
- **`docs/MVP_PRD.md`** — Phase 1 scope. **`docs/PRD.md`** — full product requirements. **`docs/adr/`** — Architecture Decision Records; check here before "fixing" surprising behaviour.
- New root-level files must be added to `.distignore` or they ship in the release zip.

## Available Skills

The `.claude/skills/` directory contains procedural HOW-TO instructions:

- **`flyaffiliate-backend-dev`** — Backend PHP conventions: namespaces, bootstrap, DI container, `Hookable`, settings, templates, REST, data access, integration guardrails. **Invoke before writing any PHP code or tests.**
- **`flyaffiliate-dev-cycle`** — Build, lint, PHPUnit, Plugin Check, release-zip workflows and test-writing conventions.
- **`flyaffiliate-wporg-compliance`** — WordPress.org submission rules and the Plugin Check gate. **Invoke before every commit that touches PHP, assets, readme.txt, or the plugin header, and before any release.**
- **`flyaffiliate-code-review`** — Review standards: security, money-rule, and architecture violations to flag; severity levels; output format.
- **`flyaffiliate-git`** — Branching, commit format, PR template, CI checks.
- **`flyaffiliate-wp-env-worktrees`** — A branch or PR in its own worktree and wp-env: ports, an isolated or cloned database. A change that also touches Pro uses Pro's `flyaffiliate-pro-wp-env-worktrees`.

## Build & Development Commands

> **Current state:** Phases 0–2 have landed, and the admin is a React app on
> `@wedevs/plugin-ui` (ADR-0010): one page, hash routes, DataViews lists for
> Affiliates, Commissions, Visits and Payouts (preview → create → mark paid, ADR-0012), the
> plugin-ui `<Settings>` screen driven by a flat-array schema stored in one
> option, dialogs for add/edit (the commission form is a page), and Playwright coverage under `tests/pw`. The
> setup wizard is the `#/setup` route of the same app; only the user-profile
> section stays PHP-rendered. The affiliate dashboard (`[flyaffiliate_dashboard]`)
> is a second React app (`src/dashboard`) on the same components, reading the
> self-scoped `me` REST routes; the states around it (logged out, pending) are
> PHP templates. Both apps share `src/styles/tailwind.css` (the Tailwind entry, plain CSS) and `src/styles/base.scss` (the plugin's own rules; every stylesheet we write is SCSS). Also present:
> both shortcodes, REST for every resource, `wp flyaffiliate seed`, and the
> Phase 3 money loop: `Tracking\Tracker` (referral link → visit → signed
> cookie), `Integrations\WooCommerce\OrderAttribution` (one pending commission
> per order item at checkout, classic and block), `Commission\RateResolver`
> (product → vendor filter → default, clamped), the maturity date every commission
> carries (`Commission\Manager::maturation_date()`, fixed when it is created;
> the hold gates payouts, not statuses, ADR-0014) and `Integrations\WooCommerce\OrderStatusSync` (the commission
> status follows the order, as in SliceWP: paid → unpaid at once, with the
> optional `reject_commissions_on_refund` switch), and
> `Integrations\WooCommerce\Analytics` (an "Affiliate Commission" box on
> WooCommerce → Analytics → Overview, narrowed to a vendor through
> `flyaffiliate_analytics_vendor_id`; it counts the orders the report counts —
> imported into `wc_order_stats`, by the report's date type, excluded
> statuses left out). Commissions carry a `note`
> (`flyaffiliate_commission_note`) the Commissions list and the affiliate
> dashboard show as an info tip. Not yet built: partial-refund
> rescaling and the optional Dashboard. The Dokan integration is on `feature/dokan-integration`.

```bash
# PHP
composer install            # Dev tools; Mozart re-prefixes the Appsero SDK into vendor-prefixed/ — nothing from vendor/ ships
composer phpcs              # PHP CodeSniffer (WordPress-Extra + PHPCompatibilityWP)
composer phpcbf             # Auto-fix code style
composer test               # PHPUnit (needs a provisioned WP test install or wp-env)
composer test-f <filter>    # PHPUnit with --filter

# Assets (Phase 1 admin is PHP-rendered; the build pipeline exists for later React work)
npm run start               # Dev build with watch
npm run build               # Production build
npm run lint:js / lint:css
npm run makepot             # languages/flyaffiliate.pot
npm run typecheck           # tsc over src/
npm run test:e2e            # Playwright against the wp-env dev site (env:start first)

# Environment & tests
npm run env:start           # wp-env: WordPress + WooCommerce in Docker
npm run env:stop
npm run phpunit             # PHPUnit inside wp-env's tests environment
npm run test:phpunit        # env:start → phpunit → env:stop
# .wp-env.json is the shared base CI reads; local settings go in the gitignored .wp-env.override.json

# WordPress.org gate
npm run release             # Builds build/flyaffiliate-v<version>.zip honouring .distignore
npm run plugin-check        # Runs Plugin Check against the built zip (wp-env)
```

## Architecture

### Entry Points
- `flyaffiliate.php` — plugin header, autoloader, container creation, `flyaffiliate()` accessor
- `flyaffiliate-class.php` — `FlyAffiliate_Plugin` singleton, plugin bootstrap
- `uninstall.php` — data removal, gated by the "clear data on uninstall" setting

### Initialization Flow
1. `flyaffiliate.php` requires Composer's autoloader (`vendor/autoload.php`, PSR-4 `FlyAffiliate\` → `includes/`)
2. Creates the `Container` instance
3. Registers `Providers\ServiceProvider`
4. Calls `FlyAffiliate_Plugin::init()`
5. On `plugins_loaded`, `init_plugin()` includes function files, adds the integration provider (whose platform services register only when WooCommerce is active), and registers hooks
6. On `init` (priority 4), `init_classes()` resolves the tagged service groups; every `Hookable` gets `register_hooks()` called

### Directory Structure

```
flyaffiliate/
├── flyaffiliate.php
├── flyaffiliate-class.php
├── uninstall.php
├── readme.txt
├── includes/                      # PHP backend, PSR-4 root for FlyAffiliate\
│   ├── Abstracts/                 # Shortcode, PayoutMethod (safe defaults + the affiliate-details store)
│   ├── Admin/                     # Menu (hash routes), PayoutExport, Settings/{Schema,Repository,Manager}, Notices, SetupWizard, UserProfile
│   ├── Affiliate/                 # Manager, Registration, Role (the flyaffiliate_affiliate user role)
│   ├── Commission/                # Manager, Commission model, Calculator, RateResolver, Hooks
│   ├── Contracts/                 # Hookable, PayoutMethod
│   ├── DependencyManagement/      # Container, Definition, BaseServiceProvider, Providers/
│   ├── Frontend/                  # Shortcodes, AffiliateDashboard, Hooks
│   ├── Install/                   # Installer (dbDelta, pages, options, cron)
│   ├── Integrations/
│   │   └── WooCommerce/           # OrderAttribution (checkout), OrderStatusSync, HPOS-safe helpers
│   ├── Models/                    # BaseModel + data stores over the custom tables
│   ├── Payout/                    # Manager, Payout model, CsvExporter
│   ├── PayoutMethod/              # Manager (the `flyaffiliate_payout_methods` registry, locks), Manual
│   ├── ReferralLink/              # Manager: the affiliate's saved referral links and their visit counts
│   ├── REST/                      # Manager, BaseController, AdminBaseController, controllers
│   ├── Tracking/                  # Tracker (query var → cookie), Visit model, Hooks
│   ├── Upgrade/                   # Manager + Upgrades/ versioned upgraders
│   ├── Utilities/
│   ├── Assets.php
│   ├── Tracker.php                # Appsero Insights, opt-in, as Dokan Lite's Tracker (ADR-0018)
│   └── functions.php              # flyaffiliate_get_option(), flyaffiliate_get_template_part(), helpers
├── templates/                     # Overridable templates: admin/ (app mount, profile), affiliate-dashboard/, registration/
├── assets/                        # css/, js/ (built output only; plain sources in assets/src/)
├── vendor-prefixed/               # Mozart output: the Appsero SDK as FlyAffiliate\Dependencies\ — committed, never edited
├── src/admin/                     # The React admin app: App.tsx (routes), pages/, components/, hooks/, lib/, tailwind.css + admin.scss
├── src/dashboard/                 # The affiliate dashboard app (frontend): App.tsx (tabs), pages/, tables/, tailwind.css + dashboard.scss
├── src/styles/tailwind.css        # The Tailwind entry both apps import: plugin-ui tokens, scoped preflight and utilities
├── src/styles/base.scss           # The shared rules both apps @use: reset, app root, DataViews tables
├── languages/
├── tests/php/                     # bootstrap.php, phpunit-wp-config.php, src/ (FlyAffiliate\Test\)
├── tests/pw/                      # Playwright: playwright.config.ts, global-setup.ts, pages/, utils/, specs/
├── bin/                           # build-zip.php, plugin-check.sh
├── docs/                          # adr/, tdd/, PRD.md, MVP_PRD.md
├── .github/workflows/             # phpcs.yml, phpunit.yml, plugin-check.yml, deploy.yml
├── .wordpress-org/                # banner, icon, screenshots for the wp.org listing
└── .claude/skills/
```

### Service Container
Services are accessed via `flyaffiliate()->service_name` (magic getter) or `flyaffiliate()->get_container()->get( 'service_name' )`.

Named services **registered today**: `affiliate`, `registration`, `commission`, `payout`, `payout_method`, `tracking`, `referral_link`, `settings`, `assets`, `api`, `upgrades`, `installer`, `admin_notices`, `tracker`. Add a name here in the same commit that registers it.

The admin is one React app (`src/admin`, built to `assets/js/admin.js`) mounted by `Admin\Menu` on `admin.php?page=flyaffiliate`; every submenu entry is a hash route. Lists are plugin-ui `<DataViews>` over the REST controllers; forms are plugin-ui dialogs (the commission add/edit form is a route); the settings screen is plugin-ui `<Settings>` fed by `Admin\Settings\Schema\SettingsSchema`. Shortcodes extend `Abstracts\Shortcode`. While FlyAffiliate Pro is not active (`flyaffiliate_is_pro_active()`), `Admin\ProExtensions` adds an Extensions page that previews Pro's extensions with an upgrade dialog, as Dokan Lite shows Dokan Pro's modules; with Pro running, Pro's page takes its place (Pro's ADR-0005). See ADR-0010 and `.claude/skills/flyaffiliate-backend-dev` ("Settings", "Admin app").

Tagged groups resolved at boot: `common-service`, `admin-service`, `frontend-service`, `ajax-service`, `integration-service`, `container-service`, `cli-service`. A group with nothing in it yet is normal — read groups with `get_tagged()`, which returns an empty array, rather than `get()`, which throws for an unknown identifier.

Any class implementing `FlyAffiliate\Contracts\Hookable` is also tagged with the interface name and gets `register_hooks()` called automatically. The container is in-house and League-shaped (ADR-0002): `add`/`addShared`/`addTag`/`setShared`/`addServiceProvider` keep League's camelCase, and constructor dependencies are autowired by type hint.

### REST API
Namespace `flyaffiliate/v1`. Controllers extend `FlyAffiliate\REST\AdminBaseController` (admin-only, `flyaffiliate_admin_capability()`: `manage_options` unless filtered) or `FlyAffiliate\REST\AffiliateBaseController` (self-scoped affiliate endpoints). Every route has a `permission_callback`. Every controller implements `prepare_item_for_response()`, `prepare_links()`, `get_item_schema()`.

## Coding Standards

- **PHP**: WordPress Coding Standards via PHPCS (`phpcs.xml.dist`). Security sniffs (escaping, sanitization, nonces, prepared SQL) are **not** relaxed — Plugin Check enforces them.
- **Text domain**: `flyaffiliate` — matches the plugin slug. Do not call `load_plugin_textdomain()`; WordPress.org loads translations automatically and Plugin Check flags the call.
- **Prefixes**: functions `flyaffiliate_`, hooks `flyaffiliate_`, options `flyaffiliate_`, meta `_flyaffiliate_`, constants `FLYAFFILIATE_`, DB tables `{prefix}flyaffiliate_`, CSS/JS handles `flyaffiliate-`, namespace `FlyAffiliate\`.
- **`@since` for new code**: use the literal placeholder `@since FLYAFFILIATE_SINCE` (also `@deprecated FLYAFFILIATE_SINCE`). Release tooling replaces it. Never guess a version number.
- **Every PHP file** starts with the direct-access guard `if ( ! defined( 'ABSPATH' ) ) { exit; }` after the docblock — including templates and class files.
- **Strict comparisons** (`===`), `in_array( ..., true )`, Yoda not enforced, `snake_case` methods and hooks.
- **JS/TS**: ESLint via `@wordpress/scripts`; **CSS**: Stylelint.

## Key Patterns

- Service-based architecture with an in-house, League-shaped DI container (no third-party runtime packages ship in the zip, except the Mozart-prefixed Appsero SDK in `vendor-prefixed/`, ADR-0018)
- Appsero Insights, opt-in as in Dokan Lite (`FlyAffiliate\Tracker`, as Dokan Lite's; not `Tracking\Tracker`, the referral tracker; ADR-0018): nothing is sent until an admin allows it through Share Essentials in the setup wizard or the SDK's notice
- WordPress hooks for extensibility; every hook name starts with `flyaffiliate_`
- Overridable templates (`templates/` → theme `flyaffiliate/`)
- Custom tables via `dbDelta` in `Install\Installer`, versioned upgrades in `Upgrade\Manager`
- Action Scheduler (bundled with WooCommerce) for the daily hook, WP-Cron only as the fallback; nothing listens to it today (ADR-0014)
- HPOS-compatible: always go through `wc_get_order()` / `WC_Order` methods, never `post_meta` on orders

## Testing

- **PHPUnit 9.6** with WP-PHPUnit, Brain Monkey, Mockery; all tests extend `FlyAffiliate\Test\FlyAffiliateTestCase`
- Test factories in `tests/php/src/Factories/` (affiliate, commission, visit, payout, product, order)
- Every money path has a test that asserts integer-cent equality and idempotency (hook fired twice → same result)