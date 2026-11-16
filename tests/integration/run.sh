#!/usr/bin/env bash
#
# Integration tests for migration snapshots against real WordPress and
# WooCommerce installs.
#
# Usage:
#   DL_DIR=/path/to/downloads tests/integration/run.sh [scenario...]
#
# Scenarios (default: all except large):
#   hpos       core scenario with WooCommerce HPOS order storage
#   legacy     core scenario with legacy (posts) order storage
#   switch     snapshot under HPOS, compare after switching to legacy
#   migrate    snapshot on site A, export, copy the site as "site B", lose
#              some rows the way a partial import does, import, compare
#   unrelated  import site "hpos"'s snapshot on an unrelated fresh site;
#              comparing must require an explicit acknowledgment (run after hpos)
#   large      200,000 posts and 20,000 orders with memory_limit=128M
#              (needs DB=mysql; slow)
#
# DL_DIR needs wordpress.tar.gz, wp-cli.phar, sqlite.zip, woocommerce.zip.
# DB=mysql uses MySQL/MariaDB (DB_NAME is DROPPED and recreated).
#
# Every check prints PASS or FAIL; exit status is non-zero on any failure.

set -uo pipefail

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
DL_DIR="${DL_DIR:?Set DL_DIR}"
BASE="${BASE:-/tmp/umin-integration}"
SLUG="unstoppable-migration-inspector"
TOTAL_PASS=0
TOTAL_FAIL=0

wp_at() { local dir="$1"; shift; php -d memory_limit="${PHP_MEMORY:-512M}" "${DL_DIR}/wp-cli.phar" --path="${dir}" --allow-root --skip-themes "$@" 2>&1; }

build_zip() {
	local build="${BASE}/build"
	rm -rf "${build}" && mkdir -p "${build}/${SLUG}"
	(cd "${REPO_DIR}" && tar --exclude-from=.distignore --exclude='phpunit.xml.dist' --exclude='phpcs.xml.dist' -cf - .) | tar -xf - -C "${build}/${SLUG}"
	(cd "${build}" && zip -qr "${SLUG}.zip" "${SLUG}")
	ZIP="${build}/${SLUG}.zip"
}

# new_site <dir> <hpos yes|no> [db name]
new_site() {
	local dir="$1" hpos="$2" dbname="${3:-umin_int}"
	rm -rf "${dir}" && mkdir -p "${dir}"
	tar -xzf "${DL_DIR}/wordpress.tar.gz" -C "${dir}" --strip-components=1

	if [ "${DB:-sqlite}" = "mysql" ]; then
		wp_at "${dir}" config create --dbname="${dbname}" --dbuser="${DB_USER:-umin}" --dbpass="${DB_PASSWORD:-umin}" --dbhost="${DB_HOST:-localhost}" --skip-check >/dev/null
		mariadb -e "DROP DATABASE IF EXISTS ${dbname}; CREATE DATABASE ${dbname} CHARACTER SET utf8mb4; GRANT ALL ON ${dbname}.* TO '${DB_USER:-umin}'@'localhost';" 2>/dev/null
	else
		unzip -q "${DL_DIR}/sqlite.zip" -d "${dir}/wp-content/plugins/"
		sed -e "s#{SQLITE_IMPLEMENTATION_FOLDER_PATH}#${dir}/wp-content/plugins/sqlite-database-integration#" \
			-e "s#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#" \
			"${dir}/wp-content/plugins/sqlite-database-integration/db.copy" > "${dir}/wp-content/db.php"
		wp_at "${dir}" config create --dbname=wp --dbuser=wp --dbpass=wp --skip-check >/dev/null
	fi

	wp_at "${dir}" config set WP_DEBUG true --raw >/dev/null
	wp_at "${dir}" config set WP_DEBUG_LOG true --raw >/dev/null
	wp_at "${dir}" config set WP_DEBUG_DISPLAY false --raw >/dev/null
	wp_at "${dir}" core install --url="http://old-site.test" --title="UMIN Integration" --admin_user=admin \
		--admin_password=admin --admin_email=owner@private-example.test --skip-email >/dev/null
	wp_at "${dir}" plugin install "${DL_DIR}/woocommerce.zip" --activate >/dev/null
	wp_at "${dir}" plugin install "${ZIP}" --activate >/dev/null

	if [ "${hpos}" = "yes" ]; then
		wp_at "${dir}" option update woocommerce_custom_orders_table_enabled yes >/dev/null
	else
		wp_at "${dir}" option update woocommerce_custom_orders_table_enabled no >/dev/null
	fi
	wp_at "${dir}" option update woocommerce_custom_orders_table_data_sync_enabled no >/dev/null
	rm -f "${dir}/wp-content/debug.log"
}

