# 0002. Use PostgreSQL as the database

- **Status:** Accepted
- **Date:** 2026-10-31

## Context

The project needs strong guarantees around concurrency (row locks, transactions)
and data integrity (a ledger entry must always balance).

## Decision

Use **PostgreSQL 17** for development, tests and CI.

- **Deferrable constraint triggers** let the database itself reject an unbalanced
  journal entry at commit time, even if application code is wrong (planned, Phase 1).
- `SELECT ... FOR UPDATE` row locking is used to serialize critical sections
  (payments, refunds, balances).
- Tests run against a real PostgreSQL instance (a dedicated `billing_engine_testing`
  database), never SQLite, because locking behaviour differs and concurrency
  tests would otherwise prove nothing.

## Consequences

- (+) Integrity rules can be enforced at the database level.
- (+) Concurrency tests reflect production behaviour.
- (-) Slightly heavier local setup (handled by Docker Compose).

## Alternatives considered

- **MySQL**: widely used and supports row locking, but has no deferrable constraints,
  so the "balance enforced at commit" guarantee could not be expressed in the database.
- **SQLite**: fine for simple tests, but not suitable for testing locking and concurrency.
