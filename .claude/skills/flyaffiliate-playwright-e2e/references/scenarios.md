# Scenario catalogue

Every behaviour the ADRs and `CONTEXT.md` promise, as e2e scenarios. IDs are
stable — cite them in test titles (`[TRK-03] an inactive affiliate's link sets no cookie`).
`✓ <spec>` = covered by `tests/pw/specs/<spec>.spec.ts`; no mark = still to write. Product bugs the suite found: `tests/pw/PRODUCT_BUGS.md`. Update this file in the same
change that adds or removes a test. `@money` = in the release money gate.

Status lifecycle under test (CONTEXT.md): commission `pending → unpaid → paid`,
`pending|unpaid → rejected`, `rejected → pending|unpaid`; admin may set any of
the four by hand **unless locked by a payment**. Affiliate: `pending | active |
inactive | suspended`. Payment: `unpaid | paid`. Payout (computed):
`processing | needs_attention | unpaid | paid`.

---

## 1. Tracking & attribution — ADR-0005, ADR-0007, rules 8–9

| ID | Scenario | Expected |
|---|---|---|
| TRK-01 | Visit `/?affiliate=<active id>` | Visit row +1; cookie `flyaffiliate_ref` set, HttpOnly, SameSite=Lax, expiry = `cookie_duration` days. ✓ tracking |
| TRK-02 | Link to any inner page `/shop/?affiliate=<id>` | Visit recorded with that landing URL ✓ tracking |
| TRK-03 | Unknown id / pending / inactive / suspended affiliate | No visit, no cookie ✓ attribution, tracking |
| TRK-04 | Non-numeric / negative / SQL-ish value (`?affiliate=1' OR 1=1`) | Ignored, no PHP error, page renders 200 ✓ tracking |
| TRK-05 | Change `referral_variable` to `ref` | `?ref=<id>` tracks; `?affiliate=<id>` no longer does; restore after ✓ tracking |
| TRK-06 | `cookie_duration = 0` | Session cookie (no `expires`) ✓ tracking |
| TRK-07 | Over HTTPS (if env supports) | Cookie `Secure` |
| TRK-08 | Last click wins: click A then B, buy | Order attributed to B only; one affiliate per order @money ✓ tracking |
| TRK-09 | Cookie cleared between click and checkout | Order not attributed, no commission ✓ attribution |
| TRK-10 | Self-referral blocked (default on): affiliate logged in buys via own link | No commission @money ✓ attribution, tracking |
| TRK-11 | `block_self_referral` off | Commission created for own purchase; restore ✓ attribution |
| TRK-12 | Visit row privacy | REST/DB visit has `ip_hash`/`user_agent_hash`, no raw IP/UA anywhere; referrer and landing URL stored as-is ✓ tracking |
| TRK-13 | Converted visit | After attributed checkout the visit shows converted on Visits list ✓ tracking |
| TRK-14 | JS cannot read cookie | `page.evaluate(() => document.cookie)` lacks `flyaffiliate_ref` ✓ tracking |
| TRK-15 | Cookie refused, WC session fallback | With cookie blocked after landing in same session, attribution still holds via WC session |
| TRK-16 | Repeated clicks same visitor | Each click records (or dedupes per implementation — assert what code does, then pin it) ✓ tracking |

## 2. WooCommerce checkout → commission — rules 1–4, ADR-0009 §3, ADR-0014