# run_scenario <label> <site dir> <php file> [args...]
run_scenario() {
	local label="$1" dir="$2" file="$3"
	shift 3
	echo
	echo "######## ${label} ########"
	local out
	out="$(wp_at "${dir}" eval-file "${REPO_DIR}/tests/integration/${file}" "$@")"
	printf '%s\n' "${out}" | grep -E '^(PASS|FAIL|      |==|RESULT|INFO)' || printf '%s\n' "${out}" | tail -30
	local pass fail
	pass="$(grep -c '^PASS' <<<"${out}")"
	fail="$(grep -c '^FAIL' <<<"${out}")"
	if ! grep -q '^RESULT' <<<"${out}"; then
		echo "FAIL  ${label}: scenario did not finish"
		printf '%s\n' "${out}" | tail -20
		fail=$((fail + 1))
	fi
	if [ -s "${dir}/wp-content/debug.log" ] && grep -q 'unstoppable-migration-inspector' "${dir}/wp-content/debug.log"; then
		echo "FAIL  ${label}: plugin wrote to debug.log"
		grep 'unstoppable-migration-inspector' "${dir}/wp-content/debug.log" | head -5
		fail=$((fail + 1))
	else
		echo "PASS  ${label}: no plugin entries in debug.log"
		pass=$((pass + 1))
	fi
	TOTAL_PASS=$((TOTAL_PASS + pass))
	TOTAL_FAIL=$((TOTAL_FAIL + fail))
}

scenario_hpos() {
	new_site "${BASE}/hpos" yes umin_int_hpos
	run_scenario "Core scenario, HPOS storage" "${BASE}/hpos" scenario-core.php "${BASE}/hpos-export.json"
}

scenario_legacy() {
	new_site "${BASE}/legacy" no umin_int_legacy
	run_scenario "Core scenario, legacy storage" "${BASE}/legacy" scenario-core.php "${BASE}/legacy-export.json"
}

scenario_switch() {
	new_site "${BASE}/switch" yes umin_int_switch
	wp_at "${BASE}/switch" option update woocommerce_custom_orders_table_data_sync_enabled yes >/dev/null
	run_scenario "Storage switch: seed and snapshot under HPOS" "${BASE}/switch" scenario-switch.php before
	echo "INFO  Switching WooCommerce to legacy storage: $(wp_at "${BASE}/switch" wc hpos sync 2>&1 | tail -1 | tr -d '\n') / $(wp_at "${BASE}/switch" wc hpos disable 2>&1 | tail -1 | tr -d '\n')"
	run_scenario "Storage switch: compare under legacy storage" "${BASE}/switch" scenario-switch.php after
}

scenario_migrate() {
	new_site "${BASE}/site-a" yes umin_int_a
	run_scenario "Migration: snapshot and export on site A" "${BASE}/site-a" scenario-migrate.php source "${BASE}/site-a-export.json" "${BASE}/site-a-expect.json"

	# "Move" the site: copy files and database to site B.
	rm -rf "${BASE}/site-b"
	cp -a "${BASE}/site-a" "${BASE}/site-b"
	if [ "${DB:-sqlite}" = "mysql" ]; then
		mariadb -e "DROP DATABASE IF EXISTS umin_int_b; CREATE DATABASE umin_int_b CHARACTER SET utf8mb4; GRANT ALL ON umin_int_b.* TO '${DB_USER:-umin}'@'localhost';"
		mariadb-dump umin_int_a | mariadb umin_int_b
		wp_at "${BASE}/site-b" config set DB_NAME umin_int_b >/dev/null
	else
		sed -i "s#${BASE}/site-a/#${BASE}/site-b/#g" "${BASE}/site-b/wp-content/db.php"
	fi
	rm -f "${BASE}/site-b/wp-content/debug.log"

	run_scenario "Migration: partial import on site B, import snapshot file, compare" "${BASE}/site-b" scenario-migrate.php destination "${BASE}/site-a-export.json" "${BASE}/site-a-expect.json"
}

scenario_unrelated() {
	if [ ! -s "${BASE}/hpos-export.json" ]; then
		scenario_hpos
	fi
	new_site "${BASE}/other" yes umin_int_other
	wp_at "${BASE}/other" option update blogname "A Different Shop" >/dev/null
	wp_at "${BASE}/other" option update home "http://different-shop.test" >/dev/null
	run_scenario "Unrelated site needs acknowledgment" "${BASE}/other" scenario-unrelated.php "${BASE}/hpos-export.json"
}

scenario_large() {
	if [ "${DB:-sqlite}" != "mysql" ]; then
		echo "SKIP  large scenario needs DB=mysql"
		return
	fi
	new_site "${BASE}/large" yes umin_int_large
	PHP_MEMORY=128M run_scenario "Large database (memory_limit=128M)" "${BASE}/large" scenario-large.php
}

build_zip
mkdir -p "${BASE}"

SCENARIOS=("$@")
if [ ${#SCENARIOS[@]} -eq 0 ]; then
	SCENARIOS=(hpos legacy switch migrate unrelated)
fi

for s in "${SCENARIOS[@]}"; do
	"scenario_${s}"
done

echo
echo "Integration tests finished: ${TOTAL_PASS} passed, ${TOTAL_FAIL} failed."
[ "${TOTAL_FAIL}" -eq 0 ]
