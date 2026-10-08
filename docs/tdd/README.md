# Technical design documents

A TDD is written before a change that is large enough that a reviewer would
otherwise have to reconstruct the design from the diff. Small changes do not
need one; the pull request description is enough.

Write one when the change:

- adds or alters a database table, an option shape, or a meta key;
- touches money — commission calculation, maturation, refunds, payouts, or the
  Dokan vendor charge;
- adds an integration point another plugin can hook (a new filter contract);
- spans more than roughly three days of work.

## Format

`docs/tdd/NNNN-short-slug.md`, numbered in order, with:

1. **Problem** — what is broken or missing, in terms of behaviour.
2. **Constraints** — the rules from [`CONTEXT.md`](../../CONTEXT.md) and the ADRs
   that bound the solution. Money rules are quoted, not paraphrased.
3. **Design** — tables, classes, hooks, and the order things run in.
4. **Failure modes** — what happens on a duplicate hook, a partial refund, a
   missing vendor, a deactivated Dokan, a clock skew.
5. **Test plan** — the cases that prove it, including the idempotency case.
6. **Rollout** — the upgrade routine, and what happens to data written by the
   previous version.

## Relationship to ADRs

A TDD says *how* something is built. An [ADR](../adr/) says *why one option was
chosen over another* and stays true after the code changes. When a TDD makes a
decision that will outlive it, promote that decision to an ADR and link it.

## Index

_(none yet)_
