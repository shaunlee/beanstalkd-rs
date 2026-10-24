# bstk-store

The crash-safe write-ahead log behind
[beanstalkd-rs](https://github.com/shaunlee/beanstalkd-rs).

Segments are preallocated and never straddle records; `-f MS` fsyncs at most
once per interval, `-F` never fsyncs, and a reply is never sent before its
change is logged. On a write, fsync or compaction error the server stops
instead of carrying on without a log. Replay truncates a torn tail in the
last segment and refuses to start on corruption in an earlier one.

The on-disk format is beanstalkd-rs's own: it is not the reference's binlog
format, and pointing either server at the other's directory fails with
`bad magic`.

Part of [beanstalkd-rs](https://github.com/shaunlee/beanstalkd-rs): a
drop-in replacement for beanstalkd. MIT licensed.
