# Releasing FlyAffiliate

The gate for every release is `npm run plugin-check`: 0 errors, 0 warnings on
the built zip (`flyaffiliate-wporg-compliance`).

## Cut a release

1. Branch `release/x.y.z` from `develop`.
2. Move the version in one commit: the header in `flyaffiliate.php`, `Stable tag`
   in `readme.txt`, `FlyAffiliate_Plugin::$version`, and `package.json`. Bump
   `Installer::DB_VERSION` only when the schema changed, with an upgrader.
3. Write the entry in `CHANGELOG.md` and its user-facing copy under
   `== Changelog ==` in `readme.txt`; set `Tested up to` to the current
   WordPress.
4. `npm run release` — builds the assets, regenerates `languages/flyaffiliate.pot`
   and stages `build/flyaffiliate` into `build/flyaffiliate-v<version>.zip`,
   replacing every `@since FLYAFFILIATE_SINCE` with the version.
5. `npm run plugin-check`, `composer phpcs`, `npm run phpunit` — all green.
6. Merge into `develop`, then `develop` into `main`, and tag `v<version>`.

## Publish

`deploy.yml` runs on a `v*` tag and pushes `build/flyaffiliate` to the
WordPress.org SVN with `.wordpress-org/` as the listing's assets. It runs only
while the repository variable `WPORG_DEPLOY_ENABLED` is `true` and the
`SVN_USERNAME` / `SVN_PASSWORD` secrets are set in the `wordpress-org`
environment. The first version goes up by hand through the plugin directory's
upload form, as its review requires; the action takes over from the next tag.
