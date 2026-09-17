#!/usr/bin/env bash
#
# Run WordPress.org's Plugin Check against the built release directory.
#
# The check runs on build/flyaffiliate — the exact tree that goes into
# build/flyaffiliate-v<version>.zip — inside the wp-env `tests` environment, where the
# plugin directory name is the real slug so the slug-dependent checks
# (text domain, readme, headers) see what wp.org will see.
#
# Gate: zero errors and zero warnings. Anything else fails the build.

set -euo pipefail

cd "$(dirname "$0")/.."

SLUG="flyaffiliate"
CONTAINER_PLUGINS="/var/www/html/wp-content/plugins"
SOURCE_MOUNT="${CONTAINER_PLUGINS}/${SLUG}-src"

if ! docker info >/dev/null 2>&1; then
	echo "error: Docker is not running. Start Docker, then 'npm run env:start'." >&2
	exit 1
fi

echo "==> Staging the release directory"
php bin/build-zip.php --no-zip

echo "==> Copying build/${SLUG} into the tests environment as ${SLUG}"
npx wp-env run tests-cli -- bash -c \
	"rm -rf ${CONTAINER_PLUGINS}/${SLUG} && cp -r ${SOURCE_MOUNT}/build/${SLUG} ${CONTAINER_PLUGINS}/${SLUG}"

# Plugin Check registers its WP-CLI command from the plugin, so it has to be
# active. The tests environment's database is rebuilt by the PHPUnit run, which
# clears active_plugins, so this cannot be assumed.
echo "==> Activating Plugin Check"
npx wp-env run tests-cli -- wp plugin activate plugin-check >/dev/null 2>&1 || true

echo "==> Running Plugin Check"
# CSV keeps the result machine-readable; the human-readable table follows only
# when something is found, so a clean run stays quiet.
RESULT="$(npx wp-env run tests-cli -- wp plugin check "${SLUG}" \
	--format=csv \
	--fields=file,line,column,type,code,message \
	--severity=5 2>/dev/null | tr -d '\r')"

# Drop the CSV header and any wp-env chatter that is not a result row.
FINDINGS="$(printf '%s\n' "${RESULT}" | sed -n '/^file,line,column,type,code,message$/,$p' | tail -n +2 | sed '/^$/d')"

if [ -z "${FINDINGS}" ]; then
	echo
	echo "Plugin Check: 0 errors, 0 warnings."
	exit 0
fi

ERRORS="$(printf '%s\n' "${FINDINGS}" | grep -c ',ERROR,' || true)"
WARNINGS="$(printf '%s\n' "${FINDINGS}" | grep -c ',WARNING,' || true)"

echo
npx wp-env run tests-cli -- wp plugin check "${SLUG}" --format=table --severity=5 || true
echo
echo "Plugin Check: ${ERRORS} errors, ${WARNINGS} warnings — the gate requires 0 of each." >&2
exit 1
