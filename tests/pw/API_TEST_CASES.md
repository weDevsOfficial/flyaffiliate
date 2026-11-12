# FlyAffiliate REST API — test cases

Every route the plugins register, and the cases to test on each. Routes were read from the
running site (`rest_get_server()->get_routes()`) for FlyAffiliate 1.0.1 (`develop`) and
FlyAffiliate Pro 1.0.0; the PayPal and Dokan routes from Pro's controllers (they only exist
while those extensions run).

**Columns.** *P* = priority: **P1** money or security (release gate), **P2** a feature works,
**P3** edge / hygiene. *Auto* = the Playwright spec that already covers it (`tests/pw/specs/`),
or **—** if it is still to write. Expected results follow the ADRs, `CONTEXT.md` and the
behaviour the suite has verified; a "—" case's expectation should be confirmed on first run.

**Conventions for every case**

- Base URL: `/wp-json/<namespace>/…` with pretty permalinks, or
  `/?rest_route=/<namespace>/…` with plain ones (CI). Test both forms at least once.
- Admin routes use a logged-in administrator **and** the `X-WP-Nonce` header
  (`/wp-admin/admin-ajax.php?action=rest-nonce`). `me` routes use the affiliate's session.
- Money: compare amounts in **integer cents**; every money write fired twice must give the
  same result (idempotency).
- A refused write must leave the database **unchanged** — read the row back.

---

## 0. Cross-cutting (apply to every route)

| ID | Case | Expected | P | Auto |
|---|---|---|---|---|
| API-X-01 | Guest (no cookie) calls any admin route | 401 `rest_forbidden` | P1 | security, negative-affiliate |
| API-X-02 | Logged-in cookie **without** `X-WP-Nonce` | treated as logged out → 401 (`rest_cookie_invalid_nonce` / forbidden) | P1 | security [SEC-07] |
| API-X-03 | Subscriber, shop manager, affiliate call admin routes | 403 | P1 | security [SEC-02] |
| API-X-04 | `flyaffiliate_admin_capability` filter grants shop managers | shop manager gets 200 | P2 | admin-shell [SEC-03] |
| API-X-05 | Every `me` route as guest / non-affiliate member / pending / inactive / suspended affiliate | 401 / 403 / 403 / 403 / 403 | P1 | negative-affiliate |
| API-X-06 | Plain-permalink URL form (`?rest_route=`, encoded slashes) | same responses as pretty | P2 | CI run (all specs) |
| API-X-07 | Unknown numeric ID on every `/{id}` route (GET, PUT, DELETE) | 404 with the resource's not-found code | P2 | negative-rest, negative-affiliate |
| API-X-08 | Non-numeric ID (`/affiliates/abc`) | 404 `rest_no_route` | P3 | — |
| API-X-09 | `page=0`, `page=-1`, `per_page=0`, `per_page=101`, `per_page=abc` | 400 `rest_invalid_param` | P2 | negative-rest |
| API-X-10 | List responses carry `X-WP-Total` and `X-WP-TotalPages`; `page` past the last returns `[]` | headers match the row count | P2 | — |
| API-X-11 | Unknown `orderby` / `order=sideways` | 400 | P3 | negative-rest |
| API-X-12 | `context=edit` vs `view` | edit fields only for admins | P3 | — |
| API-X-13 | Each item has `_links` (`self`, `collection`, related) and matches `OPTIONS` schema | true | P3 | — |
| API-X-14 | SQL / script in `search` | literal search, no SQL error, no markup executed | P1 | negative-rest |
| API-X-15 | HTML in any text field (name, note, promo) | stored sanitised, rendered as text on screen | P1 | security [SEC-06] |
| API-X-16 | Malformed JSON body | 400 `rest_invalid_json` | P3 | — |
| API-X-17 | `GET /flyaffiliate/v1` (namespace index) | 200, lists the routes | P3 | — |
| API-X-18 | No PHP notice/warning during any call | `pw-php.log` has no FlyAffiliate line | P2 | zz-php-log [SEC-10] |

