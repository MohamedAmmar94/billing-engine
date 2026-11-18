# 0010. Webhook ingestion: verify, store, acknowledge, process later

- **Status:** Accepted
- **Date:** 2026-11-08

## Context

Stripe's webhook is the only proof of payment. Deliveries can be forged, replayed,
duplicated, delayed or reordered, and our handler can fail halfway. Stripe retries
until it gets a 2xx, so the endpoint must answer fast.

## Decision

- `POST /api/webhooks/stripe` has no authentication; trust comes from the signature,
  verified by hand (`StripeSignatureVerifierService`: HMAC-SHA256 over `{t}.{raw body}`,
  constant-time comparison, 300 s tolerance, several `v1` signatures and several secrets
  accepted for rotation, `v0` ignored). Failures answer 400.
- The raw event is stored first (`webhook_events`, unique on provider + event id); a
  duplicate delivery gets 200 and is not processed again. The endpoint then queues
  `ProcessWebhookEventJob` and answers 200.
- Processing is state-based, never order-based: `checkout.session.expired` after
  `completed` changes nothing; `completed` after `expired` still records the money.
- A payment is recorded in one transaction together with the invoice, the link and the
  event status. Money is accepted whatever the link status is; whether it is applied to
  the invoice depends only on the invoice state, the amount and the currency. Otherwise the
  payment is kept with `is_applied = false` and flagged for review.
- Failures are retried by the queue (5 tries, backoff 10 s, 1 m, 5 m, 15 m). After the last
  try the event becomes `dead` in the same table; `webhooks:replay` re-queues it.
- Only `checkout.session.completed` and `checkout.session.expired` are subscribed to.
  Events must be configured as **snapshot** payloads (thin events carry no object data).

## Consequences

- (+) Fast acknowledgements, exactly-once effects, nothing lost when processing fails.
- (-) A stored event can wait for the queue; the success page therefore reads the database
  and refreshes until the webhook has been processed.

## Alternatives considered

- **Process inside the request**: slow, and a failure makes Stripe retry a half-applied event.
- **A separate `failed_webhooks` table**: one more table with no extra information; a status is enough.
