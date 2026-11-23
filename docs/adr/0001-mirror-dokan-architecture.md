# ADR-0001 — Mirror the Dokan Lite architecture

**Status:** Accepted
**Date:** 2026-09-07
**Note (2026-11-23):** the PHP floor is 7.4. See "PHP version" below.

## Context

FlyAffiliate is built and maintained by the same engineers who work on Dokan
(`getdokan/dokan`). It shares Dokan's runtime (WordPress + WooCommerce), its
release cadence, and — when the Dokan integration is active — its money paths.

A plugin of this size needs a decided answer to: where does a class go, how does
it get its dependencies, how does it register hooks, how is a screen overridden,
how is the schema upgraded, how is a test written. Inventing new answers costs
review time on every pull request and makes it expensive for a Dokan engineer to
work here.

## Decision

FlyAffiliate mirrors Dokan Lite's architecture:

- A DI container with service providers, tagged service groups resolved at boot
  (`common-service`, `admin-service`, `frontend-service`, `ajax-service`,
  `integration-service`, `container-service`, `cli-service`).
- `Contracts\Hookable`: any class implementing it is tagged with the interface
  name and gets `register_hooks()` called automatically at `init` priority 4.
- `Manager` facades over data stores, models over custom tables.
- Overridable templates: `templates/` in the plugin, `flyaffiliate/` in the theme.
- `Install\Installer` (dbDelta, options, pages, scheduled job) paired with
  `Upgrade\Manager` and versioned upgraders.
- A singleton plugin class (`FlyAffiliate_Plugin`) with a magic getter over the
  container, bootstrapped from the plugin entry file, initialised on
  `plugins_loaded` (ADR-0013; it was `woocommerce_loaded` while WooCommerce
  was required).
- PHPUnit with WP-PHPUnit, a `FlyAffiliateTestCase` base class, and factories.
- A PHP floor of 7.4, checked on activation (`$min_php`); see below.

The names, file layout and boot order follow Dokan's so that the mapping is
one-to-one. Where FlyAffiliate diverges, the divergence is recorded in an ADR:
see [ADR-0002](0002-in-house-container.md) and
[ADR-0003](0003-wporg-over-dokan-conventions.md).

## PHP version (2026-11-23)

FlyAffiliate first declared PHP 8.1, although no file used PHP 8 syntax:
every one already parsed on 7.4. It is now 7.4, the floor WordPress and
WooCommerce share.

- 7.4 is set in the plugin header's `Requires PHP`, the readme,
  `FlyAffiliate_Plugin::$min_php` and Composer's `require.php`;
  `config.platform.php` resolves the dev tools for it.
- No PHP 8 syntax and no PHP 8-only function; the backend-dev skill lists
  what that rules out. `str_contains()`, `str_starts_with()`,
  `str_ends_with()` and `array_is_list()` are fine in `includes/`, where
  WordPress polyfills them; `bin/` runs outside WordPress and cannot use them.
- CI enforces it: PHPUnit runs on 7.4, 8.1 and 8.3, and each leg first runs
  `php -l` on every tracked PHP file, so PHP 8 syntax in a file no test loads
  still fails the 7.4 leg. PHPCS's `testVersion` is `7.4-`, but the locked
  PHPCompatibility (9.3) predates PHP 8 and does not flag its syntax: a clean
  PHPCS run proves nothing here.
- Still on 8.1: the dev wp-env, the PHPCS, Plugin Check and deploy runners,
  and the e2e matrix, whose develop/master legs activate FlyAffiliate Pro,
  which requires 8.1. When Pro drops to 7.4, the WP 6.6 leg becomes the
  PHP 7.4 leg.
- What behaves differently before PHP 8.0 stays the code's job: `sort()` is
  not stable, `"abc" == 0` is true (strict comparisons are already the rule),
  and `is_numeric()` rejects trailing whitespace.

## Consequences

- A Dokan engineer can navigate this codebase without a tour.
- Dokan's own conventions are the tie-breaker for questions this ADR does not
  answer — except where WordPress.org rules conflict, which ADR-0003 settles.
- Dokan carries backwards-compatibility decisions that a plugin at 1.0.0 does
  not have to inherit. Constructor injection (ADR-0002) is the first example;
  each one is recorded rather than absorbed silently.
