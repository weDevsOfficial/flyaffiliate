---
name: flyaffiliate-backend-dev
description: Write or modify FlyAffiliate backend PHP — classes, services, hooks, settings, templates, REST controllers, models, and integrations. Invoke before writing any PHP code or PHP tests.
---

# FlyAffiliate Backend Development

How PHP is written in this plugin. Read `CONTEXT.md` first if the change touches
commissions, refunds, or payouts — the money rules there are not re-derived
here.

## Before you write

1. `CONTEXT.md` — vocabulary and money rules. Use its terms: **Commission** (not
   Referral), **Affiliate** (not Partner), **Vendor** (not Seller), **Visit**,
   **Payout**, **Mature**, **Vendor charge**.
2. `docs/adr/` — check whether the surprising behaviour you are about to "fix"
   is a decision.
3. `.claude/skills/flyaffiliate-wporg-compliance` — the gate the change has to
   pass before it can be committed.

## Namespace and file layout

- Root namespace `FlyAffiliate\`, PSR-4 onto `includes/`.
  `FlyAffiliate\Commission\Manager` → `includes/Commission/Manager.php`.
- Autoloading is `includes/Autoloader.php`, not Composer. **Nothing from
  `vendor/` ships** (ADR-0002). Do not add a runtime Composer package.
- Procedural helpers live in `includes/functions.php` and are prefixed
  `flyaffiliate_`.

## Every PHP file

```php
<?php
/**
 * Short description of the file.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\Commission;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
```

The `ABSPATH` guard is required in **every** file, templates included.

## Prefixes — no exceptions

| Thing | Prefix |
|---|---|
| Functions | `flyaffiliate_` |
| Hooks (actions and filters) | `flyaffiliate_` |
| Options | `flyaffiliate_` |
| Post/user/order meta | `_flyaffiliate_` |
| Constants | `FLYAFFILIATE_` |
| DB tables | `{$wpdb->prefix}flyaffiliate_` |
| CSS/JS handles | `flyaffiliate-` |
| Text domain | `flyaffiliate` |

`flyaff_` and `fa-` are prototype prefixes. They do not appear in new code.

## Class conventions

- Methods and properties: `snake_case`. The **one** exception is the DI
  container, which mirrors `league/container`'s camelCase public API
  (`addShared`, `addServiceProvider`, `setShared`, `addTag`) — ADR-0002.
- Type hints on parameters and returns wherever PHP 8.1 allows. Typed properties
  are encouraged: `protected int $affiliate_id = 0;`.
- Strict comparisons (`===`, `!==`), `in_array( $needle, $haystack, true )`.
- Yoda conditions are not enforced.
- New code carries `@since FLYAFFILIATE_SINCE`. **Never guess a version number.**
  Same for `@deprecated FLYAFFILIATE_SINCE`.

## Services and the container

Register a class in a service provider under `includes/DependencyManagement/Providers/`:

```php
class CommonServiceProvider extends BaseServiceProvider {
	protected $tags = [ 'common-service' ];

	protected $services = [
		\FlyAffiliate\Commission\Hooks::class,
		\FlyAffiliate\Tracking\Hooks::class,
	];

	public function register(): void {
		foreach ( $this->services as $service ) {
			$definition = $this->share_with_implements_tags( $service );
			$this->add_tags( $definition, $this->tags );
		}
	}
}
```

Tagged groups resolved at boot, in this order: `common-service`, then
`admin-service` **or** `frontend-service`, then `integration-service`,
`container-service`, and — conditionally — `ajax-service` and `cli-service`.

Named services (resolvable as `flyaffiliate()->commission` or
`flyaffiliate()->get_container()->get( 'commission' )`) are registered in
`Providers\ServiceProvider` with the `container-service` tag.

**Dependencies are constructor-injected and autowired by type hint.** If the
container cannot resolve a type hint, pass the argument explicitly in the
provider rather than reaching for a global.

## Hooks

A class that registers WordPress hooks implements `FlyAffiliate\Contracts\Hookable`:

```php
class Hooks implements Hookable {
	public function register_hooks(): void {
		add_action( 'woocommerce_checkout_order_created', [ $this, 'attribute_order' ] );
	}
}
```

`register_hooks()` is called automatically for every `Hookable` in the container.
**Do not call `add_action()` from a constructor** — construction happens during
container resolution and the ordering is not yours to rely on.

Naming: `flyaffiliate_{noun}_{verb}` for actions, `flyaffiliate_{noun}` for
filters. Document every hook with a docblock stating `@since FLYAFFILIATE_SINCE`,
the parameters, and what a filter is expected to return.

## Data access

- One model per table under `includes/Models/`, extending `BaseModel`.
- `$wpdb->flyaffiliate_commissions` etc. are set up on `init` priority 1. Use
  them; do not rebuild `$wpdb->prefix . 'flyaffiliate_commissions'` inline.
- **Every** query is prepared. Table names cannot be placeholders, so
  interpolate the `$wpdb->flyaffiliate_*` property and pass values through
  `%d`/`%s`/`%f`:

```php
$commission = $wpdb->get_row(
	$wpdb->prepare(
		"SELECT * FROM {$wpdb->flyaffiliate_commissions} WHERE order_item_id = %d",
		$order_item_id
	)
);
```

- Money is `DECIMAL(19,4)` in the schema. **Compare and sum in integer cents**:
  `(int) round( $amount * 100 )`. Never compare floats.
- Schema changes go in `Install\Installer` via `dbDelta`, plus a versioned
  upgrader under `includes/Upgrade/Upgrades/` and a bump of
  `flyaffiliate_db_version`. Never `ALTER TABLE` outside an upgrader.

## Settings

One option — `flyaffiliate_settings`, autoloaded, keyed by field id — and one
flat-array schema (`Admin\Settings\Schema\SettingsSchema`) whose elements match
`@wedevs/plugin-ui`'s `SettingsElement` shape (ADR-0010). There are no option
groups and no migration layer.

Read through the helpers, never `get_option()` directly:

```php
$rate    = flyaffiliate_get_option( 'default_rate', 10 );
$enabled = flyaffiliate_option_enabled( 'activation_email_enabled' ); // switches store 'on' / 'off'
flyaffiliate_update_option( 'hold_days', 45 );                        // validated + sanitized
```

To add a setting, add one array to the right page in `SettingsSchema`:

```php
[
	'id'                => 'my_unique_id',      // globally unique; it is the storage key
	'type'              => 'field',
	'variant'           => 'number',            // any plugin-ui built-in variant
	'section_id'        => 'rate_settings',     // parent pointer; never nest
	'title'             => __( 'My field', 'flyaffiliate' ),
	'default'           => 5,
	'validations'       => [ [ 'rules' => 'required|min', 'params' => [ 'min' => 0 ], 'message' => '…' ] ],
	'sanitize_callback' => 'absint',            // optional; stripped before REST
	'dependencies'      => [ [ 'key' => 'other_field_id', 'value' => 'on', 'comparison' => '=', 'to_self' => true, 'attribute' => 'display', 'effect' => 'show' ] ],
],
```

Rules: dependencies reference a plain field id; `sanitize_callback` and
`validate_callback` are PHP callables and never leave PHP; a `readonly` field
is display-only and never stored; `SchemaValidator` runs under `WP_DEBUG` and
refuses duplicate ids. Every save — REST `PUT /settings/{scope}` and the setup
wizard — goes through `Admin\Settings\Manager::save()`. Extensions add elements
with the `flyaffiliate_settings_schema` filter or a node's generated
`flyaffiliate_settings_{path}_children` filter.

## Tracking and attribution

- `Tracking\Tracker` runs on `template_redirect` (frontend only). A request
  carrying the referral variable for an **active** affiliate records a visit and
  sets the signed cookie `flyaffiliate_ref` (`affiliate|visit|hmac`, lifetime =
  `cookie_duration` days). `Tracker::sign()` / `parse()` build and verify it;
  tests set `$_COOKIE[ Tracker::COOKIE ]` with `sign()`.
- `Integrations\WooCommerce\OrderAttribution` hooks both
  `woocommerce_checkout_order_created` and
  `woocommerce_store_api_checkout_order_processed`. Guards in order:
  `woocommerce_enabled`, valid cookie, active affiliate, self-referral (customer
  id or billing email), order meta `_flyaffiliate_affiliate_id` already set.
  Then one `pending` commission per `line_item` (plus `shipping` items when
  `exclude_shipping` is off), base = item total (+ tax unless `exclude_tax`),
  rate from `Commission\RateResolver`, `matures_at` from `maturation_date()`.
  The unique key on `order_item_id` is the second idempotency net.
- Rates: `RateResolver::resolve( $product_id, $vendor_id )` — product meta
  `_flyaffiliate_rate` (variation, then parent) → `flyaffiliate_vendor_rate`
  filter → `default_rate`, clamped to `max_rate`; `flyaffiliate_commission_rate`
  runs last and is clamped again.
- `Commission\HoldPeriod` matures commissions: `pending` with `matures_at <= now`
  becomes `unpaid` through `Manager::set_status()`; a WooCommerce commission also
  needs its order `processing` or `completed` (`flyaffiliate_mature_order_statuses`;
  cash on delivery waits for `completed`). It runs on the order status change,
  on `flyaffiliate_order_attributed`, from the daily job on
  `Installer::MATURATION_HOOK`, and after a `hold_days` save, which first
  reschedules every pending row to `created_at + hold_days`.
- `Integrations\WooCommerce\OrderStatusSync` mirrors SliceWP: an order that fails,
  is cancelled or is trashed (`woocommerce_trash_order`) rejects its pending and
  unpaid WooCommerce commissions; a refunded order does the same only behind the
  `reject_commissions_on_refund` switch (off by default); an order the hold
  period would accept (`HoldPeriod::order_can_mature()`: processing or
  completed, cash on delivery completed) restores its rejected commissions to
  pending whoever rejected them, and so does an order leaving
  failed/cancelled/refunded (priority 10, before `HoldPeriod` matures them at
  20). Paid and manual commissions are never touched.
- Partial refunds are not rescaled (ADR-0011's rescaler was reverted): an
  admin handles them by hand through `Manager::update()`, which edits the
  amount, reference (only on an admin-created row), reference amount, type
  and status of any commission that is not paid and not inside a payment.
  `Manager::create()` is the admin's add form: origin (`source`), type, any
  status (default `unpaid`) and date; a pending commission created by hand
  keeps its status until its order is paid or the job matures it — nothing
  matures it on the spot.

## Payouts

Two steps, as in SliceWP (ADR-0012). `Payout\Manager::create()` turns the
preview into one `unpaid` payment row per affiliate and sets `payout_id` on
the commissions, which stay `unpaid`; `mark_paid()` / `pay_batch()` is what
marks a payment and its commissions `paid`; `mark_unpaid()` reverses a mistake
(commissions back to `unpaid`, still attached); `remove_commission()` and
`delete()` only work on unpaid payments; `delete_batch()` refuses once any
payment is paid. `preview()` skips every commission with a `payout_id`. Only
the payout manager moves a commission to or from `paid` —
`Commission\Manager::can_transition()` never allows it.

## The affiliate role

`Affiliate\Role` keeps the `flyaffiliate_affiliate` role (label "Affiliate",
capability `read` only) on every affiliate's user, the way SliceWP keeps
`slicewp_affiliate`. The affiliates table is the source of truth: the role is
added on `flyaffiliate_affiliate_created`, removed on
`flyaffiliate_affiliate_deleted`, re-added on every `profile_update` (a profile
save replaces the user's roles with the dropdown's pick), and a user given the
role on their profile without a row becomes an active affiliate. Never gate a
capability on the role; check the table (`flyaffiliate()->affiliate->is_affiliate()`).
`Installer::create_roles()` registers it and backfills existing affiliates on
install and upgrade.

## Templates

```php
flyaffiliate_get_template_part( 'affiliate-dashboard/summary', '', [ 'affiliate' => $affiliate ] );
```

Lookup order: `{theme}/flyaffiliate/{slug}.php` → `{plugin}/templates/{slug}.php`.
Templates receive data through the args array, escape everything at output, and
contain no queries — the caller prepares the data.

## Admin app

- The admin is one React application under `src/admin`, built to
  `assets/js/admin.js` + `assets/css/admin.css` and mounted by `Admin\Menu` on
  `admin.php?page=flyaffiliate`. Submenu entries are hash routes written into
  the `$submenu` global (Dokan's pattern); `Menu::get_route_url( 'affiliates/5' )`
  builds a link.
- Use `@wedevs/plugin-ui` for every component: `<DataViews>` for lists (over the
  REST controllers, which send `X-WP-Total`), `<Settings>` for settings,
  `Dialog`/`Field`/`Input`/`Select`/`SmartSelect` for forms, `Badge`, `toast`.
  No hand-rolled tables or modals.
- A new list page: `useListView()` + `useCounts()` from `src/admin/hooks`, a
  `DataViewField[]`, `DataViewAction[]`, and a route in `App.tsx` plus a menu
  entry in `Menu::get_routes()`.
- The brand colour is the logo teal `#0d7377`: `src/admin/theme.ts` (plugin-ui
  tokens) and the `$brand` variable in `src/styles/base.scss` carry it.
  Use `text-foreground`/`text-muted-foreground`/`text-primary`, never
  `text-gray-*` or a hex colour, so a token change reaches every page.
- `components/Layout.tsx` wraps every page in plugin-ui's `<TopBar>`; page
  content starts with `<PageHeader>`. `Menu::hide_foreign_notices()` keeps
  other plugins' notices off the page — register FlyAffiliate's own notices
  through `Admin\Notices\Manager`, which is put back after the removal.
- Plural strings use `_n()`; never `thing(s)`. Zero or missing values render a
  dash or a muted label (`Unknown affiliate`), never a link to `#0`.
- Styles are split the way Dokan splits them. `src/styles/tailwind.css` is the
  Tailwind entry: plugin-ui's tokens, then Tailwind utilities generated inside
  `.pui-root` and marked important. It stays plain CSS because Sass cannot
  parse Tailwind's directives; each app imports it through its own
  `tailwind.css`, which adds the `@source` lines. Every other stylesheet is
  SCSS: `src/styles/base.scss` holds the unlayered reset (`.pui-root :is(h1,
  p, ul, button, input, …)`) that clears wp-admin's and themes' defaults, the
  app root (`.flyaffiliate-app`) and the DataViews table rules; one app's rules
  go in `admin.scss` or `dashboard.scss`, which `@use` it. Do not import
  plugin-ui's prebuilt `styles.css`: its layered utilities lose to wp-admin.
- Lists follow Dokan's admin tables: explicit column widths in the view's
  `layout.styles`, no `isPrimary` (inline) actions, `Truncated` with a tooltip
  for long text, `StatusBadge` pills, `buildTabs()` so counts show a
  placeholder while they load, `isLoading` covering the counts too. Forms open
  in `FormDialog` (bordered title bar and footer); the commission form is a
  page instead (`#/commissions/new`, `#/commissions/:id/edit`), as SliceWP's is,
  and lists link to it from the commission ID and an Edit action. Figures use `StatCard`,
  nothing-yet states use `EmptyState`, detail pages pass `backTo` and `badge`
  to `PageHeader`. Icons are lucide only; the wp-admin menu icon is the one
  SVG, because WordPress requires a data URI there.
- The affiliate dashboard (`src/dashboard`) reuses `src/admin/components`,
  `hooks` and `lib` through the `@/` alias and reads `/me`, `/me/commissions`,
  `/me/visits`, `/me/payouts`. `Assets::enqueue_dashboard_assets()` loads it
  from the shortcode only; `window.flyaffiliate` carries a subset of the admin
  data plus `dashboard.tab` and `dashboard.notice`.
- The only admin-post handler is `Admin\PayoutExport` (CSV download); it checks
  capability, then nonce, then sanitizes. Everything else is REST.
- No inline `<style>` or `<script>`. Data reaches the app as
  `window.flyaffiliate` through `wp_add_inline_script()` in `Assets.php`.
- The setup wizard is `src/admin/pages/setup` (route `#/setup`): it renders
  fields from the settings schema, saves with `PUT /settings/{page}` and calls
  `POST /setup/complete`; `Admin\SetupWizard` only owns the one-time redirect
  and the done flag. The one PHP-rendered admin page left (user profile) keeps
  the old rules: capability, `check_admin_referer()`, then sanitize.

## REST

Namespace `flyaffiliate/v1`. Controllers extend `AdminBaseController`
(`manage_woocommerce`) or `AffiliateBaseController` (self-scoped: an affiliate
sees only their own rows; `MeController` is the one, and it reads the
affiliate from the session, never from a parameter). Every route has a real `permission_callback` —
`__return_true` is never acceptable. Every controller implements
`prepare_item_for_response()`, `prepare_links()`, and `get_item_schema()`, and
sends `X-WP-Total` / `X-WP-TotalPages` on collection responses.

## WooCommerce

- **HPOS-safe always.** `wc_get_order( $order_id )`, then `WC_Order` methods and
  `$order->get_meta()` / `$order->update_meta_data()` + `$order->save()`. Never
  `get_post_meta()` on an order id.
- Declare compatibility in the bootstrap: `custom_order_tables` and
  `cart_checkout_blocks`.
- Scheduled work uses Action Scheduler (`as_schedule_recurring_action`,
  `as_unschedule_all_actions`), which WooCommerce bundles — not `wp_schedule_event`.
- Currency and rounding go through `wc_price()`, `wc_format_decimal()`,
  `wc_get_price_decimals()`.

## Integration guardrails

Third-party code lives under `includes/Integrations/{Plugin}/` and loads only
once that plugin has confirmed it is loaded (`woocommerce_loaded` for
WooCommerce). No other directory may reference the plugin's symbols.

The marketplace (Dokan) integration is not on this branch. It lives on
`feature/dokan-integration`, which is this branch plus that work; keep the
neutral seams it plugs into — `vendor_id` on commissions, the
`flyaffiliate_vendor_rate` and `flyaffiliate_order_item_vendor_id` filters —
and add nothing Dokan-specific here.

Verify any third-party hook signature against the installed source before
using it. Never assume a version number or a function's availability.

## Idempotency

Every write that touches money is keyed so a hook firing twice changes nothing:

| Write | Key |
|---|---|
| Commission row | `order_item_id` (unique) |
| Vendor charge | order meta `_flyaffiliate_vendor_charged` |
| Paid marking | `payout_id` on the commission, set when the payment is created; the payment's `status` says whether the money went |

A commission inside a payment (`payout_id > 0`) is never moved by the order
sync or the maturation job (they call `set_status( …, true )`) and never
deleted; an admin still edits it, and an unpaid payment re-sums
(`Payout\Manager::resync()`) while a paid one keeps its amount. A `paid` row
an admin recorded by hand has no payment and is a record like any other. A
WooCommerce-origin commission's `order_id` must be an existing order;
`Commission\Manager::check_reference()` enforces it on create and edit.
