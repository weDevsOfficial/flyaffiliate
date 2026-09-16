# Changelog

All notable changes to FlyAffiliate are recorded here. The user-facing copy of
this list lives in `readme.txt` under `== Changelog ==`.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and
the project uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Repository tooling: Composer and npm dependency sets, PHPCS ruleset,
  PHPUnit configuration, wp-env environments, webpack build, release archiver,
  and the Plugin Check runner.
- CI: PHPCS on changed files, PHPUnit across PHP 7.4/8.3,
  Plugin Check on the built zip, and a tag-triggered WordPress.org deploy that
  stays disabled until the slug is approved.
- Architecture Decision Records 0001–0004 and the accepted-warnings register.
- The `.claude/skills/flyaffiliate-*` procedural documentation set.

### Changed

- Version reset to 1.0.0. The 1.0.7 prototype was never released.
