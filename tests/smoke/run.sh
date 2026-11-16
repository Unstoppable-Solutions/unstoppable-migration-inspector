#!/usr/bin/env bash
#
# Live-install smoke test for Unstoppable Migration Inspector.
#
# Builds the release ZIP from this repo (honouring .distignore), installs it
# on a fresh WordPress site running on SQLite, and checks real behaviour over
# HTTP and WP-CLI. Every check prints PASS or FAIL; the script exits non-zero
# if anything failed.
#
# Requirements: php (with pdo_sqlite), curl, tar, unzip, zip.
#
# Usage:
#   DL_DIR=/path/to/downloads tests/smoke/run.sh
#
# DL_DIR must contain:
#   wordpress.tar.gz   https://wordpress.org/latest.tar.gz
#   wp-cli.phar        https://github.com/wp-cli/wp-cli/releases
#   sqlite.zip         https://downloads.wordpress.org/plugin/sqlite-database-integration.zip
#   plugin-check.zip   https://downloads.wordpress.org/plugin/plugin-check.zip (optional)
#
# Optional: WP_DIR (default /tmp/umin-smoke-wp), PORT (default 8089),
#           MULTISITE=1 to also run the multisite checks.
#           DB=mysql to use MySQL/MariaDB instead of SQLite. The database
#           DB_NAME (default umin_smoke) is DROPPED and recreated, so point it
#           at a throwaway database. Also DB_USER, DB_PASSWORD, DB_HOST.

set -uo pipefail

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
DL_DIR="${DL_DIR:?Set DL_DIR to the folder holding wordpress.tar.gz, wp-cli.phar and sqlite.zip}"
WP_DIR="${WP_DIR:-/tmp/umin-smoke-wp}"
BUILD_DIR="${BUILD_DIR:-/tmp/umin-smoke-build}"
PORT="${PORT:-8089}"
SLUG="unstoppable-migration-inspector"
UMIN_TABLES="umin_snapshots umin_snapshot_chunks umin_comparisons umin_comparison_items umin_jobs umin_scans umin_findings"
SITE="http://127.0.0.1:${PORT}"
JAR_ADMIN="${BUILD_DIR}/admin.cookies"
JAR_SUB="${BUILD_DIR}/subscriber.cookies"

PASSES=0
FAILS=0
SERVER_PID=""

wp() { php "${DL_DIR}/wp-cli.phar" --path="${WP_DIR}" --allow-root --skip-themes "$@" 2>&1; }

pass() { PASSES=$((PASSES + 1)); printf 'PASS  %s\n' "$1"; }
fail() { FAILS=$((FAILS + 1)); printf 'FAIL  %s\n' "$1"; [ -n "${2:-}" ] && printf '      %s\n' "$2"; }
check() { if [ "$2" = "$3" ]; then pass "$1"; else fail "$1" "expected [$3], got [$2]"; fi; }
# Here-strings, not pipes: with pipefail, `printf | grep -q` reports failure
# when grep exits early on a match and printf gets SIGPIPE.
contains() { if grep -qF -- "$3" <<<"$2"; then pass "$1"; else fail "$1" "missing: $3"; fi; }
lacks() { if grep -qF -- "$3" <<<"$2"; then fail "$1" "unexpected: $3"; else pass "$1"; fi; }

cleanup() { [ -n "${SERVER_PID}" ] && kill "${SERVER_PID}" 2>/dev/null; }
trap cleanup EXIT

# --- Build the release ZIP --------------------------------------------------

