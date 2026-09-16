# ADR-0003 — WordPress.org rules outrank Dokan conventions

**Status:** Accepted
**Date:** 2026-09-07

## Context

FlyAffiliate is distributed through the WordPress.org plugin directory. Every
release passes Plugin Check (PCP) with zero errors and zero warnings, and the
first submission passes a human review.

Dokan Lite is distributed the same way, but it predates Plugin Check and carries
a large installed base. Its `phpcs.xml.dist` silences sniffs that PCP now
enforces:

| Sniff | Dokan | Why it cannot be silenced here |
|---|---|---|
| `WordPress.Security.EscapeOutput.OutputNotEscaped` | severity 0 | PCP reports unescaped output as an error. |
| `WordPress.DB.PreparedSQL.NotPrepared` | warning | PCP reports it; the gate allows no warnings. |
| `WordPress.PHP.DevelopmentFunctions` | severity 0 | PCP flags `error_log`, `var_dump`, `print_r`. |

Dokan also calls `load_plugin_textdomain()`, which PCP flags because
WordPress.org has loaded translations automatically since WordPress 4.6.

## Decision

Where a Dokan convention conflicts with a WordPress.org rule, the WordPress.org
rule wins, and the divergence is recorded here.

Divergences in force:

1. **No relaxed security sniffs.** `phpcs.xml.dist` keeps escaping,
   sanitization, nonce and prepared-SQL sniffs at their default error severity.
   Only style-only sniffs are silenced, and each has a comment saying why.
2. **No `load_plugin_textdomain()`.** The text domain is `flyaffiliate`, equal to
   the plugin slug; WordPress.org serves the translations.
3. **No telemetry.** Dokan bundles Appsero. FlyAffiliate collects nothing, so
   there is no opt-in notice to write and no privacy disclosure to maintain.
4. **No bundled runtime dependencies.** See [ADR-0002](0002-in-house-container.md).
5. **Direct database queries are allowed, unprepared ones are not.** Every table
   FlyAffiliate queries directly is one of its own custom tables, which no
   WordPress API can reach, so `WordPress.DB.DirectDatabaseQuery.DirectQuery`
   and `.NoCaching` are silenced. `WordPress.DB.PreparedSQL*` stays at error.

The one sanctioned exception to a PCP rule is documented separately in
[`docs/wporg-accepted-warnings.md`](../wporg-accepted-warnings.md), which must
stay a short list with a justification per entry.

## Consequences

- `composer phpcs` is a genuine pre-flight for PCP rather than a weaker check
  that passes while the release fails.
- Code ported from the prototype or adapted from Dokan is rewritten to this
  standard rather than carried over with an ignore comment.
- A future Dokan-shaped pattern that trips PCP is a bug in FlyAffiliate, not a
  reason to relax the ruleset.