| ID | Scenario | Expected |
|---|---|---|
| WC-01 | Referred classic checkout, 1 item $100, default rate | One `pending` commission (or `unpaid` if order already paid), amount = base × rate in cents @money ✓ attribution |
| WC-02 | Referred **block** checkout | Same as WC-01 @money ✓ money-journey |
| WC-03 | Multi-item cart (3 lines, qty>1) | One commission per order item, each `line_total × rate` @money ✓ attribution |
| WC-04 | Product rate `_flyaffiliate_rate` overrides default | That item uses product rate; others default @money ✓ attribution |
| WC-05 | Product rate > `max_rate` | Clamped to max @money ✓ attribution |
| WC-06 | `max_rate` lowered after product rate saved | New orders clamp to new max ✓ attribution |
| WC-07 | `exclude_shipping` on/off | Base excludes/includes shipping ✓ attribution |
| WC-08 | `exclude_tax` on/off (taxes enabled) | Base excludes/includes tax |
| WC-09 | Coupon discount on item | Base is discounted line total ✓ attribution |
| WC-10 | `rate_type` stored | Row records `percentage` (ADR-0009 §3) ✓ attribution |
| WC-11 | Order paid at checkout (card-like gateway → processing) | Commission `unpaid` right after checkout, whatever hold (ADR-0014 §1) ✓ attribution |
| WC-12 | COD order → processing | Stays `pending`; → completed makes `unpaid` ✓ attribution |
| WC-13 | `matures_at` = created + `hold_days` | REST/UI "Payable from" date matches ✓ attribution |
| WC-14 | Change `hold_days` after order | Existing commission's `matures_at` unchanged; next order uses new hold (ADR-0014 note 2026-10-20) ✓ attribution |
| WC-15 | Non-referred order | No commission ✓ attribution |
| WC-16 | `woocommerce_enabled` off | Referred order creates no commission; restore ✓ attribution |
| WC-17 | Same checkout hook replayed / order saved twice | Still one commission per order item (keyed `order_item_id`) @money ✓ attribution |
| WC-18 | Zero-price item | Commission 0 or none — pin current behaviour |
| WC-19 | WC Analytics → Overview | "Affiliate Commission" box shows sum of counted orders' commissions |
| WC-20 | Commission `note` | Shown as info tip on Commissions list and affiliate dashboard |

## 3. Order status sync — ADR-0014, ADR-0011 (status note), rule 7

| ID | Scenario | Expected |
|---|---|---|
| SYN-01 | pending → processing / completed | `pending` → `unpaid` @money ✓ order-sync |
| SYN-02 | → failed / cancelled / trashed | `pending` and `unpaid` → `rejected` @money ✓ order-sync |
| SYN-03 | → refunded, `reject_commissions_on_refund` off (default) | Unchanged @money ✓ order-sync |
| SYN-04 | → refunded, switch on | `pending`/`unpaid` → `rejected`; restore switch @money ✓ order-sync |
| SYN-05 | Rejected order → processing/completed | `rejected` → `unpaid` (whoever rejected) ✓ order-sync |
| SYN-06 | failed/cancelled/refunded → on-hold / pending payment | Restored to `pending` ✓ order-sync |
| SYN-07 | Untrash an order | Restored per rule |
| SYN-08 | Status change fired twice (same status) | No double transition, no duplicate row @money ✓ order-sync |
| SYN-09 | Commission in an **unpaid payment**, order cancelled | Commission untouched (locked); refusal reported, not silent @money ✓ order-sync |
| SYN-10 | `paid` commission, order refunded | Untouched — never reversed @money ✓ order-sync |
| SYN-11 | Manual-origin commission referencing the order | Never moved by the order ✓ order-sync |
| SYN-12 | WC-origin commission added by hand | Follows its order like an attributed one ✓ order-sync |
| SYN-13 | Partial refund | Nothing changes automatically (rescaling not built); admin can edit amount ✓ order-sync |

## 4. Commissions admin — ADR-0011 (amended), ADR-0012, rule 7

