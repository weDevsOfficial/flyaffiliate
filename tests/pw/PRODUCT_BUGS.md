# Product bugs found by the e2e suite

Bugs in FlyAffiliate itself (not in the tests), found while writing the
Playwright suite. Each one has a test that pins it: a `test.fail()` test is
a confirmed bug, and it starts to "unexpectedly pass" once the bug is fixed —
remove the `test.fail()` then and move the entry to *Fixed*.

Severity: **High** = money or security, **Medium** = broken feature or
layout, **Low** = quirk that harms no one.

## Index

| Id | Severity | Summary |
|---|---|---|
| BUG-004 | High | Referral variable accepts WordPress query vars (`p`): post links credit affiliates — **partly fixed in PR #22**: new saves refused; a value already stored still tracks (see BUG-004) |
| BUG-011 | High | Refused payout preview leaves the old one payable (pays everyone) — **fixed in PR #22** |
| BUG-002 | Medium | `?affiliate[]=…` credits affiliate #1; `-5`/`5abc` credit #5 — **fixed in PR #22** |
| BUG-005 | Medium | Invalid switch value saved as `off` with 200 — **fixed in PR #22** |
| BUG-009 | Medium | `/me` blanks the payment email on invalid input — **fixed in PR #22** |
| BUG-001 | Medium | Affiliate dashboard scrolls sideways on phones — **fixed in PR #22** |
| BUG-006 | Low | Removing the last commission leaves a 0.00 payment — **fixed in PR #22** |
| BUG-010 | Low | Unknown payment id shows an empty payment — **fixed in PR #22** |
| BUG-003 | Low | Date-range picker nests a button in a button — **fixed**, SEC-11 now passes |
| BUG-007 | Low | Paying an unknown batch answers 200 — **fixed in PR #22** |
| BUG-008 | Low | Impossible calendar date passes the period check — **fixed in PR #22** |
| BUG-012 | Low | Seeder gives manual commissions random (real) order ids — dev tool |

## Status after PR #22 (fix/e2e-product-bugs, merged locally 10 Nov 2026)

Re-tested with the suite: BUG-001, 002, 005, 006, 007, 008, 009, 010 and 011 are fixed
(their tests now run as normal tests). **BUG-004 is only partly fixed** — see its residual
below. BUG-012 (seeder) and PRO-BUG-001 (Pro repo) are not in the PR.

## Open

### BUG-001 — Affiliate dashboard scrolls sideways on phones (Medium)

- **Where:** `[flyaffiliate_dashboard]`, tab row (Overview, Referral links,
  Commissions, Visits, Payouts, Settings) — `src/dashboard/App.tsx`.
- **Steps:** log in as an active affiliate, open the dashboard at a 375px
  wide viewport (Twenty Twenty-Five theme, wp-env).
- **Expected:** the dashboard fits the screen (scenario DSH-15); the tabs
  wrap, or scroll inside their own row.
- **Actual:** the tab list is 662px wide and pushes the whole page to scroll
  horizontally by 287px; "Commissions" and the tabs after it are off-screen.
- **Test:** `specs/affiliate-dashboard.spec.ts` › `[DSH-15]` (`test.fail()`).

### BUG-003 — The date-range picker nests a button inside a button (Low) — FIXED

> Fixed in source: the dashboard's picker trigger no longer renders its own
> `<Button>`. SEC-11 runs as a normal test now (no `test.fail()`).

- **Where:** the date-range trigger on the admin Dashboard
  (`src/admin/pages/dashboard/index.tsx`) and on the affiliate dashboard
  (`src/dashboard/App.tsx:214`). The `trigger` render prop of plugin-ui's
  `DateRangePicker` returns a `<Button>`, and plugin-ui already wraps the
  trigger in its own `<button>`.
- **Steps:** open `admin.php?page=flyaffiliate#/`, or any affiliate dashboard
  tab except Settings, with the browser console open (`SCRIPT_DEBUG` on).
- **Expected:** valid markup, no console errors (scenario SEC-11).
- **Actual:** `Warning: validateDOMNesting(...): <button> cannot appear as a
  descendant of <button>`; `document.querySelectorAll('button button')`
  finds the inner "Pick a date range" button inside the outer one. Invalid
  HTML: screen readers and keyboard focus can treat it as two controls or
  drop one, and some browsers re-parent the inner button.