build_zip() {
	local version
	version="$(grep -m1 -E '^ \* Version:' "${REPO_DIR}/${SLUG}.php" | awk '{print $3}')"
	rm -rf "${BUILD_DIR}" && mkdir -p "${BUILD_DIR}/${SLUG}"
	# tar is used instead of rsync so the script runs on minimal systems.
	(cd "${REPO_DIR}" && tar --exclude-from=.distignore --exclude='phpunit.xml.dist' --exclude='phpcs.xml.dist' \
		--exclude='./build' -cf - .) | tar -xf - -C "${BUILD_DIR}/${SLUG}"
	(cd "${BUILD_DIR}" && zip -qr "${SLUG}-${version}.zip" "${SLUG}")
	ZIP="${BUILD_DIR}/${SLUG}-${version}.zip"
	if ! unzip -l "${ZIP}" | grep -q "${SLUG}/${SLUG}.php"; then
		echo "Build failed: ${ZIP} does not contain ${SLUG}.php"; exit 1
	fi
	echo "Built ${ZIP} ($(unzip -l "${ZIP}" | tail -1 | awk '{print $2}') files)"
}

# --- Fresh WordPress on SQLite ----------------------------------------------

setup_wordpress() {
	rm -rf "${WP_DIR}" && mkdir -p "${WP_DIR}"
	tar -xzf "${DL_DIR}/wordpress.tar.gz" -C "${WP_DIR}" --strip-components=1

	if [ "${DB:-sqlite}" = "mysql" ]; then
		local name="${DB_NAME:-umin_smoke}" user="${DB_USER:-umin}" pass="${DB_PASSWORD:-umin}" host="${DB_HOST:-localhost}"
		wp config create --dbname="${name}" --dbuser="${user}" --dbpass="${pass}" --dbhost="${host}" --skip-check >/dev/null
		wp db reset --yes >/dev/null
	else
		unzip -q "${DL_DIR}/sqlite.zip" -d "${WP_DIR}/wp-content/plugins/"
		sed -e "s#{SQLITE_IMPLEMENTATION_FOLDER_PATH}#${WP_DIR}/wp-content/plugins/sqlite-database-integration#" \
			-e "s#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#" \
			"${WP_DIR}/wp-content/plugins/sqlite-database-integration/db.copy" > "${WP_DIR}/wp-content/db.php"
		wp config create --dbname=wp --dbuser=wp --dbpass=wp --skip-check >/dev/null
	fi

	wp config set WP_DEBUG true --raw >/dev/null
	wp config set WP_DEBUG_LOG true --raw >/dev/null
	wp config set WP_DEBUG_DISPLAY false --raw >/dev/null
	wp core install --url="${SITE}" --title="UMIN Smoke" --admin_user=admin --admin_password=admin \
		--admin_email=admin@example.test --skip-email >/dev/null
	wp user create subscriber sub@example.test --role=subscriber --user_pass=sub >/dev/null
	rm -f "${WP_DIR}/wp-content/debug.log"
	echo "WordPress $(wp core version) installed at ${WP_DIR} (database: ${DB:-sqlite}$( [ "${DB:-sqlite}" = mysql ] && printf ', %s' "$(wp db query 'SELECT VERSION()' --skip-column-names 2>/dev/null)"))"
}

start_server() {
	php -S "127.0.0.1:${PORT}" -t "${WP_DIR}" >"${BUILD_DIR}/server.log" 2>&1 &
	SERVER_PID=$!
	for _ in $(seq 1 50); do curl -s -o /dev/null "${SITE}/" && return 0; sleep 0.2; done
	echo "Server did not start"; exit 1
}

login() { # $1 user, $2 password, $3 cookie jar
	rm -f "$3"
	curl -s -o /dev/null -c "$3" "${SITE}/wp-login.php"
	curl -s -o /dev/null -b "$3" -c "$3" --data-urlencode "log=$1" --data-urlencode "pwd=$2" \
		-d "testcookie=1&wp-submit=Log+In" "${SITE}/wp-login.php"
}

get() { curl -s -b "$1" "${SITE}$2"; }
status_of() { curl -s -o /dev/null -w '%{http_code}' -b "$1" "${SITE}$2"; }

debug_log() { [ -f "${WP_DIR}/wp-content/debug.log" ] && cat "${WP_DIR}/wp-content/debug.log"; }