| ID | Scenario | Expected |
|---|---|---|
| COM-01 | List: status tabs with counts, ID → edit link, affiliate → affiliate link | ✓ commissions |
| COM-02 | Add manual commission, status Pending | Created pending; tab count +1. ✓ commissions |
| COM-03 | Add form default status | Paid (SliceWP default) |
| COM-04 | WC origin with non-existent order | Toast "no WooCommerce order #…"; nothing stored. ✓ commissions |
| COM-05 | WC origin with existing order | Created |
| COM-06 | Manual commission stays as given | Pending one never auto-becomes unpaid (rule 4) |
| COM-07 | Validation: empty affiliate, negative / non-numeric amount, >2 decimals | Inline errors, no request or 400 |
| COM-08 | Edit: ID, affiliate, origin, date disabled; amount editable | ✓ commissions |
| COM-09 | Edit amount of WC-origin unpaid commission | Sticks; rate re-derived; no recalculation later @money |
| COM-10 | Status transitions via row actions: every allowed pair incl. rejected → pending/unpaid | Moves, toast, tab counts shift |
| COM-11 | Commission in unpaid payment: edit amount | Allowed; payment re-sums (`resync`) @money ✓ payout-rules |
| COM-12 | Commission in paid payment: edit amount | Commission changes; payment keeps paid amount @money ✓ payout-rules |
| COM-13 | Commission in a payment: Delete | Not offered in row menu; REST DELETE refused.commissions.spec ✓ payout-rules |
| COM-14 | Paid-by-hand commission (no payment) | Editable, movable, deletable ✓ payout-rules |
| COM-15 | Bulk select → toolbar → bulk delete / bulk status | ✓ commissions |
| COM-16 | Filters/search/pagination/sort | Results and `X-WP-Total` consistent |
| COM-17 | Note column info tip | Visible when note set |

## 5. Payouts & payments — ADR-0006, ADR-0012, ADR-0016, rule 7

| ID | Scenario | Expected |
|---|---|---|
| PAY-01 | Preview → create → mark all paid | Commissions stay unpaid until marked; then paid.@money ✓ payout-rules |
| PAY-02 | Preview excludes immature unpaid commissions | Shown as *held* with count (`flyaffiliate-payout-held`) @money ✓ payout-rules |
| PAY-03 | Preview excludes `pending`, `rejected`, `paid`, and any with `payout_id` | Reported as pending where applicable @money ✓ payout-rules |
| PAY-04 | `minimum_amount` threshold | Affiliates below it left out ✓ payout-rules |
| PAY-05 | Date range filter | Only commissions in range ✓ payout-rules |
| PAY-06 | One payment per affiliate per batch, amount = sum of their commissions in cents @money ✓ payout-rules |
| PAY-07 | Amounts changed between preview and confirm (edit a commission in another tab) | Create refused (`expected` mismatch), nothing attached @money ✓ payout-rules |
| PAY-08 | Two payouts created concurrently (two contexts, same preview) | Each commission attached once; loser unwinds whole batch @money ✓ payout-rules |
| PAY-09 | Commission in unpaid payment not offered to a second preview @money ✓ payout-rules |
| PAY-10 | Mark single payment paid | Only its still-`unpaid` commissions → paid ✓ payout-rules |
| PAY-11 | Mark paid twice | No change second time @money ✓ payout-rules |
| PAY-12 | Mark paid payment unpaid | Only the commissions it paid return to unpaid; stay attached @money ✓ payout-rules |
| PAY-13 | Remove commission from unpaid payment | `payout_id` cleared, payment amount reduced @money ✓ payout-rules |
| PAY-14 | Remove from paid payment | Refused ✓ payout-rules |
| PAY-15 | Delete unpaid payment | Commissions detached, available to next preview @money ✓ payout-rules |
| PAY-16 | Delete batch with any paid payment | Refused ✓ payout-rules |
| PAY-17 | Delete batch all unpaid | Gone; commissions freed ✓ payout-rules |
| PAY-18 | CSV export link | `flyaffiliate_payout_csv` downloads; rows match payments; nonce required; non-admin refused ✓ payout-rules |
| PAY-19 | All-payments list (`#/payouts/payments`) | Lists payments across batches |
| PAY-20 | Payout status summary | `unpaid` with manual left, `paid` when all paid ✓ payout-rules |
| PAY-21 | With only Manual registered | One preview group, no method picker on dashboard, setting has one option (ADR-0016) ✓ payout-rules |
| PAY-22 | Affiliate's `payment_email` appears in payment/CSV ✓ payout-rules |
| PAY-23 | Hold 0 | Completed order commission immediately payable ✓ money-journey |
| PAY-24 | Locked payment (needs a test method registered via mu-plugin fixture with state `processing`) | Delete, remove, mark paid/unpaid, edit its commissions all refused (ADR-0016) |
| PAY-25 | Method not registered (mu-plugin removed) | Payment locked; payout `needs_attention` |
| PAY-26 | Failed transfer | Retry available; mark paid by hand records Manual |
| PAY-27 | "Switch to Manual" override on preview | Payment created with method manual |

