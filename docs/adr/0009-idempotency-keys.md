# 0009. Idempotency keys for money-affecting API calls

- **Status:** Accepted
- **Date:** 2026-11-04

## Context

Clients retry after timeouts and users double-click. A retried `POST /invoices` must not
create a second invoice, and a retried `open` should return the original result, not a 409.

## Decision

- `Idempotency-Key` is **required** on `POST /invoices`, `POST /invoices/{id}/open` and
  `POST /invoices/{id}/void`; format 8-255 characters of `A-Za-z0-9_-`.
- Keys are scoped per authenticated user and tied to a request fingerprint
  (`sha256(method + path + canonical body)`). Same key with another fingerprint: 422.
- A marker row is inserted first with `INSERT ... ON CONFLICT DO NOTHING` (the unique index
  `(user_id, key)` decides races). The business work and the stored response then commit in
  **one transaction**, so "work done but response not stored" cannot happen.
- Concurrent duplicates get 409 + `Retry-After`. A marker older than 60 seconds is considered
  abandoned and taken over; a **fencing token** (`lock_token`) makes the old attempt unable to commit.
- 2xx and deterministic 404/409/422 responses are stored and replayed (`Idempotent-Replayed: true`).
  5xx, 429 and the like are not stored: the transaction is rolled back and the key released.
- Records expire after 24 hours (`idempotency:prune` runs hourly).
- Replays return the original snapshot even if the invoice has changed since.
- The Stripe call for payment links has its own deterministic key (`invoice-{uuid}-link-{attempt}`);
  the two mechanisms are independent.

## Consequences

- (+) Exactly-once effects for retried requests, safe under concurrency and crashes.
- (-) The whole request runs inside a transaction; acceptable because these endpoints make no
  network calls (Stripe expiry is queued after commit).

## Alternatives considered

- **Store the response in a separate step after the work**: a crash in between would create duplicates.
- **Keep keys in Redis only**: loses the atomic link with the business transaction.