## 1. Affiliates — `/flyaffiliate/v1/affiliates`

| ID | Method · route | Case | Expected | P | Auto |
|---|---|---|---|---|---|
| API-AFF-01 | GET /affiliates | List as admin | 200, rows with id, user, status, emails, totals | P2 | affiliates |
| API-AFF-02 | GET /affiliates?status= | each of pending / active / inactive / suspended; unknown status | filtered; unknown → 400 | P2 | negative-rest |
| API-AFF-03 | GET /affiliates?search= | by name and by email; nobody matches | matches; `[]` | P2 | affiliates |
| API-AFF-04 | GET /affiliates?user_id= | an affiliate's user | that one row | P3 | — |
| API-AFF-05 | POST /affiliates | existing user, `status: active` | 201; user gets the Affiliate role; referral link works at once | P1 | admin-records [AFF-02] |
| API-AFF-06 | POST /affiliates | same user twice | 409 "That user is already an affiliate." | P1 | admin-records [AFF-03] |
| API-AFF-07 | POST /affiliates | user that does not exist; missing `user_id` | 400/404, nothing stored | P2 | admin-records, negative-rest |
| API-AFF-08 | POST /affiliates | invalid `status`, invalid `payment_email` | 400, nothing stored | P2 | negative-rest |
| API-AFF-09 | POST /affiliates | `send_welcome_email: true` / omitted | email sent with the referral link / no email | P2 | release-critical [AFF-04] |
| API-AFF-10 | POST /affiliates | empty `payment_email` | stored as the account email | P3 | — |
| API-AFF-11 | POST /affiliates | unknown `payout_method`; `payout_details` for a method | 400; details saved per method | P2 | — (pro-paypal covers PayPal) |
| API-AFF-12 | GET /affiliates/{id} | one affiliate | 200 with `effective_payout_method` | P2 | paid-provenance [COM-20] |
| API-AFF-13 | PUT /affiliates/{id} | change status pending → active → inactive → suspended → active | each saved; tracking obeys (inactive/suspended links record nothing) | P1 | affiliates, tracking [TRK-03] |
| API-AFF-14 | PUT /affiliates/{id} | invalid status / email | 400, row unchanged | P2 | negative-rest |
| API-AFF-15 | PUT /affiliates/{id} | try to change `user_id` | ignored or refused; user unchanged | P1 | — |
| API-AFF-16 | DELETE /affiliates/{id} | delete | 200; saved referral links removed; commissions, visits, payments kept as records | P1 | admin-records [AFF-07], negative-money |
| API-AFF-17 | DELETE /affiliates/{id} | the user loses the Affiliate role, keeps the WP account | true | P2 | — |
| API-AFF-18 | DELETE then GET | 404 | P3 | negative-rest |

## 2. Commissions — `/flyaffiliate/v1/commissions`

