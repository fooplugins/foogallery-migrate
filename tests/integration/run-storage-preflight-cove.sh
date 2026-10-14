#!/bin/bash
# Focused exact-head storage primitive checkpoint, NOT a release gate.
set -euo pipefail
umask 077
SOURCE=${1:?absolute clean source repository}
ZIP=${2:?absolute candidate ZIP}
OUT=${3:?absolute new evidence directory}
EXPECTED=${4:?exact candidate commit}
CASE=${5:-storage-preflight}
[[ "$CASE" == storage-preflight || "$CASE" == storage-writer-gate || "$CASE" == storage-writer-adoption || "$CASE" == storage-conversion || "$CASE" == storage-operations || "$CASE" == storage-pinned-transport || "$CASE" == storage-runtime-transport || "$CASE" == storage-callback-drain || "$CASE" == storage-output-drain || "$CASE" == storage-process-death ]] || exit 1
COVE=/opt/homebrew/bin/cove
[[ "$SOURCE" == /* && "$ZIP" == /* && "$OUT" == /* ]] || exit 1
[[ "$SOURCE$ZIP$OUT" != *"'"* ]] || exit 1
[[ "$EXPECTED" =~ ^[0-9a-f]{40}$ ]] || exit 1
[[ $(git -C "$SOURCE" rev-parse HEAD) == "$EXPECTED" ]] || exit 1
[[ -z $(git -C "$SOURCE" status --porcelain) && ! -e "$OUT" && ! -L "$OUT" ]] || exit 1
# Use the same private connection configuration as Cove. Never print credentials.
source "$HOME/Cove/config" || exit 1
db() { MYSQL_PWD="$DB_PASSWORD" mariadb -u "$DB_USER" "$@"; }
dump() { MYSQL_PWD="$DB_PASSWORD" mariadb-dump -u "$DB_USER" "$@"; }
mkdir -p "$OUT/source" "$OUT/package" "$OUT/raw"
git -C "$SOURCE" archive HEAD | tar -xf - -C "$OUT/source"
unzip -q "$ZIP" -d "$OUT/package"
diff -qr "$OUT/source/includes" "$OUT/package/includes" > "$OUT/includes-parity.txt"
for file in migrate.php uninstall.php readme.txt LICENSE; do cmp "$OUT/source/$file" "$OUT/package/$file"; done
printf '%s\n' "$EXPECTED" > "$OUT/candidate.txt"
shasum -a 256 "$ZIP" > "$OUT/package-sha256.txt"
"$COVE" list --json > "$OUT/sites-before.json"
"$COVE" status > "$OUT/services-before.txt"
printf 'case\tresult\texit\tdb_equal\n' > "$OUT/ledger.tsv"
setup=''
before=''
after=''
owned=false
site=''
root=''
database=''
cleanup() {
  if [[ "$owned" == true ]]; then
    [[ $("$COVE" wp "$site" config get DB_NAME) == "$database" ]] || return 1
    local delete_rc=0
    "$COVE" delete "$site" --force --no-reload > "$OUT/raw/$site-delete.txt" 2>&1 || delete_rc=$?
    if [[ -d "$root/public/wp-content/plugins" ]]; then
      rm -rf -- "$root/public/wp-content/plugins/foogallery-migrate-source" "$root/public/wp-content/plugins/foogallery-migrate-package" || return 1
    fi
    [[ "$delete_rc" == 0 && ! -e "$root" && ! -L "$root" ]] || return 1
    [[ $(db -N -e "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='$database'") == 0 ]] || return 1
    "$COVE" list --json > "$OUT/sites-after-$site.json" || return 1
    printf '%s\t%s\tabsent-root-and-database\n' "$site" "$database" >> "$OUT/cleanup.tsv" || return 1
    owned=false
  fi
}
finish() {
  rc=$?
  trap - EXIT
  cleanup || rc=1
  rm -f -- "$setup" "$before" "$after"
  "$COVE" status > "$OUT/services-after.txt" || rc=1
  git -C "$SOURCE" status --porcelain > "$OUT/source-status-after.txt"
  exit "$rc"
}
trap finish EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
setup=$(mktemp "${TMPDIR:?}/fgm-preflight-setup.XXXXXXXX")
before=$(mktemp "${TMPDIR:?}/fgm-preflight-before.XXXXXXXX")
after=$(mktemp "${TMPDIR:?}/fgm-preflight-after.XXXXXXXX")
server=$(db -N -e 'SELECT CONCAT(@@hostname, ":", @@port, ":", @@socket, ":", VERSION())')
[[ -n "$server" ]] || exit 1
printf '%s\n' "$server" > "$OUT/db-server.txt"
for topology in single multi; do
  site="fgm-preflight-$(date -u +%Y%m%d%H%M%S)-$$-$(openssl rand -hex 3)-$topology"
  [[ "$site" =~ ^[a-z0-9]+(-[a-z0-9]+)*$ ]] || exit 1
  root="$HOME/Cove/Sites/$site.localhost"
  database="cove_${site//-/_}_"
  [[ ! -e "$root" && ! -L "$root" ]] || exit 1
  [[ $(db -N -e "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='$database'") == 0 ]] || exit 1
  "$COVE" list --json > "$OUT/sites-before-$site.json"
  printf '%s\t%s\t%s\n' "$topology" "$site" "$database" >> "$OUT/fixtures.tsv"
  args=(add "$site" latest --no-reload)
  [[ "$topology" != multi ]] || args+=(--multisite)
  if "$COVE" "${args[@]}" > "$setup" 2>&1; then
    [[ -f "$root/public/wp-config.php" ]] || exit 1
    [[ $("$COVE" wp "$site" config get DB_NAME) == "$database" ]] || exit 1
    owned=true
  else
    printf '%s\tcreation-failed-not-owned-no-deletion-attempted\n' "$site" >> "$OUT/cleanup.tsv"
    exit 1
  fi
  : > "$setup"
  actual_server=$("$COVE" wp "$site" --skip-plugins --skip-themes eval 'global $wpdb; echo $wpdb->get_var("SELECT CONCAT(@@hostname, CHAR(58), @@port, CHAR(58), @@socket, CHAR(58), VERSION())");')
  [[ "$actual_server" == "$server" ]] || exit 1
  "$COVE" wp "$site" core verify-checksums > "$OUT/raw/$topology-core-checksums.txt" 2>&1
  scopes=(single)
  if [[ "$topology" == multi ]]; then
    "$COVE" wp "$site" --skip-plugins --skip-themes site create --slug=second --title='Owned preflight blog' > "$OUT/raw/second-blog.txt" 2>&1
    scopes=(multi-1 multi-2)
  fi
  for kind in source package; do
    plugin="$root/public/wp-content/plugins/foogallery-migrate-$kind"
    [[ ! -e "$plugin" && ! -L "$plugin" ]] || exit 1
    mkdir "$plugin"
    cp -R "$OUT/$kind/." "$plugin/"
    for scope in "${scopes[@]}"; do
      url="https://$site.localhost/"
      [[ "$scope" != multi-2 ]] || url="https://$site.localhost/second/"
      id="$kind-$scope-$CASE"
      "$COVE" wp "$site" --url="$url" --skip-plugins --skip-themes --user=1 eval 'get_option("siteurl"); current_user_can("manage_options");' > /dev/null 2>&1
      dump --skip-comments --skip-dump-date --skip-extended-insert --no-create-info --compact "$database" > "$before"
      harness="$OUT/source/tests/integration/$CASE.php"
      code="define('FOOGM_STORAGE_TEST_DISPOSABLE', true); define('FOOGM_STORAGE_TEST_ROOT', '$plugin'); require '$harness'; echo 'PREFLIGHT_CASE_COMPLETE:$id' . PHP_EOL;"
      rc=0
      "$COVE" wp "$site" --url="$url" --skip-plugins --skip-themes --user=1 eval "$code" > "$OUT/raw/$id.stdout" 2> "$OUT/raw/$id.stderr" || rc=$?
      if [[ "$rc" == 0 ]]; then
        php "$OUT/source/tests/integration/validate-storage-preflight.php" "$OUT/raw/$id.stdout" "$id" > "$OUT/raw/$id.validated.txt" 2>&1 || rc=1
      fi
      dump --skip-comments --skip-dump-date --skip-extended-insert --no-create-info --compact "$database" > "$after"
      equal=false
      cmp -s "$before" "$after" && equal=true
      if [[ "$equal" != true ]]; then diff -u "$before" "$after" > "$OUT/raw/$id.db-diff.txt" || [[ "$?" == 1 ]]; fi
      result=FAIL
      [[ "$rc" != 0 || "$equal" != true ]] || result=PASS
      printf '%s\t%s\t%s\t%s\n' "$id" "$result" "$rc" "$equal" >> "$OUT/ledger.tsv"
      shasum -a 256 "$before" "$after" > "$OUT/raw/$id.db-hashes.txt"
      printf '%s %s (exit=%s db_equal=%s)\n' "$id" "$result" "$rc" "$equal"
      [[ "$result" == PASS ]] || exit 1
    done
  done
  cleanup
done
