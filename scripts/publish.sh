#!/usr/bin/env bash
# Publishes the workspace to crates.io in dependency order and waits for each
# version to appear in the index before packaging the next crate.
#
#   scripts/publish.sh                 # publish
#   scripts/publish.sh --dry-run       # package and verify, do not upload
#   scripts/publish.sh --allow-dirty   # while iterating on the release commit
#
# `--dry-run` only succeeds for a crate whose dependencies are already on
# crates.io: cargo verifies the packaged tarball against the registry. So a
# full dry run needs the previous crates published first; before the first
# release, the first crate (bstk-proto) is the only one that can dry-run.
#
# Requires an authenticated cargo (`cargo login` or CARGO_REGISTRY_TOKEN).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
VERSION="$(sed -n 's/^version = "\([^"]*\)"/\1/p' "$ROOT/Cargo.toml" | head -1)"

# Dependency order: a crate may only be published after everything it
# depends on, dev-dependencies included (bstk-engine's tests use the oracle).
CRATES=(
  bstk-proto
  bstk-engine-oracle
  bstk-engine
  bstk-net
  bstk-store
  bstk-raft
  bstkd
)

DRY_RUN=0
for arg in "$@"; do
  [ "$arg" = "--dry-run" ] && DRY_RUN=1
done

die() { echo "publish: $*" >&2; exit 1; }

command -v curl >/dev/null || die "curl is required to poll crates.io"
[ -n "$VERSION" ] || die "could not read the workspace version from $ROOT/Cargo.toml"

echo "publishing workspace $VERSION from $ROOT"
if [ "$DRY_RUN" = 0 ] && [ -n "$(git -C "$ROOT" status --porcelain)" ]; then
  die "the working tree is dirty; commit first or pass --allow-dirty"
fi

for crate in "${CRATES[@]}"; do
  echo "==> $crate $VERSION"
  (cd "$ROOT" && cargo publish -p "$crate" "$@")
  [ "$DRY_RUN" = 1 ] && continue

  # crates.io indexes a new version asynchronously; the next crate cannot be
  # packaged until its dependency resolves.
  for _ in $(seq 1 60); do
    if curl -fsS -A "beanstalkd-rs-publish" \
      "https://crates.io/api/v1/crates/$crate/$VERSION" >/dev/null 2>&1; then
      break
    fi
    sleep 2
  done
  curl -fsS -A "beanstalkd-rs-publish" \
    "https://crates.io/api/v1/crates/$crate/$VERSION" >/dev/null 2>&1 ||
    die "$crate $VERSION did not appear on crates.io; rerun from this crate"
done

echo "done: https://crates.io/crates/bstkd/$VERSION"
