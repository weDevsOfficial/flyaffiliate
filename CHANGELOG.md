### v1.0.2 ( Nov 12, 2026 ) ###

- **new:** A commission marked paid by hand now shows an info icon beside its Paid status in the admin lists and on the affiliate dashboard, so it no longer looks the same as one a payout paid.
- **new:** The commission form now warns, when you set a commission to Paid for an affiliate paid through an automatic payout method, that saving sends no money, and links to Payouts.
- **new:** Affiliates now see the transaction reference of a payout an automatic payout method sent, on their Payouts tab and in the payout's details.
- **update:** The admin's not-found page now follows Dokan's 404 page and says what is missing, with a way back.
- **update:** The commission note in the affiliate's commission details now sits behind its info icon, as it does in the lists.
- **fix:** The referral variable can no longer be set to one of WordPress's own query variables, such as `p` or `page_id`, which credited affiliates for ordinary visits; a site that already saved one falls back to `affiliate`.
- **fix:** A payout preview that is refused, such as one with no affiliate chosen, now clears the previous preview, so it can no longer be paid by mistake.
- **fix:** A referral link now tracks only a plain affiliate ID; values such as `-5`, `5abc` or an array no longer credit an affiliate.
- **fix:** Settings now refuse a switch value other than on or off instead of saving it.
- **fix:** Affiliates can no longer save an invalid payment email from their dashboard.
- **fix:** Removing the last commission from a payment is now refused, so no empty payment is left; delete the payment instead.
- **fix:** Opening a payment that does not exist now shows the not-found page instead of an empty payment.
- **fix:** Paying a payout that does not exist through the REST API now returns not found, and a payout period must be a real calendar date.
- **fix:** The affiliate dashboard's tabs now wrap on a phone instead of pushing the page sideways.
- **fix:** Long names and amounts on the admin dashboard and in stat cards are now cut with an ellipsis, the full value in a tooltip, instead of overlapping.

### v1.0.1 ( Nov 02, 2026 ) ###

- **update:** Rewrote the plugin page with a clearer description, the free and Pro feature lists, and new FAQ answers.

### v1.0.0 ( Oct 29, 2026 ) ###

- **new:** Affiliates, with email-only signup and a one-time activation link, an Affiliate user role, pending, active, inactive and suspended statuses, and an admin form with payment email, website, promotion method and an optional welcome email.
- **new:** Tracking, with a referral link per affiliate, a visit log with hashed visitor data, and a signed first-party cookie for the attribution window.
- **new:** Referral links, where affiliates generate a link to any page of your site from their dashboard and keep the ones they use, each with its visit count, which you also see on the affiliate's page.
- **new:** Commissions, with one commission per order item at checkout (classic and block), a product meta then default rate order capped at a maximum, optional shipping and tax exclusion, and self-referral blocked by default.
- **new:** Hold period, where every commission carries the date its hold ends, fixed when it is created, and a payout takes a commission only once that date has passed.
- **new:** Order sync, where commissions are pending at checkout and unpaid once the order is paid, failed, cancelled and (optionally) refunded orders reject them, a recovered order restores them, and a paid commission is never reversed.
- **new:** Hand-entered commissions with affiliate, amount, reference order (checked against WooCommerce), reference amount, origin, date, type and status.
- **new:** Payouts, with a preview grouped by payout method, one payment per affiliate, mark paid or unpaid, remove a commission, delete an unpaid payment and CSV export, and each payment keeps the address it was sent to.
- **new:** Payout methods, with Manual payouts to the affiliate's payment email and room for more methods, and affiliates choose how they are paid in their dashboard.
- **new:** WooCommerce Analytics, with an Affiliate Commission box on Analytics > Overview that counts the orders the report counts.
- **new:** A React admin with a dashboard and chart, affiliates, commissions, visits, payouts, settings and a setup wizard that opens on a first install only, with other plugins' notices kept off its screens.
- **new:** Extensions, a page that previews FlyAffiliate Pro's extensions while Pro is not active.
- **new:** Affiliate dashboard shortcode `[flyaffiliate_dashboard]` with the referral link and link generator, balance, commissions, visits, payouts and settings, and registration shortcode `[flyaffiliate_register]`.
- **new:** Usage data, with opt-in Appsero Insights that sends nothing until an administrator allows it (see Usage Data).
- **new:** REST API under `flyaffiliate/v1` for affiliates, commissions, visits, referral links, payouts, payout methods, settings and the affiliate's own data.
- **new:** WP-CLI command `wp flyaffiliate seed` for demo data.
