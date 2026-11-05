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
| BUG-004 | High | Referral variable accepts WordPress query vars (`p`): post links credit affiliates |
| BUG-011 | High | Refused payout preview leaves the old one payable (pays everyone) |
| BUG-002 | Medium | `?affiliate[]=…` credits affiliate #1; `-5`/`5abc` credit #5 |
| BUG-005 | Medium | Invalid switch value saved as `off` with 200 |
| BUG-009 | Medium | `/me` blanks the payment email on invalid input |
| BUG-001 | Medium | Affiliate dashboard scrolls sideways on phones |
| BUG-006 | Low | Removing the last commission leaves a 0.00 payment |
| BUG-010 | Low | Unknown payment id shows an empty payment |
| BUG-003 | Low | Date-range picker nests a button in a button |
| BUG-007 | Low | Paying an unknown batch answers 200 |
| BUG-008 | Low | Impossible calendar date passes the period check |

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

### BUG-003 — The date-range picker nests a button inside a button (Low)

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
  older preview to pay` (`test.fail()`). Changing a filter without previewing
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
