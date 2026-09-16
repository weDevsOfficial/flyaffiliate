# FlyAffiliate MVP PRD
**Phase 1: Core Affiliate + Dokan Integration**

**Version:** 1.0 MVP  
**Status:** Phase 1 Spec  
**Last Updated:** 2026-08-24  
**Owner:** @anik-fahmid  
**Target Launch:** 2026-10-30  

---

## Executive Summary

FlyAffiliate MVP is a lightweight affiliate plugin for WordPress/WooCommerce with native Dokan multivendor support. Phase 1 ships core affiliate tracking (email-only signup, referral links, commission holds, balance ledger) without automated payouts or advanced reporting. Standalone plugin works on any WP+WooCommerce site; Dokan integration optional. FREE tier only; Phase 2 adds PRO features (automated payouts, UTM tracking, advanced reporting).

---

## MVP Scope (Phase 1 Only)

### Core Features (Standalone, Any WP+WooCommerce)

**1. Affiliate Registration**
- Email-only signup (no password)
- One-time activation link via email
- One WordPress user = one affiliate sitewide
- Signup form via shortcode: `[flyaffiliate_register]`
- No approval queue (auto-approve)

**2. Referral Link Generation**
- Unique per affiliate: `/?affiliate=AFFILIATE_ID`
- GET param tracking (cookie-free)
- Copy-to-clipboard, QR code in affiliate dashboard
- UTM param passthrough (not tracked, Phase 2)

**3. Commission Calculation**
- Per-item basis (not per-order)
- Formula: `item_total × commission_rate`
- Rate hierarchy: product meta → admin global default → clamped to admin max
- Calculation fires on order creation (before refunds)

**4. Commission Ledger**
- Created as `pending` on order placement
- Hold period (configurable, default 30 days) keeps `pending`
- Cron job matures to `unpaid` after hold
- Admin can manually mature early

**5. Balance & Withdrawal**
- Affiliate balance = sum of `unpaid` commissions
- Withdrawals via WordPress user withdrawal methods (payment gateway agnostic)
- Refund rescaling: item refunded → affiliate commission scaled proportionally
- Full refund → reject commission entirely

**6. Affiliate Dashboard (Frontend)**
- Shortcode: `[flyaffiliate_dashboard]`
- Shows: referral link, clicks (if tracked), pending/paid balance, earnings table
- Withdrawal balance display
- No UTM/advanced analytics (Phase 2)

**7. Admin Dashboard**
- FlyAffiliate menu in wp-admin
- **Affiliates:** list all, search, sort by earnings
- **Commissions:** filter by status (pending/unpaid/paid/rejected)
- **Visits:** referral tracking table (basic: visit, visitor, referrer, conversion status)
- **Payouts:** manual payout creation only (no automated transfers)
- **Settings:** global rate, max cap, hold period, enable/disable, shortcodes

**8. Admin Settings**
- General: referral currency, CAPTCHA (optional)
- Commissions: default rate (%), max cap (%), hold period (days)
- Registration & Login: enable signup, shortcodes, auto-register toggle
- Payment: manual payout only (no Stripe/PayPal Phase 1)
- Integrations: WooCommerce basic (no custom per-product rates Phase 1)

---

### Dokan Integration (Optional Layer)

**When Dokan is active:**

**1. Vendor-Run Programs**
- Each vendor toggles affiliate participation in Store Settings → Affiliates
- Program state in vendor `user_meta` (per-vendor, not sitewide)
- Vendor sees affiliate list in their store settings
- No marketplace admin control (vendor-independent)

**2. Vendor Commission Rates**
- Vendor can override admin default rate for their products
- Product-level override available in WC product editor (basic, no UI Phase 1)
- Rate hierarchy: product → vendor → admin default (clamped to max)

**3. Vendor Earnings Dashboard**
- Vendor sees commission deductions in their earnings (separate from admin)
- Referral attribution: one affiliate → one vendor per commission
- Multi-vendor carts split into sub-orders; each vendor gets own commission rows

**4. Dokan Balance Integration**
- Commission matures into Dokan `dokan_vendor_balance` CREDIT
- `trn_type = 'dokan_affiliate_commission'`
- Affiliate can withdraw via Dokan's withdrawal methods
- Display: vendor earnings show commission as line item (transparent deduction)

**5. Refund Sync**
- Hooks: `woocommerce_order_refunded`, `woocommerce_order_status_changed`
- On refund: scale affiliate commission proportionally (if unpaid) or vendor absorbs (if paid)
- Multi-vendor: attribute commission rescaling to correct vendor's sub-order

**6. Vendor Isolation**
- Affiliate-to-vendor relationships are many-to-many
- Vendor A's affiliates don't see Vendor B
- Commission only paid when that vendor's program is enabled
- No cross-vendor affiliate rosters

---

## Not in MVP (Phase 2+)

❌ Automated payouts (Stripe, PayPal, etc.)  
❌ Affiliate approval queues (manual roster management)  
❌ Email notifications (welcome, summary, payout)  
❌ Custom fields per affiliate  
❌ UTM/campaign tracking  
❌ Advanced reporting (top affiliates, trends, export)  
❌ Affiliate tier systems  
❌ Multi-level commissions  
❌ Affiliate profile pages  
❌ Affiliate API  

