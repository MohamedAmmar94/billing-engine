# 0006. Authenticate staff and external systems with Sanctum personal access tokens

- **Status:** Accepted
- **Date:** 2026-11-01

## Context

Only staff and trusted external systems call the API; customers are records and do
not log in. The future dashboard is a separate React application.

## Decision

- Laravel Sanctum personal access tokens. `POST /api/v1/auth/token` exchanges
  email and password for a token (rate limited to 5 attempts per minute);
  `DELETE /api/v1/auth/token` revokes the current one.
- Every business endpoint sits behind `auth:sanctum`; invoices record `created_by`.
- Error mapping: validation and business-rule violations are 422, invalid state
  transitions are 409, unknown or malformed ids are 404, missing token is 401.
- No roles in v1: every authenticated user is staff.

## Consequences

- (+) Small surface, no OAuth complexity, tokens are hashed at rest.
- (-) Tokens do not expire by default; set `expiration` in `config/sanctum.php`
  before any real deployment.
- (-) No per-user permissions yet; roles can be added without changing the token mechanism.

## Alternatives considered

- **Laravel Passport / OAuth2**: unnecessary for first-party staff and trusted systems.
- **Session cookie auth**: suited to SPAs on the same domain; it would complicate API-to-API use.
