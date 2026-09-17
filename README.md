# FlyAffiliate

<img src="assets/images/logo.svg" alt="FlyAffiliate" width="96" />

Welcome to the **FlyAffiliate** repository on **GitHub**!

**FlyAffiliate is an affiliate-marketing plugin for WordPress and WooCommerce, built by [weDevs](https://wedevs.com/).** Affiliates get a referral link, a frontend dashboard and a balance. Store owners get per-item commissions with a configurable hold period, commission statuses that follow the order, and two-step payouts with a CSV export — with nothing leaving their site.

Here you can find the **source code**, **open issues**, and **contribute** to the development of the plugin.

- **Requires:** PHP 8.1+, WordPress 6.4+. WooCommerce 8.5+ for the order integration, which loads only when WooCommerce is active.
- **Licence:** GPL-2.0-or-later
- **Reference behaviour:** commission and payout semantics match SliceWP where the two overlap, so anyone coming from there feels at home. The differences that exist are deliberate and recorded in [`docs/adr/`](docs/adr/).

## 🚀 Getting Started

To get up and running with **FlyAffiliate development**, make sure you have installed all of the prerequisites.

### 📋 Prerequisites

* **[Node.js](https://nodejs.org/en/download) (>= 22.x)** — we recommend [**NVM**](https://github.com/nvm-sh/nvm#installing-and-updating) to pin the version.
* **[npm](https://docs.npmjs.com/downloading-and-installing-node-js-and-npm)** — manages the JavaScript dependencies and runs the build and test scripts.
* **[PHP 8.1](https://www.php.net/manual/en/install.php)+** — the plugin's minimum, and what Composer and the PHP tooling run on.
* **[Composer](https://getcomposer.org/doc/00-intro.md)** — installs the PHP development dependencies (nothing from `vendor/` ships).
* **[Docker](https://www.docker.com/)** — `wp-env` runs WordPress + WooCommerce in containers for PHPUnit, Playwright and Plugin Check.

Once the prerequisites are installed, the following prepares everything for development:

```bash
# Navigate to the plugin directory
cd wp-content/plugins/flyaffiliate

# Install the PHP development dependencies
composer install

# Install the npm dependencies
npm install

# Build the admin and affiliate-dashboard apps for production
npm run build

# OR build in development mode and watch for changes
npm run start

# Start WordPress + WooCommerce in Docker (needed for the test suites)
npm run env:start
```

## 📁 Repository Architecture

FlyAffiliate follows Dokan's organisation, so a Dokan developer can find their way around:

```
flyaffiliate/
├── 📂 assets/             # Built assets (never edit by hand).
│   ├── css/               # admin.css, dashboard.css, frontend.css.
│   ├── js/                # admin.js, dashboard.js.
│   └── images/            # Logo and icons.
├── 📂 includes/           # Core PHP, PSR-4 root for FlyAffiliate\.
│   ├── Admin/             # Menu (hash routes), Dashboard stats, Settings schema, Notices, SetupWizard.
│   ├── Affiliate/         # Manager, Registration, Role.
│   ├── Commission/        # Manager, RateResolver, HoldPeriod.
│   ├── Payout/            # Manager, CsvExporter.
│   ├── Tracking/          # Tracker (referral link → visit → cookie).
│   ├── Integrations/      # WooCommerce: OrderAttribution, OrderStatusSync.
│   ├── Models/            # BaseModel + the custom-table models.
│   ├── REST/              # Controllers under flyaffiliate/v1.
│   ├── DependencyManagement/ # The container and service providers.
│   ├── Install/, Upgrade/ # dbDelta schema, pages, cron; versioned upgraders.
│   └── functions.php      # flyaffiliate_get_option() and friends.
├── 📂 src/                # React sources on @wedevs/plugin-ui.
│   ├── admin/             # The admin app: App.tsx (routes), pages/, components/, hooks/, lib/.
│   ├── dashboard/         # The affiliate dashboard app (frontend).
│   └── styles/            # Tailwind entry and the shared SCSS.
├── 📂 templates/          # Overridable templates (copy to {theme}/flyaffiliate/).
├── 📂 tests/              # Test suites.
│   ├── php/               # PHPUnit (WP-PHPUnit, Brain Monkey, Mockery).
│   └── pw/                # Playwright, against the wp-env site.
├── 📂 docs/               # PRD, MVP scope and the ADRs.
├── 📂 bin/                # build-zip.php, plugin-check.sh.
├── 📂 languages/          # flyaffiliate.pot.
└── 📂 .github/workflows/  # PHPCS, PHPUnit, Plugin Check, deploy.
```

## 📚 Developer Documentation

| Document | What it covers |
|---|---|
| [`CLAUDE.md`](CLAUDE.md) | Project map, commands, coding standards |
| [`CONTEXT.md`](CONTEXT.md) | Vocabulary and the money rules — read before touching commissions, refunds or payouts |
| [`docs/adr/`](docs/adr/) | Architecture decisions and why they were made |
| [`docs/MVP_PRD.md`](docs/MVP_PRD.md) | Phase 1 scope |
| [`docs/PRD.md`](docs/PRD.md) | Full product requirements |
| [`CONTRIBUTING.md`](CONTRIBUTING.md) | Branching, commits, pull requests |
| [`.claude/skills/`](.claude/skills/) | How to build, test, review and ship |

## 🧪 Testing

```bash
composer phpcs              # PHP CodeSniffer: WordPress-Extra + PHPCompatibilityWP
npm run lint:js             # ESLint over src/
npm run lint:css            # Stylelint over the SCSS
npm run typecheck           # tsc over src/
npm run phpunit             # PHPUnit inside wp-env (env:start first)
npm run test:e2e            # Playwright against the wp-env site
npm run plugin-check        # WordPress.org Plugin Check against the built zip
npm run release             # Builds build/flyaffiliate-v<version>.zip honouring .distignore
```

Every change must pass Plugin Check with zero errors and zero warnings before it ships: the plugin is distributed on WordPress.org.

## 🔌 Extending FlyAffiliate

* **Hooks** — every action and filter starts with `flyaffiliate_`; a docblock above each one states its `@since` and what a filter is expected to return.
* **REST** — every resource is under `flyaffiliate/v1` with a schema, links and a real permission callback. The affiliate dashboard reads the self-scoped `/me` routes.
* **Templates** — copy a file from `templates/` into `{theme}/flyaffiliate/` keeping the same path.
* **Settings** — add a field to `Admin\Settings\Schema\SettingsSchema` through the `flyaffiliate_settings_schema` filter.
* **Marketplaces** — the `flyaffiliate_vendor_rate` and `flyaffiliate_order_item_vendor_id` filters are the seams a marketplace integration plugs into. The Dokan integration lives on the `feature/dokan-integration` branch.

## 🤝 Contributing

1. Fork the repository and create a branch from `develop` (`feature/…`, `fix/…`).
2. Make your change, add or update tests, and run the gates above.
3. Open a pull request against `develop` using the template.

See [`CONTRIBUTING.md`](CONTRIBUTING.md) for the details.

## 📝 License

FlyAffiliate is free software: you can redistribute it and/or modify it under the terms of the [GNU General Public License](https://www.gnu.org/licenses/gpl-2.0.html) as published by the Free Software Foundation, either version 2 of the License, or (at your option) any later version.
