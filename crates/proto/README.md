# bstk-proto

The beanstalkd wire protocol in Rust: command parsing, response encoding and
the stats types, shared by the server and the Raft layer of
[beanstalkd-rs](https://github.com/shaunlee/beanstalkd-rs).

It follows the reference implementation, not only `protocol.txt`: the
quirks of beanstalkd's parser are part of the contract and are covered by
the compatibility suite. The public types (`Command`, `Response`, `Frame`,
`TubeName`, the stats structs) are what the other crates build on.

```rust
use bstk_proto::Command;

assert_eq!(
    Command::parse(b"put 0 0 60 5\r\n").unwrap().0,
    Command::Put { pri: 0, delay: 0, ttr: 60, size: 5, .. }
);
```

Part of [beanstalkd-rs](https://github.com/shaunlee/beanstalkd-rs): a
drop-in replacement for beanstalkd. MIT licensed.
