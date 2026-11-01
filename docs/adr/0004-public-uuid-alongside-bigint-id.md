# 0004. Expose a public UUID, keep bigint primary keys

- **Status:** Accepted
- **Date:** 2026-11-01

## Context

Sequential ids in URLs and API responses let anyone enumerate resources and infer
business volume. Authorization checks remain the real protection, but ids should
not be guessable either.

## Decision

- Primary and foreign keys stay `bigint` (compact, fast joins).
- Every table exposed through the API has a separate `uuid` column with a UNIQUE
  index, used in URLs and responses. The bigint id is never serialized.
- UUID **version 4** (fully random): version 7 embeds the creation timestamp, which
  would leak when a resource was created. Index locality, the main benefit of v7,
  does not matter here because the primary key is not the UUID.
- The column defaults to `gen_random_uuid()` in PostgreSQL, and Laravel also fills
  it in application code (`HasPublicUuid` trait) so the model knows it immediately.
- Internal tables (`journal_lines`, `account_balances`, `sequences`) have no UUID.

## Consequences

- (+) Non-enumerable public identifiers; fast integer joins internally.
- (-) One extra column and unique index per exposed table.
- (-) Two identifiers per entity; API resources must always map `uuid` to `id`.

## Alternatives considered

- **UUID as primary key**: simpler model, but 16-byte keys everywhere and index
  locality concerns (v4) or timestamp leakage (v7).
- **Hashids over the integer id**: reversible with the salt, adds a dependency, and
  couples public ids to the primary key.