table_exists() { # $1 unprefixed table, $2 optional prefix
	wp eval "global \$wpdb; \$t = '${2:-wp_}$1'; echo \$wpdb->get_var( \$wpdb->prepare( 'SHOW TABLES LIKE %s', \$t ) ) === \$t ? 'yes' : 'no';"
}

setting() { wp eval "\$s = get_option( 'umin_settings' ); echo is_array( \$s ) ? wp_json_encode( \$s ) : 'none';"; }

settings_nonce() { # $1 tab
	get "${JAR_ADMIN}" "/wp-admin/admin.php?page=umin-settings&tab=$1" \
		| grep -o 'name="_wpnonce" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"$//'
}

save_tab() { # $1 tab, then curl --data args
	local tab="$1" nonce
	shift
	nonce="$(settings_nonce "${tab}")"
	curl -s -o /dev/null -b "${JAR_ADMIN}" -c "${JAR_ADMIN}" \
		-e "${SITE}/wp-admin/admin.php?page=umin-settings&tab=${tab}" \
		--data-urlencode "option_page=umin_settings_group" --data-urlencode "action=update" \
		--data-urlencode "_wpnonce=${nonce}" \
		--data-urlencode "_wp_http_referer=/wp-admin/admin.php?page=umin-settings&tab=${tab}" \
		"$@" "${SITE}/wp-admin/options.php"
}

# --- Checks -----------------------------------------------------------------

