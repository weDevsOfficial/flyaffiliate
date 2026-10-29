# ADR-0001 — Mirror the Dokan Lite architecture

**Status:** Accepted
**Date:** 2026-09-07

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

The names, file layout and boot order follow Dokan's so that the mapping is
one-to-one. Where FlyAffiliate diverges, the divergence is recorded in an ADR:
see [ADR-0002](0002-in-house-container.md) and
[ADR-0003](0003-wporg-over-dokan-conventions.md).

## Consequences

- A Dokan engineer can navigate this codebase without a tour.
- Dokan's own conventions are the tie-breaker for questions this ADR does not
  answer — except where WordPress.org rules conflict, which ADR-0003 settles.
- Dokan carries backwards-compatibility decisions that a plugin at 1.0.0 does
  not have to inherit. Constructor injection (ADR-0002) is the first example;
  each one is recorded rather than absorbed silently.