PAY-24..27 need a fixture mu-plugin under `tests/pw/fixtures/` mapped through
`.wp-env.override.json` `mappings` that registers a fake method on
`flyaffiliate_payout_methods` with a state controlled by an option. Only build
it when writing those tests.

## 6. Affiliates admin — ADR-0009 §1, ADR-0015

| ID | Scenario | Expected |
|---|---|---|
| AFF-01 | List tabs by status, search | ✓ affiliates |
| AFF-02 | Add affiliate for existing user, status active | Appears; user gets `flyaffiliate_affiliate` role |
| AFF-03 | Same user twice | 409 conflict toast |
| AFF-04 | "Send welcome email" ticked/unticked | Email sent only when ticked (check via mail catcher or `wp_mail` log mu-plugin) |
| AFF-05 | Status change active → inactive → suspended → active | Tracking obeys each (TRK-03) |
| AFF-06 | Affiliate page: referral links list read-only, figures | Admin cannot add/remove |
| AFF-07 | Delete affiliate | Their saved referral links deleted; commissions & visits kept |
| AFF-08 | Profile screen (user-edit.php) section | Renders, saves |

## 7. Registration & activation — ADR-0009 §1, ADR-0013

| ID | Scenario | Expected |
|---|---|---|
| REG-01 | Logged-out signup on register page (email + promo) | Affiliate `pending`; activation email sent ✓ registration |
| REG-02 | Activation link `?flyaffiliate_activate=…` | Becomes active; second use refused (one-time) ✓ registration |
| REG-03 | Tampered/expired key | Refused, no status change ✓ registration |
| REG-04 | `activation_email_enabled` off | Created pending "review"; no email; admin activates ✓ registration |
| REG-05 | Honeypot `flyaffiliate_website` filled | Rejected silently, no affiliate ✓ registration |
| REG-06 | `registration_enabled` off | Form not shown / submission refused ✓ registration |
| REG-07 | Already affiliate submits again | Friendly message, no duplicate ✓ registration |
| REG-08 | Logged-in non-affiliate signup | Email readonly, uses own account ✓ registration |
| REG-09 | Invalid email / missing nonce | Error, nothing stored ✓ registration |

## 8. Affiliate dashboard (`[flyaffiliate_dashboard]`) — ADR-0008, ADR-0010, ADR-0015, ADR-0016

| ID | Scenario | Expected |
|---|---|---|
| DSH-01 | Logged out | Login template, no app ✓ affiliate-dashboard |
| DSH-02 | Logged-in non-affiliate | "Not an affiliate" template with signup ✓ affiliate-dashboard |
| DSH-03 | Pending affiliate | Pending template ✓ affiliate-dashboard |
| DSH-04 | Active: Overview figures | Match REST `me` totals (cents) @money ✓ affiliate-dashboard |
| DSH-05 | Own referral link + generator on Overview | Generated link = page + `?<referral_variable>=<id>` ✓ affiliate-dashboard |
| DSH-06 | Save generated link | Appears in Referral links tab; saving same page twice returns the first (idempotent) ✓ affiliate-dashboard |
| DSH-07 | Remove from list | Confirm says link keeps working; removed; link still tracks (ADR-0015) ✓ affiliate-dashboard |
| DSH-08 | Saved link figures | Visits/conversions counted from visits table by landing URL; re-save restores history ✓ affiliate-dashboard |
| DSH-09 | Change `referral_variable` | Saved links render with new variable ✓ affiliate-dashboard |
| DSH-10 | Commissions / visits / payouts tables | Only own rows; unpaid payments shown as on their way ✓ affiliate-dashboard |
| DSH-11 | Settings tab: payment email, promo method | Saves via `PUT /me` ✓ affiliate-dashboard |
| DSH-12 | No withdraw button anywhere (ADR-0006) ✓ affiliate-dashboard |
| DSH-13 | Hold note when supplied (`program.holdNote`) |
| DSH-14 | Transfer failure message never shown to affiliate (ADR-0016) |
| DSH-15 | Theme fit: no horizontal scroll at 375px; popups inside viewport ✓ affiliate-dashboard (test.fail: BUG-001/003) |

