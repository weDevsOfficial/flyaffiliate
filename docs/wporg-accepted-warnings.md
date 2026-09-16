# Accepted Plugin Check findings

The gate for every release is **0 errors, 0 warnings** from
`npm run plugin-check` on the built zip. This file is the complete list of
findings that are allowed to remain, with the reason and the exact suppression.

A finding may only be added here when all three are true:

1. The code is correct and the check is reporting a pattern that has no safe
   alternative in this context.
2. The suppression is narrow — a `phpcs:ignore` on the single line, never a
   file-level or ruleset-level exclusion.
3. A reviewer at WordPress.org would accept the justification as written.

Anything else is a bug to fix, not a warning to accept.

## Current list

Plugin Check reports **0 errors and 0 warnings** on the built zip. The entries
below are the suppressions that keep it there — each one a single-line
`phpcs:ignore` with its reason in the comment, and each one on a query against a
table this plugin created itself.

### Custom-table queries — `includes/Models/BaseModel.php`

| Lines | Checks | Why it stands |
|---|---|---|
| insert, update, delete, `get_row`, `get_results`, `get_var` | `WordPress.DB.DirectDatabaseQuery.DirectQuery`, `.NoCaching`, `WordPress.DB.PreparedSQL.NotPrepared`, `PluginCheck.Security.DirectDB.UnescapedDBParameter` | These are the four `{prefix}flyaffiliate_*` tables. No WordPress API reads or writes them, so there is no alternative call to make and nothing to warm an object cache from. Every value is bound through a `$wpdb` placeholder; the only interpolated parts are the table name and the column identifiers, and identifiers come from the model's declared `$columns` map, which is also the allow-list a request parameter would have to be in to reach the SQL. |

### Aggregates — `includes/Commission/Manager.php`, `includes/Payout/Manager.php`

| Lines | Checks | Why it stands |
|---|---|---|
| per-affiliate totals, sums by affiliate, batch summaries | `DirectQuery`, `.NoCaching`, `PreparedSQL.InterpolatedNotPrepared`, `PreparedSQLPlaceholders.UnfinishedPrepare` | `GROUP BY` aggregates over this plugin's own tables. The only interpolation is the table name (and, for the status filter, an `IN` list of one `%s` per status validated against the model's list); every value is a placeholder. |

### CSV export — `includes/Payout/CsvExporter.php`

| Lines | Checks | Why it stands |
|---|---|---|
| `fopen( 'php://output' )`, `fclose()` | `WordPress.WP.AlternativeFunctions.file_system_operations_fopen`, `_fclose` | The export is streamed to the browser, not written to disk. `WP_Filesystem` has no streaming API, and buffering a large batch into a string to echo it is what the export exists to avoid. It is `manage_woocommerce`-gated, nonce-checked, and every cell is formula-escaped. |

### Uninstall — `uninstall.php`

| Lines | Checks | Why it stands |
|---|---|---|
| `DROP TABLE IF EXISTS` | `DirectQuery`, `.NoCaching`, `.SchemaChange`, `PreparedSQL.NotPrepared`, `UnescapedDBParameter` | Dropping this plugin's own tables is what uninstall is for, and it only runs when the site owner turned on "clear data on uninstall". The table names come from `Installer::get_table_names()`, a hard-coded list, never from input. |
| `DELETE FROM {usermeta}` / `{postmeta}` WHERE `meta_key LIKE` | `DirectQuery`, `.NoCaching` | WordPress has no API for "delete this meta key for every user" or "for every post". The pattern is `$wpdb->esc_like()`-escaped and bound as a placeholder. |
| `SHOW TABLES LIKE` and the HPOS meta delete | `DirectQuery`, `.NoCaching`, `PreparedSQL.InterpolatedNotPrepared`, `UnescapedDBParameter` | Under HPOS the order meta lives in WooCommerce's own table, which exists only when HPOS has been enabled; checking for a table has no WordPress API. The table name is `$wpdb->prefix` plus a literal, and it is checked to exist before it is used. |

### Tests — `tests/php/bootstrap.php`

| Lines | Checks | Why it stands |
|---|---|---|
| `TRUNCATE TABLE` | `PreparedSQL.InterpolatedNotPrepared` | Test harness only; `.distignore` keeps `tests/` out of the zip, so Plugin Check never sees it. Recorded here so the suppression is not mistaken for one that ships. |

### Settings schema — `includes/Admin/Settings/Schema/SettingsRegistry.php`

| Lines | Checks | Why it stands |
|---|---|---|
| `apply_filters( $element['hook_key'], … )` | `WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound` | The hook name is dynamic by design — one filter per structural node, so an extension can inject fields under exactly one section — but `generate_keys()` builds every key as `flyaffiliate_settings_{path}_children`, so the prefix rule is met; the sniff just cannot see it through the variable. |
