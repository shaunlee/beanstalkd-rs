# bstk-engine

The deterministic beanstalkd state machine in Rust: jobs, tubes, priorities,
TTR, delays, bury and kick, pause and drain.

There is no clock, no randomness and no I/O: time is passed in as `now`
(monotonic nanoseconds), so identical inputs yield identical outputs. That is
what makes the compatibility suite (a differential test against the C
reference, replaying the same operations on both) and the Raft layer
possible.

```rust
use bstk_engine::Engine;

let mut engine = Engine::new(Default::default());
let (reply, _) = engine.handle(Command::Put { .. }, now);
```

Part of [beanstalkd-rs](https://github.com/shaunlee/beanstalkd-rs): a
drop-in replacement for beanstalkd. MIT licensed.
