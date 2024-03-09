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

![Screenshot 2024-03-09 at 01-54-04 AutoTTL · Extensions · FreshRSS](https://github.com/mgnsk/FreshRSS-AutoTTL/assets/15255910/e5b2fec6-2263-4abb-97da-4b28726c1f2b)
