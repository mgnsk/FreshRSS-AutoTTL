# FreshRSS-AutoTTL extension

A FreshRSS extension for automatic feed refresh TTL based on the average frequency of entries.
It dynamically adjusts the update TTL of feeds which use the default TTL option.

# Configuration

The main configurable value is the max TTL.
Feeds that use the default TTL are updated at an interval between the default and max TTL.
It is recommended to configure max TTL to be greater than default TTL.

For example with default TTL of `1h` and max TTL of `1d`, a feed is updated at least once per day but no more often than once per hour
depending on the average frequency of entries.

When a feed becomes idle (the last entry is more than 2x max TTL ago), the feed will fall back to be updated once every max TTL until the feed becomes active again.

![Screenshot 2024-10-17 at 16-42-11 AutoTTL · Extensions · FreshRSS](https://github.com/user-attachments/assets/ba712811-d65b-4cd7-ba91-c8cba5c40d64)

# Testing

Run `docker compose up` and open browser at `http://localhost:8080`.

## MySQL credentials

Host: `mysql`
Username: `freshrss`
Password: `freshrss`
Database: `freshrss`

## PostgreSQL credentials

Host: `postgres`
Username: `freshrss`
Password: `freshrss`
Database: `freshrss`

To reset, run `docker compose down`.