| ID | Method · route | Case | Expected | P | Auto |
|---|---|---|---|---|---|
| API-COM-01 | GET /commissions | filters: status, affiliate_id, order_id, vendor_id, source, payout_id, after/before | each narrows correctly; bad status / dates → 400 | P2 | negative-rest |
| API-COM-02 | GET /commissions/{id} | one | 200 with `paid_by` (null / method), `matures_at`, `note` | P2 | paid-provenance [COM-18] |
| API-COM-03 | POST /commissions | manual, amount 12.34, status unpaid | 201; amount stored to the cent | P1 | payout-rules, admin-records |
| API-COM-04 | POST /commissions | no status given | defaults to unpaid (owed) | P2 | admin-records [COM-03] |
| API-COM-05 | POST /commissions | amount 0, negative, non-numeric, more than 2 decimals | 400, nothing stored | P1 | admin-records [COM-07], negative-rest |
| API-COM-06 | POST /commissions | no affiliate; unknown affiliate | 400 | P1 | negative-rest |
| API-COM-07 | POST /commissions | `source: woocommerce` with an order that does not exist | 400 "There is no WooCommerce order #N" | P2 | admin-records [COM-05] |
| API-COM-08 | POST /commissions | `source: manual` naming an order | stored; never moved by that order's status | P1 | order-sync [SYN-11] |
| API-COM-09 | POST /commissions | `source: woocommerce` added by hand | follows its order's status | P2 | order-sync [SYN-12] |
| API-COM-10 | POST /commissions | status `paid` | recorded paid by hand, `paid_by` null, held by no payment | P1 | payout-rules [COM-14], paid-provenance |
| API-COM-11 | PUT /commissions/{id} | amount on an order commission | sticks, never recalculated | P1 | admin-records [COM-09] |
| API-COM-12 | PUT /commissions/{id} | every status → every status (pending, unpaid, paid, rejected) | allowed, saved | P2 | admin-records [COM-10] |
| API-COM-13 | PUT /commissions/{id} | inside an **unpaid** payment: change amount | the payment re-sums in cents | P1 | payout-rules [COM-11] |
| API-COM-14 | PUT /commissions/{id} | inside a **paid** payment: change amount | the payment's paid amount never moves | P1 | payout-rules [COM-12], negative-money |
| API-COM-15 | PUT /commissions/{id} | inside an unpaid payment: reject | taken out of the payment's sum | P1 | negative-money |
| API-COM-16 | PUT /commissions/{id} | invalid amount / status / type | 400, row unchanged | P2 | negative-rest |
| API-COM-17 | DELETE /commissions/{id} | free commission | 200, gone | P2 | — |
| API-COM-18 | DELETE /commissions/{id} | inside any payment | refused (409/400), row kept | P1 | payout-rules [COM-13], negative-money |
| API-COM-19 | Every commission write | fired twice | same result (idempotent) | P1 | order-sync [SYN-08] |

## 3. Visits and referral links (admin, read-only)

| ID | Method · route | Case | Expected | P | Auto |
|---|---|---|---|---|---|
| API-VIS-01 | GET /visits | filters: affiliate_id, converted, search (URL), after/before | narrows correctly | P2 | visits |
| API-VIS-02 | GET /visits/{id} | one visit | landing URL, referrer, **hashed** IP/agent (never raw), order when converted | P1 | tracking [TRK-02][TRK-12] |
| API-VIS-03 | DELETE / POST /visits | not allowed | 404 / 405 — visits are a ledger | P2 | negative-rest |
| API-REF-01 | GET /referral-links (?affiliate_id) | an affiliate's saved links with visit counts | 200 | P3 | — |
| API-REF-02 | POST / PUT / DELETE /referral-links | admin list is read-only (ADR-0015) | not allowed | P2 | negative-rest |

## 4. Payouts — `/flyaffiliate/v1/payouts`

