# 0008. Call Stripe over plain HTTP instead of the SDK

- **Status:** Accepted (supersedes the SDK decision in ADR 0003; the gateway interface stays)
- **Date:** 2026-11-04

## Context

The system uses a handful of Stripe endpoints: create, retrieve and expire Checkout
Sessions, create refunds, list charges for reconciliation. The project's goal is to show
how failures are handled, and the hard parts (timeouts, idempotency keys, error mapping)
should be visible in the code rather than hidden inside a library.

## Decision

- `StripeGateway` (Phase 3) implements `PaymentGatewayInterface` with Laravel's HTTP client
  (`Http::asForm()`, explicit timeouts, the `Idempotency-Key` header, a pinned `Stripe-Version`).
- Stripe errors are mapped to `GatewayException` and transport timeouts to `GatewayTimeoutException`.
- Webhook signature verification stays hand-written (HMAC-SHA256 with timestamp tolerance).
- Tests use `FakeGateway`; a small `@group stripe` suite targets Stripe test mode.

## Consequences

- (+) No dependency in the most sensitive code path; failure handling is explicit and testable with `Http::fake()`.
- (-) We maintain request encoding (form-encoded nested parameters), pagination and error parsing ourselves.

## Alternatives considered

- **Official Stripe PHP SDK**: less code, but it hides the behaviour this project wants to demonstrate.