## 9. Permissions & security — ADR-0008, ADR-0003

| ID | Scenario | Expected |
|---|---|---|
| SEC-01 | Subscriber/shop_manager opens `admin.php?page=flyaffiliate` | Denied ✓ security |
| SEC-02 | Every admin REST route as logged-out and as affiliate | 401/403 (loop over affiliates, commissions, visits, payouts, settings, setup, dashboard, referral-links) ✓ security |
| SEC-03 | `flyaffiliate_admin_capability` filtered to `manage_woocommerce` (mu-plugin fixture) | shop_manager gets in ✓ admin-shell |
| SEC-04 | Affiliate A requests `/me/...` | Only A's rows; cannot pass `affiliate_id` to see B ✓ affiliate-dashboard |
| SEC-05 | Affiliate deletes another affiliate's referral link id | 403/404 ✓ affiliate-dashboard |
| SEC-06 | Stored XSS: affiliate promo method / payment email / payout note `<img src=x onerror=…>` | Rendered escaped in admin & dashboard; no dialog fires (listen `page.on('dialog')` → fail) ✓ security |
| SEC-07 | REST without nonce from logged-in cookie | 401 `rest_cookie_invalid_nonce` ✓ security |
| SEC-08 | `admin_post_flyaffiliate_*` without nonce | Refused |
| SEC-09 | No external requests from admin/dashboard pages | `page.on('request')` sees only same-origin (ADR-0013 build strips remote hosts) ✓ security |
| SEC-10 | No PHP notices/warnings | `wp-content/debug.log` gains no `flyaffiliate` lines during the suite (check in globalTeardown) |
| SEC-11 | No console errors on every admin route and the dashboard | `page.on('console')` error count 0 ✓ security (nested-button part test.fail: BUG-003) |

## 10. Settings — ADR-0010, ADR-0014

| ID | Scenario | Expected |
|---|---|---|
| SET-01 | Every page/subpage renders (general/tracking, currency_display, affiliate_area, data; commission/rates, rules; payout/payout_rules; integrations/woocommerce; email/email_notifications) | ✓ settings |
| SET-02 | Save persists across reload | Value from `GET /settings` ✓ admin-shell |
| SET-03 | `default_rate` > `max_rate` | Inline error, 400, nothing stored ✓ settings |
| SET-04 | Empty `referral_variable` | Required error ✓ admin-shell |
| SET-05 | Negative `hold_days` / `cookie_duration` | Refused ✓ admin-shell |
| SET-06 | Save button disabled until dirty; disabled after save | |
| SET-07 | Switch values stored `'on'/'off'` | Read via REST ✓ admin-shell |
| SET-08 | Hold-days help says change applies to new orders |
| SET-09 | Currency/position/separators | Amounts in lists and dashboard formatted accordingly |
| SET-10 | `method` select lists every registered method |
| SET-11 | Integrations → WooCommerce subpage with WC inactive | Present, says WooCommerce is not active ✓ standalone |
| SET-12 | Shortcode copy fields | Copy `[flyaffiliate_dashboard]` / register shortcode |

## 11. Setup wizard, admin shell, notices — ADR-0010, ADR-0017