| ID | Method · route | Case | Expected | P | Auto |
|---|---|---|---|---|---|
| API-PAY-01 | POST /payouts/preview | mode `all`, minimum 0 | every affiliate owed: one row each, summed in cents | P1 | payout-rules [PAY-06] |
| API-PAY-02 | POST /payouts/preview | unpaid commission still in its hold | left out; `held` count and amount reported | P1 | payout-rules [PAY-02], negative-money |
| API-PAY-03 | POST /payouts/preview | pending, paid, rejected, or already in a payment | not offered | P1 | payout-rules [PAY-03], [PAY-09] |
| API-PAY-04 | POST /payouts/preview | minimum above an affiliate's total; exactly equal; a cent less | left out; included; left out | P1 | payout-rules [PAY-04], negative-money |
| API-PAY-05 | POST /payouts/preview | `period_start` / `period_end` | only commissions earned in the period | P2 | payout-rules [PAY-05] |
| API-PAY-06 | POST /payouts/preview | `mode: selected` with nobody; `except` | nobody paid; the listed are left out | P1 | negative-rest |
| API-PAY-07 | POST /payouts/preview | bad mode, negative minimum, bad date format, impossible date (`2025-13-45`), end before start, non-numeric ids | 400 / nobody | P2 | negative-rest |
| API-PAY-08 | POST /payouts/preview | `methods` override to Manual; unknown method | that affiliate's row is Manual; unknown falls back to Manual | P2 | negative-rest, release-critical |
| API-PAY-09 | POST /payouts | create | 201 with `batch_key`, one payment per affiliate; commissions stay **unpaid** but attached | P1 | payout-rules [PAY-01] |
| API-PAY-10 | POST /payouts | `expected` totals differ from now (something changed since the preview) | refused, nothing created | P1 | payout-rules [PAY-07] |
| API-PAY-11 | POST /payouts | two creates at the same time | never both take a commission | P1 | payout-rules [PAY-08] |
| API-PAY-12 | POST /payouts | nothing to pay | 400 | P2 | negative-admin-ui (UI), — (API) |
| API-PAY-13 | GET /payouts (?batch_key, ?status, ?affiliate_id) | payments list | filtered | P2 | — |
| API-PAY-14 | GET /payouts/batches | batches with status, totals, counts | paginated | P2 | — |
| API-PAY-15 | GET /payouts/batch/{key} | one batch summary; unknown key | 200; 404 | P2 | negative-rest |
| API-PAY-16 | POST /payouts/batch/{key}/pay | mark every manual payment paid | payments and their commissions paid, once; rejected-meanwhile stays rejected | P1 | payout-rules [PAY-16]…, [ADR-0012] |
| API-PAY-17 | POST /payouts/batch/{key}/pay | unknown key | 404 | P2 | negative-rest |
| API-PAY-18 | POST /payouts/batch/{key}/pay | twice | second changes nothing | P1 | payout-rules |
| API-PAY-19 | DELETE /payouts/batch/{key} | batch with no paid payment; with a paid one | deleted, commissions freed; refused | P1 | payout-rules [PAY-20] |
| API-PAY-20 | POST /payouts/batch/{key}/refresh | ask automatic methods for news | 200 summary; Manual-only batch unchanged | P2 | pro-paypal |
| API-PAY-21 | PUT /payouts/{id} `status: paid` | one payment | it and its commissions paid, once | P1 | payout-rules [PAY-10] |
| API-PAY-22 | PUT /payouts/{id} `status: unpaid` | undo | commissions back to unpaid, still attached | P1 | payout-rules [PAY-11][PAY-12] |
| API-PAY-23 | PUT /payouts/{id} | bad / missing status | 400 | P2 | negative-rest |
| API-PAY-24 | PUT/DELETE /payouts/{id} | payment locked by an automatic method in flight | refused | P1 | pro-paypal [PP-PAY-03] |
| API-PAY-25 | DELETE /payouts/{id} | unpaid payment | deleted; commissions free for the next payout | P1 | payout-rules [PAY-15] |
| API-PAY-26 | DELETE /payouts/{id} | paid payment | refused, amount never moves | P1 | negative-money |
| API-PAY-27 | DELETE /payouts/{id}/commissions/{cid} | from an unpaid payment | removed, payment re-sums | P1 | payout-rules [PAY-13] |
| API-PAY-28 | DELETE /payouts/{id}/commissions/{cid} | the **last** commission | 409 `flyaffiliate_payout_last_commission` (delete the payment instead) | P2 | negative-rest |
| API-PAY-29 | DELETE /payouts/{id}/commissions/{cid} | from a paid payment; a commission of another payment | refused | P1 | payout-rules [PAY-14], negative-rest |
| API-PAY-30 | POST /payouts/{id}/retry | manual payment | refused (only automatic methods retry) | P2 | negative-rest |
| API-PAY-31 | `admin-post.php?action=flyaffiliate_payout_csv&batch=…` | admin with nonce; no / forged nonce; guest, subscriber, affiliate | CSV; 403 | P1 | release-critical, payout-rules [PAY-18] |

