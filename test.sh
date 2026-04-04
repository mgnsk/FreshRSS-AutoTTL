#!/bin/env bash

function test_sqlite {
	trap "docker compose down" RETURN

	docker compose up -d wiremock freshrss

	docker compose exec -w /var/www/FreshRSS freshrss \
		./cli/prepare.php

	docker compose exec -w /var/www/FreshRSS freshrss \
		./cli/do-install.php \
		--default-user admin \
		--auth-type none \
		-- environment development \
		--db-type sqlite

	docker compose exec -w /var/www/FreshRSS freshrss \
		./cli/create-user.php \
		--user admin

	docker compose run --rm -it composer install
	docker compose run --rm -it composer dump-autoload

	docker compose exec -w /var/www/FreshRSS/extensions/FreshRSS-AutoTTL freshrss \
		./vendor/bin/phpunit tests
}

test_sqlite

# --db-type can be: 'sqlite' (default), 'mysql' (MySQL or MariaDB), 'pgsql' (PostgreSQL).
# --db-host URL of the database server. Default is 'localhost'.
# --db-user sets database user.
# --db-API password sets database password.
# --db-base sets database name.
# --db-prefix is an optional prefix in front of the names of the tables. We suggest using 'freshrss_' (default).
# This command does not create the default user. Do that with ./cli/create-user.php.
