#!/usr/bin/env bash
# Bounded fresh-process source-only seeding on a manifest-owned R0 fixture.
set -euo pipefail

if [[ $# -ne 1 ]]; then
	echo 'Usage: bash storage-release-real-seed.sh /tmp/foogallery-migrate-r0.XXXXXX' >&2
	exit 2
fi
repo="$(cd "$(dirname "$0")/../.." && pwd -P)"
runner="$repo/tests/integration/storage-release-real-journey.php"
site="$1"
command -v php >/dev/null
if php "$runner" "$site" status 2>/dev/null | php -r '
$s = json_decode( stream_get_contents( STDIN ), true );
exit( is_array( $s ) && isset( $s["phase"] ) && "ready" === $s["phase"] ? 0 : 1 );
'; then
	php "$runner" "$site" verify-source
	exit 0
fi
for attempt in $(seq 1 40); do
	output="$(php "$runner" "$site" seed)"
	printf '%s\n' "$output"
	if printf '%s\n' "$output" | php -r '
$s = json_decode( stream_get_contents( STDIN ), true );
exit( is_array( $s ) && isset( $s["phase"] ) && "ready" === $s["phase"] ? 0 : 1 );
'; then break; fi
done
php "$runner" "$site" verify-source
