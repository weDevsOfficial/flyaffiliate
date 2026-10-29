# Releasing FlyAffiliate

The gate for every release is `npm run plugin-check`: 0 errors, 0 warnings on
the built zip (`flyaffiliate-wporg-compliance`).

## Cut a release

1. Branch `release/x.y.z` from `develop`.
2. Move the version in one commit: the header in `flyaffiliate.php`, `Stable tag`
   in `readme.txt`, `FlyAffiliate_Plugin::$version`, and `package.json`. Bump
   `Installer::DB_VERSION` only when the schema changed, with an upgrader.
   Then `npm run version` stamps that version on every
   `@since FLYAFFILIATE_SINCE` in the shipping sources, in a commit of its
   own, so the next release's zip stamps only the code it adds.
3. Write the entry in `CHANGELOG.md` and its user-facing copy under
   `== Changelog ==` in `readme.txt`; set `Tested up to` to the current
   WordPress.
4. `npm run release` — builds the assets, regenerates `languages/flyaffiliate.pot`
   and stages `build/flyaffiliate` into `build/flyaffiliate-v<version>.zip`,
   replacing any `@since FLYAFFILIATE_SINCE` still left with the version. WP-CLI parses
   the built bundles for the template, so the script runs it through PHP
   with a 1G memory limit.
5. `npm run plugin-check`, `composer phpcs`, `npm run phpunit` — all green.
6. Merge into `develop`, then `develop` into `main`, and tag `v<version>`.

## Publish

`deploy.yml` runs on a `v*` tag and pushes `build/flyaffiliate` to the
WordPress.org SVN with `.wordpress-org/` as the listing's assets. It runs only
while the repository variable `WPORG_DEPLOY_ENABLED` is `true` and the
`SVN_USERNAME` / `SVN_PASSWORD` secrets are set in the `wordpress-org`
environment. The review took its zip through the plugin directory's upload
form; since its approval every version, the first included, is an SVN commit,
made by this action on the tag or by hand from `build/flyaffiliate`.
