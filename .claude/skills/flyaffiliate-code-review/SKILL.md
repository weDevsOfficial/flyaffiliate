---
name: flyaffiliate-code-review
description: Review standards for FlyAffiliate — what to flag, at what severity, and in what format. Use when reviewing a diff, a pull request, or your own work before committing.
---

# FlyAffiliate Code Review

Review in this order: **correct**, **secure**, **readable**, **elegant**. A
finding at a higher level outranks every finding below it.

## Severity

| Level | Meaning | Examples |
|---|---|---|
| **Blocker** | Merging this loses money, leaks data, or fails the wp.org gate | A money rule broken; unescaped output; missing `permission_callback`; unprepared SQL; a `paid` commission mutated |
| **Major** | Correct today, wrong under a foreseeable condition | Non-idempotent write; float money comparison; HPOS-unsafe order access; a Dokan symbol referenced outside the integration |
| **Minor** | Works, but costs the next reader | Wrong vocabulary; duplicated query; missing `@since`; a template doing its own query |
| **Nit** | Style, prefixed `nit:` and never blocking | Formatting the auto-fixer would handle |

## Blockers — money

Check against `CONTEXT.md`; these are not matters of opinion.

- Commission calculated per **order**, not per **order item**.
- A rate used without clamping to the global maximum — including a stored rate
  saved before the maximum changed.
- Rate hierarchy out of order. It is product → vendor (filter) → global.
- Money compared or summed as a float. It is integer cents:
  `(int) round( $amount * 100 )`.
- A write that is not idempotent. Commissions key on `order_item_id`, paid
  marking on `payout_id`. Ask:
  what happens when this hook fires twice?
- A `paid` commission edited, rescaled, or deleted. `paid` is terminal.
- A commission maturing without both conditions: hold period elapsed **and**
  order `processing` or `completed`.
- A refund rescaling by the order's refunded fraction rather than the **item's**.
- Shipping or tax included in the base amount when the setting excludes them.
- More than one affiliate attributed to an order.
- Self-referral earning a commission while the block is enabled.

## Blockers — integrations

- A third-party plugin's symbol referenced outside `includes/Integrations/`, or
  an integration loading before that plugin has confirmed it is loaded.
- Anything Dokan-specific on this branch — the marketplace integration lives
  on `feature/dokan-integration`.

## Blockers — security and wp.org

- Output not escaped at the point of output.
- Request data used without sanitization matching its type.
- A state-changing handler missing a capability check or a nonce check.
- `manage_options` where `manage_woocommerce` belongs.
- `permission_callback` set to `__return_true`.
- SQL built by concatenation instead of `$wpdb->prepare()`.
- A remote asset (CDN script, web font, external image).
- An inline `<style>` or `<script>` block.
- `load_plugin_textdomain()`, or a text domain that is not `flyaffiliate`.
- A database write on `admin_init` or an ordinary page load — including any
  seeder.
- A new root-level file missing from `.distignore`.

## Major

- `get_post_meta()` on an order id instead of `WC_Order` methods (HPOS).
- `wp_schedule_event()` where Action Scheduler is the convention.
- `add_action()` called from a constructor instead of `register_hooks()`.
- A class registering hooks without implementing `Hookable`.
- `date()` instead of `gmdate()` / `current_time()`.
- `rand()` / `mt_rand()` instead of `wp_rand()`.
- A schema change outside `Installer` + a versioned upgrader.
- An unbounded query over commissions or visits with no `LIMIT` and no paging.
- A new hook without `flyaffiliate_` prefix or without a documented contract.
- A test asserting a float equality on money.

## Minor

- `CONTEXT.md` vocabulary broken — "referral" for a commission row, "partner"
  for an affiliate, "seller" for a vendor, "withdrawal" for a payout.
- `@since` with a guessed version instead of `FLYAFFILIATE_SINCE`.
- A template running a query, or a screen class rendering HTML inline instead of
  through a template.
- Duplicated logic that belongs on the Manager or the model.
- A missing `ABSPATH` guard.

## Output format

Group by severity, most severe first. One finding per entry:

```
**Blocker** — includes/Commission/Calculator.php:88
Commission is computed from $order->get_total() rather than per item, so a
two-item order pays one commission on the whole cart. CONTEXT.md money rule 1.
→ Loop $order->get_items() and create one row per item, keyed on order_item_id.
```

Each finding states: where, what is wrong, the consequence, and the fix. Cite
`CONTEXT.md` or the ADR when the rule comes from there. Do not report a finding
you have not traced to a concrete failure — "this could be a problem" without a
case is noise.

Close with what is **not** covered: the tests you would want that do not exist
yet, and anything you could not verify.

## Reviewing your own work

Before committing, re-read the diff against the Blocker list only, then run the
gate (`flyaffiliate-dev-cycle`). A green gate is not a substitute for the money
rules — PHPCS cannot tell you that you clamped the wrong rate.