## 5. Settings and setup

| ID | Method · route | Case | Expected | P | Auto |
|---|---|---|---|---|---|
| API-SET-01 | GET /settings | schema + stored values | 200 | P2 | settings |
| API-SET-02 | POST /settings/{scope} | valid values | saved; survive a reload | P2 | admin-shell [SET-02] |
| API-SET-03 | POST /settings/{unknown} | 404, option untouched | P2 | negative-rest |
| API-SET-04 | POST /settings/{scope} | default rate above maximum; rate over 100; negative days; negative minimum | 400 with the field's message, nothing stored | P1 | admin-shell [SET-04], settings |
| API-SET-05 | POST /settings/{scope} | switch value other than on/off | 400 "Use on or off." | P2 | negative-rest |
| API-SET-06 | POST /settings/{scope} | unknown select choice | stored value kept | P3 | negative-rest |
| API-SET-07 | POST /settings/{scope} | a field of another scope, an unknown field | not written | P2 | negative-rest |
| API-SET-08 | POST /settings/{scope} | referral variable empty; with markup; a WordPress query var (`p`, `s`, `page_id`) | refused; stripped to a safe key; refused | P1 | negative-rest, admin-shell |
| API-SET-09 | — | a reserved variable stored before the fix (upgrade path) | ignored by the tracker | P1 | negative-affiliate |
| API-SET-10 | POST /setup/complete | finishes the wizard (`share_essentials` on/off) | wizard marked done; pages exist | P2 | setup |

## 6. Admin dashboard

| ID | Method · route | Case | Expected | P | Auto |
|---|---|---|---|---|---|
| API-DSH-01 | GET /dashboard | all time; after/before | cards (referral revenue, net, paid, unpaid, commissions, visits, conversion, affiliates) match the ledger; rejected excluded | P2 | dashboard (render only) — formulas **—** |
| API-DSH-02 | GET /dashboard | malformed dates | 400 | P3 | negative-rest |
| API-DSH-03 | POST /dashboard/pending-notice | dismiss the pending-affiliates notice | 200; stays dismissed | P3 | — |

## 7. The affiliate's own routes — `/flyaffiliate/v1/me`

| ID | Method · route | Case | Expected | P | Auto |
|---|---|---|---|---|---|
| API-ME-01 | GET /me | active affiliate | own profile, referral link with the current variable, overview figures | P1 | affiliate-dashboard [DSH-04][DSH-05] |
| API-ME-02 | GET /me?after&before | range | figures for the range; malformed → 400 | P3 | negative-affiliate |
| API-ME-03 | PUT /me | payment email, promo method | saved | P2 | affiliate-dashboard [DSH-11] |
| API-ME-04 | PUT /me | invalid payment email | 400, stored address unchanged; `""` → account email | P1 | negative-affiliate |
| API-ME-05 | PUT /me | `payout_method: paypal` + details; unknown method | saved; 400 | P2 | pro-paypal [PP-AFF-01], negative-affiliate |
| API-ME-06 | PUT /me | try to change status, user, id | ignored | P1 | negative-affiliate |
| API-ME-07 | GET /me/commissions (+ filters) | own rows only; another affiliate's never | true | P1 | affiliate-dashboard [SEC-04][SEC-05] |
| API-ME-08 | GET /me/commissions/{id} | own; someone else's | 200; 404 | P1 | negative-affiliate |
| API-ME-09 | GET /me/visits, /me/payouts | own rows; payouts never expose a transfer's failure detail or PayPal internals | true | P1 | affiliate-dashboard [DSH-10], pro-paypal [PP-AFF-02] |
| API-ME-10 | POST /me/referral-links | a page of this site | saved once (same URL again → same link) | P2 | affiliate-dashboard [DSH-06] |
| API-ME-11 | POST /me/referral-links | another site, `javascript:`, malformed, empty | 400 with its message, nothing saved | P1 | negative-affiliate |
| API-ME-12 | DELETE /me/referral-links/{id} | own; someone else's | hidden from the list (link keeps working); 404 | P2 | affiliate-dashboard [DSH-07] |