| ID | Scenario | Expected |
|---|---|---|
| SHL-01 | Every submenu entry → hash route, heading correct | ✓ navigation |
| SHL-02 | Unknown hash route | Not-found route, no crash ✓ navigation |
| SHL-03 | Old per-screen slugs redirect to hash routes |
| SHL-04 | TopBar: logo, version, Documentation, Get support |
| SHL-05 | Another plugin's `admin_notices` (mu-plugin fixture) hidden on FlyAffiliate screen, visible on Dashboard/Plugins (ADR-0017) ✓ admin-shell |
| SHL-06 | FlyAffiliate's own notice visible above app (`flyaffiliate_before_admin_app`) |
| SHL-07 | Exactly one `.wp-header-end` on the page ✓ admin-shell |
| SHL-08 | Setup wizard: continue/back/skip/finish saves settings, `POST /setup/complete` marks done | ✓ setup |
| SHL-09 | Extensions page without Pro: preview cards, search, category, upgrade dialog | |
| SHL-10 | Admin dashboard tiles, date range presets, calendar, chart, lists | ✓ dashboard |

## 12. Standalone & lifecycle — ADR-0013, ADR-0001

| ID | Scenario | Expected |
|---|---|---|
| STD-01 | WooCommerce deactivated: plugin activates, admin app loads, no fatal | ✓ standalone |
| STD-02 | Without WC: add manual commission, payout, dashboard all work | ✓ standalone |
| STD-03 | Without WC: WC-origin commissions keep rows, editable, reference shown as plain number, payable | ✓ standalone |
| STD-04 | Without WC: currency select uses built-in list | ✓ standalone |
| STD-05 | Reactivate WC | Pending WC commissions resume following orders ✓ standalone |
| STD-06 | Deactivate plugin | Daily hook cleared; reactivate restores; data intact |
| STD-07 | Uninstall with `data_clear_on_uninstall` off | Tables and option kept |
| STD-08 | Uninstall with it on (throwaway env) | Tables, option, pages, role removed |
| STD-09 | Fresh activation | Tables created, dashboard/register pages created, wizard prompted |
| STD-10 | Release zip install (not dev tree) | Activates, admin.js/dashboard.js load (no 404 assets), no `src/` shipped |

## 13. Cross-cutting UX

| ID | Scenario |
|---|---|
| UX-01 | Every destructive action asks confirmation (`alertdialog` / `flyaffiliate-confirm`) and Cancel does nothing |
| UX-02 | Toast on every save/delete, error toast on REST failure (route.abort a request) |
| UX-03 | Empty states on each list with zero rows (fresh filter) |
| UX-04 | Keyboard: dialogs trap focus, Esc closes, actions reachable by Tab |
| UX-05 | Narrow admin viewport (782px) and dashboard mobile (375px) layouts |
| UX-06 | RTL locale smoke (`wp language core install ar && wp site switch-language ar`) on main screens |

---

## 14. Negative paths (every scenario above has its refusal)

Rule: for each happy path there is at least one test where the input is
wrong, the actor is wrong, or the state forbids it — and the test asserts
both the refusal (status/code/message) **and** that nothing was written.

