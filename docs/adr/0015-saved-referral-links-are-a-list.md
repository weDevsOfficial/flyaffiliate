# ADR-0015 — A saved referral link is a list entry, not a tracking key

**Status:** Accepted
**Date:** 2026-10-05

## Context

The affiliate dashboard's generator turned any page of the site into a
referral link in the browser and kept nothing. Affiliates and admins now want
a paginated list of those links, on the dashboard and on the admin's affiliate
page, so the links have to be stored.

Two questions decide what a stored link means:

1. **Where it lives.** User meta was the alternative to a table. WordPress
   loads every meta row of a user on each request that user makes, so a long
   list would ride along on every page load; a serialized array would also be
   read-modify-written by concurrent saves, and gives no SQL paging, no unique
   key and no row id for the REST routes.
2. **What removing one does.** If a stored link were what tracking looked up,
   removing it would break every cookie and every place it was already shared,
   and a customer mid-purchase would lose the affiliate their commission.

## Decision

Saved links live in `{prefix}flyaffiliate_referral_links` (`id`,
`affiliate_id`, `url`, `url_hash`, `created_at`), unique on
`(affiliate_id, url_hash)`. A row is a bookmark and nothing more:

- **Tracking is untouched.** `Tracking\Tracker` still attributes a visit by the
  referral variable on any page, and the cookie still carries the affiliate and
  the visit (ADR-0005). No code path reads the links table to decide
  attribution.
- **Removing a link takes it off the list only.** The link keeps working
  wherever it was shared; its visits and commissions are not touched. The
  dashboard's action says "Remove from list" and its confirmation says so.
- **No figures are stored on a link.** Visits, conversions and the last visit
  are counted from `{prefix}flyaffiliate_visits`, matching each link's page to
  the landing URL a visit records. Both sides go through
  `Tracker::to_landing_url()`, so they cannot drift apart; removing a link and
  saving the page again brings its history back.
- **The link to share is built on output** from the stored page, the current
  referral variable and the affiliate id, so changing the variable setting
  reaches every saved link.
- **Saving is idempotent:** the same page twice returns the first link.
- **Deleting an affiliate deletes their links.** Unlike commissions and
  visits, a link is not a financial record.

The admin list is read-only; the affiliate adds and removes their own through
`me/referral-links`, which binds every row to the caller.

The schema version stays `1.0.0`: the plugin has not been released, so the
table is part of the first schema and needs no upgrader. Once 1.0.0 ships, a
table change follows the installer's rule — bump `DB_VERSION` and add the
upgrader.

## Consequences

- A visit to a saved page counts on the link however the visitor arrived
  there — through the generated link, the plain referral link plus a hand-typed
  path, or a link the affiliate built themselves. The figure answers "visits
  this affiliate brought to this page", which is what an affiliate can act on.
- Two saved links for the same page (one with a fragment, one without) show
  the same figures, since the fragment never reaches the server.
- The landing URL a visit records has percent-encoded characters stripped
  (`sanitize_text_field()` in the tracker). Matching applies the same step, so
  the figures stay right, while the stored link keeps its encoding so the link
  itself still works.
