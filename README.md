# beanstalkd-rs

[![CI](https://github.com/shaunlee/beanstalkd-rs/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/shaunlee/beanstalkd-rs/actions/workflows/ci.yml)
[![crates.io](https://img.shields.io/crates/v/bstkd.svg)](https://crates.io/crates/bstkd)
[![docs.rs](https://img.shields.io/docsrs/bstkd)](https://docs.rs/bstkd)
[![Docker image](https://img.shields.io/docker/pulls/shonhen/beanstalkd-rs)](https://hub.docker.com/r/shonhen/beanstalkd-rs)
[![license](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

A drop-in replacement for [beanstalkd](https://github.com/beanstalkd/beanstalkd),
the simple work queue, written in Rust. The protocol is the reference's, byte
for byte, so existing clients work unmodified; the command line is the
reference's too, except `-u` (leave privilege dropping to the service
manager).

What you get for switching:

- **1.2–1.4× the throughput** at 10–100 connections and **2.2× with a
  binlog** and fsync, with 10–24% less CPU per operation
  ([numbers](#performance)).
- **TLS, mutual TLS and token authentication** per listener, Prometheus
  `/metrics`, `/healthz`, `/readyz`, a TOML configuration file and graceful
  shutdown, with no proxy in front.
- **Optional Raft replication**: 3 or 5 nodes over mutual TLS, clients connect
  to any node, a minority can be lost, and nodes can be added, removed or
  moved while the cluster serves.
- **`unsafe` forbidden** at the workspace level, 222 differential test cases
  against the C reference, and the Python and Go clients compared
  transcript-to-transcript against it.

One binary, no runtime dependencies, MIT.

## Try it

Docker (linux/amd64 and linux/arm64):

```sh
docker run -d --name bstk -p 127.0.0.1:11300:11300 -v bstk-data:/data \
  shonhen/beanstalkd-rs -l 0.0.0.0 -p 11300 -b /data
```

Or from crates.io (Rust 1.98 or newer; the binary is called `beanstalkd-rs`):

```sh
cargo install bstkd        # or, for a prebuilt archive: cargo binstall bstkd
beanstalkd-rs -l 127.0.0.1 -p 11300 -b ./binlog
```

From a checkout, [`scripts/demo.sh`](scripts/demo.sh) needs nothing but bash,
python3 and curl. It runs a producer and a worker over the real protocol,
reads `/metrics`, then restarts the server on the same binlog to show that
the jobs are still there:

```console
$ ./scripts/demo.sh

beanstalkd-rs 0.5.1
protocol on 127.0.0.1:56386, HTTP on 127.0.0.1:56387

[1/3] the beanstalkd protocol: put, reserve, delete, bury
  producer use demo                      -> USING demo
  worker   watch demo                    -> WATCHING 2
  worker   ignore default                -> WATCHING 1
  producer put 0 0 60 17                 -> INSERTED 1
  producer put 100 0 60 11               -> INSERTED 2
  producer put 200 0 60 9                -> INSERTED 3
  worker   reserve                       -> RESERVED 1 17
           body                          -> render invoice 42
  worker   delete 1                      -> DELETED
  worker   reserve                       -> RESERVED 2 11
           body                          -> send digest
  worker   bury 2 0                      -> BURIED
  monitor  stats-tube demo               -> ready 1, buried 1, reserved 0

[2/3] the same server, as Prometheus sees it
  beanstalkd_current_jobs{state="ready"} 1
  beanstalkd_current_jobs{state="buried"} 1
  beanstalkd_jobs_total 3
  beanstalkd_commands_total{cmd="put"} 3
  ...

[3/3] SIGTERM, then a restart on the same binlog
  SIGTERM                   exit 0, binlog synced
  restart                   counts unchanged after the restart
```

Release archives (Linux x86_64 and aarch64, glibc or static musl; macOS
aarch64) are on the [releases page](https://github.com/shaunlee/beanstalkd-rs/releases).
Each holds the binary, a systemd unit, example configurations, the cluster
certificate script and the operations guide. Installation with systemd,
configuration, clusters, backups, upgrades and monitoring:
[docs/OPERATIONS.md](docs/OPERATIONS.md).

## Migrating from beanstalkd

**Protocol and command line**: point an existing client at beanstalkd-rs and
it works, including the reference's parser quirks. `-l -p -z -b -f -F -s -V
-v` mean what they meant; the only removed flag is `-u`, because privilege
dropping belongs to the service manager.

**The binlog directory is not compatible.** beanstalkd-rs uses its own
on-disk format (a segmented, preallocated log) and pointing either server at
the other's directory fails with `bad magic`. A migration drains and
switches rather than swapping the binary under a full queue:

1. Stop producers, or point them at a new queue, and let the workers drain.
2. Send `SIGUSR1` to the old server: drain mode stops accepting puts but
   keeps serving reservations.
3. Wait for `current-jobs-ready`, `current-jobs-reserved` and
   `current-jobs-delayed` to reach 0.
4. Stop it, start beanstalkd-rs with `-b` on a **new, empty** directory, and
   move the clients.

If downtime is not acceptable, run both servers side by side and dual-write
while the old queue drains.

Behavior changes worth knowing before you switch
([docs/COMPAT.md](docs/COMPAT.md) has all of them, with the test that covers
each):

- **Fail-stop on a disk error.** A binlog write, fsync or compaction error
  logs and exits non-zero; the reference silently disables its binlog and
  keeps serving (D5).
- **`-u` is rejected**, and `-s` above 4 GiB fails at startup (D10).
- **A put whose records do not fit in one segment** replies `OUT_OF_MEMORY`
  instead of letting the file grow (D7).
- **Cross-connection order** within a fraction of a millisecond may differ;
  per-connection order is preserved (D11).

## Is it ready?

0.5.1 is the current release; it differs from 0.5.0 only in packaging,
documentation and dependency updates. In test terms:

- **222 differential cases** replay the same operations against the reference
  C implementation and require identical replies
  ([tests/compat](tests/compat)).
- **800+ tests** across the workspace, including crash/restart suites and a
  chaos harness ([tests/chaos](tests/chaos)).
- **Real clients**: the Python `greenstalk` and Go `beanstalk` clients run the
  same script against both servers and the normalized transcripts must be
  identical ([clients/](clients)).
- **Fuzzing**: four targets (`proto_decode`, `raft_wire`,
  `snapshot_decode`, `wal_read`) with a seeded corpus ([fuzz/](fuzz)).
- **`unsafe_code = "forbid"`** and `unwrap_used = "deny"` at the workspace
  level.

## When something else fits better

- You need consumer groups, streams, replay or fan-out to independent
  consumers: NATS, Kafka or Redis Streams model that; tubes do not.
- You want a managed service and no operations: SQS, Cloud Tasks or a hosted
  RabbitMQ.
- You depend on beanstalkd's exact on-disk binlog format: this project
  deliberately does not keep it.

## Performance

Operations per second, beanstalkd-rs against beanstalkd
(`25085c5`, built with `-O2`), both with default settings, on Linux 7.0
(aarch64) in an OrbStack VM on an Apple M6: servers on 6 cores, the
`bstk-bench` load generator on the other 6, loopback, 5 alternated runs
per cell, medians. 16-byte job bodies unless noted.

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="docs/images/perf-dark.svg">
  <img alt="Operations per second, beanstalkd against beanstalkd-rs, for the workloads in the table below" src="docs/images/perf-light.svg">
</picture>

| Workload | beanstalkd | beanstalkd-rs | |
|---|---:|---:|---:|
| put-reserve-delete, 1 connection | 56,176 | 53,943 | 0.96× |
| put-reserve-delete, 10 connections | 339,283 | 468,310 | **1.38×** |
| put-reserve-delete, 100 connections | 392,713 | 516,336 | **1.31×** |
| put-reserve-delete, 100 connections, 4 KiB bodies | 362,247 | 463,416 | **1.28×** |
| put-reserve-delete, 100 connections, 16 pipelined | 490,247 | 643,204 | **1.31×** |
| producers and consumers, 100 connections | 422,349 | 500,891 | **1.19×** |
| with a binlog (`-b`, default fsync), 100 connections | 267,391 | 594,148 | **2.22×** |

- **CPU**: 10–24% less CPU per operation at 10 and 100 connections (1.94
  against 2.55 µs at 100 connections). One connection is bound by
  round-trip latency, where beanstalkd-rs is 4% slower on Linux and
  equal on macOS.
- **Cluster** (3 nodes on the same machine, every operation committed by
  a majority before its reply): 316k operations per second through the
  leader and 236k through a follower at 100 connections with the data on
  tmpfs; on a disk, each commit waits for a durable fsync on a majority
  of the nodes and the disk's sync rate sets the limit (48k on the VM's
  volume).
- **Memory per job**: 249 against 219 bytes for small jobs (1.13×), equal
  for 4 KiB jobs.
- **macOS** (native, same machine): 1.2–1.4× at 10 and 100 connections,
  1.9× with a binlog, equal at one connection.

Every cell, the macOS run, the method and the raw data:
[docs/BENCH.md](docs/BENCH.md) ("README numbers").

## beanstalkd-rs and beanstalkd

| | beanstalkd | beanstalkd-rs |
|---|---|---|
| Protocol | the reference | every command, reply and edge case, checked against the reference by differential tests and real Python and Go clients |
| Command line | `-l -p -z -b -f -F -s -u -V -v` | the same, except `-u` (leave the user switch to the service manager) |
| Throughput, 10–100 connections | baseline | 1.2–1.4× ([Performance](#performance)) |
| Persistence | binlog | write-ahead log with the same fsync policies (`-b`, `-f`, `-F`); no reply before its change is logged; on a disk error it stops instead of carrying on without a log |
| Replication and failover | — | Raft cluster of 3 or 5 nodes over mutual TLS; clients connect to any node; keeps serving through the loss of a minority |
| Online cluster changes | — | add, remove, replace and move nodes, grow from 1 to 3 or 3 to 5 nodes while serving (`beanstalkd-rs cluster`) |
| TLS | — (needs a proxy such as stunnel) | built in: several listeners, each plaintext or TLS |
| Authentication | — | mutual TLS or a token per listener |
| Monitoring | `stats` commands | the same, plus `/healthz`, `/readyz`, Prometheus `/metrics` and JSON `/admin` |
| Configuration | command line | command line or a TOML file (`--config`, `--check-config`) |
| SIGTERM | killed | graceful: stops accepting, syncs the log, exits 0 |
| SIGUSR1 | drain mode | drain mode (cluster-wide in a cluster) |
| Threads | one | one, or two with TLS, a binlog or a cluster; `--threads N` |
| Memory safety | C | Rust, no `unsafe` code (`unsafe_code = "forbid"`) |
| Packages | distribution packages | crates.io (`bstkd`), release archives for Linux x86_64 and aarch64 (glibc or static musl) and macOS aarch64 with a systemd unit, and a Docker image (`shonhen/beanstalkd-rs`) |

The few intentional behavior differences are listed in
[docs/COMPAT.md](docs/COMPAT.md); with no configuration file the server
behaves like the reference.

## Documentation

- [docs/OPERATIONS.md](docs/OPERATIONS.md): operations guide
- [docs/beanstalkd-rs.example.toml](docs/beanstalkd-rs.example.toml): every configuration key
- [docs/DESIGN.md](docs/DESIGN.md): architecture and design
- [docs/COMPAT.md](docs/COMPAT.md): differences from the reference beanstalkd
- [docs/BENCH.md](docs/BENCH.md): performance results
- [docs/PLAN.md](docs/PLAN.md): development, test and acceptance plan
- [scripts/demo.sh](scripts/demo.sh): the demo above
- [clients/](clients): the Python and Go client runs compared against the reference
- [CHANGELOG.md](CHANGELOG.md): changes per release

## License

MIT, see [LICENSE](LICENSE). beanstalkd itself is also MIT-licensed.