| ID | Scenario | Expected |
|---|---|---|
| NEG-01 | Affiliates API: bad status/email/user, duplicate user, unknown id, bad paging/order/status, SQL in search | 400/404/409, no row ✓ negative-rest |
| NEG-02 | Commissions API: zero/negative/sub-cent/non-numeric amount, bad status/type/source/date, missing WC order, unknown id | 400/404, row count/row unchanged ✓ negative-rest |
| NEG-03 | Read-only routes: delete a visit, admin POST/DELETE on referral links, bad dashboard dates | 404/400 ✓ negative-rest |
| NEG-04 | Payout preview/create: bad mode/minimum/dates/ids, impossible date, inverted range, `selected` with none, `except`, unknown method override | 400 or empty preview ✓ negative-rest (impossible date: BUG-008) |
| NEG-05 | Payment/batch actions on unknown ids; bad/missing status; retry of manual payment; foreign/unknown commission removal; last commission removed | 404/400/409 ✓ negative-rest (BUG-006, BUG-007) |
| NEG-06 | Settings API: unknown scope, malformed values, field from another scope, unknown field, unknown select choice, invalid switch, markup in referral variable, reserved query var | 404/400/ignored, option unchanged ✓ negative-rest (BUG-004, BUG-005) |
| NEG-07 | CSV export: guest, affiliate, forged nonce, unknown batch; formula injection in cells | refused/escaped ✓ negative-rest |
| NEG-08 | Orders that earn nothing: free item, 100% coupon, product rate 0/negative, default 0, max 0, deleted/pending/inactive/suspended affiliate, forged/malformed cookie | no commission row ✓ negative-money |
| NEG-09 | Rate limits and rounding: rate = max kept, > max clamped, sub-cent items, thirds, fractional rate | exact cents ✓ negative-money |
| NEG-10 | Status changes that must not move: on-hold/pending, unreferred order, paid-in-payment commission vs every order status, rejecting status fired twice | ledger unchanged ✓ negative-money |
| NEG-11 | Payout boundaries: minimum inclusive, future-dated held, reject inside unpaid payment re-sums, paid payment's commissions undeletable and amount fixed, deleted affiliate's records kept | ✓ negative-money |
| NEG-12 | `me` routes: bad payout method/email, protected fields, foreign/garbage links, unknown ids, bad paging, no nonce; guest 401, pending/inactive/suspended/admin 403 on every route | ✓ negative-affiliate (BUG-009) |
| NEG-13 | Abusive links: array param, deleted affiliate, forged cookie with and without click, huge/repeated values, `p` as variable | ✓ negative-affiliate (BUG-002, BUG-004) |
| NEG-14 | Dashboard generator: empty, foreign, `javascript:`, malformed | message shown, nothing saved ✓ negative-affiliate |
| NEG-15 | Signup: malformed emails, existing affiliate in other letter case, expired activation link | ✓ negative-affiliate |
| NEG-16 | Admin UI: commission form refusals, server error mid-save, unknown ids (affiliate/commission/payout/payment), new-payout refusals incl. stale preview, cancelled confirmations, settings refusals | ✓ negative-admin-ui (BUG-010, BUG-011) |

## 15. Dokan marketplaces — FlyAffiliate Pro's Dokan extension (Pro ADR-0006, ADR-0008)

Dokan support ships in **FlyAffiliate Pro** (`includes/Extensions/Dokan/`), on
FlyAffiliate's seams; free `develop` alone has none (its `feature/dokan-integration`
branch is an abandoned copy). Run in Pro's paired env — see SKILL.md "Dokan".
Worked money: $100 vendor sale, Dokan admin 20 %, affiliate 15 % → vendor nets
$65, affiliate $15, marketplace keeps $20.

