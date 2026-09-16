---
name: flyaffiliate-dev-cycle
description: Build, lint, test, and package FlyAffiliate — PHPCS, PHPUnit, wp-env, Plugin Check, release zip — plus the test-writing conventions. Use when running any quality check or before reporting work as done.
---

# FlyAffiliate Development Cycle

## The loop

```bash
composer phpcs          # style + security sniffs
npm run phpunit         # tests (wp-env must be running)
npm run plugin-check    # the WordPress.org gate, on the built zip
```

All three must be green before a commit is reported as done. `npm run plugin-check`
is the one that decides whether a release is possible; the other two are how you
find out early.

## Setup, once

```bash
composer install        # dev dependencies only — nothing from vendor/ ships
npm install
npm run env:start       # WordPress + WooCommerce + Dokan Lite in Docker
```

`npm run env:start` needs Docker running. The dev site is on
<http://localhost:8888>, the tests site on <http://localhost:8889>.

`.wp-env.json` mounts the repo twice on purpose:

- **dev** environment: the repo is the active plugin at
  `wp-content/plugins/flyaffiliate`.
- **tests** environment: the repo is mounted at
  `wp-content/plugins/flyaffiliate-src`, leaving the name `flyaffiliate` free for
  `bin/plugin-check.sh` to drop the built release directory into. That is what
  makes Plugin Check see the real slug.

PHP is 7.4 by default. To work against 8.3 locally, write a
`.wp-env.override.json` (gitignored) with `{ "phpVersion": "8.3" }` and restart.

## PHP

```bash
composer phpcs          # WordPress-Extra + PHPCompatibilityWP, whole repo
composer phpcbf         # auto-fix what can be auto-fixed
```

`phpcs.xml.dist` does **not** relax the security sniffs (ADR-0003). If PHPCS
reports unescaped output, an unprepared query, or a missing nonce check, that is
a real finding — Plugin Check will report the same thing on the built zip.

The only excluded PHP is `includes/admin.php` and `includes/frontend.php`, the
1.0.7 prototype screens the Phase 2 port reads from. Nothing new goes in them.

## JavaScript and CSS

```bash
npm run start           # dev build with watch
npm run build           # production build → assets/js, assets/css
npm run lint:js
npm run lint:css
npm run format
```

Sources live in `assets/src/js` and `assets/src/css`; `webpack.config.js` picks
them up by directory scan, so a new file needs no config change. `src/` is
reserved for Phase 2 React work and is wired the same way.

## Tests

```bash
npm run phpunit                       # whole suite
npm run phpunit -- --filter CommissionCalculatorTest
npm run phpunit:no-dokan              # what CI's "without Dokan" leg runs
npm run test:phpunit                  # env:start → phpunit → env:stop
composer test-f <filter>              # outside wp-env, needs WP_TESTS_DIR or wp-phpunit's WP_PHPUNIT__DIR
```

### Conventions

- Every test extends `FlyAffiliate\Test\FlyAffiliateTestCase`.
- Namespace mirrors the code: `FlyAffiliate\Test\Commission\CalculatorTest`
  tests `FlyAffiliate\Commission\Calculator`.
- File name ends in `Test.php` — `phpunit.xml` only collects that suffix.
- Fixtures come from the factories in `tests/php/src/Factories/` (affiliate,
  commission, visit, payout, product, order). Do not hand-insert rows when a
  factory exists.
- Assertions on tables use `DBAssertionTrait`
  (`assertDatabaseHas`, `assertDatabaseCount`, `assertDatabaseMissing`); money
  uses `MoneyAssertionTrait`, which compares integer cents.
- Dokan-dependent tests carry `@group dokan` and skip themselves when Dokan is
  not loaded. CI runs a leg with Dokan absent, so a missing skip fails there.
- Container tests carry `@group container`.

### What a money test must cover

Any change to commission calculation, maturation, refunds, payouts, or the Dokan
vendor charge needs all four:

1. **Happy path**, asserted in integer cents.
2. **Partial refund** — the commission rescales by the unrefunded fraction of its
   own item.
3. **Full refund** — the commission becomes `rejected`, and a `paid` commission
   is never touched.
4. **Idempotency** — fire the hook twice, assert nothing changed the second time.

The golden path test ($100 order, 15% rate → 1500 cents from visit to payout)
is the canary; if it fails, stop and fix it before anything else.

## Release

```bash
npm run release         # build → makepot → build/flyaffiliate.zip
npm run plugin-check    # Plugin Check against build/flyaffiliate
```

`php bin/build-zip.php` stages `build/flyaffiliate/` honouring `.distignore`,
then zips it with `flyaffiliate/` as the single top-level entry. `--no-zip`
stages without archiving.

**A new root-level file that is not shipping code must be added to `.distignore`**
or it lands in the zip.

## CI

| Workflow | Trigger | What fails it |
|---|---|---|
| `phpcs.yml` | PR touching `**.php` | Any violation on a changed file (no `--graceful-warnings`) |
| `phpunit.yml` | push to `main`, PRs | Any failing test on PHP 7.4/8.3 × with/without Dokan |
| `plugin-check.yml` | push, PR, manual | Any Plugin Check error **or warning** on the built zip |
| `deploy.yml` | `v*` tag | Disabled until the wp.org slug is approved |

## When something is broken

- **`npm run env:start` hangs or errors** — Docker is not running, or a previous
  environment is stale: `npm run env:destroy`, then start again.
- **PHPUnit cannot find WordPress** — you are running `composer test` outside
  wp-env without a provisioned test install (`WP_TESTS_DIR`, or the
  `wp-phpunit/wp-phpunit` dev package's `WP_PHPUNIT__DIR`).
  Use `npm run phpunit` instead.
- **Plugin Check reports something new** — read
  `.claude/skills/flyaffiliate-wporg-compliance` before deciding it is
  acceptable. The list of accepted findings is
  `docs/wporg-accepted-warnings.md`, and it is short on purpose.

## End-to-end tests (Playwright)

`tests/pw` mirrors Dokan's `tests/pw`: `playwright.config.ts`, a `global-setup.ts`
that seeds sample data and logs in once, `pages/` page objects, `utils/`, and
`specs/`. They run against the wp-env development site:

```bash
npm run env:start
npm run test:e2e            # headless; HEADLESS=false to watch
npm run test:e2e -- --grep settings
```

Conventions: one spec per screen; find elements by role or by the
`data-testid` hooks plugin-ui and the app expose (`settings-field-{id}`,
`settings-menu-{id}`, `flyaffiliate-*`); wait for lists with
`waitForListReady()` rather than fixed sleeps; never assert on seeded row
counts, only on relative changes.
