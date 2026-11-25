# FlyAffiliate – Product Requirements Document

**Version:** 1.0  
**Status:** In Development  
**Last Updated:** 2026-08-24  
**Owner:** @anik-fahmid  

---

## Vision

FlyAffiliate is a WordPress affiliate marketing plugin for WooCommerce with optional Dokan integration. 

**Core product (runs standalone):** Merchants (single or multi-vendor) enable affiliate programs where customers earn commissions on referred sales. Core features work on any WP+WooCommerce site: affiliate registration, referral links, commission tracking, balance ledger, hold periods, payouts via WP-native withdrawal methods.

**Dokan integration (optional, layered on):** When Dokan is active, each vendor independently runs their own affiliate program (not marketplace-wide). Vendors control rates, see earnings, approve affiliates, and receive payouts via Dokan's withdrawal infrastructure. This removes the need for third-party platforms like SliceWP; Dokan gets affiliate-as-a-service natively.

Unlike generic platforms (SliceWP, AffiliateWP, FluentAffiliate), FlyAffiliate is lightweight, wp.org-native, and integrates directly with Dokan's vendor model, refund logic, and payment gateways.

**Customers:** 
- Single-merchant WooCommerce stores wanting basic affiliate programs
- Dokan multivendor marketplaces wanting vendor-run affiliate programs without external dependencies

---

## Core Features (Standalone, Any WP+WooCommerce)

### 1. Affiliate Program Control
- Site admin toggles affiliate program on/off in wp-admin FlyAffiliate → Settings
- When enabled: public registration open; affiliates can join
- When disabled: new registrations close, existing affiliates frozen
- Settings persist in wp_options

### 2. Affiliate Registration & Management
- Public registration form (embed on page, shortcode, or standalone page)
- Email signup; no password required (one-time activation link via email)
- Duplicate prevention: one WordPress user = one affiliate record sitewide
- Affiliate dashboard shows:
  - Unique referral link (parameterized, GET params only)
  - QR code
  - HTML embed snippet
  - Link copy-to-clipboard