| ID | Scenario | Expected |
|---|---|---|
| DOK-EXT-01 | Switching the extension on | `reject_commissions_on_refund` turned on once; an admin turning it off later is not overruled ✓ dokan |
| DOK-EXT-02 | No valid Pro license | Extension refuses to switch on (`flyaffiliate_pro_license_required`) ✓ dokan |
| DOK-RATE-01 | Opted-in vendor, no Dokan default | FlyAffiliate default rate; source `flyaffiliate_default` on the order line ✓ dokan |
| DOK-RATE-02 | Dokan Default Affiliate Rate set | Replaces FlyAffiliate's for vendor sales; source `dokan_default` ✓ dokan |
| DOK-RATE-03/04 | Vendor's own rate, Allow Vendor Rates on/off | Own rate (source `vendor`) only while allowed; earlier commissions keep their rate ✓ dokan |
| DOK-RATE-05 | Own rate above Dokan's maximum | Capped; source records `capped`, `uncapped` ✓ dokan |
| DOK-RATE-06 | Vendor not opted in | No commission on their items ✓ dokan |
| DOK-RATE-07 | `_flyaffiliate_rate` on a vendor product | Ignored: one rate per vendor ✓ dokan |
| DOK-RATE-08 | Vendor programs switched off | No vendor commissions ✓ dokan |
| DOK-MV-01 | Mixed cart, opted-in + not | Commission per item for the opted-in vendor only; rows on the parent, none on sub-orders ✓ dokan |
| DOK-EARN-01 | Vendor pays | `dokan_orders.net_amount` 65.00 for the opted-in vendor; 40.00 (full 80 %) for the other ✓ dokan |
| DOK-EARN-02 | Re-saving orders | Deduction recomputed, never stacked ✓ dokan |
| DOK-EARN-03 | Commission larger than vendor's earning | Deduction capped at the earning (net 0, not negative) ✓ dokan |
| DOK-SUB-01 | One vendor's sub-order cancelled | Only that vendor's commission rejected; other vendor untouched ✓ dokan |
| DOK-SUB-02 | COD: one vendor's sub-order completed | Only theirs becomes unpaid ✓ dokan |
| DOK-SUB-03 | Sub-order fully refunded | Follows `reject_commissions_on_refund` (on → rejected, off → stays) ✓ dokan |
| DOK-SUB-04 | Parent completed after a sub-order was cancelled | Cancelled vendor's commission stays rejected ✓ dokan |
| DOK-SUB-05 | Paid commission, sub-order refunded | Stays paid; order note says the vendor absorbs it ✓ dokan |
| DOK-HOLD-01 | Hold order | Vendor's own (while allowed) → Dokan's Hold Period → FlyAffiliate's ✓ dokan |
| DOK-SEC-01 | `flyaffiliate-pro/v1/dokan/vendor/program` | Guest 401, affiliate 403, vendor 200 ✓ dokan |
| DOK-SEC-02 | Vendor's affiliates list | Only affiliates who sold for this vendor ✓ dokan |
| DOK-UI-01 | Product page earn card | Active affiliate sees rate on opted-in vendor's product; not on others; guest never ✓ dokan |
| DOK-UI-02 | Vendor dashboard → Affiliates | "Start my affiliate program" before opting in; "List of Affiliates" with the affiliate after ✓ dokan |
| DOK-OFF-01 | Extension switched off | Existing deduction stands; new vendor sales earn nothing ✓ dokan |
| DOK-JOURNEY | Guest, two vendors, real block checkout (COD) | Parent + 2 sub-orders; one commission (opted-in vendor, $15); completed sub-order → unpaid; nets 65 / 40 ✓ dokan |
| DOK-RW-01 | COD + reverse withdrawal (ADR-0008 §5a) | Affiliate part of the debt equals standing commissions; credited back on reject | |
| DOK-REF-01 | Dokan Pro vendor refund request approved | Counts once approved; partial refund never changes the commission | |
| DOK-REP-01 | Dokan reports / Admin Earnings | Affiliate Earnings card, tips, CSV column | |
| DOK-ADM-01 | Admin vendors list column/filter, vendor profile Affiliates tab | Shows program state | |
| DOK-HIST-01 | Admin changes rates | Rate history entry + notice per opted-in vendor | |
| DOK-STORE-01 | Store page notice and shop-loop rate line | Affiliate only; hold shown when it differs | |
| DOK-VSET-01 | Vendor Store Settings opt-in / own rate / own hold form | Validates rate ≤ max; saved to `_flyaffiliate_pro_dokan_*` | |

## End-to-end money journey (the one test that must never break) @money @smoke — ✓ money-journey

1. Admin: `hold_days = 0`, default rate 10%, COD enabled; product $100 with
   `_flyaffiliate_rate` 15.
2. Fresh active affiliate A (own user, own session).
3. Guest context: `/?affiliate=A` → add product → block checkout → place COD order.
4. Commission exists, `pending`, 1500 cents, `vendor_id` 0, `matures_at` = created.
5. Order → completed ⇒ `unpaid`. Fire completed again ⇒ unchanged.
6. New payout preview shows A with 1500 ⇒ create ⇒ payment unpaid, commission
   still unpaid with `payout_id`.
7. Order → cancelled ⇒ commission untouched (locked).
8. Mark payment paid ⇒ commission paid. Mark paid again ⇒ unchanged.
9. A's dashboard: paid total +1500, payout history shows it, visit converted.
10. Order → refunded (switch on) ⇒ paid commission untouched.
