# ADR-0007 — A visit stores hashed IP and hashed user agent, never the raw values

**Status:** Accepted
**Date:** 2026-09-07

## Context

A visit row exists to answer two questions: which affiliate sent this traffic,
and did it convert. Affiliate plugins commonly store the raw IP address and the
full user agent string alongside, for fraud checks.

Under GDPR an IP address is personal data. Storing it raw obliges the site owner
to disclose it, to honour erasure requests against it, and to define a retention
period — and obliges this plugin to ship privacy-exporter and eraser hooks for
data it does not need in the first place.

## Decision

`{prefix}flyaffiliate_visits` stores **`ip_hash`** and **`user_agent_hash`**, not
`ip` and `user_agent`.

- Both are `wp_hash()` of the value, which is salted with the site's own
  `NONCE_SALT`. The digest is not reversible and is not portable between sites.
- They are enough for what they are for: detecting that a single source is
  generating many visits, and deduplicating repeated clicks.
- The raw values are never written to the database and never logged.
- The landing URL and referrer are stored as-is; they are not personal data and
  the "highest converting URLs" report needs them.

## Consequences

- No personal data is stored on a visit, so `readme.txt` needs a short honest
  disclosure rather than a privacy policy section, and the plugin does not need
  WordPress's personal-data exporter/eraser hooks for visits.
- A fraud check can compare hashes for equality but cannot resolve a hash to an
  address, geolocate it, or block a range. If a future phase needs that, it is a
  new decision with a new disclosure — not a quiet schema change.
- Hashes are salted per site, so a visits table copied to another install stops
  matching. That is intended.
