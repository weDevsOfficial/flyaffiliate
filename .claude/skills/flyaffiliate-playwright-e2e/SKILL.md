---
name: flyaffiliate-playwright-e2e
description: Write, extend and run FlyAffiliate's Playwright end-to-end suite (tests/pw) — scenario coverage derived from the ADRs and CONTEXT.md money rules, page objects, data setup through wp-env CLI and REST, role sessions, and the release gate. Use when adding or fixing an e2e spec, planning test coverage for a feature or ADR, or checking a build is ready to release.
---

# FlyAffiliate Playwright E2E

The e2e suite proves the plugin end to end in a real browser against wp-env
(WordPress + WooCommerce). PHPUnit owns the unit/REST contract; Playwright owns
**user-visible flows and the money loop across requests** (click → cookie →
checkout → order status → commission → payout → affiliate dashboard).

Read first, every time:
- `CONTEXT.md` — terms and the 9 money rules. Test names use its words
  (Commission not Referral, Affiliate not Partner, Payment vs Payout).
- `docs/adr/` — each ADR is a behaviour contract. The scenario catalogue in
  [`references/scenarios.md`](references/scenarios.md) maps every ADR to tests.
  When code and an ADR disagree, check the ADR's *Status*/notes (0011 was
  reverted, 0014's decision 3 reversed on 2026-10-20) before writing an
  assertion.

## Layout (mirrors Dokan's tests/pw)

```
tests/pw/
├── playwright.config.ts   # workers:1, baseURL localhost:8888, admin storageState
├── global-setup.ts        # wp flyaffiliate seed + admin login → .auth/admin.json
├── pages/                 # AdminPage (shell, toast, confirm), ListPage (DataViews), SettingsPage
├── utils/
│   ├── site.ts            # urls, ADMIN, logIn() (waits for the auth cookie, retries)
│   ├── dataViews.ts       # waitForListReady, statusTab, row actions
│   ├── wp.ts              # wp([...]) and php(`…return …;`) — WP-CLI in the wp-env cli container
│   ├── api.ts             # Api.as(state|null): REST with cookie + nonce; cents()
│   └── fixtures.ts        # factories: settings, affiliates, users, products, orders, ledger reads
├── specs/                 # one spec per screen / flow / ADR area
└── PRODUCT_BUGS.md        # bugs in the plugin the suite found (one entry per test.fail())
```

Spec map: `attribution` (WC-*), `order-sync` (SYN-*), `payout-rules`
(PAY-*, COM-11..14), `tracking` (TRK-*), `registration` (REG-*),
`affiliate-dashboard` (DSH-*, SEC-04/05), `security` (SEC-*), `standalone`
(STD-*, WooCommerce deactivated for the file), `admin-shell` (SHL-05/07,
SEC-03, SET-*), `admin-records` (AFF-*, COM-*), `money-journey` (the whole
loop through a real block checkout), `dokan` (DOK-*, Pro + Dokan env only);
plus the screen specs `affiliates`,
`commissions`, `dashboard`, `navigation`, `payouts`, `settings`, `setup`,
`visits`.

Run: `npm run env:start` then `npm run test:e2e` (or `-- specs/payouts.spec.ts`,
`-- -g "title"`, `test:e2e:ui`). `FLYAFFILIATE_SKIP_SEED=1` skips the seeder on
reruns. `HEADLESS=false` to watch. Report: `tests/pw/report`.

## Rules

1. **Reuse before adding.** `AdminPage.goto/expectToast/confirmDialog`,
   `ListPage.open/selectTab/tabCount/search/rows`, `SettingsPage.navigate/fill/setSwitch/save`,
   `utils/dataViews` row helpers. Add a method to a page object only when two
   specs need it. New screen with 3+ interactions → new page object in `pages/`.
2. **Selectors, in order:** `data-testid` (`flyaffiliate-*`, `settings-field-<id>`,
   `settings-menu-<id>`) → role + accessible name → stable `#flyaffiliate-*` ids.
   Never CSS classes from plugin-ui/Tailwind, never nth-child. Missing testid on
   something a test needs → add it in `src/` in the same change.
