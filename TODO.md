# TODO

Open work, most useful first within each section. This is an index, not a
plan: [docs/PLAN.md](docs/PLAN.md) holds the phase plans with their tasks
and acceptance criteria, and [docs/DESIGN.md](docs/DESIGN.md) records what
was built and why. Nothing here is a promise about a date.

If you want to pick something up, read [CONTRIBUTING.md](CONTRIBUTING.md)
first; small, self-contained items are marked **good first issue**.

## Engine and cluster

- [ ] **Cluster CPU per operation.** 17–18 µs per operation at 100
      connections (target 15) and 0.55–0.77× of the pre-P4 build at one
      connection (target 0.5×). Blocked on openraft 0.10, which does not
      await each append's flush before its next command. Numbers and the
      profile: `docs/BENCH.md` "P4-T6".
- [ ] **openraft 0.10.** Removes the awaited flush per append (see above).
      Touches the log store's group commit, the apply path and the
      membership guardrails, so it needs the chaos suites green before
      anything else.
- [ ] **Automatic membership.** Removing a dead voter is manual today
      (documented as out of scope in `docs/PLAN.md` §9.1). Do not do this
      without a written safety argument like the one behind rejoins
      (`docs/DESIGN.md` §8).

Not on this list on purpose: **sharding**. `reserve` spans tubes and must
return the globally most urgent job, so a sharded design changes the
protocol's meaning (`docs/DESIGN.md` §3).

## Code

- [ ] **The deprecated `AsyncFd` constructor** in `crates/net/src/lib.rs`
      (`registered`) is kept under `#[allow(deprecated)]` with a soundness
      argument, because tokio 1.54's replacement is `unsafe` and this
      workspace forbids `unsafe`. Revisit when tokio ships a safe
      constructor, or when the workspace's stance on `unsafe` changes.

## Tests

- [ ] **PHP client: the other modes** — `clients/php/smoke.php` implements
      the full flow only, so `SMOKE_RESTART`, `SMOKE_CLUSTER_KILL`,
      `SMOKE_TLS` and `SMOKE_MTLS` refuse `SMOKE_CLIENTS=php`. Restart is
      the valuable one (Pheanstalk's `--leave-jobs` / `--after-restart`
      equivalents); TLS would need a `SocketFactory` wrapper.
- [ ] **good first issue** — a `clients/` example that drives the server
      through a fourth stack (Ruby `beaneater`, Node `beanstalkd`, Java
      `javabean`) with the same transcript format.

## Packaging and release

- [ ] **`docs/BENCH.md` version labels**: the "README numbers" section is
      labelled 0.5.0. It is a historical record and stays, but add one line
      saying that the 0.5.x line shares those measurements, so nobody reads
      the label as "numbers are stale".
- [ ] **Three descriptions, one text**: the GitHub Release, the Docker Hub
      repository description and the ghcr.io package description should say
      the same thing as the README's first paragraph.

## Repository hygiene

- [ ] **good first issue** — a Grafana dashboard JSON for the `/metrics`
      endpoint, under `packaging/`.
- [ ] **good first issue** — a systemd `ExecReload` or an `OPERATIONS.md`
      recipe for reloading listeners without a restart, if that is possible
      without dropping connections; check the config code before promising
      it.
- [ ] **Failed CI runs are noticed by hand.** Set up a notification for
      failed runs on `main` (the `deny` job failed silently for two days in
      October 2026 before anyone looked).
- [ ] **awesome-rust**: their CONTRIBUTING requires 50 stars or 2,000
      crates.io downloads. Submit the entry under "Message Queue" once
      either is reached.

## Done, as a reminder of the shape of this list

- 0.5.1 / 0.5.2: crates.io packaging, `bstkd` as the package name, the
  `cargo binstall` metadata fix (verified: binstall now fetches the release
  archive instead of compiling), the container image built and pushed by
  `.github/workflows/image.yml` to ghcr.io and Docker Hub, and the PHP
  Pheanstalk client in the smoke tests.
