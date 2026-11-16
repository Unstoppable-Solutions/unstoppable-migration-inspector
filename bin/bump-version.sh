#!/usr/bin/env bash
#
# Set the plugin version everywhere it appears.
#
# Usage: bin/bump-version.sh X.Y.Z
#
# Updates:
#   unstoppable-migration-inspector.php  Version: header and UMIN_VERSION
#   readme.txt                           Stable tag: and the development-build line
#
# Refuses to run unless readme.txt (== Changelog ==) and CHANGELOG.md already
# have an entry for X.Y.Z, so a version is never bumped without saying what
# changed. tests/unit/VersionTest.php checks the result.
#
# Versioning: each development phase raises the minor version (0.2.0, 0.3.0);
# every build handed over for installation after a change raises the patch
# version (0.3.1). UMIN_VERSION is also the asset version, so a new number
# makes browsers load the new CSS and JS.

set -euo pipefail

NEW="${1:-}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
MAIN="${ROOT}/unstoppable-migration-inspector.php"
README="${ROOT}/readme.txt"
CHANGELOG="${ROOT}/CHANGELOG.md"

if ! [[ "${NEW}" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
	echo "Usage: $0 X.Y.Z" >&2
	exit 1
fi

OLD="$(sed -n "s/^define( 'UMIN_VERSION', '\([0-9.]*\)' );/\1/p" "${MAIN}")"

if [ "${OLD}" = "${NEW}" ]; then
	echo "Already at ${NEW}." >&2
	exit 1
fi

if ! grep -q "^= ${NEW} =" "${README}"; then
	echo "Add a '= ${NEW} =' entry under == Changelog == in readme.txt first." >&2
	exit 1
fi

if ! grep -q "^## \[${NEW}\]" "${CHANGELOG}"; then
	echo "Add a '## [${NEW}]' section to CHANGELOG.md first." >&2
	exit 1
fi

sed -i "s/^ \* Version: .*/ * Version: ${NEW}/" "${MAIN}"
sed -i "s/^define( 'UMIN_VERSION', '[0-9.]*' );/define( 'UMIN_VERSION', '${NEW}' );/" "${MAIN}"
sed -i "s/^Stable tag: .*/Stable tag: ${NEW}/" "${README}"
sed -i "s/\*\*This is a development build ([0-9.]*)\.\*\*/**This is a development build (${NEW}).**/" "${README}"

echo "Version ${OLD} -> ${NEW}"
grep -n "Version:\|UMIN_VERSION'" "${MAIN}"
grep -n "Stable tag:\|development build (" "${README}"
