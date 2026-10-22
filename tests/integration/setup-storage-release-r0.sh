#!/usr/bin/env bash
# Creates a single-use WordPress + MariaDB fixture, wholly separate from Local foo.
set -euo pipefail

repo="$(cd "$(dirname "$0")/../.." && pwd -P)"
gallery="$(cd "$repo/../foogallery" && pwd -P)"
test -f "$repo/migrate.php"
test -f "$gallery/foogallery.php"
command -v docker >/dev/null
command -v wp >/dev/null
command -v php >/dev/null

site="$(mktemp -d /tmp/foogallery-migrate-r0.XXXXXX)"
suffix="${site##*.}"
container="fgm-r0-$suffix"
database="fgm_r0_$(printf '%s' "$suffix" | tr '[:upper:]' '[:lower:]')"
db_password="fgm_r0_pass_$suffix"
root_password="fgm_r0_root_$suffix"
echo "Owned site created: $site"

container_id="$(docker run --detach --name "$container" --label foogallery-migrate-r0=owned \
	--env "MARIADB_ROOT_PASSWORD=$root_password" --env "MARIADB_DATABASE=$database" \
	--env MARIADB_USER=fgm_r0 --env "MARIADB_PASSWORD=$db_password" \
	--publish 127.0.0.1::3306 mariadb:11.4)"
port="$(docker port "$container" 3306/tcp | sed -n 's/^127\.0\.0\.1:\([0-9][0-9]*\)$/\1/p')"
test -n "$port"
for attempt in $(seq 1 30); do
	if docker exec "$container" mariadb --protocol=tcp -h127.0.0.1 -ufgm_r0 "-p$db_password" "$database" -e 'SELECT 1' >/dev/null 2>&1; then break; fi
	sleep 1
done
docker exec "$container" mariadb --protocol=tcp -h127.0.0.1 -ufgm_r0 "-p$db_password" "$database" -e 'SELECT 1' >/dev/null 2>&1

wp core download --path="$site" --version=7.1.3 --skip-content
wp config create --path="$site" --dbname="$database" --dbuser=fgm_r0 \
	--dbpass="$db_password" --dbhost="127.0.0.1:$port" --dbprefix=wp_ --skip-check
wp core install --path="$site" --url="http://fgm-r0-$suffix.invalid" \
	--title='FooGallery Migrate R0' --admin_user=fgmr0admin \
	--admin_password="fgm_r0_admin_$suffix" --admin_email=r0@example.invalid --skip-email
mkdir -p "$site/wp-content/plugins"
ln -s "$repo" "$site/wp-content/plugins/foogallery-migrate"
ln -s "$gallery" "$site/wp-content/plugins/foogallery"
wp plugin activate foogallery foogallery-migrate --path="$site"

php -r '
$path = $argv[1] . "/.fgm-r0-owner.json";
$manifest = array( "kind" => "foogallery-migrate-r0-v1", "site" => $argv[1],
	"database" => $argv[2], "host" => $argv[3], "repo" => $argv[4],
	"container" => $argv[5], "container_id" => $argv[6] );
if ( file_exists( $path ) || false === file_put_contents( $path, json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" ) ) { exit( 1 ); }
chmod( $path, 0600 );
' "$site" "$database" "127.0.0.1:$port" "$repo" "$container" "$container_id"

echo "Container: $container ($container_id)"
echo "Database: $database at 127.0.0.1:$port"
echo "Seed: php $repo/tests/integration/storage-release-r0.php $site seed"
echo "Preflight: php $repo/tests/integration/storage-release-r0.php $site preflight"
echo "Teardown after preserving evidence: bash $repo/tests/integration/teardown-storage-release-r0.sh $site"
