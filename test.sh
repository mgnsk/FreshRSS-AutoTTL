#!/bin/env bash

set -eu

USER_ID=$(id -u)
GROUP_ID=$(id -g)
export USER_ID
export GROUP_ID

docker compose run --rm -it composer install --ignore-platform-reqs
docker compose run --rm -it composer dump-autoload

function test_sqlite {
	(
		trap "docker compose down" EXIT

		docker compose up -d --wait wiremock freshrss

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

		docker compose exec -w /var/www/FreshRSS/extensions/FreshRSS-AutoTTL freshrss \
			./vendor/bin/phpunit tests
	)
}

function test_mysql {
	(
		trap "docker compose down" EXIT

		docker compose up -d --wait mysql wiremock freshrss

		docker compose exec -w /var/www/FreshRSS freshrss \
			./cli/prepare.php

		docker compose exec -w /var/www/FreshRSS freshrss \
			./cli/do-install.php \
			--default-user admin \
			--auth-type none \
			--environment development \
			--db-type mysql \
			--db-host mysql \
			--db-user freshrss \
			--db-password freshrss \
			--db-base freshrss

		docker compose exec -w /var/www/FreshRSS freshrss \
			./cli/create-user.php \
			--user admin

		docker compose exec -w /var/www/FreshRSS/extensions/FreshRSS-AutoTTL freshrss \
			./vendor/bin/phpunit tests
	)
}

function test_postgres {
	(
		trap "docker compose down" EXIT

		docker compose up -d --wait postgres wiremock freshrss

		docker compose exec -w /var/www/FreshRSS freshrss \
			./cli/prepare.php

		docker compose exec -w /var/www/FreshRSS freshrss \
			./cli/do-install.php \
			--default-user admin \
			--auth-type none \
			--environment development \
			--db-type pgsql \
			--db-host postgres \
			--db-user freshrss \
			--db-password freshrss \
			--db-base freshrss

		docker compose exec -w /var/www/FreshRSS freshrss \
			./cli/create-user.php \
			--user admin

		docker compose exec -w /var/www/FreshRSS/extensions/FreshRSS-AutoTTL freshrss \
			./vendor/bin/phpunit tests
	)
}

test_sqlite
test_mysql
test_postgres
