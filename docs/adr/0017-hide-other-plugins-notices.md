# ADR-0017 — Other plugins' notices are hidden on FlyAffiliate's screen

**Status:** Accepted
**Date:** 2026-10-08
**Amends:** ADR-0010 ("notices left alone") and ADR-0013 (other plugins' notices left alone on FlyAffiliate's screens)

## Context

ADR-0010 and ADR-0013 left other plugins' `admin_notices` where WordPress puts
them, above FlyAffiliate's app, on the reading that WordPress.org treats
removing them as hijacking the dashboard (Guideline 11). On a real store that
is a stack of upgrade prompts, trial offers and data-collection requests above
the header bar before any of FlyAffiliate shows (thirteen on the development
site), none of them about FlyAffiliate.

Dokan's admin dashboard and WooCommerce's admin pages, both distributed on
WordPress.org, hide other plugins' notices on their own screens. The product
decision (2026-10-08) is to do the same.

## Decision

- **On FlyAffiliate's screen (`toplevel_page_flyaffiliate`) only**, every
  notice printed on `admin_notices` and `all_admin_notices` goes into a hidden
  container: `Admin\Notices\Manager` opens it at `admin_notices` priority
  `PHP_INT_MIN` and closes it at `all_admin_notices` priority `PHP_INT_MAX`.
  Every other screen of wp-admin is untouched.
- The container holds the page's **only** `.wp-header-end`. WordPress's admin
  script moves every `.notice`, `.updated` and `.error` box printed elsewhere
  after the first `.wp-header-end`, so those end up hidden as well. The app
  template (`templates/admin/app.php`) no longer carries its own; with two,
  the script would put a copy of each notice after both.
- **FlyAffiliate's own notices stay visible**: they render on the new
  `flyaffiliate_before_admin_app` action, inside the template above the app,
  with the `inline` class that keeps the admin script from moving them.
- Nothing is removed: the notices still print and other screens still show
  them; FlyAffiliate only hides them on its own page.

## Consequences

- A notice another plugin needs the admin to see is visible on every screen
  but FlyAffiliate's. FlyAffiliate's own notices, Pro's included (through
  `flyaffiliate_admin_notices`), are unaffected.
- A theme override of `admin/app.php` must not add a `.wp-header-end`, and
  should keep `do_action( 'flyaffiliate_before_admin_app' )`, or
  FlyAffiliate's own notices stop showing.
- A notice drawn without `.notice`/`.updated`/`.error` and printed outside
  `admin_notices`/`all_admin_notices` (late, from a script) is not caught.
- The WordPress.org review may question it under Guideline 11; Dokan's and
  WooCommerce's identical behaviour is the precedent to cite.
