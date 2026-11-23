# ADR-0019 — PHP 7.4 is the floor

**Status:** Accepted
**Date:** 2026-11-23

## Context

FlyAffiliate declared PHP 8.1 in four places: the plugin header's
`Requires PHP`, the readme, `FlyAffiliate_Plugin::$min_php` (the activation
check) and Composer's `require.php`. WordPress and WooCommerce both run on
PHP 7.4, so the 8.1 floor kept FlyAffiliate off sites its platforms support.

The code did not need 8.1. Every tracked PHP file already parsed on 7.4 (no
union types, `match`, `?->`, constructor promotion, enums or `readonly`), and
the PHP 8 functions it calls, `str_starts_with()` among them, are polyfilled
by WordPress core.

## Decision

- **The floor is PHP 7.4**, in all four places, and Composer resolves the dev
  tools for it (`config.platform.php` 7.4).
- **No PHP 8 syntax or PHP 8-only functions.** The backend-dev skill lists
  what that rules out. PHP 8 functions WordPress polyfills
  (`str_contains()`, `str_starts_with()`, `str_ends_with()`, `array_is_list()`)
  are fine in `includes/`; `bin/` runs outside WordPress and cannot use them.
- **CI enforces it.** The PHPUnit workflow runs on 7.4, 8.1 and 8.3, and each
  leg first runs `php -l` on every tracked PHP file, so PHP 8 syntax in a
  file no test loads still fails the 7.4 leg. PHPCS's `testVersion` is
  `7.4-`, but the locked PHPCompatibility (9.3) predates PHP 8 and does not
  flag its syntax: PHPCS does not catch it, and nobody should read a clean
  PHPCS run as proof.

## Not changed

- The dev environment (`.wp-env.json`) stays on 8.1, and so do the PHPCS,
  Plugin Check and deploy runners.
- The e2e matrix stays on 8.1: its develop/master legs activate FlyAffiliate
  Pro, which still requires 8.1. Pro's floor is its own decision. When it
  drops to 7.4, the WP 6.6 leg should become the PHP 7.4 leg.

## Consequences

- A contributor on PHP 8 can write code that passes locally and fails the
  7.4 leg. The dev-cycle skill says to run the suite with
  `{ "phpVersion": "7.4" }` in `.wp-env.override.json` before a change that
  adds PHP.
- Behaviour that differs between 7.4 and 8.x stays the code's job: `sort()`
  is not stable before 8.0, `"abc" == 0` is true before 8.0 (strict
  comparisons are already the rule), and `is_numeric()` rejects trailing
  whitespace before 8.0.
- On 7.4, the dev lock pins older dev packages (`doctrine/instantiator` 1.5,
  the Symfony 5.4 contracts and string component). Nothing from `vendor/`
  ships, so the release zip is unaffected.
