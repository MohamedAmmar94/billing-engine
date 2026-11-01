# 0005. Payment links are created on demand; a payment is a fact

- **Status:** Accepted
- **Date:** 2026-11-01

## Context

Stripe Checkout Sessions expire (30 minutes to 24 hours), customers can ask to pay
days after an invoice was issued, and a payment attempt can be abandoned.

## Decision

- Customers receive a stable URL (`/pay/{invoice uuid}`). The Stripe link is created
  only when they open it, and reused while it is still alive.
- `payment_links` is the parent: a link row exists first and may never become a payment.
  `payments.payment_link_id` references it. Payments are created by the webhook only.
- `payments` has no status: it records money that arrived. `is_applied = false`
  marks money that could not be matched to an open invoice.
- A link row is stored as `pending` and committed **before** calling Stripe, with the
  deterministic idempotency key `invoice-{id}-link-{attempt}`, so a crash or timeout
  cannot create two sessions and leaves a trace.
- A partial unique index allows one live (`pending`/`open`) link per invoice.
- Only the signed webhook confirms a payment; the success redirect is never trusted.

## Consequences

- (+) The customer always has one working URL; no dead links in old emails.
- (+) `payments` rows are all real money: one entry each in the ledger, direct comparison with Stripe in reconciliation.
- (-) Opening the pay URL can trigger an external call; it is rate limited.
- (-) Late payments on dead links need explicit handling (`is_applied`, Unapplied Payments).

## Alternatives considered

- **Payment first, link as child**: puts rows without money in `payments`, forcing status filtering in reports and reconciliation.
- **Link created when the invoice opens**: expires before many customers click it.
