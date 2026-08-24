# FreshRSS-AutoTTL extension

A FreshRSS extension for automatic feed refresh TTL based on the average frequency of entries.
It dynamically adjusts the update TTL of feeds which use the default TTL option.

# Configuration

The main configurable value is the max TTL.
Feeds that use the default TTL are updated at an interval between the default and max TTL.
It is recommended to configure max TTL to be greater than default TTL.

For example with default TTL of `1h` and max TTL of `1d`, a feed is updated at least once per day but no more often than once per hour
depending on the average frequency of entries.

![Screenshot 2024-10-17 at 16-42-11 AutoTTL · Extensions · FreshRSS](https://github.com/user-attachments/assets/ba712811-d65b-4cd7-ba91-c8cba5c40d64)

# Interaction with FreshRSS's hidden HTTP cache

FreshRSS has its own system-wide HTTP cache floor (`limits.cache_duration` in `data/config.php`, default 800 seconds)
that is **not exposed in the admin web UI**. Before performing a real HTTP fetch, FreshRSS serves the on-disk feed
cache instead if it is younger than this duration, and a feed's "last update" timestamp only advances when a real
fetch actually happens. This means a feed can never be refreshed more often than this hidden interval, regardless of
cron frequency or any TTL setting.

AutoTTL reads this value from FreshRSS's system configuration and floors its own computed TTL at it, so its stats
table and throttling decisions stay consistent with what FreshRSS will actually do. If `limits.cache_duration` is set
higher than AutoTTL's own max TTL, AutoTTL-managed feeds will never refresh faster than that.

# Testing

## Manually

- `docker compose up -d freshrss mysql postgres`
- open browser at `http://localhost:8080`.

## MySQL credentials

- Host: `mysql`
- Username: `freshrss`
- Password: `freshrss`
- Database: `freshrss`

## PostgreSQL credentials

- Host: `postgres`
- Username: `freshrss`
- Password: `freshrss`
- Database: `freshrss`

To reset, run `docker compose down`.

Run `docker compose exec freshrss php /var/www/FreshRSS/app/actualize_script.php` to run the actualization script manually.

## Unit tests

- `bash test.sh`
