#!/bin/env bash

set -eu

USER_ID=$(id -u)
GROUP_ID=$(id -g)
export USER_ID
export GROUP_ID

trap "docker compose down" EXIT

docker compose run --rm -it composer install --ignore-platform-reqs
docker compose run --rm -it composer dump-autoload

docker compose up -d --wait wiremock freshrss caddy

docker compose exec -w /var/www/FreshRSS freshrss \
	./cli/prepare.php

docker compose exec -w /var/www/FreshRSS freshrss \
	./cli/do-install.php \
	--default-user admin \
	--auth-type none \
	--environment development \
	--db-type sqlite

docker compose exec -w /var/www/FreshRSS freshrss \
	./cli/create-user.php \
	--user admin

# There is no FreshRSS CLI command to enable an extension, so replicate what
# extensionController::enableAction() does: find it by metadata.json's "name"
# (AutoTTL), install() it, then add it to the user's extensions_enabled list.
docker compose exec -w /var/www/FreshRSS freshrss php -r '
	require "/var/www/FreshRSS/cli/_cli.php";
	cliInitUser("admin");
	$ext = Minz_ExtensionManager::findExtension("AutoTTL");
	if ($ext === null) {
		fwrite(STDERR, "AutoTTL extension not found\n");
		exit(1);
	}
	if (!$ext->isEnabled()) {
		if ($ext->install() !== true) {
			fwrite(STDERR, "AutoTTL extension failed to install\n");
			exit(1);
		}
		$conf = FreshRSS_Context::userConf();
		$ext_list = $conf->extensions_enabled;
		$ext_list["AutoTTL"] = true;
		$conf->extensions_enabled = $ext_list;
		$conf->save();
	}
'

# The steps above ran via `exec` as root, but Apache serves as an unprivileged
# user - fix ownership so it can actually read what was just written (same
# step the image's own entrypoint runs after an install).
docker compose exec -w /var/www/FreshRSS freshrss \
	./cli/access-permissions.sh --only-userdirs

echo
echo "FreshRSS is up:"
echo "  https://autottl.localhost (via Caddy - browser will warn about the untrusted self-signed cert)"
echo "  http://localhost:8080 (direct, no TLS)"
echo
echo "Press Ctrl+C to stop and tear down."

xdg-open https://autottl.localhost || true

sleep infinity
