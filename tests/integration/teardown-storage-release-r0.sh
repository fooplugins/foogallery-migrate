#!/usr/bin/env bash
# Remove only a manifest-bound R0 fixture and its labelled Docker container.
set -euo pipefail
test "$#" -eq 1
site="$(cd "$1" && pwd -P)"
[[ "$site" =~ ^/(private/)?tmp/foogallery-migrate-r0\.[A-Za-z0-9]+$ ]]
test -f "$site/.fgm-r0-owner.json"
read_field() {
	php -r '
	$m = json_decode( file_get_contents( $argv[1] ), true );
	if ( ! is_array( $m ) || ! isset( $m["kind"], $m["site"], $m["container"], $m["container_id"] ) ||
		$m["kind"] !== "foogallery-migrate-r0-v1" || realpath( $m["site"] ) !== $argv[2] ||
		! preg_match( "/^fgm-r0-[A-Za-z0-9]+$/D", $m["container"] ) ||
		! preg_match( "/^[a-f0-9]{64}$/D", $m["container_id"] ) ) { exit( 1 ); }
	echo $m[$argv[3]];
	' "$site/.fgm-r0-owner.json" "$site" "$1"
}
container="$(read_field container)"
expected_id="$(read_field container_id)"
actual_id="$(docker inspect --format '{{.Id}}' "$container")"
label="$(docker inspect --format '{{index .Config.Labels "foogallery-migrate-r0"}}' "$container")"
test "$expected_id" = "$actual_id"
test "$label" = owned
test ! -L "$site"
docker rm --force --volumes "$container"
rm -rf -- "$site"
echo "Removed owned R0 container and site: $container $site"