3. **Own your data.** A spec that asserts exact numbers creates its own
   affiliate/order/commission (see Data setup) with a unique stamp
   (`pw-${Date.now()}`) instead of trusting seed rows. Seed rows are fine for
   "list renders" checks; guard with `test.skip( count === 0, 'needs …' )`.
4. **Assert deltas or exact owned values, never absolute global counts.**
   `before = tabCount(...)` → act → `expect.poll(() => tabCount(...)).toBe(before + 1)`.
5. **Money in integer cents.** `const cents = (s: string) => Math.round(parseFloat(s.replace(/[^0-9.-]/g, '')) * 100)`.
   Never compare floats or formatted strings for amounts. Read amounts from
   REST (`amount` field) when the UI formats with currency settings.
6. **Idempotency on every money path** (rule 7): repeat the trigger (status
   change twice, mark paid twice, double-submit, reload mid-flow) and assert the
   ledger is unchanged.
7. **Wait on state, not time.** `waitForListReady`, `expect.poll`, `toBeVisible`,
   `waitForResponse(/flyaffiliate\/v1\/.../)`. No `waitForTimeout`.
8. **Restore global state.** Settings a describe needs go through
   `withSettings()`; a one-test change uses `setSettings()`/`restoreSettings()`
   in `try/finally`. Anything else global (WooCommerce options, mu-plugins,
   deactivated plugins) is put back in `afterAll`. Settings live in one option
   `flyaffiliate_settings`; switches are `'on'`/`'off'` strings.
9. **Hash routes need a full load**: use `AdminPage.goto()` (it hops through
   `about:blank`). After that `goBack()` lands on about:blank — re-`goto` instead.
