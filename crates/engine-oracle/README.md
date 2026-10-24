# bstk-engine-oracle

A frozen copy of [`bstk-engine`](https://crates.io/crates/bstk-engine) taken
before a later internal refactor, used only as the differential-test oracle
of [beanstalkd-rs](https://github.com/shaunlee/beanstalkd-rs).

Every test in that crate runs the same operations through the current engine
and through this frozen one and requires identical results, so a refactor
that changes behavior fails even where no test spells the behavior out.

It is published because `bstk-engine`'s tests depend on it; it is not meant
to be used by anything else. MIT licensed.