### 3. Referral Link Format & Tracking
- Format: `/?affiliate=AFFILIATE_ID` (base case, single-merchant)
- Link survives cart abandonment + checkout redirect
- UTM param preservation (append, don't replace)
- Tracking cookie-free (GET params persist in session/cart)

### 4. Commission Calculation
- **Formula:** `item_total × commission_rate`
- **Rate hierarchy** (first match wins):
  1. Product-level override (via product meta)
  2. Site-wide default (admin setting, e.g., 10%)
  3. Clamped to admin max cap (e.g., no override > 30%)
- Rate is percentage (e.g., "15%" = 0.15)
- Calculation fires on order creation (before any refunds)

### 5. Commission Ledger & Balance
- Commission created as `pending` when order placed
- Hold period (configurable, default 30 days) keeps commission `pending`
- After hold, cron job marks commission `unpaid` and affiliate balance matures
- Affiliate can withdraw once balance matures (method depends on site setup)
- Admin can manually mature a commission early
- Refund handling: if item refunded → affiliate's commission rescaled proportionally

### 6. Admin Dashboard (wp-admin)
- FlyAffiliate menu:
  - **Settings:** default rate, max cap, hold period, enable/disable
  - **Affiliates:** list all, approve new (if Phase 2 approval enabled), deactivate
  - **Commissions:** all commissions, filter by status (pending/unpaid/paid/rejected)
  - **Reports:** top affiliates, top products, payouts, earnings trends
- Manual Actions: mature commission early, reject commission, export CSV

### 7. Affiliate Dashboard (Frontend)
- Shortcode or custom WP page shows:
  - Total earned (all-time, this month, pending)
  - Referral link + QR code
  - Earnings table: date, referred product, amount, status
  - Withdrawal balance + withdrawal method setup link
  - Performance graph (referrals/conversions/earnings over time)

---

## Dokan Integration (Optional Layer)

When Dokan is active, core affiliate functionality extends to vendor-run programs:

### Vendor-Run Programs
- Each vendor independently toggles affiliate participation in vendor dashboard → Store Settings → Affiliates
- Program state lives in vendor `user_meta` (per-vendor, not sitewide)
- No marketplace admin approval needed
- Toggle on = vendor accepts affiliates for their products; toggle off = freeze existing

### Vendor-Specific Rates
- Each vendor sets default commission rate for their products (override admin global default)
- Per-product rate override (vendor can set rate on individual products)
- Rate hierarchy: product → vendor → admin default, clamped to admin max
- Vendor store Settings tab controls their rate defaults

### Vendor Dashboard
- Vendor sees Affiliate Earnings Report under Store Settings → Affiliates:
  - Total earned (all-time, this month, pending)
  - Affiliate list: name, email, total commission, items referred, status
  - Date range filter
- Vendor can manually mature commissions early, reject, or review audit trail

### Multi-Vendor Commission Tracking
- One cart referral → splits into sub-orders per vendor
- Commission breakdown: each vendor gets attributed commission only for their items
- Tracking survives Dokan sub-order split (attribute by sub-order, not parent order)
- Display: vendor earnings show commission as deduction from their net (transparent)

### Balance Integration with Dokan
- Commission matures into Dokan `dokan_vendor_balance` CREDIT entry
- `trn_type = 'dokan_affiliate_commission'`
- Affiliate (usually vendor or third party) can withdraw via Dokan withdrawal methods
- Withdrawal gated by `Vendor::get_balance()` (includes affiliate credits)

### Refund Sync
- Hooks into `woocommerce_order_refunded` and `woocommerce_order_status_changed`
- On refund:
  - If commission not yet paid: scale proportionally
  - If commission already paid: vendor absorbs (no reversal charge)
  - If full refund: reject commission entirely
- Handles Dokan sub-order refunds correctly (attribute to right vendor)

### Gateway Payout Sync
- When Stripe Connect (or other gateway) transfers to vendor, subtract affiliate share from transfer amount
- Filter `flyaffiliate_gateway_transfer_amount` fires before transfer
- Webhook `charge.updated` resolves by order ID (stateless, works with async transfers)
- Compatible with Stripe Connect PR #5646 (PaymentIntents + Transfers)

### Vendor Isolation
- Affiliates are per-vendor relationships (many-to-many)
- Vendor A's affiliates don't see Vendor B; vice versa
- Commission only paid when that vendor's affiliate program is active
- No sitewide vendor roster or approval queue

---

## Business Model & Packaging

### Positioning
- **Free plugin** on wp.org (no freemium upsell in Phase 1)
- Target: WooCommerce stores wanting basic affiliate programs; Dokan marketplaces wanting vendor-run programs
- Standalone core runs on any WP+WooCommerce site; Dokan integration optional

### Release & Updates
- **Phase 1:** core affiliate features (core features 1–7) + Dokan integration (optional layer) + basic admin/affiliate dashboards
  - Launch date: 2026-10-30
  - Free on wp.org
- **Phase 2 (future):** vendor approval rosters, affiliate tier systems, advanced reporting, email notifications
- Updates follow WordPress.org cycle (auto-updates on client sites)

### Support Scope
- Support SliceWP free version (for feature parity reference on Dokan integration only)
- **Untested Phase 1:** gateway live transfers (Stripe API keys needed; test harness sufficient)
- **Not supported:** SliceWP add-ons (Multi-level, Product Commission Rates)
- **Not supported Phase 1:** affiliate profile pages, per-affiliate-tier rates (Phase 2+)

---

## Success Metrics

### Core Plugin (All Installs)
- **Adoption:** % of WooCommerce sites enabling affiliate programs within 30 days of install
- **Affiliate signup rate:** avg affiliates registered per enabled site
- **Commission rate:** $ commissioned per enabled site per month
- **Plugin rating:** ≥4.5 stars on wp.org by Month 6
- **Support load:** avg support threads per 1k active installs (target: <3)

### Dokan Integration
- **Vendor participation:** % of Dokan vendors with affiliate program enabled
- **Affiliate registrations:** avg affiliates per participating vendor
- **Commission velocity:** days from commission created to payout
- **Refund accuracy:** % of refunds that correctly rescale affiliate share

---

## Scope & Non-Goals

### In Scope (Phase 1)
- Vendor-funded commission model only
- Per-item calculation
- Single affiliate per WP user
- Auto-approval (no vendor roster)
- Hold period + cron maturation
- Dokan balance integration
- Refund rescaling
- Basic admin/vendor reporting

### Out of Scope (Phase 1, Phase 2+)
- ❌ Marketplace-funded commissions (vendor sets rate but marketplace pays)
- ❌ Multi-level/hierarchical commissions (Vendor A's affiliates earning on Vendor B)
- ❌ Affiliate tier systems (bronze/silver/gold with escalating rates)
- ❌ Vendor approval queues (auto-approve only; manual roster is Phase 2)
- ❌ Affiliate profile pages (vendor controls visibility)
- ❌ Email notifications (Phase 2: welcome, monthly summary, payout)
- ❌ API for third-party integrations (Phase 2)
- ❌ Cookie-based tracking (GET params only; cookies don't survive checkout redirect)
- ❌ Cross-marketplace sync (single WordPress install only)

### Explicitly NOT Supporting
- SliceWP add-ons (Multi-level, Product Rates) — scope statement documented
- Non-Dokan vendors (not a goal; don't optimize for it)
- Affiliate password logins (use WordPress auth only)
- Commission disputes/reversal chains (admin can mature/reject manually)

---

## Technical Design Notes

### Database
- New table: `wp_flyaffiliate_commissions` (affiliate_id, vendor_id, order_id, amount, rate, status, created_at, mature_at)
- New table: `wp_flyaffiliate_affiliates` (affiliate_id, vendor_id, status, created_at, note)
- User meta: `{user}_flyaffiliate_vendor_{vendor_id}_enabled` (vendor toggle)
- User meta: `{user}_flyaffiliate_rate_default` (vendor store rate)
- Post meta on WC products: `_flyaffiliate_rate` (product override)

### Hooks (Planned)
```php
flyaffiliate_commission_created              // new commission
flyaffiliate_commission_calculated           // amount + rate
flyaffiliate_commission_matured              // pending → unpaid → credit created
flyaffiliate_commission_refunded             // rescale on refund
flyaffiliate_affiliate_registered            // new affiliate
flyaffiliate_vendor_program_toggled          // program on/off
flyaffiliate_rate_hierarchy                  // filter commission rate
flyaffiliate_gateway_transfer_amount         // adjust transfer for gateway payout
```

### Dokan Hooks Used
- `dokan_store_profile_saved` (vendor settings)
- `woocommerce_order_refunded` (refund sync)
- `woocommerce_order_status_changed` (status sync)
- Dokan balance API (credit creation + withdrawal)
- Dokan vendor earning filters (if needed for edge cases)

### Compatibility
- WordPress 6.2+
- WooCommerce 7.0+
- Dokan 5.0+ (tested against 5.0.10+)
- PHP 7.4+
- Multisite: supported (vendor meta + commissions per site)

---

## Launch Timeline

| Phase | Scope | Target |
|-------|-------|--------|
| **Kickoff** | Spec freeze, dev setup | 2026-08-25 |
| **Phase 1 Dev** | Core features, admin, vendor dash | 2026-09-30 |
| **QA & Hardening** | Test harness, edge cases, gateway compat | 2026-10-15 |
| **wp.org Submission** | Review + publish | 2026-10-30 |
| **Phase 2 Planning** | Roster, tiers, email | 2026-11-15 |

---

## Dependencies & Risks

### Hard Dependencies
- Dokan 5.0+
- WooCommerce 7.0+
- Active vendor market (low risk — Dokan ecosystem)

### Risk: Gateway Payout Integration
- Stripe Connect PR #5646 in flight; FlyAffiliate filter must land before PR ships
- Mitigation: Phase 1 assumes PR ships first; if delayed, test in isolation with mock transfers

### Risk: Refund Multi-Vendor Logic
- Dokan's sub-order structure changes with major WC versions
- Mitigation: comprehensive test harness (seeded marketplace + refund simulator)

### Risk: Performance at Scale
- Per-item loop in commission calculation; watch for slow checkout at 1000+ cart items
- Mitigation: batch processing for cleanup tasks; profile before Phase 2

---

## Stakeholders & Approval

- **Product:** @anik-fahmid (PM, owner)
- **Engineering:** TBD (dev lead)
- **QA:** TBD (test harness + edge cases)
- **Marketing:** (wp.org messaging + launch announcement)

---

## Appendix: SliceWP R&D Learnings

### What We Learned (Built Aug 2026)
- Per-order commission (SliceWP default) loses vendor attribution in multi-vendor carts → must force per-item
- Affiliate lock period requires plugin-level hold (SliceWP has no filter) → custom `pending` state
- Cache (Dokan) bypasses filters on first read → must write adjusted value into named cache key
- Refund sync needs fresh sub-order reads inside loop → stale meta causes double-logs
- Vendor approval rosters (planned) are out of Phase 1 scope — auto-approve only

### What We're Dropping (Reference Only)
- ❌ SliceWP as a dependency (building native instead)
- ❌ Marketplace-funded model (vendor absorbs all risk)
- ❌ Multi-level commissions (impossible without vendor-affiliate binding)
- ❌ Reverse withdrawal debt collection (doesn't reduce balance; non-payment penalties disproportionate)

### Dokan Internals (Dev Notes)
- `Vendor::get_balance()` — withdrawable; includes all credits (no trn_type filter)
- `Vendor::get_seller_earnings()` — reports only; filters to `trn_type='dokan_refund'`
- `VendorBalanceUpdateHandler` — recalculates on every order update; filter early or use cache write
- `get_earning_from_order_table()` — caches raw value; bypass via named cache key write
- Stripe Connect PR #5646 — switches to PaymentIntents; transfer amount via `get_vendor_earning_subtotal_by_order()`

---

## Document History

| Date | Author | Change |
|------|--------|--------|
| 2026-08-24 | @anik-fahmid | Initial PRD from SliceWP R&D (Phase 1 kickoff) |
