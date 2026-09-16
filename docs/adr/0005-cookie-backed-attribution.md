# ADR-0005 — Referral attribution persists in a first-party cookie

**Status:** Accepted
**Date:** 2026-09-07

## Context

`docs/MVP_PRD.md` §2 describes referral tracking as "GET param tracking
(cookie-free)". Taken literally, that only attributes a purchase that happens in
the same request as the click — which is almost no purchase. A visitor clicks a
referral link, lands on a product page, browses, adds to cart, and checks out
several requests later. By then the query variable is gone.

Without persistence the plugin would attribute close to nothing, and every
affiliate would conclude the tracking is broken.

## Decision

The referral link carries a query variable (`affiliate` by default; the name is a
setting). On a request that carries it:

1. Resolve the value to an **active** affiliate. An unknown or inactive
   affiliate is ignored — no visit row, no cookie.
2. Write a `Visit` row.
3. Set a first-party cookie **`flyaffiliate_ref`** holding the affiliate id and
   the visit id.

Cookie properties:

| Property | Value |
|---|---|
| Lifetime | 30 days, configurable in settings, may be 0 for session-only |
| `HttpOnly` | true — no JavaScript reads it |
| `SameSite` | `Lax` |
| `Secure` | true when the request is HTTPS |
| Domain | the site's own domain; never a third-party domain |

Attribution happens on `woocommerce_checkout_order_created`: the cookie is read,
the affiliate re-validated, and the id stored on the order as
`_flyaffiliate_affiliate_id` with `_flyaffiliate_visit_id`. The visit is marked
converted.

A WooCommerce session fallback covers the case where the cookie was refused: if
`WC()->session` is available, the same pair is mirrored there.

**Last click wins** within the cookie window, consistent with `CONTEXT.md` money
rule 8: exactly one affiliate per order.

Self-referral is blocked by default — an affiliate buying through their own link
earns nothing.

## Privacy

The cookie is first-party, contains two integers, and is not shared with anyone.
`readme.txt` discloses it under a "Does this plugin use cookies?" FAQ entry, with
the name, the purpose and the lifetime, so a site owner can put it in their own
cookie policy. See also [ADR-0007](0007-hashed-visitor-data.md) for what a visit
row stores.

## Consequences

- The PRD's "cookie-free" phrasing is superseded. It is recorded here rather than
  silently ignored.
- A visitor who clears cookies between click and purchase is not attributed. That
  is correct — there is no honest way to attribute them without cross-request
  fingerprinting, which this plugin does not do.
- The cookie lifetime is the attribution window, so changing the setting changes
  who gets paid. The settings screen says so.
- No third-party cookie, no localStorage, no fingerprint, no external tracker.
