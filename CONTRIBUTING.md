# Contributing

Thanks for taking the time to report a bug, propose a change or send a patch.

## Before you start

- **A bug report** needs the server version (`beanstalkd-rs -v`), the
  platform, how the server was started (flags, or the config file with
  secrets removed), and what you expected and got. A protocol transcript
  beats a description: the exact commands you sent and the bytes that came
  back.
- **A behavior change** should say which part of the reference it matches.
  Compatibility with beanstalkd is the project's main constraint: a change
  that deliberately differs from it needs an entry in
  [docs/COMPAT.md](docs/COMPAT.md) with the reason and the test that covers
  it.
- **A security problem** does not go in an issue; see
  [SECURITY.md](SECURITY.md).

## Building

Rust 1.98 or newer.

```sh
cargo build --release -p bstkd
target/release/beanstalkd-rs -l 127.0.0.1 -p 11300
```

`scripts/demo.sh` runs a producer and a worker against a fresh server with
no client library, if you want to see it work before changing anything.

## Testing

The quality gate is what CI runs:

```sh
scripts/check.sh
```

It runs `cargo fmt --check`, clippy with `-D warnings` (including the
test-only membership feature), the workspace tests, and the cluster
membership suite.

The differential suite replays the same operations against the reference C
implementation and requires identical replies. It needs a reference build:

```sh
scripts/build-ref.sh              # builds .ref/beanstalkd/beanstalkd
cargo test -p bstk-compat         # 222 cases across nine suites
```

`clients/run-smoke.sh` runs the Python `greenstalk` and Go `beanstalk`
clients against both servers and diffs the normalized transcripts; set
`SMOKE_TLS=1`, `SMOKE_HTTP=1` or `SMOKE_CLUSTER=1` for those paths.

## Pull requests

- Keep the change focused, and separate refactors from behavior changes.
- Add a test for every behavior change. The engine has property tests, and
  `crates/engine-oracle` is a frozen copy that catches accidental changes.
- Record deliberate differences from the reference in `docs/COMPAT.md`, and
  user-facing changes in `CHANGELOG.md` under `Unreleased`.
- CI must be green.

## Commit messages

A one-line subject, `Area: summary`, then a body that explains why. Recent
examples from the history:

```
Engine: expire a re-reserved job on time
binlog: fail-stop on a write error instead of disabling the log
Bench: openraft 0.10 evaluation
```

## License

By contributing you agree that your contribution is MIT licensed, like the
rest of the project.
