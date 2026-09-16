# Contributing to FlyAffiliate

## Getting set up

```bash
composer install     # dev dependencies only — nothing from vendor/ ships
npm install
npm run env:start    # WordPress + WooCommerce in Docker
```

Dev site: <http://localhost:8888>. Tests site: <http://localhost:8889>.

## Read before you write code

1. [`CLAUDE.md`](CLAUDE.md) — the project map, commands, and standards.
2. [`CONTEXT.md`](CONTEXT.md) — the vocabulary and the money rules. Anything
   touching commissions, refunds, or payouts is decided there.
3. [`docs/adr/`](docs/adr/) — check here before "fixing" surprising behaviour.
4. [`.claude/skills/`](.claude/skills/) — the procedural how-to for backend
   conventions, the dev cycle, the WordPress.org gate, review standards, and git.

## The gate

This plugin ships on WordPress.org. Every change passes:

```bash
composer phpcs          # 0 violations
npm run phpunit         # green
npm run plugin-check    # 0 errors, 0 warnings on the built zip
```

No pull request merges without all three, and none without tests for what it
changes.

## Branches and commits

`main` is released code, `develop` is the integration branch, work happens on
`enhance/`, `fix/`, or `chore/` branches cut from `develop`. Commit messages are
`type(scope): imperative subject` with a body explaining why.

The full convention is in [`.claude/skills/flyaffiliate-git`](.claude/skills/flyaffiliate-git/SKILL.md).

## Things that will get a change sent back

- Money compared as a float instead of integer cents.
- A write that is not idempotent when its hook fires twice.
- Output that is not escaped at the point of output, or a handler without a
  capability **and** nonce check.
- `manage_options` where `manage_woocommerce` belongs.
- A remote asset, an inline `<style>`/`<script>`, or a database write on
  `admin_init`.
- A version number guessed instead of `@since FLYAFFILIATE_SINCE`.
- A third-party plugin's symbol referenced outside `includes/Integrations/`.
- A new root-level file missing from `.distignore`.

## Reporting a bug

Include the WordPress, WooCommerce and PHP versions, and whether the problem
reproduces with other plugins deactivated.
