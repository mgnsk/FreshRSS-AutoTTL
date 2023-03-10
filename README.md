# FreshRSS-AutoTTL extension

A FreshRSS extension for automatic feed refresh TTL based on the average frequency of entries.

# Configuration

The extension has a single configurable value - the max TTL which is 1 day by default.
Feed update interval is at least once per max TTL but no more often than feed TTL.
When feed TTL is larger than max TTL, the feed TTL will be respected.

When a feed becomes idle (the last entry is more than 2x max TTL ago) and feed TTL is less than max TTL,
the feed will be updated once every max TTL until the feed becomes active again.

It is recommended to configure each feed to use the default TTL and set the default TTL to 1h. This will let AutoTTL work most efficiently.

The extension includes a simple feed frequency statistics table on its configuration page.

![Screenshot](https://user-images.githubusercontent.com/15255910/224358248-e2c30f62-f250-4ec6-9858-2505eded4aae.png)