## 8. Tracking (front end, not REST — the input the API's data comes from)

| ID | Request | Expected | P | Auto |
|---|---|---|---|---|
| API-TRK-01 | `/?affiliate=<active id>` | one visit, HttpOnly SameSite=Lax cookie for the window | P1 | tracking [TRK-01] |
| API-TRK-02 | `/?affiliate=` `john`, `5abc`, `-5`, `[]`, 2000 digits, unknown id | nothing recorded, no error | P1 | tracking [TRK-04], negative-affiliate |
| API-TRK-03 | same link again | no second visit | P2 | tracking [TRK-16] |
| API-TRK-04 | forged / tampered cookie at checkout | no commission | P1 | attribution [WC-15], negative-money |

---

## 9. FlyAffiliate Pro — `/flyaffiliate-pro/v1`

| ID | Method · route | Case | Expected | P | Auto |
|---|---|---|---|---|---|
| API-PRO-01 | any Pro route | guest, subscriber, shop manager | 401 / 403 (administrators only) | P1 | pro-core [PRO-SEC-01] |
| API-PRO-02 | GET /license | key masked, status, expiry | 200, full key never returned | P1 | pro-core [PRO-LIC-02] |
| API-PRO-03 | POST /license/activate | no key; invalid key; valid key (license server) | 400; error; activated | P2 | pro-core (no key) — real server **manual** |
| API-PRO-04 | POST /license/deactivate | no key stored; stored | 400; deactivated, extensions stop running | P1 | pro-core [PRO-LIC-01] |
| API-PRO-05 | POST /license/refresh | refresh status | 200 | P3 | — (manual) |
| API-PRO-06 | GET /extensions | every extension with category, active, running, requirements, available | 200 | P2 | pro-core [PRO-EXT-01] |
| API-PRO-07 | PUT /extensions/{id} `active` | on → routes load at once; off → routes gone | true | P1 | pro-core [PRO-EXT-02] |
| API-PRO-08 | PUT /extensions/{unknown} | 404 | P3 | pro-core [PRO-EXT-03] |
| API-PRO-09 | PUT /extensions/dokan `active: true` without Dokan | refused; requirement listed | P2 | pro-core [PRO-EXT-04] |
| API-PRO-10 | PUT /extensions/{id} without a valid license | refused | P1 | pro-core [PRO-LIC-01] |

### 9.1 PayPal — `/flyaffiliate-pro/v1/paypal/*` (extension on)