- **Fix hint:** render the trigger content without its own `<Button>`, or use
  the picker's `asChild`-style option if it has one.
- **Test:** `specs/security.spec.ts` › `[SEC-11] no button is nested inside
  another` (`test.fail()`). The console-error tests ignore this one warning so
  they keep guarding everything else.

### BUG-002 — A malformed referral value credits an affiliate, `[]` credits affiliate #1 (Medium)

- **Where:** `includes/Tracking/Tracker.php`, `track()` — `absint()` on the
  raw query value.
- **Steps:** as a guest, visit `/?affiliate[]=999` (any id), or
  `/?affiliate=-5` / `/?affiliate=5abc`.
- **Expected:** a malformed value is ignored (ADR-0005: "an unknown … affiliate
  is ignored") — no visit, no cookie.
- **Actual:** `absint()` of an array is 1, so `?affiliate[]=…` records a visit
  and sets the attribution cookie for **affiliate #1**, who then earns the
  commission on whatever that visitor buys. `-5` and `5abc` credit affiliate 5.
  No injection is possible; the id is still checked against an active
  affiliate.
- **Fix hint:** read the value only when it is a string and `ctype_digit()`.
- **Test:** `specs/negative-affiliate.spec.ts` › `an array in the referral
  variable credits nobody` (`test.fail()`). `[TRK-04]` in `tracking.spec.ts`
  covers values with no leading number.

### BUG-004 — WordPress's own query variables are accepted as the referral variable (High)

> **PR #22: partly fixed.** `SettingsSchema::validate_referral_variable()` now refuses a
> reserved name when it is saved (REST and the Settings form answer 400 with a message).
> **Residual:** a store that saved `p` (or another reserved name) on 1.0.0 / 1.0.1 keeps it
> after updating — `Tracking\Tracker` reads the stored option as is (`Tracker.php:56`) and no
> upgrade routine resets it — so every `/?p=<post>` link still credits an affiliate there.
> Fix: have the tracker ignore a stored reserved name (fall back to `affiliate`), or reset it
> in an `Upgrade\Upgrades` step. Test: `negative-affiliate.spec.ts` › "with the referral
> variable set to "p"…" (still `test.fail()`, it writes `p` straight into the option).

- **Where:** `SettingsSchema::sanitize_referral_variable()` — only
  `sanitize_key()`, no list of reserved names.
- **Steps:** Settings → General → Tracking → Referral variable = `p` (also
  `s`, `page_id`, `name`, `cat`, `author`, `year` …), save. As a guest, open
  any ordinary post link, e.g. `/?p=1`.
- **Expected:** the save is refused: the variable must not collide with a
  WordPress query variable.
- **Actual:** saved with 200. Every plain-permalink post link `/?p=<id>` then
  sets the attribution cookie for the affiliate whose id equals the post id,
  so that affiliate is credited for organic traffic and its orders.
- **Fix hint:** refuse the names in `$wp->public_query_vars` (plus
  `$wp->private_query_vars`) in the sanitizer/validator, with a message.
- **Tests:** `specs/negative-rest.spec.ts` › `a WordPress query variable cannot
  become the referral variable`; `specs/negative-affiliate.spec.ts` › `with the
  referral variable set to "p", an ordinary post link credits no one` (both
  `test.fail()`).

### BUG-011 — A refused payout preview leaves the previous one payable (High)

- **Where:** `src/admin/pages/payouts/new.tsx` — the "Choose at least one
  affiliate." early return in the preview handler does not clear the earlier
  preview.
- **Steps:** Payouts → New payout, Minimum 0, **Preview** (everyone owed is
  listed, "Pay $X"). Switch to **Only the affiliates I choose**, choose
  nobody, **Preview** again.
- **Expected:** the preview and its Pay button go away; nothing can be paid
  until a valid preview is shown.
- **Actual:** the toast says "Choose at least one affiliate.", but the old
  "everyone" preview stays with its live **Pay** button. Clicking it posts
  `mode: selected` with **every affiliate of the old preview** — on the test
  site 14 affiliates, $160.50 — while the form says nobody is chosen.
- **Test:** `specs/negative-admin-ui.spec.ts` › `a refused preview leaves no
  older preview to pay` (`test.fail()`); the screen now previews everyone on load, so the first visit is enough. Changing a filter without previewing
  again was checked and is safe.

### BUG-009 — `/me` blanks the payment email on invalid input (Medium)

- **Where:** `REST\Controllers\MeController` `payment_email` argument: it has
  `sanitize_callback => sanitize_email` but no `format => email` (the admin
  `AffiliatesController` has both).
- **Steps:** as an active affiliate, `PUT /flyaffiliate/v1/me`
  `{ "payment_email": "not-an-email" }` (the dashboard Settings tab sends the
  same).
- **Expected:** 400, the stored address unchanged.
- **Actual:** 200, and the stored payment email becomes `''` — a typo erases
  where manual payouts are sent, without a word.
- **Test:** `specs/negative-affiliate.spec.ts` › `an invalid payment email is
  refused, not silently blanked` (`test.fail()`).

### BUG-005 — An invalid switch value is saved as "off" with a 200 (Medium)

- **Where:** `Admin\Settings\Manager::sanitize_field()`, `switch` case: any
  value that is not `on`/`off` becomes `off`.
- **Steps:** `PUT /flyaffiliate/v1/settings/rules`
  `{ "values": { "block_self_referral": "maybe" } }`.
- **Expected:** 400 with an `errors` map, nothing stored (ADR-0010: "Validation
  failures return 400 … and store nothing").
- **Actual:** 200, and `block_self_referral` is now `off` — self-referrals are
  paid. Select fields with an unknown value also answer 200 but keep the
  stored value (safe, but the API reports a success that did not happen).
- **Test:** `specs/negative-rest.spec.ts` › `an invalid switch value is
  refused, not read as "off"` (`test.fail()`).

### BUG-006 — Removing the last commission leaves an empty payment (Low)

- **Where:** `Payout\Manager::remove_commission()`.
- **Steps:** create a payout for one affiliate with one commission, then
  `DELETE /payouts/{id}/commissions/{commission}` (or "Remove from payment").
- **Expected:** the payment is deleted, or the removal refused — an unpaid
  payment of 0.00 is meaningless.
- **Actual:** the payment stays, `unpaid`, amount `0.00`, can be marked paid
  and appears in the CSV.
- **Test:** `specs/negative-rest.spec.ts` › `taking the last commission out does
  not leave an empty payment` (`test.fail()`).

### BUG-010 — An unknown payment id shows an empty payment instead of "not found" (Low)

- **Where:** `src/admin/pages/payouts/payment.tsx` — a failed load sets the
  payment to `null` and renders the empty state.
- **Steps:** open `admin.php?page=flyaffiliate#/payouts/payment/99999999`.
- **Expected:** a not-found state, as for an unknown affiliate, commission or
  payout.
- **Actual:** "Payment #99999999 — No commissions in this payment. Every
  commission was removed." with a Delete button.
- **Test:** `specs/negative-admin-ui.spec.ts` › `an unknown payment says so
  instead of showing an empty one` (`test.fail()`).

### BUG-007 — Paying an unknown batch answers 200 (Low)

- **Where:** `PayoutsController::pay_batch()`.
- **Steps:** `POST /flyaffiliate/v1/payouts/batch/nope/pay`.
- **Expected:** 404, like GET, DELETE and refresh of the same unknown batch.
- **Actual:** 200 `{ "paid": 0 }`.
- **Test:** `specs/negative-rest.spec.ts` › `paying an unknown batch is 404`
  (`test.fail()`).

### BUG-008 — An impossible calendar date passes the payout period check (Low)

- **Where:** `PayoutsController::get_batch_args()` — `period_start` /
  `period_end` are checked by pattern `^\d{4}-\d{2}-\d{2}$` only.
- **Steps:** `POST /payouts/preview { "period_start": "2025-13-45" }`.
- **Expected:** 400.
- **Actual:** 200; the preview runs with the string compared as a date.
- **Test:** `specs/negative-rest.spec.ts` › `preview: an impossible calendar
  date is refused` (`test.fail()`).

## Not bugs (checked, by design)

- A commission can be added by hand for a suspended affiliate, with a future
  date (held until it matures) or a very large amount — SliceWP allows the
  same; the admin decides.
- Deleting an affiliate keeps their payments and commissions (financial
  records, ADR-0015); an unpaid payment for them can still be paid.
- Changing a payout filter without previewing again sends the new filter, not
  the old preview (the `expected` guard holds).

## Fixed

_None yet._

### BUG-012 — The seeder gives manual commissions random order ids (Low, dev tool)

- **Where:** `includes/CLI/Seeder.php:133` — `'order_id' => wp_rand( 1000, 9999 )`
  on commissions whose source is `manual`.
- **Steps:** `wp flyaffiliate seed` on a development site that has (or later
  gets) WooCommerce orders numbered 1000–9999.
- **Expected:** sample commissions point at no order (0), or at orders the
  seeder created.
- **Actual:** they claim real orders' ids, so an order's commissions include
  sample rows, and order links in the Commissions list open unrelated orders.
  Development-only (`WP_ENVIRONMENT_TYPE=development`), so no store is hit.
- **Found by:** intermittent e2e failures — specs that read "the commissions of
  this order" picked up seeded rows. `utils/fixtures.ts` `orderCommissions()`
  now leaves manual rows out.

## FlyAffiliate Pro

### PRO-BUG-001 — Translations load too early when Dokan is not active (Low)

- **Where:** `flyaffiliate-pro/includes/Extensions/Dokan/Extension.php:166`
  (`get_unmet_requirements()`), called from `Extensions\Manager::load_active_extensions()`
  (`Manager.php:308`) inside `init_plugin()` (`flyaffiliate-pro-class.php:219`),
  which runs on `plugins_loaded`.
- **Steps:** FlyAffiliate + FlyAffiliate Pro + WooCommerce active, **Dokan not
  active**, WordPress 6.7+ with `WP_DEBUG` on. Load any page or run any WP-CLI
  command.
- **Expected:** no notice; requirement messages are translated when shown.
- **Actual:** `PHP Notice: Function _load_textdomain_just_in_time was called
  incorrectly. Translation loading for the flyaffiliate-pro domain was
  triggered too early` on every request — the "Dokan plugin is required…"
  string is built with `__()` before `init`. With Dokan active the check passes
  without translating, so the paired Pro + Dokan env never shows it.
- **Fix hint:** return codes from `get_unmet_requirements()` at load time and
  translate them where they are displayed, or defer the check to `init`.

### PRO-BUG-002 — A Dokan vendor can read any affiliate's name and email by id (High, privacy)

> **Fixed** by weDevsOfficial/flyaffiliate-pro#26 (`4ff4925`): `find_affiliate()` now
> requires a commission on the vendor's own products for every affiliate. Verified
> 12 Nov 2026: the affiliate and referrals routes answer 404 for another store's affiliate,
> the vendor's own affiliates still open; `dokan.spec.ts` 32 / 32 with `[API-DOK-06]` as a
> normal test; `pro-core` + `pro-paypal` 38 passed.

- **Where:** FlyAffiliate Pro, `Extensions/Dokan/REST/VendorProgramController::get_item()` —
  `GET /flyaffiliate-pro/v1/dokan/vendor/affiliates/{id}`.
- **Steps:** two opted-in vendors A and B; an affiliate who referred a sale for B only. Log
  in as vendor A and request that affiliate's id.
- **Expected:** 404 "This affiliate has not referred any of your sales." — the answer an
  unknown id already gets.
- **Actual:** 200 with the affiliate's **name, email** and avatar (the money figures are
  scoped and show $0). Any vendor can walk ids and collect every affiliate's email. The
  list route and the referrals route are scoped correctly.
- **Fix hint:** return the not-found error when the affiliate has no commission on the
  signed-in vendor's orders, before building the response.
- **Cause:** `find_affiliate()` applies the referral check to inactive affiliates only
  ("an active affiliate could refer the vendor's sales any time"), so every active
  affiliate is returned to every vendor.
- **Issue:** https://github.com/weDevsOfficial/flyaffiliate-pro/issues/25
- **Test:** `specs/dokan.spec.ts` › `[API-DOK-06]` (`test.fail()`).
