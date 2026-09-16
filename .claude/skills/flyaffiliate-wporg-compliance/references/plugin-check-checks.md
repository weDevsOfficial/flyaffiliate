# Plugin Check: what it inspects

Plugin Check (PCP) is the WordPress.org tool that runs against a submitted
plugin. It is a WordPress plugin providing `wp plugin check <slug>`. FlyAffiliate
runs it through `npm run plugin-check` against the staged release directory.

**The authoritative answer is always the tool's own output.** This file is a map
of what it looks at so you can predict a finding before the run, not a
substitute for running it.

## Categories

| Category | What it covers |
|---|---|
| General | Plugin headers, i18n, code structure, obfuscation, file types |
| Plugin Repo | `readme.txt`, stable tag, trademarks, licence, tested-up-to |
| Security | Escaping, sanitization, nonces, SQL, file operations, uploads |
| Performance | Enqueued script size, footer loading, query parameters |
| Accessibility | Markup patterns in admin output |

## The checks this codebase has to satisfy

### Static analysis over PHP (PHPCS-backed)

These run WordPress Coding Standards sniffs. Because `phpcs.xml.dist` keeps the
same sniffs at error severity, **`composer phpcs` is a genuine pre-flight for
this half of the gate**.

- Unescaped output — `WordPress.Security.EscapeOutput`
- Unsanitized input — `WordPress.Security.ValidatedSanitizedInput`
- Missing nonce verification — `WordPress.Security.NonceVerification`
- Unprepared SQL — `WordPress.DB.PreparedSQL`, `PreparedSQLPlaceholders`
- Development functions — `error_log`, `var_dump`, `print_r`, `phpinfo`
- Discouraged functions — `eval`, `create_function`, `extract`, `serialize`
  of untrusted input, `base64_*` used to hide code
- Filesystem calls that should go through `WP_Filesystem`
- `mt_rand`/`rand` where `wp_rand` is expected
- `date()` where `gmdate()` / `current_time()` is expected
- I18n usage: wrong text domain, variable as a translatable string, missing
  translator comment on a placeholder string

### Plugin header and file structure

- Required headers present and non-empty; `Requires at least` and
  `Requires PHP` declared
- `Text Domain` equal to the plugin slug
- No VCS directories, hidden files, archives, or executables in the zip
- No obfuscated or compiled-only PHP
- `readme.txt` present and parseable

### readme.txt

- `Stable tag` matches the plugin header `Version`
- `Tested up to` is a real, current WordPress version
- Short description ≤ 150 characters
- At most 5 tags
- A `== Changelog ==` section that is not the generator's default text
- Licence declared and GPL-compatible
- Screenshot captions matching the numbered files

### Runtime and enqueue

- No script or style loaded from a remote host
- Scripts enqueued in the footer where possible
- Enqueued bundle size within limits
- No inline `<script>`/`<style>` where an enqueue would do

### Trademarks

The slug and display name are checked against a reserved-term list. Using
"WordPress", "WooCommerce", "Woo", or a trademarked product name as a slug
prefix is rejected. A `… for WooCommerce` display-name suffix is accepted.

## Reading a result

`npm run plugin-check` prints `file,line,column,type,code,message`. `type` is
`ERROR` or `WARNING`; **both fail the gate here**. `code` is the check or sniff
name — search it in this file, then in `docs/wporg-accepted-warnings.md`, before
concluding it is acceptable.

## What is not checked but is still rejected by human review

PCP is automated; a wp.org reviewer additionally rejects:

- Writing to the database on every page load
- Un-dismissible or site-wide admin notices
- Undisclosed external service calls, including analytics
- Bundled premium upsell UI that misrepresents itself as functionality
- Collecting personal data without a privacy disclosure in `readme.txt`

FlyAffiliate stores hashed IP and hashed user agent on a visit for this reason,
and discloses cookie use in the readme.