| ID | Method · route | Case | Expected | P | Auto |
|---|---|---|---|---|---|
| API-PP-01 | POST /paypal/connection | keys PayPal refuses | 400, nothing saved | P1 | pro-paypal [PP-CONN-01] |
| API-PP-02 | POST /paypal/connection | valid sandbox keys | connected; account, Payouts permission, token checks; secret never returned, client id masked | P1 | pro-paypal [PP-CONN-02], paypal-sandbox (live) |
| API-PP-03 | POST /paypal/connection | on http | webhook not registered, says why | P2 | pro-paypal [PP-CONN-02] |
| API-PP-04 | POST /paypal/connection/verify | re-check the connection | "PayPal connection checked." | P3 | — |
| API-PP-05 | DELETE /paypal/connection | nothing in flight; payments in flight | disconnected; refused with the count | P1 | pro-paypal [PP-CONN-03] |
| API-PP-06 | POST /paypal/connection/source | switch own app ↔ Dokan Pro's PayPal Marketplace (ADR-0009) | source changes; in-flight payments keep their account | P1 | **—** (no test yet) |
| API-PP-07 | GET/POST /paypal/settings | page settings | saved | P3 | — |
| API-PP-08 | POST /paypal/webhook | no / bad signature | refused, nothing changes | P1 | pro-paypal [PP-HOOK-01] |
| API-PP-09 | POST /paypal/webhook | signed item SUCCEEDED | payment paid at once, once | P1 | pro-paypal [PP-HOOK-02] |
| API-PP-10 | POST /paypal/webhook | signed REVERSED / REFUNDED | confirmed with PayPal first; payment back to unpaid, flagged | P1 | pro-paypal [PP-PAY-11] |
| API-PP-11 | POST /paypal/webhook | same event delivered twice | applied once | P1 | — |
| API-PP-12 | (through /payouts) PayPal statuses | SUCCESS, FAILED, PENDING, UNCLAIMED, ONHOLD, BLOCKED, RETURNED | each maps as in guide B3.5 | P2 | pro-paypal (SUCCESS, FAILED, REVERSED, unreachable), paypal-sandbox (UNCLAIMED live); ONHOLD/BLOCKED **—** |
| API-PP-13 | POST /payouts/{id}/retry | after failure; twice fast | one new attempt; second refused | P1 | pro-paypal [PP-PAY-05] |
| API-PP-14 | (send) currency PayPal does not pay in | failed before sending | P2 | pro-paypal [PP-PAY-12] |
| API-PP-15 | (send) the client secret | only ever sent to the token request | P1 | pro-paypal [PP-LOG-01] |

### 9.2 Dokan — `/flyaffiliate-pro/v1/dokan/*` (extension on, Dokan active)

| ID | Method · route | Case | Expected | P | Auto |
|---|---|---|---|---|---|
| API-DOK-01 | any `/dokan/vendor/*` | guest, customer, affiliate, admin who is not a vendor | 401 / 403 | P1 | dokan [DOK-SEC-01] |
| API-DOK-02 | GET /dokan/vendor/program | opted in / not; effective rate, its source, hold, cap note | 200 | P2 | dokan [DOK-UI-02] |
| API-DOK-03 | POST /dokan/vendor/program | opt in; own rate; own hold — allowed / not allowed by the admin; rate above the max; invalid values | saved / refused with the message; capped note | P1 | dokan [DOK-RATE-03], [DOK-HOLD-01] — validation **—** |
| API-DOK-04 | GET /dokan/vendor/program/history | every rate change, by vendor or marketplace | rows | P3 | — |
| API-DOK-05 | GET /dokan/vendor/affiliates | only this vendor's store's affiliates | never another store's | P1 | dokan [DOK-SEC-02] |
| API-DOK-06 | GET /dokan/vendor/affiliates/{id} | own affiliate; another store's | 200; 404 | P1 | — |
| API-DOK-07 | GET /dokan/vendor/affiliates/{id}/referrals | each sale: commission, status, payable-from, link to the vendor order | matches the ledger | P2 | — |
| API-DOK-08 | GET /dokan/vendors/{id} (admin) | a vendor's program: status, current rate, comes from, hold | 200 | P3 | — |

---

## 10. Summary — what is left to automate

| Area | Cases still **—** | Priority |
|---|---|---|
| Cross-cutting | pagination headers, non-numeric IDs, context=edit, `_links`/schema, malformed JSON, namespace index | P2–P3 |
| Affiliates | user_id change refused, role removed on delete, unknown payout method on create | P1–P2 |
| Commissions | delete a free commission | P2 |
| Payouts | create with nothing to pay (API), payment/batch lists and filters | P2 |
| Dashboard | **each card's formula against a known data set** | P2 |
| Pro PayPal | **connection source switch (Dokan PayPal Marketplace, ADR-0009)**, webhook delivered twice, ONHOLD/BLOCKED, verify, settings | **P1**–P3 |
| Pro Dokan | vendor program validation, own-affiliate isolation by ID, referrals ledger, history, admin vendor view | P1–P3 |

Highest value next: **API-PP-06** (no test at all, money path), **API-PP-11**, **API-DOK-03 / 06**,
**API-AFF-15**, **API-DSH-01**.
