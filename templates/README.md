# Templates

Every file here is overridable. Copy one into a `flyaffiliate` directory inside
your theme, keeping the same path, and your copy is loaded instead:

```
wp-content/themes/your-theme/flyaffiliate/affiliate-dashboard/summary.php
```

Lookup order is child theme, parent theme, then this directory.

## The contract

- A template **renders**. It does not query, and it does not decide. The caller
  prepares the data and passes it in.
- Variables arrive as documented `@var` entries in the file's docblock.
- Everything is escaped at the point of output, and every string is translated
  with the `flyaffiliate` text domain.
- Every file starts with the `ABSPATH` guard, this one included.

## Directories

| Directory | What it renders |
|---|---|
| `admin/` | wp-admin screens |
| `affiliate-dashboard/` | the `[flyaffiliate_dashboard]` shortcode |
| `registration/` | the `[flyaffiliate_register]` shortcode |
