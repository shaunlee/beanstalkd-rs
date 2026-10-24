# bstk-net

Tokio codec and socket helpers for the beanstalkd protocol, shared by the
client path of [beanstalkd-rs](https://github.com/shaunlee/beanstalkd-rs)
and its Raft transport.

`QuietTcp` registers a TCP stream for read readiness only, and registers a
duplicate descriptor for writes only while a write would block. On kqueue
(macOS, BSD) this removes the extra wake-up that every acknowledged write
otherwise costs, which matters at hundreds of thousands of operations per
second.

Part of [beanstalkd-rs](https://github.com/shaunlee/beanstalkd-rs): a
drop-in replacement for beanstalkd. MIT licensed.
