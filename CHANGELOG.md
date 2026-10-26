# Changelog

All notable user-facing changes to beanstalkd-rs are recorded here. The
format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and
the project uses [Semantic Versioning](https://semver.org/) (before 1.0, a
minor version may change behavior; such changes are called out here).

Release tags are `v<version>` and match the workspace version in
`Cargo.toml`. 0.5.0 is the first tagged release; the `vN` labels in
`docs/DESIGN.md` §10 are revisions of the design document, not releases.

## [Unreleased]

### Added

- **Container image on ghcr.io**: `ghcr.io/shaunlee/beanstalkd-rs`, the
  same image as `shonhen/beanstalkd-rs` on Docker Hub. A release tag now
  builds both architectures, smoke-tests each and pushes the image
  (`.github/workflows/image.yml`); it was pushed by hand before. 0.5.1 is
  available there as well.

### Fixed

- **`cargo binstall bstkd`**: the `bin-dir` in `[package.metadata.binstall]`
  named the directory holding the binary instead of the binary, so binstall
  fell back to compiling from source. Only the metadata is wrong and 0.5.1
  is immutable, so the fix first takes effect in this release.

## [0.5.1] - 2026-10-24

Packaging, documentation and dependency updates: no protocol, binlog,
cluster or command line changes, so 0.5.0 and 0.5.1 are interchangeable and
a rolling upgrade between them is a plain restart.

### Added

- **crates.io**: the server is published as the `bstkd` package
  (`cargo install bstkd`; the binary is still `beanstalkd-rs`), and
  `cargo binstall bstkd` installs it too. The package is `bstkd` because
  `beanstalkd-rs` on crates.io is
  an unrelated client library. The crates it builds on (`bstk-proto`,
  `bstk-engine`, `bstk-store`, `bstk-net`, `bstk-raft`, plus
  `bstk-engine-oracle`, the frozen engine its tests compare against) are
  published too, in dependency order, by `scripts/publish.sh`.
- **`scripts/demo.sh`**: a demo with no dependencies beyond bash, python3
  and curl. It runs a producer and a worker over the protocol, reads
  `/metrics`, sends SIGTERM and restarts on the same binlog to show that
  the jobs survived.
- **README**: a "Migrating from beanstalkd" section (the protocol and the
  command line are compatible; the binlog directory is not, plus the other
  behavior changes), "Is it ready?" (the test evidence) and "When
  something else fits better".
- crates.io metadata for every published crate (description, keywords,
  categories, README) and `[package.metadata.binstall]` for the server.

### Changed

- The server package is named `bstkd` instead of `bstk-server`. The binary,
  the release archives, the container image and the systemd unit are still
  `beanstalkd-rs`.
- Dependencies: rustls 0.23.46, rustls-webpki 0.103.16 and tokio 1.54.1,
  which fix RUSTSEC-2026-0346, -0347, -0382 and -0386.

## [0.5.0] - 2026-10-10

