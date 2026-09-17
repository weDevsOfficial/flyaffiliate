# ADR-0002 — An in-house, League-shaped DI container

**Status:** Accepted
**Date:** 2026-09-07

## Context

Dokan's container extends `league/container`, vendored into the plugin namespace
with Mozart (`WeDevs\Dokan\ThirdParty\Packages\League\Container`) so that a
Composer package can ship inside the plugin without colliding with another
plugin's copy.

FlyAffiliate ships on WordPress.org, where every line of PHP in the zip is
reviewed as the plugin's own code and where bundled third-party libraries are a
recurring source of review friction and of security advisories the plugin then
owns. The plugin needs exactly three things from a container: shared and
transient definitions, tags, and constructor autowiring. That is a few hundred
lines.

Adding Mozart/Strauss also means a build step whose output must be committed or
regenerated, and a `vendor/` directory that must be pruned before packaging —
one more way for the release zip to be wrong.

## Decision

Write the container in-house under `FlyAffiliate\DependencyManagement`, with the
same public API as `league/container` so that Dokan's usage patterns transfer
verbatim:

```php
$container->add( $id, $concrete )->setShared( true )->addTag( $tag );
$container->addShared( $id, $concrete );
$container->addServiceProvider( new ServiceProvider() );
$container->get( $id );      // one entry
$container->get( $tag );     // every entry carrying that tag, as an array
$container->has( $id );
```

Method names stay camelCase (`addShared`, `addServiceProvider`, `setShared`,
`addTag`) because they are League's names, not new ones — this is the single
exception to the snake_case rule in `CLAUDE.md`, and `phpcs.xml.dist` silences
`WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid` for it.

**No third-party runtime package is ever added to the release zip.** Classes
are autoloaded by Composer's own loader; how that loader reaches the zip is
decided in [ADR-0013](0013-wordpress-first-woocommerce-optional.md), which
retired the in-house `includes/Autoloader.php`.

### Divergence from Dokan: constructor injection

Dokan's `Definition` deliberately replaces constructor injection with method
injection through an `init()` method, because changing a public constructor
signature breaks third-party subclasses. FlyAffiliate has no installed base and
no third-party subclasses, so it autowires **constructors** by type hint, which
is the ordinary PHP idiom and keeps dependencies visible in the signature.

## Consequences

- Nothing in the zip comes from Packagist; PCP sees only first-party code.
- The container is ours to test: `tests/php/src/DependencyManagement/` covers
  shared vs. transient resolution, tags, provider boot order and automatic
  `Hookable` registration.
- League's more advanced features (delegate containers, inflectors, service
  provider aggregates) do not exist here. If one is genuinely needed, it gets
  implemented and tested, not pulled in.
- A class that a service provider registers must be constructible from type
  hints the container can resolve, or the provider must pass the arguments
  explicitly.