run_single_site_checks() {
	echo; echo "== Install and activate the built ZIP =="
	local out
	out="$(wp plugin install "${ZIP}" --activate)"
	contains "ZIP installs" "${out}" "Success: Installed 1 of 1 plugins"
	contains "Plugin activates" "${out}" "activated."
	check "No debug.log output after activation" "$(debug_log)" ""

	for t in ${UMIN_TABLES}; do
		check "Table ${t} created" "$(table_exists "${t}")" "yes"
	done
	check "Schema version stored" "$(wp option get umin_db_version)" "4"
	contains "Data is kept on uninstall by default" "$(setting)" '"delete_data_on_uninstall":false'

	echo; echo "== ZIP contents =="
	local listing
	listing="$(unzip -l "${ZIP}")"
	for f in "${SLUG}/${SLUG}.php" "${SLUG}/readme.txt" "${SLUG}/license.txt" "${SLUG}/uninstall.php" "${SLUG}/assets/css/admin-menu.css"; do
		contains "ZIP contains ${f#${SLUG}/}" "${listing}" "${f}"
	done
	for f in "${SLUG}/docs/" "${SLUG}/tests/" "${SLUG}/vendor/" "${SLUG}/composer.json" "${SLUG}/CLAUDE.md" "${SLUG}/.github/" "${SLUG}/phpcs.xml.dist"; do
		lacks "ZIP excludes ${f#${SLUG}/}" "${listing}" "${f}"
	done

	echo; echo "== Admin screens over HTTP =="
	start_server
	login admin admin "${JAR_ADMIN}"
	local page code
	for path in "/wp-admin/admin.php?page=${SLUG}" "/wp-admin/admin.php?page=umin-snapshots" "/wp-admin/admin.php?page=umin-scan" "/wp-admin/admin.php?page=umin-settings" \
		"/wp-admin/admin.php?page=umin-settings&tab=performance" "/wp-admin/admin.php?page=umin-settings&tab=data" \
		"/wp-admin/admin.php?page=umin-settings&tab=nonsense" "/wp-admin/plugins.php" "/wp-admin/index.php"; do
		code="$(status_of "${JAR_ADMIN}" "${path}")"
		check "HTTP 200 for ${path}" "${code}" "200"
	done
	page="$(get "${JAR_ADMIN}" "/wp-admin/admin.php?page=${SLUG}")"
	contains "Dashboard leads with Scan Website" "${page}" "Scan Website"
	contains "Dashboard shows Not Tested overall" "${page}" "Not tested yet"
	contains "WooCommerce category Not Applicable without WooCommerce" "${page}" "umin-badge--not_applicable"
	lacks "Dashboard never claims healthy before a scan" "${page}" "umin-overall umin-status--passed"
	contains "Scan Website button is active" "${page}" 'data-umin-action="scan"'
	contains "Scan script enqueued on the dashboard" "${page}" "assets/js/scan.js"
	contains "Admin stylesheet enqueued on plugin screen" "${page}" "assets/css/admin.css"
	lacks "No inline style attributes from the plugin" "$(printf '%s' "${page}" | sed -n '/umin-wrap/,$p')" ' style="'
	contains "Gold menu stylesheet enqueued" "$(get "${JAR_ADMIN}" '/wp-admin/index.php')" "assets/css/admin-menu.css"
	lacks "Plugin stylesheet not loaded on unrelated screens" "$(get "${JAR_ADMIN}" '/wp-admin/index.php')" "assets/css/admin.css"
	lacks "No plugin assets on the front end" "$(curl -s "${SITE}/")" "unstoppable-migration-inspector"
	contains "Dashboard link on Plugins screen" "$(get "${JAR_ADMIN}" '/wp-admin/plugins.php')" "page=${SLUG}"
	page="$(get "${JAR_ADMIN}" "/wp-admin/admin.php?page=umin-snapshots")"
	contains "Snapshots screen offers Take Snapshot" "${page}" "Take Snapshot"
	contains "Snapshots screen offers import" "${page}" "Import Snapshot"
	contains "Snapshots script enqueued on Snapshots screen" "${page}" "assets/js/snapshots.js"
	lacks "Snapshots script not loaded on the dashboard" "$(get "${JAR_ADMIN}" "/wp-admin/admin.php?page=${SLUG}")" "assets/js/snapshots.js"
	lacks "No inline script blocks from the plugin" "$(printf '%s' "${page}" | sed -n '/umin-wrap/,/<\/div><!-- wpbody-content/p')" "<script>"
	code="$(status_of "${JAR_SUB}" "/wp-admin/admin.php?page=umin-snapshots")"
	check "Subscriber cannot open Snapshots (before login: redirect)" "$( [ "${code}" = 302 ] || [ "${code}" = 403 ] && echo ok || echo "${code}" )" "ok"

	echo; echo "== Settings: each tab saves independently =="
	save_tab general --data-urlencode "umin_settings[_declared][]=previous_domains" \
		--data-urlencode $'umin_settings[previous_domains]=old.example.com\nhttps://staging.example.com/site'
	save_tab performance --data-urlencode "umin_settings[_declared][]=batch_size" --data-urlencode "umin_settings[_declared][]=time_budget" \
		--data-urlencode "umin_settings[batch_size]=1200" --data-urlencode "umin_settings[time_budget]=5"
	save_tab data --data-urlencode "umin_settings[_declared][]=report_retention_days" --data-urlencode "umin_settings[_declared][]=delete_data_on_uninstall" \
		--data-urlencode "umin_settings[report_retention_days]=30"
	local s
	s="$(setting)"
	contains "General tab value kept after saving other tabs" "${s}" '"previous_domains":["https:\/\/old.example.com","https:\/\/staging.example.com\/site"]'
	contains "Performance tab values saved" "${s}" '"batch_size":1200,"time_budget":5'
	contains "Data tab values saved" "${s}" '"report_retention_days":30,"delete_data_on_uninstall":false'

	save_tab performance --data-urlencode "umin_settings[_declared][]=batch_size" --data-urlencode "umin_settings[_declared][]=time_budget" \
		--data-urlencode "umin_settings[batch_size]=999999" --data-urlencode "umin_settings[time_budget]=6"
	s="$(setting)"
	contains "Out-of-range value rejected, old value kept" "${s}" '"batch_size":1200'
	contains "Valid field in same submission still saved" "${s}" '"time_budget":6'
	contains "Other tabs untouched by a rejected save" "${s}" '"report_retention_days":30'
	page="$(get "${JAR_ADMIN}" "/wp-admin/admin.php?page=umin-settings&tab=performance&settings-updated=true")"
	contains "Rejection message shown to the admin" "${page}" "Please enter a whole number between 50 and 5000"

	save_tab general --data-urlencode "umin_settings[_declared][]=previous_domains" \
		--data-urlencode "umin_settings[previous_domains]=javascript://x" --data-urlencode "umin_settings[siteurl]=http://evil.test"
	s="$(setting)"
	contains "Invalid domain rejected" "${s}" 'old.example.com'
	lacks "Unknown key not stored" "${s}" 'evil.test'
	check "siteurl untouched" "$(wp option get siteurl)" "${SITE}"

	echo; echo "== Permissions =="
	login subscriber sub "${JAR_SUB}"
	code="$(status_of "${JAR_SUB}" "/wp-admin/admin.php?page=${SLUG}")"
	check "Subscriber cannot open the dashboard" "${code}" "403"
	local before
	before="$(setting)"
	curl -s -o /dev/null -b "${JAR_SUB}" --data "option_page=umin_settings_group&action=update&umin_settings[batch_size]=100" "${SITE}/wp-admin/options.php"
	check "Subscriber cannot change settings" "$(setting)" "${before}"

	echo; echo "== REST API =="
	code="$(curl -s -o /dev/null -w '%{http_code}' "${SITE}/?rest_route=/${SLUG}/v1/status")"
	check "Anonymous REST request refused" "${code}" "401"
	local sub_nonce admin_nonce body
	sub_nonce="$(get "${JAR_SUB}" '/wp-admin/admin-ajax.php?action=rest-nonce')"
	code="$(curl -s -o /dev/null -w '%{http_code}' -b "${JAR_SUB}" -H "X-WP-Nonce: ${sub_nonce}" "${SITE}/?rest_route=/${SLUG}/v1/status")"
	check "Subscriber REST request refused" "${code}" "403"
	code="$(curl -s -o /dev/null -w '%{http_code}' -b "${JAR_ADMIN}" "${SITE}/?rest_route=/${SLUG}/v1/status")"
	check "Admin cookie without nonce refused" "${code}" "401"
	admin_nonce="$(get "${JAR_ADMIN}" '/wp-admin/admin-ajax.php?action=rest-nonce')"
	body="$(curl -s -b "${JAR_ADMIN}" -H "X-WP-Nonce: ${admin_nonce}" "${SITE}/?rest_route=/${SLUG}/v1/status")"
	contains "Admin REST status returns overall" "${body}" '"overall":"not_tested"'
	contains "Admin can modify on single site" "${body}" '"can_modify":true'

	echo; echo "== Snapshot endpoints: permissions and nonces =="
	code="$(curl -s -o /dev/null -w '%{http_code}' -X POST "${SITE}/?rest_route=/${SLUG}/v1/snapshots")"
	check "Anonymous cannot start a snapshot" "${code}" "401"
	code="$(curl -s -o /dev/null -w '%{http_code}' -X POST -b "${JAR_SUB}" -H "X-WP-Nonce: ${sub_nonce}" "${SITE}/?rest_route=/${SLUG}/v1/snapshots")"
	check "Subscriber cannot start a snapshot" "${code}" "403"
	code="$(curl -s -o /dev/null -w '%{http_code}' -X POST -b "${JAR_SUB}" -H "X-WP-Nonce: ${sub_nonce}" "${SITE}/?rest_route=/${SLUG}/v1/jobs/1/run")"
	check "Subscriber cannot run jobs" "${code}" "403"
	code="$(curl -s -o /dev/null -w '%{http_code}' -X POST -b "${JAR_ADMIN}" "${SITE}/?rest_route=/${SLUG}/v1/snapshots")"
	check "Admin without REST nonce cannot start a snapshot" "${code}" "401"
	code="$(curl -s -o /dev/null -w '%{http_code}' -b "${JAR_SUB}" -F "action=umin_import_snapshot" -F "_wpnonce=x" -F "umin_snapshot_file=@${REPO_DIR}/readme.txt" "${SITE}/wp-admin/admin-post.php")"
	check "Subscriber cannot import a snapshot" "${code}" "403"
	code="$(curl -s -o /dev/null -w '%{http_code}' -b "${JAR_ADMIN}" -F "action=umin_import_snapshot" -F "_wpnonce=forged" -F "umin_snapshot_file=@${REPO_DIR}/readme.txt" "${SITE}/wp-admin/admin-post.php")"
	check "Admin import with a forged nonce is refused" "${code}" "403"
	code="$(curl -s -o /dev/null -w '%{http_code}' -b "${JAR_ADMIN}" "${SITE}/wp-admin/admin-post.php?action=umin_export_snapshot&id=1&_wpnonce=forged")"
	check "Export with a forged nonce is refused" "${code}" "403"
	code="$(curl -s -o /dev/null -w '%{http_code}' -b "${JAR_SUB}" "${SITE}/wp-admin/admin-post.php?action=umin_comparison_csv&id=1&kind=missing&_wpnonce=x")"
	check "Subscriber cannot download comparison CSVs" "${code}" "403"
	body="$(curl -s -X POST -b "${JAR_ADMIN}" -H "X-WP-Nonce: ${admin_nonce}" -H 'Content-Type: application/json' -d '{"label":"<script>alert(1)</script>Smoke"}' "${SITE}/?rest_route=/${SLUG}/v1/snapshots")"
	contains "Admin can start a snapshot" "${body}" '"job_id"'
	lacks "Snapshot labels are stripped of markup" "$(get "${JAR_ADMIN}" "/wp-admin/admin.php?page=umin-snapshots")" "<script>alert(1)"

	echo; echo "== Scanner endpoints and a full scan over HTTP =="
	code="$(curl -s -o /dev/null -w '%{http_code}' -X POST "${SITE}/?rest_route=/${SLUG}/v1/scans")"
	check "Anonymous cannot start a scan" "${code}" "401"
	code="$(curl -s -o /dev/null -w '%{http_code}' -X POST -b "${JAR_SUB}" -H "X-WP-Nonce: ${sub_nonce}" "${SITE}/?rest_route=/${SLUG}/v1/scans")"
	check "Subscriber cannot start a scan" "${code}" "403"
	code="$(curl -s -o /dev/null -w '%{http_code}' -X POST -b "${JAR_ADMIN}" "${SITE}/?rest_route=/${SLUG}/v1/scans")"
	check "Admin without REST nonce cannot start a scan" "${code}" "401"
	body="$(curl -s -X POST -b "${JAR_ADMIN}" -H "X-WP-Nonce: ${admin_nonce}" "${SITE}/?rest_route=/${SLUG}/v1/scans")"
	contains "Admin can start a scan" "${body}" '"job_id"'
	local scan_job scan_id status i
	scan_job="$(printf '%s' "${body}" | sed -n 's/.*"job_id":\([0-9]*\).*/\1/p')"
	scan_id="$(printf '%s' "${body}" | sed -n 's/.*"scan_id":\([0-9]*\).*/\1/p')"
	status=""
	for i in $(seq 1 200); do
		status="$(curl -s -X POST -b "${JAR_ADMIN}" -H "X-WP-Nonce: ${admin_nonce}" "${SITE}/?rest_route=/${SLUG}/v1/jobs/${scan_job}/run" | sed -n 's/^{"status":"\([a-z]*\)".*/\1/p')"
		[ "${status}" = "running" ] || break
	done
	check "Scan driven over REST completes" "${status}" "completed"
	code="$(curl -s -o /dev/null -w '%{http_code}' -b "${JAR_SUB}" -H "X-WP-Nonce: ${sub_nonce}" "${SITE}/?rest_route=/${SLUG}/v1/scans/${scan_id}")"
	check "Subscriber cannot read scan results" "${code}" "403"
	code="$(curl -s -o /dev/null -w '%{http_code}' -X DELETE -b "${JAR_SUB}" -H "X-WP-Nonce: ${sub_nonce}" "${SITE}/?rest_route=/${SLUG}/v1/scans/${scan_id}")"
	check "Subscriber cannot delete scans" "${code}" "403"
	contains "Scan status is complete over REST" "$(curl -s -b "${JAR_ADMIN}" -H "X-WP-Nonce: ${admin_nonce}" "${SITE}/?rest_route=/${SLUG}/v1/scans/${scan_id}")" '"status":"complete"'
	page="$(get "${JAR_ADMIN}" "/wp-admin/admin.php?page=umin-scan&scan=${scan_id}")"
	contains "Results screen shows Migration Health" "${page}" "Migration Health:"
	contains "Results screen lists checks run" "${page}" "Checks run"
	contains "Results screen explains why findings matter" "${page}" "Why it matters:"
	contains "Results screen gives a next step" "${page}" "Recommended next step:"
	lacks "No inline style attributes on the results screen" "$(printf '%s' "${page}" | sed -n '/umin-wrap/,$p')" ' style="'
	lacks "Dashboard no longer says Not tested after a scan" "$(get "${JAR_ADMIN}" "/wp-admin/admin.php?page=${SLUG}")" "Not tested yet"
	code="$(status_of "${JAR_SUB}" "/wp-admin/admin.php?page=umin-scan")"
	check "Subscriber cannot open Scan Results" "$( [ "${code}" = 302 ] || [ "${code}" = 403 ] && echo ok || echo "${code}" )" "ok"

	echo; echo "== UMIN_DISABLE_WRITES kill switch =="
	wp config set UMIN_DISABLE_WRITES true --raw >/dev/null
	sleep 3 # the long-running PHP server keeps a cached wp-config.php for ~2 s after an edit
	body="$(curl -s -b "${JAR_ADMIN}" -H "X-WP-Nonce: ${admin_nonce}" "${SITE}/?rest_route=/${SLUG}/v1/status")"
	contains "Writes disabled for everyone" "${body}" '"can_modify":false,"writes_disabled":true'
	wp config delete UMIN_DISABLE_WRITES >/dev/null
	sleep 3

	echo; echo "== Deactivate and reactivate =="
	before="$(setting)"
	wp plugin deactivate "${SLUG}" >/dev/null
	check "Settings survive deactivation" "$(setting)" "${before}"
	check "Tables survive deactivation" "$(table_exists umin_scans)" "yes"
	wp plugin activate "${SLUG}" >/dev/null
	check "Settings intact after reactivation" "$(setting)" "${before}"

	echo; echo "== Uninstall: data kept by default =="
	wp plugin deactivate "${SLUG}" >/dev/null
	wp plugin uninstall "${SLUG}" >/dev/null
	check "Plugin files removed" "$(test -d "${WP_DIR}/wp-content/plugins/${SLUG}" && echo present || echo gone)" "gone"
	check "Snapshots table kept" "$(table_exists umin_snapshots)" "yes"
	check "Settings kept" "$(setting)" "${before}"

	echo; echo "== Reinstall keeps prior data =="
	wp plugin install "${ZIP}" --activate >/dev/null
	check "Previous settings still there after reinstall" "$(setting)" "${before}"

	echo; echo "== Uninstall: explicit opt-in removes everything =="
	save_tab data --data-urlencode "umin_settings[_declared][]=report_retention_days" --data-urlencode "umin_settings[_declared][]=delete_data_on_uninstall" \
		--data-urlencode "umin_settings[report_retention_days]=30" --data-urlencode "umin_settings[delete_data_on_uninstall]=1"
	contains "Opt-in saved" "$(setting)" '"delete_data_on_uninstall":true'
	wp option add umin_unrelated_canary keep >/dev/null
	wp plugin deactivate "${SLUG}" >/dev/null
	wp plugin uninstall "${SLUG}" >/dev/null
	for t in ${UMIN_TABLES}; do
		check "Table ${t} removed after opt-in uninstall" "$(table_exists "${t}")" "no"
	done
	check "Settings removed" "$(setting)" "none"
	check "Unrelated data untouched" "$(wp option get umin_unrelated_canary)" "keep"

	check "debug.log clean after all single-site checks" "$(debug_log)" ""
	kill "${SERVER_PID}" 2>/dev/null; SERVER_PID=""
}

