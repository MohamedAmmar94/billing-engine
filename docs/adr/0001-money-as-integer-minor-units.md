# 0001. Represent money as integer minor units

- **Status:** Accepted
- **Date:** 2026-10-31

## Context

Floating point numbers cannot represent most decimal fractions exactly
(`0.1 + 0.2 !== 0.3`). In an accounting system the books must balance to the
cent, so even tiny rounding errors are unacceptable. Stripe also expects amounts
in minor units (cents).

## Decision

- All amounts are stored and computed as **integers in minor units** (1050 = 10.50 USD).
- A `Money` value object (`App\Shared\Money\Money`) wraps `(int amount, Currency currency)`.
  It is `final readonly` (immutable) and refuses arithmetic across currencies.
- Percentages use **basis points** (14% = 1400), rounded **half away from zero**.
- Splitting uses an **allocation** algorithm: the remainder is distributed one
  minor unit at a time, so no cent is ever created or lost.
- Integer overflow throws `OverflowException` instead of silently becoming a float.
- Every file uses `declare(strict_types=1)` so floats cannot sneak into `int` parameters.

## Consequences

- (+) Exact arithmetic; the ledger can always balance to zero.
- (+) All money rules live in one tested place.
- (-) Conversion to/from decimal strings is only done at the edges (display, API).
- (-) No currency conversion; single-currency arithmetic only (out of scope for v1).
- (-) Custom code to maintain.

## Alternatives considered

- **float**: rejected, inexact.
- **Decimal strings + bcmath**: exact and flexible, but slower and easy to misuse (strings everywhere).
- **`brick/money`**: mature and well tested. A custom class was chosen to keep the
  scope minimal and to make the reasoning explicit in this project. In a
  production codebase, using the library would be the pragmatic choice.
