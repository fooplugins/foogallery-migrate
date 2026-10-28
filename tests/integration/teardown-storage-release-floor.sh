#!/usr/bin/env bash
# Remove only the exact manifest and label bound disposable fixture.
set -euo pipefail
test "$#" -eq 1
fixture="$(cd "$1" && pwd -P)"
[[ "$fixture" =~ ^/(private/)?tmp/foogallery-migrate-floor\.[A-Za-z0-9]+$ ]]
test -f "$fixture/.fgm-floor-owner.json"
suffix="${fixture##*.}"
network="fgm-floor-$suffix"
container="$network-db"
expected_id="$(php -r '
$m = json_decode( file_get_contents( $argv[1] ), true );
if ( !is_array( $m ) || !isset( $m["kind"], $m["fixture"], $m["network"], $m["container"], $m["container_id"] ) ||
	$m["kind"] !== "foogallery-migrate-floor-v1" || realpath( $m["fixture"] ) !== $argv[2] ||
	$m["network"] !== $argv[3] || $m["container"] !== $argv[4] ||
	!preg_match( "/^[a-f0-9]{64}$/D", $m["container_id"] ) ) { exit( 1 ); }
echo $m["container_id"];
' "$fixture/.fgm-floor-owner.json" "$fixture" "$network" "$container")"
test "$(docker inspect --format '{{.Id}}' "$container")" = "$expected_id"
test "$(docker inspect --format '{{index .Config.Labels "foogallery-migrate-floor"}}' "$container")" = "$suffix"
test "$(docker network inspect --format '{{index .Labels "foogallery-migrate-floor"}}' "$network")" = "$suffix"
test ! -L "$fixture"
docker rm --force --volumes "$container" >/dev/null
docker network rm "$network" >/dev/null
rm -rf -- "$fixture"
echo "Removed owned floor fixture: $fixture"