run_plugin_check() {
	echo; echo "== WordPress Plugin Check (all checks, including late escaping) =="
	if [ ! -f "${DL_DIR}/plugin-check.zip" ]; then
		echo "SKIP  plugin-check.zip not found in DL_DIR"
		return
	fi
	wp plugin install "${ZIP}" >/dev/null
	wp plugin install "${DL_DIR}/plugin-check.zip" --activate >/dev/null
	local out
	out="$(wp plugin check "${SLUG}" --format=csv --include-experimental)"
	printf '%s\n' "${out}" > "${BUILD_DIR}/plugin-check.csv"
	if grep -q 'Checks complete. No errors found.' <<<"${out}"; then
		pass "Plugin Check: no errors or warnings"
	else
		local errors warnings
		errors="$(grep -c ',ERROR,' <<<"${out}")"
		warnings="$(grep -c ',WARNING,' <<<"${out}")"
		check "Plugin Check errors" "${errors}" "0"
		check "Plugin Check warnings" "${warnings}" "0"
		printf '%s\n' "${out}" | head -40
	fi
	wp plugin deactivate plugin-check >/dev/null
}

run_multisite_checks() {
	echo; echo "== Multisite =="
	rm -f "${WP_DIR}/wp-content/debug.log"
	wp core multisite-convert --title="UMIN Network" >/dev/null
	wp plugin install "${ZIP}" >/dev/null 2>&1
	local out
	out="$(wp plugin activate "${SLUG}" --network)"
	contains "Network activation succeeds" "${out}" "activated"
	check "Main site tables created" "$(table_exists umin_scans)" "yes"

	wp site create --slug=second --title=Second --email=admin@example.test >/dev/null
	check "New site gets its own tables" "$(table_exists umin_scans wp_2_)" "yes"
	wp site create --slug=third --title=Third --email=admin@example.test >/dev/null
	wp user create siteadmin siteadmin@example.test --role=administrator --user_pass=siteadmin --url="${SITE}/third/" >/dev/null 2>&1
	check "Subsite admin may view on own site" \
		"$(wp eval --url="${SITE}/third/" --user=siteadmin 'echo current_user_can( "manage_options" ) ? "yes" : "no";')" "yes"
	check "Subsite admin may NOT modify the database" \
		"$(wp eval --url="${SITE}/third/" --user=siteadmin 'echo current_user_can( "umin_modify_database" ) ? "yes" : "no";')" "no"
	check "Super admin may modify the database" \
		"$(wp eval --url="${SITE}/third/" --user=admin 'echo current_user_can( "umin_modify_database" ) ? "yes" : "no";')" "yes"

	local second_id
	second_id="$(wp site list --field=blog_id --path="${WP_DIR}" | sed -n '2p')"
	wp site delete "${second_id}" --yes >/dev/null
	check "Deleting a site drops its plugin tables" "$(table_exists umin_scans wp_2_)" "no"
	check "debug.log clean after multisite checks" "$(debug_log)" ""
}

# --- Run --------------------------------------------------------------------

build_zip
setup_wordpress
run_single_site_checks
run_plugin_check
if [ "${MULTISITE:-0}" = "1" ]; then
	run_multisite_checks
fi

echo
echo "Smoke test finished: ${PASSES} passed, ${FAILS} failed."
[ "${FAILS}" -eq 0 ]
