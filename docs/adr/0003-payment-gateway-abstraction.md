# 0003. Hide the payment provider behind an interface

- **Status:** Partially
- **Date:** 2026-10-31

## Context

The system talks to Stripe, but business logic should not depend on Stripe, and
tests must be able to simulate failures (timeouts, declines) that are hard to
trigger on demand with the real provider.

## Decision

- Define a `PaymentGatewayInterface` interface owned by the application.
- `StripeGateway` implements it using the official Stripe API.
- `FakeGateway` implements it for tests and can simulate success, failure and timeouts.
- The majority of tests use `FakeGateway`; a small, separate group of integration
  tests (`@group stripe`) runs against Stripe test mode.
- Webhook signature verification is implemented by hand (HMAC-SHA256 with
  timestamp tolerance) rather than calling the SDK helper, to make the
  security mechanism explicit and fully tested.
- superseded by ADR 0008 (the SDK is no longer used; the interface and the hand-written signature check remain).

## Consequences

- (+) Business logic is provider-independent and quick to test.
- (+) Failure scenarios become deterministic tests.
- (-) One extra layer of indirection.
- (-) The fake must stay faithful to Stripe's real behaviour; integration tests guard that.

## Alternatives considered

- **Calling Stripe directly from services**: simpler, but couples the domain to the
  provider and makes failure paths untestable.
- **Raw HTTP calls instead of the SDK**: more code to maintain, no benefit
  (the SDK handles auth, idempotency headers, error types, API versioning).
