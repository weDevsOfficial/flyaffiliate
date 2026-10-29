# ADR-0018 — Appsero Insights, opt-in, as Dokan Lite has it

**Status:** Accepted
**Date:** 2026-10-23
**Supersedes:** ADR-0003, divergence 3 ("No telemetry")
**Amends:** ADR-0002 and ADR-0003, divergence 4 (no bundled runtime package)

## Context

ADR-0003 kept FlyAffiliate free of telemetry, and ADR-0002 kept every
third-party package out of the zip. FlyAffiliate now has a product on Appsero
(hash `bc4b55f8-…`), as Dokan Lite has, and FlyAffiliate Pro has its own for
licensing and updates. The product decision (2026-10-23) is to connect
FlyAffiliate the way Dokan Lite is connected: Appsero Insights, opt-in, with
Share Essentials in the setup wizard.

WordPress.org allows usage tracking only with the site owner's consent
(Guideline 7) and wants every external service named in the readme.

## Decision

- **`FlyAffiliate\Tracker`** (named service `tracker`) is Dokan Lite's
  `Tracker`, under the same name: it builds the SDK's `Client` with
  FlyAffiliate's hash and calls `insights()->init_plugin()`, with
  `add_extra()` adding the number of
  affiliates and commissions, whether Pro is active, and the WooCommerce and
  FlyAffiliate versions. Nothing about an affiliate or a visitor. The
  referral tracker is `Tracking\Tracker`, another namespace.
- **Nothing is sent until an administrator allows it**: Share Essentials on
  the wizard's last step (off by default; `POST /setup/complete` takes
  `share_essentials` and calls `optin()` or `optout()`, as Dokan Lite's
  wizard does), or the SDK's own notice. That notice shows on every wp-admin
  screen but FlyAffiliate's, where ADR-0017 hides it with the rest. The SDK's
  deactivation survey is kept, with its default reasons.
- **The SDK is set up on `plugins_loaded`**, as a `Hookable`, not while the
  plugin file loads as Dokan Lite does. Its own activation hook is therefore
  never attached in the activation request, so `FlyAffiliate_Plugin::activate()`
  calls `Tracker::activate()`, which resumes the weekly send on a site that
  had allowed it.
- **One bundled package: `appsero/client`.** It is `require-dev` (Mozart's
  input), prefixed by Mozart into `FlyAffiliate\Dependencies\` under
  `vendor-prefixed/`, and committed, exactly as FlyAffiliate Pro bundles its
  copy (Pro's ADR-0003). The production `vendor/` stays Composer's loader and
  nothing else. `vendor-prefixed/` is never edited; updating the SDK is
  `composer update appsero/client` and committing the output.
- **Plugin Check skips `vendor-prefixed/`**, as it skips `vendor/`: it is
  where prefixed third-party code goes. PHPCS and makepot skip it too. The
  inline script and styles the SDK prints (its notice, the deactivation
  modal) are its own, not FlyAffiliate's, and FlyAffiliate's no-inline rule
  does not reach into it.
- **The readme says what is sent, when, and to whom**: Appsero, and
  icanhazip.com (Cloudflare), which the SDK asks for the server's public IP
  address before each send.
- **Uninstall** deletes the SDK's options (`flyaffiliate_allow_tracking`,
  `_tracking_notice`, `_tracking_last_send`, `_tracking_skipped`) with the
  rest, when the owner asked for a wipe. The SDK clears its weekly event on
  deactivation itself.

## Consequences

- "FlyAffiliate makes no external requests" is no longer true, and the readme
  no longer says it: it sends nothing until allowed, and then sends weekly.
  Choosing No thanks, or leaving Share Essentials off when finishing the
  wizard, sends Appsero a "declined" ping with the hash and nothing else;
  Submit & Deactivate on the survey sends the answer with the usual data,
  allowed or not, as in Dokan Lite.
- The SDK's option and hook names follow the plugin's directory
  (`flyaffiliate_…`); a copy installed under another directory name keeps its
  choice under that name.
- The SDK's strings are not in `flyaffiliate.pot`: it translates through
  `call_user_func( '__', … )`, which makepot does not read.
- A second copy of the SDK on the site (Dokan Lite ships an unprefixed one)
  does not collide with FlyAffiliate's.
