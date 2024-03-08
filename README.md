# FreshRSS-AutoTTL extension

A FreshRSS extension for automatic feed refresh TTL based on the average frequency of entries.
It handles only feeds which use the default TTL option.

# Configuration

The extension has a single configurable value - the max TTL which is 1 day by default.
It is recommended to configure max TTL to be greater than default TTL. For example `1d` and `1h`.

Feed update interval is at least once per max TTL but no more often than default TTL.

When a feed becomes idle (the last entry is more than 2x max TTL ago), the feed will fall back to be updated once every max TTL until the feed becomes active again.

![Screenshot](https://user-images.githubusercontent.com/15255910/224358248-e2c30f62-f250-4ec6-9858-2505eded4aae.png)
