# FreshRSS-AutoTTL extension

A FreshRSS extension for automatic feed refresh TTL based on the average frequency of entries.

# Configuration

The extension has a single configurable value - the max TTL which is 1 day by default.
Feeds are updated at least once per max TTL but no more often than the default TTL.
It is recommended to configure each feed to use the default TTL. This will let AutoTTL work most efficiently.

![Screenshot 2023-02-03 at 21-19-02 Extensions · FreshRSS](https://user-images.githubusercontent.com/15255910/216688926-c3705989-d048-4ccd-b242-9edf5ec42686.png)
