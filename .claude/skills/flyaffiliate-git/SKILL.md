---
name: flyaffiliate-git
description: Branching, commit format, pull requests, and CI expectations for the FlyAffiliate repository. Use when creating branches, commits, or PRs.
---

# FlyAffiliate Git Guidelines

## Branches

Mirrors Dokan:

- **`main`** — released code. Tagged `v1.0.0`, `v1.0.1`, … Never committed to
  directly.
- **`develop`** — the integration branch. Everything merges here first.
- **Working branches** — cut from `develop`, merged back into `develop`:

| Prefix | For |
|---|---|
| `enhance/` | new capability or an improvement to an existing one |
| `fix/` | a bug |
| `chore/` | tooling, CI, docs, dependencies |
| `release/x.y.z` | release preparation, cut from `develop` |

Name the rest of the branch after the change, not the ticket:
`enhance/commission-hold-period`, `fix/refund-rescales-paid-commission`.

## Commits

One concern per commit. A commit that both moves a file and changes its
behaviour is two commits.

```
<type>(<scope>): <subject in the imperative>

<body: what changed and why, wrapped at 72 columns. Cite CONTEXT.md or an
ADR when the change is bound by a money rule or an architecture decision.>
```

Types: `feat`, `fix`, `refactor`, `test`, `docs`, `chore`, `perf`, `style`.
Scope is the area: `commission`, `tracking`, `payout`, `dokan`, `admin`, `rest`,
`install`, `ci`.

```
feat(commission): rescale commissions on partial refund

A refund on one item now scales that item's commission by the unrefunded
fraction rather than rejecting the whole row. A fully refunded item still
rejects; a paid commission is never touched (CONTEXT.md, money rule 5).

Keyed on order_item_id so a duplicate woocommerce_order_refunded does not
rescale twice.
```

Do not write `WIP`, `fixes`, `update code`, or a bare file name as a subject.

## Before you commit

```bash
composer phpcs
npm run phpunit
npm run plugin-check
```

`flyaffiliate-wporg-compliance` is invoked before **every** commit that touches
PHP, assets, `readme.txt`, or the plugin header.

Version discipline: new code carries `@since FLYAFFILIATE_SINCE`. The header
`Version`, the readme `Stable tag`, `FlyAffiliate_Plugin::$version`, and
`package.json` are changed together, only in a release commit.

## Pull requests

Fill in `.github/pull_request_template.md` — the checklist is the review
contract, not decoration. A PR without tests for what it changes is not ready.

Reviewed for: **correct**, **secure**, **readable**, **elegant** — in that order.
`flyaffiliate-code-review` is the standard.

## CI

Every PR runs PHPCS (changed files), PHPUnit (PHP 7.4/8.3),
and Plugin Check on the built zip. All three must be green before merge.

`deploy.yml` is tag-triggered and stays disabled until the WordPress.org slug is
approved — see `RELEASE.md`.
