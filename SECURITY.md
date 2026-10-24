# Security

## Supported versions

`0.5.x` is the current release line and receives security fixes. Older
versions do not.

The project has not been independently audited. It is written without
`unsafe` code (`unsafe_code = "forbid"`), but that is not a substitute for
a review.

## Reporting a vulnerability

Please do not open a public issue. Use GitHub's private vulnerability
reporting on the repository's **Security** tab, or email `me@shaunli.com`.

Include what an attacker can do, the configuration (listeners, TLS, auth,
cluster), the version, and a reproduction if you have one. A protocol
transcript is ideal.

You can expect an acknowledgement within a few days. When a fix is ready,
the release notes describe the impact, and the report is credited unless
you prefer otherwise.
