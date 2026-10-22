#!/usr/bin/env bash
# Build an owned PHP 5.4.45 / WordPress 5.0 single-site fixture.
set -euo pipefail

repo="$(cd "$(dirname "$0")/../.." && pwd -P)"
test -f "$repo/migrate.php"
command -v docker >/dev/null
command -v wp >/dev/null
command -v curl >/dev/null
command -v unzip >/dev/null
command -v php >/dev/null
command -v shasum >/dev/null

fixture="$(mktemp -d /tmp/foogallery-migrate-floor.XXXXXX)"
suffix="${fixture##*.}"
network="fgm-floor-$suffix"
db_container="$network-db"
database="fgm_floor_$(printf '%s' "$suffix" | tr '[:upper:]' '[:lower:]')"
db_password="fgm_floor_pass_$suffix"
site="$fixture/site"
echo "Owned floor fixture: $fixture"

curl -fsSL https://www.php.net/distributions/php-5.4.45.tar.gz -o "$fixture/php-5.4.45.tar.gz"
echo '25bc4723955f4e352935258002af14a14a9810b491a19400d76fcdfa9d04b28f  '"$fixture"'/php-5.4.45.tar.gz' | shasum -a 256 -c -
docker build --platform linux/arm64 --tag fgm-floor-php54:5.4.45 \
	--file "$repo/tests/integration/Dockerfile.storage-release-floor" "$fixture"

wp core download --path="$site" --version=5.0 --skip-content --quiet
curl -fsSL https://github.com/wp-cli/wp-cli/releases/download/v2.1.0/wp-cli-2.1.0.phar -o "$site/.wp-cli-2.1.0.phar"
echo '44f54cca06f9d87cdb2a55129bf3dbe468b224a7d9ac87e1620b075b2711bb28  '"$site"'/.wp-cli-2.1.0.phar' | shasum -a 256 -c -
curl -fsSL https://downloads.wordpress.org/plugin/foogallery.1.8.8.zip -o "$fixture/foogallery.1.8.8.zip"
echo 'a215e22fe69ddb2b020b433cf9117ee0766b2149bdaf7d377342fdccc6b32d9d  '"$fixture"'/foogallery.1.8.8.zip' | shasum -a 256 -c -
unzip -q "$fixture/foogallery.1.8.8.zip" -d "$site/wp-content/plugins"
ln -s /repo "$site/wp-content/plugins/foogallery-migrate"

docker network create --label "foogallery-migrate-floor=$suffix" "$network" >/dev/null
db_id="$(docker run --detach --name "$db_container" --network "$network" \
	--label "foogallery-migrate-floor=$suffix" \
	--env MARIADB_RANDOM_ROOT_PASSWORD=yes --env "MARIADB_DATABASE=$database" \
	--env MARIADB_USER=fgm_floor --env "MARIADB_PASSWORD=$db_password" mariadb:11.4)"
for attempt in $(seq 1 30); do
	if docker exec "$db_container" mariadb --protocol=tcp -h127.0.0.1 -ufgm_floor "-p$db_password" "$database" -e 'SELECT 1' >/dev/null 2>&1; then break; fi
	sleep 1
done
docker exec "$db_container" mariadb --protocol=tcp -h127.0.0.1 -ufgm_floor "-p$db_password" "$database" -e 'SELECT 1' >/dev/null 2>&1

floor_wp() {
	docker run --rm --platform linux/arm64 --network "$network" \
		--volume "$site:/site" --volume "$repo:/repo:ro" --workdir /site \
		--env WP_CLI_DISABLE_AUTO_CHECK_UPDATE=1 fgm-floor-php54:5.4.45 \
		php /site/.wp-cli-2.1.0.phar "$@" --allow-root
}
php -r '
$path = $argv[1] . "/.fgm-floor-owner.json";
$manifest = array( "kind" => "foogallery-migrate-floor-v1", "fixture" => $argv[1],
	"site" => $argv[1] . "/site", "repo" => $argv[2], "network" => $argv[3],
	"container" => $argv[4], "container_id" => $argv[5], "database" => $argv[6] );
if ( file_exists( $path ) || false === file_put_contents( $path, json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" ) ) { exit( 1 ); }
chmod( $path, 0600 );
' "$fixture" "$repo" "$network" "$db_container" "$db_id" "$database"

floor_wp config create --path=/site --dbname="$database" --dbuser=fgm_floor \
	--dbpass="$db_password" --dbhost="$db_container" --dbprefix=wp_ --skip-check
floor_wp core install --path=/site --url="http://fgm-floor-$suffix.invalid" \
	--title='FooGallery Migrate floor' --admin_user=fgmflooradmin \
	--admin_password="fgm_floor_admin_$suffix" --admin_email=floor@example.invalid --skip-email
floor_wp plugin activate foogallery --path=/site
floor_wp plugin activate foogallery-migrate --path=/site

floor_wp core version --path=/site
floor_wp plugin list --status=active --field=name --path=/site
echo "Floor runner: bash $repo/tests/integration/run-storage-release-floor.sh $fixture <WP-CLI arguments>"
echo "Seed: bash $repo/tests/integration/run-storage-release-floor.sh $fixture eval-file /repo/tests/integration/storage-release-floor-native-seed.php --user=fgmflooradmin"
echo "Preflight: bash $repo/tests/integration/run-storage-release-floor.sh $fixture eval-file /repo/tests/integration/storage-release-floor-preflight.php --user=fgmflooradmin"
echo "Teardown: bash $repo/tests/integration/teardown-storage-release-floor.sh $fixture"