10. **One behaviour per test**, title states the rule ("a failed order rejects
    its pending commissions"). Group by ADR in `test.describe`, cite the ADR /
    money rule in the file header comment.
11. **Every scenario gets its negative.** For each happy path write the
    refusals too: wrong input (each invalid field on its own), wrong actor
    (guest, affiliate, pending/inactive/suspended, admin-not-affiliate), wrong
    state (paid, locked, deleted, unknown id), and boundaries (0, negative,
    sub-cent, exactly the limit, one past it). Assert the refusal *and* that
    nothing changed (`countRows()`, `storedSettings()`, the row re-read).
    Probe real behaviour first (a throwaway script against the env), then
    pin it; never guess a status code. Negative specs: `negative-rest`,
    `negative-money`, `negative-affiliate`, `negative-admin-ui`.
12. **Tag** release-critical tests `@smoke` (fast happy paths) and money tests
    `@money` in the title so `-g @money` runs the gate subset.

## Data setup

Everything below already exists — import it, never re-implement it.

```ts
import { php, wp, lit } from '../utils/wp';
import { Api, BASE_URL, cents } from '../utils/api';
import {
	withSettings,                            // describe-level: set, then back to BASELINE
	setSettings, restoreSettings,            // inside one test, in try/finally
	createAffiliate, createUser, setAffiliateStatus, loginAs,
	createProduct, createCoupon, createOrder, setOrderStatus, refireStatus,
	reattribute, trashOrder, orderMeta,
	orderCommissions, affiliateVisits, signedCookie, pageUrls, stamp,
} from '../utils/fixtures';

withSettings( { hold_days: 0, default_rate: 10 } );  // registers beforeAll + afterAll

const aff = createAffiliate();                       // active; .id .userId .login .email .password
const order = createOrder( { affiliateId: aff.id, items: [ { productId: createProduct( 100, 15 ) } ] } );
setOrderStatus( order, 'completed' );
expect( cents( orderCommissions( order )[ 0 ].amount ) ).toBe( 1500 );

const admin = await Api.as();                         // admin; Api.as( null ) = guest
const { context, state } = await loginAs( browser, aff );
const me = await Api.as( state );                    // the affiliate's own `me` routes
```

| Need | How |
|---|---|
| Any PHP state | `php()` with a PHP body ending in `return $value;` → JSON-decoded value. Interpolate JS values with `lit()` (safe PHP literal). Runs via `docker exec` on this checkout's cli container (`wp-env install-path` hash), falling back to `wp-env run`. ~1.3 s per call: batch work into one call. |
| Referred order, fast | `createOrder()` fires `woocommerce_checkout_order_created` with a signed `flyaffiliate_ref` in `$_COOKIE` — the exact input `OrderAttribution` reads. `cookie:` passes a raw value (forged, or one read from a browser). |
| Referred order, real | Browser only (see `money-journey.spec.ts`): `/?affiliate=<id>` → `/?add-to-cart=<pid>` → `wc_get_checkout_url()` → block checkout fields `#email`, `#billing-*` → "Place Order" → `order-received`. Needs `woocommerce_coming_soon = no` (wp-env starts in Coming soon mode, guests see a placeholder) and COD enabled; restore both. Use a virtual product to skip shipping. |
| Payable commission | `setSettings( { hold_days: 0 } )` before creating it, or a manual one with an old `created_at` (`POST /commissions { created_at: '2020-01-01 00:00:00' }`). `matures_at` is fixed at creation (ADR-0014 note). |
| Isolated payout | `POST /payouts { mode: 'selected', affiliate_ids: [ aff.id ], minimum_amount: 0 }` — never `mode: all` from a spec, it sweeps every seeded commission. If a screen forces "all", delete the other payments afterwards (money-journey does). |
| Another plugin | Write a throwaway mu-plugin with `php()` in `beforeAll`, delete it in `afterAll`, switch its behaviour with an option (admin-shell.spec.ts). |
| Guest | `browser.newContext( { baseURL: BASE_URL, storageState: { cookies: [], origins: [] } } )`. |

Gotchas found the hard way:
- **One suite per database.** Two `playwright test` runs against the same
  wp-env break each other (one deactivates WooCommerce in `standalone`, another
  flips `referral_variable` mid-test). For parallel runs give each its own env
  (`flyaffiliate-wp-env-worktrees`). Check `ps aux | grep "playwright test"`
  before blaming a failure on the product.
- A failed test restarts the worker and Playwright **re-runs `beforeAll`**. A
  snapshot taken there captures values the first run already changed, and
  "restores" them for good. Describe-level settings therefore go through
  `withSettings()`, which resets to `BASELINE` (the installer defaults, also
  written by global setup at the start of every run); snapshots are only for a
  change inside one test.
- A Playwright `APIRequestContext` kept across tests breaks on the next
  test's trace (ENOENT in `.playwright-artifacts`); `Api` therefore uses Node
  `fetch` with the stored cookies.
- Never assert a site-wide count (Unpaid tab, list total) after an action:
  other specs leave rows. Count what your action touched and assert the delta.
- WordPress validates REST args **before** `permission_callback`: a guest
  POST with a missing required arg gets 400, not 401. Send a well-formed body
  when testing permissions.
- `wp-admin/` can hold its `load` event for 30 s+ on remote widgets: never
  `waitForURL` the dashboard after login — `logIn()` waits for the auth cookie.
- WooCommerce redirects customers/subscribers from wp-admin to My account.
- Notes and other text fields are stored through `sanitize_*`: an XSS test
  asserts nothing executes (no dialog, no `window.__pwXss`), not that the raw
  tags are shown.

## Dokan (FlyAffiliate Pro's Dokan extension)

Dokan support is **not** in this repo's `develop`: it ships in FlyAffiliate Pro
(`weDevsOfficial/flyaffiliate-pro`, `includes/Extensions/Dokan/`, Pro ADR-0006
and ADR-0008), plugging into free seams (`flyaffiliate_order_item_vendor_id`,
`flyaffiliate_commission_rate`, `flyaffiliate_max_rate`,
`flyaffiliate_commission_hold_days`, …). `feature/dokan-integration` here is an
abandoned older copy (pre-ADR-0014) — do not test it as the product.

`specs/dokan.spec.ts` + `utils/dokan.ts` run against Pro's **paired** env and
skip themselves anywhere else.

```bash
# once: Pro beside a free worktree (Pro's flyaffiliate-pro-wp-env-worktrees skill;
# its script expects both checkouts under one plugins/ folder — or do it by hand:)
D=~/flyaffiliate-wt/dokan-e2e
git -C <free-main> worktree add --detach $D/flyaffiliate origin/develop
git -C <pro-clone> worktree add --detach $D/flyaffiliate-pro develop
FLYAFFILIATE_WT_DOKAN=<dokan-lite ≥5.3.2> FLYAFFILIATE_WT_DOKAN_PRO=<dokan-pro, optional> \
  bash <pro-clone>/.claude/skills/flyaffiliate-pro-wp-env-worktrees/write-override.sh $D/flyaffiliate-pro 8900
# build both (composer install, node_modules, npm run build), then in $D/flyaffiliate-pro: npx wp-env start

# run (from this repo):
FLYAFFILIATE_WP_ENV_DIR=$D/flyaffiliate-pro BASE_URL=http://localhost:8900 FLYAFFILIATE_SKIP_SEED=1 \
  npm run test:e2e -- specs/dokan.spec.ts
```

- `FLYAFFILIATE_WP_ENV_DIR` makes `utils/wp.ts` target that env's cli container.
  `Api` takes another namespace as a full route: `api.get( '/flyaffiliate-pro/v1/…' )`.
- `setMarketplace()` sets a **local test license** (Pro's QA skill allows it on a
  local site; say so in reports), turns WooCommerce's Coming soon off, and sets
  pretty permalinks if plain — Dokan's vendor dashboard needs them, as every real
  marketplace has. The spec switches the extension on through
  `PUT flyaffiliate-pro/v1/extensions/dokan`.
- Dokan 5.3 mirrors `dokan_selling` and `dokan_admin_settings` both ways: to
  clear a key write `''`, never delete it (a deleted key comes back from the other
  store). `setSelling()`/`setMarketplace()` do this for `null`.
- `dokanCheckout()` fires `woocommerce_checkout_order_created` then
  `woocommerce_checkout_update_order_meta` (Pro attributes at 5, Dokan splits at
  10, syncs `dokan_orders` at 20) and returns `{ parent, byVendor }`. Commissions
  sit on the **parent**; vendor money is read from `dokan_orders` (`dokanOrderRow()`).
- Pro has no Playwright suite of its own; these specs live here because the
  harness does. Moving them into Pro is a reasonable follow-up.

## Workflow for a new or changed feature

1. Find the ADR(s) and money rules it touches; open the matching section of
   `references/scenarios.md`. New behaviour with no row there → add the rows
   first (happy path, each guard/refusal, idempotency, permission, restore).
2. Check existing specs (`grep -rn "<feature>" tests/pw/specs`) — extend before
   creating a file.
3. Write the spec with owned data; run it alone 3× (`--repeat-each=3`) to prove
   it is not flaky and not order-dependent; then the full suite.
4. `npm run lint:js` covers `tests/pw` TS; `npx tsc -p tests/pw` typechecks it.
5. Failing because the product is wrong (not the test)? Keep the test, mark it
   `test.fail()` with a comment naming the bug id, and add the bug to
   `tests/pw/PRODUCT_BUGS.md` (where, steps, expected, actual, fix hint, test) —
   never weaken an assertion to go green. When a fix lands the test reports
   "expected to fail but passed": drop `test.fail()`, move the entry to *Fixed*.
6. Mark the rows you covered in `references/scenarios.md` (`✓ <spec>`).

## Release gate

A build ships only when all of these pass, on the release zip, not the dev tree:

1. `npm run build && npm run release` → install `build/flyaffiliate-v*.zip` on a
   clean wp-env (`wp plugin install <zip> --activate --force`).
2. Full suite green with `CI=1` (retries 2 — a test that needed a retry is
   reported and investigated, not ignored).
3. `-g @money` green twice in a row on the same DB (idempotency across runs).
4. Matrix: WooCommerce active **and** deactivated (`wp plugin deactivate woocommerce`
   then the "standalone" scenarios, ADR-0013); WP 6.6 minimum and latest
   (`.wp-env.override.json` `core`); PHP 8.1.
5. Fresh-install path: setup wizard spec on a DB with no `flyaffiliate_settings`.
6. `npm run plugin-check` clean (see `flyaffiliate-wporg-compliance`).

Report results as: passed / failed / skipped counts, every skip's reason, every
`test.fail()` with its ADR reference.
