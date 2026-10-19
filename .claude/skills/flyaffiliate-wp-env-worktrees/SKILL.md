---
name: flyaffiliate-wp-env-worktrees
description: Run FlyAffiliate's wp-env in a git worktree, one site per branch, with its own ports and database, optionally cloned from a local site. Use when starting a FlyAffiliate branch or PR in its own environment, running two environments at once, or reusing a seeded database. For a change that also touches FlyAffiliate Pro, use Pro's flyaffiliate-pro-wp-env-worktrees skill.
---

# wp-env across git worktrees

Ported from Dokan's `dokan-wp-env-worktrees` skill (getdokan/dokan#3306). This
skill covers a FlyAffiliate-only change. A change that also touches
FlyAffiliate Pro, or a Pro PR, belongs to Pro's
`flyaffiliate-pro-wp-env-worktrees` skill (in the Pro repo): Pro drives that
environment and mounts a FlyAffiliate worktree beside it as `../flyaffiliate`,
and its `worktree-from-pr.sh` hands a FlyAffiliate PR back to this skill.

## Quick start

**Once per machine:** Docker Desktop, `jq`, and `mysqldump`/`mysql` on the
host if you clone a Herd or Valet site. Never edit the committed `.wp-env.json`:
CI reads it. Local settings go in the gitignored `.wp-env.override.json`, which
the script writes for you.

**1. Create the worktree** (from the FlyAffiliate main checkout; pick an unused even port):

```bash
S=.claude/skills/flyaffiliate-wp-env-worktrees
$S/create-worktree.sh fix/x develop 8910
# real data instead of an empty site:
FLYAFFILIATE_CLONE_DB_FROM=/Users/you/Herd/site/wp-config.php $S/create-worktree.sh fix/x develop 8910
```

This makes `~/flyaffiliate-wt/<branch>/flyaffiliate/` (a branch that exists
only on GitHub is checked out from `origin`) and writes its
`.wp-env.override.json`: ports, clone source.

**2. Install, build, start:**

```bash
cd ~/flyaffiliate-wt/fix-x/flyaffiliate && composer install && npm ci && npm run build
npx wp-env start              # http://localhost:8910, admin / password
```

**3. Work.** `npm run start` for a watch build, `npm run phpunit` against this
worktree's own tests database (port 8911). Later starts never clone again;
`clone-db.sh --force` does.

**4. Finish.**

```bash
npx wp-env destroy            # drops this worktree's containers and volumes
git -C <main> worktree remove ~/flyaffiliate-wt/fix-x/flyaffiliate
```

## What the script does

`create-worktree.sh <branch> [base] [port]` adds the worktree (made from `base`,
default `develop`, when the branch is new) and writes:

```jsonc
// .wp-env.override.json (gitignored, per worktree)
{
  "port": 8910,
  "env": {
    "tests": { "port": 8911 },
    "development": { "config": { "FLYAFFILIATE_CLONE_DB_FROM": "…" } }   // only with a clone source
  },
  "lifecycleScripts": { "afterStart": "/abs/path/to/clone-db.sh" }
}
```

No `plugins`: the committed file already mounts WooCommerce and `.`. An
override's `plugins` would replace that list wholesale, so one that adds a
plugin repeats both.

When the branch is checked out somewhere already (the main checkout, say), the
worktree gets that commit detached and the script says so: git allows a branch
in one worktree only.

`FLYAFFILIATE_WT_HOME` moves the parent folder (default `~/flyaffiliate-wt`).

## Why each worktree is its own site

wp-env names an instance `md5( absolute path of .wp-env.json )` (in
`lib/config/load-config.js`): `~/.wp-env/<md5>/`, containers `<md5>-mysql-1`,
`<md5>-cli-1`, …, volumes `<md5>_mysql` and `<md5>_mysql-test`. A worktree is a
new path, so it gets its own database without configuration.

The only conflict is ports. FlyAffiliate's `.wp-env.json` uses wp-env's
defaults, 8888 and 8889; Pro's uses 8898 and 8899. FlyAffiliate worktrees take
an even base from 8910 (dev `B`, tests `B + 1`); Pro's paired worktrees start
at 8900.

The worktree folder is named `flyaffiliate` on purpose: wp-env mounts `.` at
`wp-content/plugins/<folder name>`, so the plugin keeps its slug, and a cloned
database's `active_plugins` still matches. The tests site maps
`wp-content/plugins/flyaffiliate-src` to `.` (`npm run phpunit` runs there), so
the tests work whatever the folder is called.

## Install and build: npm ci in a worktree

A fresh worktree has no `vendor/`, `node_modules/` or built `assets/`.
`@wedevs/plugin-ui` installs from git. Dokan's skill records `npm ci` failing
on such a dependency in a fresh worktree (npm 11, error code 128, `destination
path ... already exists and is not an empty directory`), intermittently. If it
fails here: copy `node_modules` from the main checkout into the worktree, then
`npm run build`.

## Clone a database (reuse a seeded site)

Set the source as environment variables when creating the worktree; the script
writes them as wp-env `config` constants (wp-env rejects unknown top-level
keys), and `clone-db.sh` reads them back on `afterStart`:

| Variable | Meaning |
|---|---|
| `FLYAFFILIATE_CLONE_DB_FROM` | A local install's `wp-config.php` (credentials read with `sed`, never executed), or another wp-env checkout (its running dev database). |
| `FLYAFFILIATE_CLONE_DB_NAME` (+ `_HOST`, `_PORT`, `_USER`, `_PASS`, `_PREFIX`) | Explicit MySQL instead. Defaults 127.0.0.1, 3306, `wp_`. |

On the first start, `clone-db.sh` streams the source into the worktree's
database (the source is only read), sets `$table_prefix` if the source's is not
`wp_`, rewrites the source's `home` URL to the worktree's, activates
WooCommerce and FlyAffiliate (and Dokan or Pro if mounted), and records
`flyaffiliate_wp_env_cloned_from`. Later starts see the marker and skip ("already
cloned from …"). `clone-db.sh --force [source]` clones again. With no source the
hook does nothing.

The cloned site keeps the source's accounts and options. Uploads are files, not
rows: map `wp-content/uploads` to the source's folder to see its media. Only the
dev database is cloned; `npm run phpunit` rebuilds the tests database each run.

`clone-db.sh` exists in this skill and in Pro's
`flyaffiliate-pro-wp-env-worktrees`. The two copies are the same file: change
both.

## Gotchas

- A config change takes effect on the next `npx wp-env start` (the mounts live
  in the generated `docker-compose.yml`).
- `woocommerce.zip` is the latest tag, not always a stable release; pin a
  versioned zip in the override when it matters.
- `npx wp-env destroy` removes only the current worktree's instance (from a
  script: `printf 'y\n' | npx wp-env destroy`).
- wp-env shows the `afterStart` hook's output only with `--debug` or on failure.
  `docker volume ls | grep mysql` shows leftovers from deleted worktrees.
- Open the site from a phone: set `WP_HOME` and `WP_SITEURL` to
  `http://<lan-ip>` (no port) under `env.development.config` in the override,
  then start again. Pro's skill has the details.
