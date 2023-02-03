# FreshRSS-AutoTTL extension

FreshRSS extension for automatic feed refresh TTL based on the average frequency of posts.

# Configuration

The extension has a single configurable value - the max TTL which is 1 day by default.
Feeds are updated at least once per max TTL but no more often than the default TTL.
It is recommended to configure each feed to use the default TTL. This will let AutoTTL work most efficiently.
