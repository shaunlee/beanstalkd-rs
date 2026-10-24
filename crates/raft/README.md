# bstk-raft

Raft replication for [beanstalkd-rs](https://github.com/shaunlee/beanstalkd-rs),
on [openraft](https://github.com/databendlabs/openraft) 0.9.

Every engine input is a log entry, so the deterministic engine applies the
same committed entries in the same order on every node and every node
computes every reply; each node delivers only the replies for connections it
owns. Nothing is acknowledged to a client before its input is committed on a
majority.

The crate also carries the membership operations the `beanstalkd-rs cluster`
command drives: add, remove, replace and move a node, and growing a cluster
from 1 to 3 or 3 to 5 nodes while it serves.

Part of [beanstalkd-rs](https://github.com/shaunlee/beanstalkd-rs): a
drop-in replacement for beanstalkd. MIT licensed.
