# FlyAffiliate

Affiliate marketing for WordPress and WooCommerce, with native
[Dokan](https://dokan.co/wordpress/) multivendor support.

Affiliates get a referral link, a frontend dashboard, and a balance. Store owners
get per-item commissions with a configurable hold period, refund handling that
rescales rather than guesses, and manual payouts with a CSV export. On a Dokan
marketplace, each vendor can run their own program — and the vendor, not the
marketplace, funds the commission.

- **Requires:** PHP 7.4+, WordPress 6.4+, WooCommerce 8.5+
- **Optional:** Dokan Lite 5.0+, detected at runtime
- **Licence:** GPL-2.0-or-later

## Status

In development toward the Phase 1 release. See
[`docs/MVP_PRD.md`](docs/MVP_PRD.md) for the scope and
[`docs/PRD.md`](docs/PRD.md) for the full product requirements.

## Development

```bash
composer install
npm install
npm run env:start
```

Then read [`CONTRIBUTING.md`](CONTRIBUTING.md). Contributors and agents working
in this repository should start with [`CLAUDE.md`](CLAUDE.md) and
[`CONTEXT.md`](CONTEXT.md).

## Documentation

| Document | What it covers |
|---|---|
| [`CLAUDE.md`](CLAUDE.md) | Project map, commands, coding standards |
| [`CONTEXT.md`](CONTEXT.md) | Vocabulary and the money rules |
| [`docs/adr/`](docs/adr/) | Architecture decisions and why they were made |
| [`docs/MVP_PRD.md`](docs/MVP_PRD.md) | Phase 1 scope |
| [`.claude/skills/`](.claude/skills/) | How to build, test, review, and ship |
