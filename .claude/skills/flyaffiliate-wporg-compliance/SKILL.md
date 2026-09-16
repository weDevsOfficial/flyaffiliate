---
name: flyaffiliate-wporg-compliance
description: The WordPress.org submission rules and the Plugin Check gate for FlyAffiliate. Invoke before every commit that touches PHP, assets, readme.txt, or the plugin header, and before any release.
---

# FlyAffiliate WordPress.org Compliance

This plugin ships on WordPress.org. **The gate is `npm run plugin-check` on the
built zip: 0 errors, 0 warnings.** That constraint outranks any Dokan convention
it conflicts with (ADR-0003).

## Run the gate

```bash
npm run plugin-check
```

It stages `build/flyaffiliate` from `.distignore`, drops it into the wp-env tests
environment under the real slug, and runs Plugin Check against it. Anything it
reports is a finding to fix — not a finding to suppress.

Reference: [`references/plugin-check-checks.md`](./references/plugin-check-checks.md).

## The rules that actually bite here

### Escaping and sanitization

- Every value that reaches output is escaped **at the point of output**:
  `esc_html()`, `esc_attr()`, `esc_url()`, `wp_kses_post()`. Escaping at
  assignment does not satisfy the check and does not survive refactoring.
- Every value that arrives from a request is sanitized before use, and the
  sanitizer matches the type: `absint()`, `sanitize_text_field()`,
  `sanitize_key()`, `sanitize_email()`, `wc_format_decimal()`.
- `$_GET`, `$_POST`, `$_REQUEST`, `$_SERVER` are all untrusted. Reading
  `$_SERVER['REQUEST_URI']` for display still needs `esc_url_raw()` +
  `wp_unslash()`.
- Translated strings are escaped too: `esc_html__()`, `esc_attr_e()`, and
  `printf( esc_html__( '…%s…', 'flyaffiliate' ), esc_html( $value ) )`.

### Nonces and capabilities

- Every state-changing request checks a capability **and** a nonce, in that
  order, before it does anything: `current_user_can( 'manage_woocommerce' )`,
  then `check_admin_referer()` / `wp_verify_nonce()`.
- `manage_options` is not used. Admin screens are `manage_woocommerce`.
- Every REST route has a real `permission_callback`. `__return_true` is a
  finding, not a shortcut.

### SQL

- Every query is prepared. Table names cannot be placeholders — interpolate the
  `$wpdb->flyaffiliate_*` shortcut and pass every value through `%d`/`%s`/`%f`.
- `IN (...)` lists are built with a generated placeholder string, never by
  concatenating values.
- `$wpdb->prepare()` with a variable as the whole query is a finding.

### Files, headers, and metadata

- **No remote assets.** No CDN `<script>`, no Google Fonts, no external image.
  Everything is enqueued from `assets/`. (The prototype's Headway widget is the
  example of what is not allowed.)
- No inline `<style>` or `<script>` blocks. Use `wp_enqueue_style()`,
  `wp_enqueue_script()`, `wp_add_inline_script()`.
- No `load_plugin_textdomain()` — WordPress.org loads translations. The text
  domain is `flyaffiliate` and must equal the slug in every `__()` call.
- Plugin header carries: `Plugin Name`, `Plugin URI`, `Description`, `Version`,
  `Requires at least`, `Requires PHP`, `Requires Plugins: woocommerce`,
  `Author`, `Author URI`, `License: GPLv2 or later`, `License URI`,
  `Text Domain: flyaffiliate`, `Domain Path: /languages`.
- `readme.txt` `Stable tag` **equals** the header `Version` equals
  `FlyAffiliate_Plugin::$version` equals `package.json` version.
- No `.git`, `.DS_Store`, `node_modules`, `vendor`, `tests`, zip files, or
  minified-without-source files in the archive. `.distignore` is what enforces
  this; `bin/build-zip.php` is what applies it.
- No hardcoded `localhost`, `127.0.0.1`, or developer paths.
- No obfuscated or minified-only PHP.

### Behaviour

- Nothing writes to the database on `admin_init` or on a plain page load except
  in response to a user action. **No seeders.** Sample data is a WP-CLI command
  (`wp flyaffiliate seed`) registered in `CliServiceProvider`.
- No forced admin redirect on activation without a user-visible way out. The
  setup wizard redirect fires once, records that it fired, and the wizard has a
  visible skip.
- No admin notice that cannot be dismissed, and none outside the plugin's own
  screens.
- No telemetry, no phone-home, no external API call the user did not ask for.
- Uninstall removes data only when the "clear data on uninstall" setting says so,
  and `uninstall.php` starts with the `WP_UNINSTALL_PLUGIN` guard.

## Trademark and naming

The slug is `flyaffiliate`. Plugin name, slug, and text domain do not use
"WordPress", "WooCommerce", or "Dokan" as a prefix. "for WooCommerce" as a
**suffix** in the display name is acceptable; `woocommerce-flyaffiliate` as a
slug is not.

## Accepting a finding

Almost never. To accept one, all three must hold:

1. The code is correct and the check has no safe alternative in this context.
2. The suppression is a single-line `phpcs:ignore` with the reason in the
   comment — never a file-level or ruleset-level exclusion.
3. A wp.org reviewer would accept the justification as written.

Then record it in `docs/wporg-accepted-warnings.md` with the check, the location,
and the reason. That file is the complete list; if a finding is not in it, it is
a bug to fix.

The only anticipated entry is `fopen( 'php://output' )` in the payout CSV
exporter.

## Pre-submission checklist

Run before the first submission and before every release:

- [ ] `npm run release` builds the zip; unzip it and read the file list — nothing
      but shipping code, one top-level `flyaffiliate/` directory.
- [ ] `npm run plugin-check` — 0 errors, 0 warnings. Paste the table into the PR.
- [ ] `composer phpcs` clean repo-wide.
- [ ] `npm run phpunit` green.
- [ ] Header `Version` = readme `Stable tag` = `FlyAffiliate_Plugin::$version` =
      `package.json`.
- [ ] `Tested up to` is the current WordPress version.
- [ ] `readme.txt`: short description ≤ 150 characters, ≤ 5 tags, a real
      changelog, a real FAQ, screenshots numbered to match `.wordpress-org/`.
- [ ] `.wordpress-org/` holds real artwork, not the generated placeholders.
- [ ] `languages/flyaffiliate.pot` regenerated (`npm run makepot`).
- [ ] Activate on a clean WP + WooCommerce site: no notices, no errors, tables
      created, pages created.
- [ ] Deactivate, reactivate, uninstall with the clear-data setting **on** and
      **off** — the site is clean either way.
- [ ] `@since FLYAFFILIATE_SINCE` replaced by the release tooling, and no
      `FLYAFFILIATE_SINCE` string survives in the zip.
- [ ] GPLv2-or-later declared, and every bundled asset is GPL-compatible.
