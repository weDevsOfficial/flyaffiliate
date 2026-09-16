# ADR-0010 — One flat settings option, and a plugin-ui admin app

**Status:** Accepted
**Date:** 2026-09-08

## Context

The Phase 2 admin was five PHP-rendered `WP_List_Table` screens and a tabbed
settings page writing five option groups. Dokan is moving its admin to a React
application built on `@wedevs/plugin-ui` (weDevs' shared component library) and
to a flat-array settings schema stored in a single option
(getdokan/dokan#3141, getdokan/dokan-pro#5538). FlyAffiliate is new and has no
installed base, so it can adopt the destination architecture directly and skip
the bridge Dokan carries for its legacy options.

## Decision

### Settings

- **One option.** Every setting lives in `flyaffiliate_settings`, autoloaded,
  keyed by field id. There are no per-group options and no migration layer.
  Moving a field between pages is a schema edit, not a data migration.
- **The schema is a flat PHP array** (`Admin\Settings\Schema\SettingsSchema`)
  whose elements match plugin-ui's `SettingsElement` shape one to one: pages,
  subpages, sections and fields linked by parent pointers (`page_id`,
  `subpage_id`, `section_id`). `SettingsRegistry` generates a `hook_key` per
  element, fires it as a filter so extensions can inject children, fills
  defaults, populates values and — under `WP_DEBUG` — runs `SchemaValidator`.
- **Field ids are globally unique** and are the storage keys. The validator
  refuses duplicates.
- **Switches store `'on'` / `'off'`.** Read them through
  `flyaffiliate_option_enabled()`; a boolean cast would read `'off'` as true.
- **One save path.** `Admin\Settings\Manager::save()` validates (plugin-ui's
  `validations` rule format, so the same rule runs in the browser), sanitizes
  (per variant, or the field's `sanitize_callback`) and writes. The REST
  controller is its only caller; the setup wizard saves through REST.
- **REST:** `GET flyaffiliate/v1/settings` returns the schema with values,
  callables stripped; `PUT flyaffiliate/v1/settings/{scope}` saves the fields
  under one page or subpage, accepting either field ids or the dot-path keys
  plugin-ui emits. Validation failures return 400 with an `errors` map and
  store nothing.

### Admin app

- **One admin page.** `admin.php?page=flyaffiliate` mounts a React app;
  every submenu entry is a hash route (`#/commissions`, `#/settings`), written
  into the `$submenu` global the way Dokan does. Old per-screen slugs redirect.
- **plugin-ui throughout.** `<Settings>` renders the settings schema;
  `<DataViews>` renders every list (affiliates, commissions, visits, payouts)
  against the existing REST controllers, which already send `X-WP-Total`;
  dialogs, forms, badges and toasts come from the same package. No custom
  component library.
- **Scoped Tailwind, Dokan's way.** The stylesheet generates the preflight and
  every utility *inside* `.pui-root` and marks utilities important, so the app
  holds its own against wp-admin's global styles and against another plugin-ui
  build on the same page. plugin-ui's prebuilt `styles.css` is not imported:
  its layered utilities lose to unlayered admin CSS.
- **The brand is the logo teal.** `src/admin/theme.ts` sets plugin-ui's
  `primary` to `#0d7377`, the colour of `logo.svg`, the WordPress.org icon,
  the frontend stylesheet and the setup wizard. WordPress's own components
  inside the app (DataViews checkboxes) read `--wp-components-color-accent`,
  which `admin.css` sets to the same value on the app element; `--primary`
  only exists inside `.pui-root`, so a `var()` on `body` would not see it.
- **A header bar, and no foreign notices.** Every page renders under
  plugin-ui's `<TopBar>` (logo, version, Documentation, Get support), like
  Dokan's admin. `Admin\Menu::hide_foreign_notices()` removes other plugins'
  `admin_notices` on the app page and puts FlyAffiliate's own back, as the
  setup wizard already did; the menu icon is the logo's paper plane as an
  inline SVG so wp-admin recolours it per colour scheme.
- **The bundle is not externalised.** plugin-ui and its dependencies compile
  into `assets/js/admin.js`; only the `@wordpress/*` packages WordPress ships
  are externalised. The zip carries compiled JavaScript with `src/` excluded.
  WordPress.org's review asks that compiled code link to its human-readable
  source, so `readme.txt` must point at the public repository before
  submission; the URL is still to be decided.

## Consequences

- Reading a setting is `flyaffiliate_get_option( 'hold_days' )` — no group.
- Adding a field is one array in `SettingsSchema`; it appears in the UI, the
  REST API and the sanitizer at once. A field without a `default` is a
  validator warning.
- The `WP_List_Table` screens, their templates and `Abstracts\Screen` are
  gone. The setup wizard is the `#/setup` route of the app, saving through
  the settings endpoint and marking itself done through `POST /setup/complete`;
  only the user-profile section stays PHP-rendered.
- The admin bundle is large (plugin-ui ships as one file). Splitting it, or
  sharing one bundle with Dokan when both are active, is a follow-up.
- wp-admin's stylesheets, and most themes', are unlayered, so they beat the
  preflight inside the `base` layer: a `<p>` in a card picked up wp-admin's
  1em margins, an `<h3>` its 18px ones. `src/styles/base.css` therefore ends
  with an unlayered reset scoped to `.pui-root` (margins, list styles, link
  and button defaults). It is the one place such fixes go; a plugin-ui bump
  re-checks it.
- The affiliate dashboard is the same stack on the front end: `src/dashboard`
  mounts on the `[flyaffiliate_dashboard]` page only, reads the self-scoped
  `me` routes (`REST\Controllers\MeController`, on `AffiliateBaseController`),
  and enqueues `wp-components` there. Dokan's vendor dashboard uses DataViews
  on the front end the same way. The pages around it (logged out, not an
  affiliate, pending) stay PHP templates styled by `assets/src/css/frontend.css`.
- End-to-end coverage moves to Playwright (`tests/pw/`), mirroring Dokan's
  `tests/pw` layout; PHPUnit keeps the settings service and REST contract.
