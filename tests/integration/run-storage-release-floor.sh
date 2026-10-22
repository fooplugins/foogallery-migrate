#!/usr/bin/env bash
# Execute WP-CLI against an exact manifest-bound disposable floor site.
set -euo pipefail
test "$#" -ge 2
repo="$(cd "$(dirname "$0")/../.." && pwd -P)"
fixture="$(cd "$1" && pwd -P)"
[[ "$fixture" =~ ^/(private/)?tmp/foogallery-migrate-floor\.[A-Za-z0-9]+$ ]]
manifest="$fixture/.fgm-floor-owner.json"
test -f "$manifest"
expected_id="$(php -r '
$m = json_decode( file_get_contents( $argv[1] ), true );
if ( !is_array( $m ) || !isset( $m["kind"], $m["fixture"], $m["site"], $m["repo"], $m["network"], $m["container"], $m["container_id"], $m["database"] ) ||
	$m["kind"] !== "foogallery-migrate-floor-v1" || realpath( $m["fixture"] ) !== $argv[2] ||
	realpath( $m["site"] ) !== realpath( $argv[2] . "/site" ) ||
	realpath( $m["repo"] ) !== $argv[3] || !is_file( $argv[2] . "/site/wp-load.php" ) ||
	!preg_match( "/^fgm-floor-[A-Za-z0-9]+$/D", $m["network"] ) ||
	$m["container"] !== $m["network"] . "-db" ||
	!preg_match( "/^fgm_floor_[a-z0-9]+$/D", $m["database"] ) ||
	!preg_match( "/^[a-f0-9]{64}$/D", $m["container_id"] ) ) { exit( 1 ); }
echo $m["container_id"];
' "$manifest" "$fixture" "$repo")"
network="fgm-floor-${fixture##*.}"
test "$(docker inspect --format '{{.Id}}' "$network-db")" = "$expected_id"
test "$(docker inspect --format '{{index .Config.Labels "foogallery-migrate-floor"}}' "$network-db")" = "${fixture##*.}"
test "$(docker network inspect --format '{{index .Labels "foogallery-migrate-floor"}}' "$network")" = "${fixture##*.}"
test "$(readlink "$fixture/site/wp-content/plugins/foogallery-migrate")" = /repo
read_config() {
	docker run --rm --platform linux/arm64 --network none \
		--volume "$fixture/site:/site:ro" --workdir /site \
		--env WP_CLI_DISABLE_AUTO_CHECK_UPDATE=1 fgm-floor-php54:5.4.45 \
		php /site/.wp-cli-2.1.0.phar config get "$1" --type=constant --path=/site --allow-root
}
test "$(read_config DB_NAME)" = "fgm_floor_$(printf '%s' "${fixture##*.}" | tr '[:upper:]' '[:lower:]')"
test "$(read_config DB_HOST)" = "$network-db"
shift
docker run --rm --platform linux/arm64 --network "$network" \
	--volume "$fixture/site:/site" --volume "$repo:/repo:ro" \
	--volume "$manifest:/fixture-owner.json:ro" --workdir /site \
	--env WP_CLI_DISABLE_AUTO_CHECK_UPDATE=1 fgm-floor-php54:5.4.45 \
	php /site/.wp-cli-2.1.0.phar "$@" --path=/site --allow-root