---

## Deliverables

### Code
- Plugin: `flyaffiliate/` (wp.org structure)
- Tables: `flyaffiliate_commissions`, `flyaffiliate_affiliates`
- No external dependencies (WooCommerce + optional Dokan)

### Features (Admin)
- Settings page (8 sections)
- Affiliates list + detail + edit
- Commissions list + edit (paid state locked, others editable)
- Visits table (basic)
- Payouts manual workflow
- Shortcode registration

### Features (Frontend)
- Registration form (email-only, activation link)
- Affiliate dashboard (referral link, balance, earnings)
- Settings link (future: payment method, profile)

### Documentation
- README (setup, usage, Dokan integration)
- Shortcodes reference
- Hooks (extensibility points for Phase 2)

---

## Success Metrics (MVP)

- **Plugin installs:** 500+ by end of Q4 2026
- **Active installs:** 200+ by Month 3
- **Affiliate registration rate:** 10+ per marketplace (Dokan users)
- **Commission accuracy:** 99%+ (no dropped/duplicated commissions)
- **Refund handling:** 100% correct rescaling (verified in test harness)
- **WP.org rating:** 4.0+ stars

---

## Assumptions & Constraints

**Dokan Compatibility**
- Tested against Dokan 5.0.10+ only
- Stripe Connect PR #5646 (PaymentIntents) assumed shipped before FlyAffiliate release
- Multi-vendor attribute recovery via sub-order table (Dokan stable API)

**WooCommerce**
- Assumes standard order/refund flow
- Variable products: commission on item total, not variants separately
- Subscriptions: no recurring commission Phase 1

**Performance**
- Per-item commission loop: assume <1000 items per cart
- Cron job maturation: nightly (86,400+ commissions per run acceptable)
- Cache bypass: write adjusted value to named Dokan cache key

**Browser Storage**
- Affiliate dashboard uses sessionStorage for referral link (not persistent)
- No user data retained client-side

---

## Risks & Mitigations

| Risk | Impact | Mitigation |
|------|--------|-----------|
| Stripe Connect PR #5646 delayed | Dokan payout sync breaks | Assume shipped; if not, manual integration in Phase 2 |
| Dokan sub-order API changes | Multi-vendor split fails | Test against Dokan 5.0+ canary; document API dependency |
| High refund volume | Commission ledger bloat | Batch cleanup task; index `order_id` + `status` |
| Affiliate dashboard performance | Slow load at 1000+ affiliates | Paginate affiliate list; defer chart render |

---

## Technical Decisions

**Database**
- Store commissions flat (no hierarchy) for simplicity
- Use Dokan native balance table (credit entry) vs custom ledger
- User meta for settings (per-vendor toggles) vs custom table

**Hooks**
- Minimal filter set (rate, transfer amount, maturation)
- No action hooks Phase 1 (Phase 2 extends via events)
- SliceWP-compatible filter names where applicable

**Shortcodes**
- `[flyaffiliate_register]` — registration form
- `[flyaffiliate_dashboard]` — affiliate dashboard + earnings
- Auto-generated on plugin activation if pages don't exist

**WP.org**
- Freemium model: free plugin, Phase 2 adds SaaS upsell (not in repo)
- No paid add-ons or premium version in Phase 1
- GPL 2.0 license (WP.org requirement)

---

## Timeline

| Phase | Milestone | Target |
|-------|-----------|--------|
| **Dev** | Core affiliate + Dokan integration | 2026-09-30 |
| **QA** | Test harness, edge cases, Dokan compat | 2026-10-15 |
| **Launch** | wp.org submission + publish | 2026-10-30 |
| **Phase 2 Planning** | Payouts, email, reporting | 2026-11-15 |

---

## Stakeholders

- **Product:** @anik-fahmid (PM, owner)
- **Engineering:** TBD (dev lead)
- **QA:** TBD (test harness)
- **Marketing:** wp.org listing, launch announcement

---

## Appendix: Why No Phase 2 Features in MVP?

**Automated Payouts**
- Requires PCI compliance, gateway onboarding (Stripe/PayPal/etc.)
- Manual-first allows validation of payment mechanics before automation
- Dokan's existing withdrawal infrastructure covers Phase 1 use case

**Email Notifications**
- Email setup is often broken in client environments (spam, deliverability)
- Phase 1 relies on dashboard visibility; email can follow
- Reduces Phase 1 risk surface

**Advanced Reporting**
- Basic list views sufficient for MVP (filter, sort, export as CSV manually)
- Analytics require data stability (Phase 1 proof); Phase 2 adds charting
- Prevents over-engineering of data pipeline early

**Affiliate Approval Rosters**
- Auto-approve lowers barrier to entry; rosters add admin overhead
- Phase 2 can layer approval workflow without breaking MVP

---

## Document History

| Date | Author | Change |
|------|--------|--------|
| 2026-08-24 | @anik-fahmid | MVP PRD (Phase 1 only, core + Dokan) |
