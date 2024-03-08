# FreshRSS-AutoTTL extension

A FreshRSS extension for automatic feed refresh TTL based on the average frequency of entries.
It handles only feeds which use the default TTL option.

# Configuration

The extension has a single configurable value - the max TTL which is 1 day by default.
It is recommended to configure max TTL to be greater than default TTL. For example `1d` and `1h`.

Feed update interval is at least once per max TTL but no more often than default TTL.

When a feed becomes idle (the last entry is more than 2x max TTL ago), the feed will fall back to be updated once every max TTL until the feed becomes active again.

![Screenshot 2024-03-08 at 22-45-24 Extensions · FreshRSS](https://github.com/mgnsk/FreshRSS-AutoTTL/assets/15255910/6811b5d3-0820-4d88-b1fa-0bd4f8c0e9e9)
