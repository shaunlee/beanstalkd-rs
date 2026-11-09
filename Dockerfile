# syntax=docker/dockerfile:1
#
# beanstalkd-rs container image: docker build -t beanstalkd-rs .
#
#   docker run -d -p 11300:11300 beanstalkd-rs                 # in memory
#   docker run -d -p 11300:11300 -v bstk:/data beanstalkd-rs \
#       -l 0.0.0.0 -p 11300 -b /data                           # with a binlog
#   docker run -d -v /etc/beanstalkd-rs:/etc/beanstalkd-rs:ro -v bstk:/data \
#       beanstalkd-rs --config /etc/beanstalkd-rs/config.toml  # config file
#
# Arguments replace the default command, so give -l / -p again (or use
# [[listener]] in a config file) when adding flags. Point binlog.dir or
# cluster.data_dir at /data, which belongs to the non-root uid the server
# runs as (10001).
#
# The binary is linked statically against musl and the image is FROM
# scratch: no shell, no libc, nothing but the binary. musl's allocator also
# returns freed memory to the kernel, so a drained backlog does not keep the
# container at its peak RSS the way glibc's does. Without a shell there is
# no HEALTHCHECK: enable the [http] listener and probe /healthz or /readyz
# from the orchestrator.

FROM rust:1.98-alpine AS build
RUN apk add --no-cache musl-dev
WORKDIR /src
# Every workspace member's manifest must be present for cargo to resolve
# the workspace, even though only bstkd is built.
COPY . .
# target/ lives in a cache mount that disappears after this step, so the
# binary is copied out within the same RUN. The release profile keeps line
# tables for profiling (debug = 1), which makes a Linux binary about 6x
# larger; the shipped binary drops them but keeps symbols, so backtraces
# still name functions.
ENV CARGO_PROFILE_RELEASE_STRIP=debuginfo
RUN --mount=type=cache,target=/usr/local/cargo/registry \
    --mount=type=cache,target=/usr/local/cargo/git \
    --mount=type=cache,id=bstk-target-musl,target=/src/target \
    cargo build --release --locked -p bstkd \
 && cp target/release/beanstalkd-rs /usr/local/bin/beanstalkd-rs \
 && mkdir /data

FROM scratch
COPY --from=build /usr/local/bin/beanstalkd-rs /usr/local/bin/beanstalkd-rs
COPY LICENSE README.md CHANGELOG.md /usr/share/doc/beanstalkd-rs/
# A fresh named volume copies the ownership of the image's /data, so it
# must already belong to the uid (scratch has no useradd or /etc/passwd).
COPY --from=build --chown=10001:10001 /data /data
ENV PATH=/usr/local/bin
USER 10001:10001
WORKDIR /data
VOLUME /data
EXPOSE 11300
ENTRYPOINT ["beanstalkd-rs"]
CMD ["-l", "0.0.0.0", "-p", "11300"]
