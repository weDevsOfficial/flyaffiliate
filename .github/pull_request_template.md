## What this changes

<!-- One paragraph. Link the issue or the PRD section this implements. -->

## Why

<!-- The behaviour or constraint that made this necessary. Cite CONTEXT.md or docs/adr/ when a money rule or an architecture decision is involved. -->

## How to verify

<!-- Steps a reviewer can follow, including the data setup. -->

## Checklist

- [ ] `composer phpcs` is clean.
- [ ] `npm run phpunit` passes.
- [ ] `npm run plugin-check` reports 0 errors and 0 warnings on the built zip.
- [ ] Tests cover what this changes. Money paths also cover partial refund, full refund and idempotency (hook fired twice → same result).
- [ ] New code uses `@since FLYAFFILIATE_SINCE`, never a guessed version number.
- [ ] Every new string is translated with the `flyaffiliate` text domain and escaped at output.
- [ ] Every new file starts with the `ABSPATH` guard.
- [ ] Any new root-level file is listed in `.distignore`, or it ships on purpose.
- [ ] `CLAUDE.md` still describes the tree truthfully (services, commands, structure).
- [ ] Vocabulary matches `CONTEXT.md` (Commission, Affiliate, Vendor — not Referral, Partner, Seller).
