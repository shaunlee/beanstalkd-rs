# bstkd

The server binary of [beanstalkd-rs](https://github.com/shaunlee/beanstalkd-rs):
a drop-in replacement for [beanstalkd](https://github.com/beanstalkd/beanstalkd),
the simple work queue, written in Rust.

It speaks the beanstalkd protocol byte for byte, so existing clients work
unmodified, and it keeps the reference's command line (`-l -p -z -b -f -F -s
-V -v`). On top of that: TLS and mutual TLS, token authentication,
Prometheus `/metrics`, `/healthz` and `/readyz`, a TOML configuration file,
graceful shutdown, and a Raft cluster of 3 or 5 nodes.

```sh
cargo install bstkd            # or: cargo binstall bstkd
beanstalkd-rs -l 127.0.0.1 -p 11300 -b ./binlog
```

The binary is `beanstalkd-rs`; the crates.io package is `bstkd` because
`beanstalkd-rs` there is an unrelated client library. (Package and binary
names differ elsewhere too: `ripgrep` installs `rg`.)

Documentation: [migrating from
beanstalkd](https://github.com/shaunlee/beanstalkd-rs#migrating-from-beanstalkd),
[operations guide](https://github.com/shaunlee/beanstalkd-rs/blob/main/docs/OPERATIONS.md),
[compatibility notes](https://github.com/shaunlee/beanstalkd-rs/blob/main/docs/COMPAT.md).
MIT licensed.