First release. A Rust reimplementation of
[beanstalkd](https://github.com/beanstalkd/beanstalkd) 1.13 that existing
clients can use unmodified.

### Added

- **Protocol compatibility**: every command, reply, error code and edge case
  of the beanstalkd text protocol, checked byte for byte against the
  reference server by differential tests and real clients (Python
  greenstalk, Go go-beanstalk). The few intentional differences are listed
  in [docs/COMPAT.md](https://github.com/shaunlee/beanstalkd-rs/blob/main/docs/COMPAT.md).
- **Reference command line**: `-l`, `-p`, `-z`, `-b`, `-f`, `-F`, `-s`,
  `-V`, `-v` with the reference's defaults; `--version` as an alias of `-v`.
  `-u` is rejected: run the server as the desired user (for example with the
  systemd unit in `packaging/systemd/`).
- **Write-ahead log** (`-b DIR`, `-f MS`, `-F`, `-s BYTES`): jobs survive
  restarts and crashes, and no reply is sent before its change reaches the
  log. The on-disk format is not the reference's: a reference binlog
  directory cannot be reused.
- **Graceful shutdown** on SIGTERM / SIGINT (syncs the log; the reference
  exits abruptly), and drain mode on SIGUSR1 as in the reference.
- **Configuration file** (`--config FILE`, TOML; `--check-config` validates
  it): several listeners, binlog, logging, HTTP and cluster settings. See
  [docs/beanstalkd-rs.example.toml](https://github.com/shaunlee/beanstalkd-rs/blob/main/docs/beanstalkd-rs.example.toml) and
  the ready-made files in [packaging/examples/](https://github.com/shaunlee/beanstalkd-rs/tree/main/packaging/examples).
- **TLS and mutual TLS** listeners, and an optional token authentication
  extension (`auth <token>`, needs client support) on TLS listeners, with
  limits on unauthenticated connections.
- **HTTP monitoring** (off by default): `/healthz`, `/readyz`, `/metrics`
  (Prometheus) and `/admin` (read-only JSON). Log lines carry ANSI colors
  only when stderr is a terminal.
- **Cluster mode** (`[cluster]`): 3 or 5 nodes replicate every job and
  connection through Raft over mutual TLS, keep serving through the loss of
  a minority of nodes, and look like one beanstalkd server to clients
  connected to any node. Bootstrap with `--cluster-init`
  (`cluster.initial_voters` chooses the first voters); `[[cluster.peer]]`
  lists seeds and address overrides. A node with an empty data directory
  rejoins against the current voters, one node at a time; a node whose id is
  not a member yet waits until it is added. See
  [docs/DESIGN.md](https://github.com/shaunlee/beanstalkd-rs/blob/main/docs/DESIGN.md)
  §8 and [docs/OPERATIONS.md](https://github.com/shaunlee/beanstalkd-rs/blob/main/docs/OPERATIONS.md).
- **Membership changes at runtime** with the `beanstalkd-rs cluster`
  operator command (`status`, `add`, `promote`, `remove`, `set-addr`) over
  the cluster port's authenticated admin channel (mTLS with the `bstk-admin`
  certificate from `scripts/mkcluster-certs.sh DIR admin`): grow from 1 to 3
  or 3 to 5 nodes, shrink, replace a node or disk, remove the leader, change
  an address, while the cluster serves. Guardrails: one voter added or
  removed per change, only a caught-up learner is promoted, node ids are
  never reused, fewer than 3 voters or a plaintext address off loopback only
  when forced. A removed node stops by itself (exit status 11) once a
  majority of the remaining voters confirm its removal; a node whose Raft
  core stops on a fatal error exits with status 21 so that the service
  manager restarts it.
- **Membership metrics** on `/metrics` and `/admin` (voters, learners,
  members, joint state, learner lag, joining and rejoining phases); cluster
  `/readyz` is 503 on a node that is isolated, not a member, or still
  starting.
- **Operations guide**
  ([docs/OPERATIONS.md](https://github.com/shaunlee/beanstalkd-rs/blob/main/docs/OPERATIONS.md)):
  install, configuration, persistence, security, cluster bootstrap, node
  loss and rejoin, membership runbooks, CA rotation, backup and restore,
  upgrades, monitoring and alerts, troubleshooting.
- **Performance**: 1.2–1.4× the reference's throughput at 10 and 100
  connections with 10–24% less CPU per operation, 2.2× with a binlog at 100
  connections; a 3-node cluster serves about 316k operations per second
  through the leader (tmpfs, Linux on an Apple M6). Worker threads are
  tunable with `--threads` / `server.threads`. See
  [docs/BENCH.md](https://github.com/shaunlee/beanstalkd-rs/blob/main/docs/BENCH.md).
- **Packaging**: release archives for Linux (x86_64, aarch64; glibc 2.34
  or newer, or fully static with musl) and macOS (aarch64) with SHA-256
  checksums, a container image (`Dockerfile`), a hardened systemd unit
  (`RestartPreventExitStatus=10 11`), example configurations
  ([packaging/](https://github.com/shaunlee/beanstalkd-rs/tree/main/packaging))
  and `scripts/mkcluster-certs.sh` for a cluster CA and node certificates.

[Unreleased]: https://github.com/shaunlee/beanstalkd-rs/compare/v0.5.1...HEAD
[0.5.1]: https://github.com/shaunlee/beanstalkd-rs/compare/v0.5.0...v0.5.1
[0.5.0]: https://github.com/shaunlee/beanstalkd-rs/releases/tag/v0.5.0
