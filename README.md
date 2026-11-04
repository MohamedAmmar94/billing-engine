# Billing Engine

![CI](https://github.com/MohamedAmmar94/billing-engine/actions/workflows/ci.yml/badge.svg)

A fault-tolerant payment and double-entry accounting backend built with
**Laravel 13**, **PostgreSQL** and **Stripe**.

> 🚧 **Status:** under active development. **Phases 0, 1 and 2 are complete**
> (money primitives, schema, invoices, staff API, payment links with a fake gateway, idempotency keys). Stripe, webhooks and the ledger come next.

## What it does

```
Staff creates customer and invoice -> invoice opened -> customer pays through a stable link
-> Stripe webhook confirms -> journal entries -> account balances -> financial reports
```

The business logic is intentionally small. The focus of this project is
**engineering for what goes wrong** in a system that moves money: duplicate requests,
forged or replayed webhooks, expired payment links, race conditions, partial failures
and drift between our books and the payment provider.

Full walkthrough with every edge case: **[docs/business-logic.md](docs/business-logic.md)**.

## Problems this project solves

Each row links to the test that proves it as the phases land.

| Problem                                                    | Approach                                                                       | Phase | Status |
| ---------------------------------------------------------- | ------------------------------------------------------------------------------ | ----- | ------ |
| Floating point errors and lost cents when splitting money  | Integer minor units, basis points, allocation algorithm                        | 0     | ✅     |
| Money values mixed across currencies                       | Immutable `Money` value object refuses mismatches                              | 0     | ✅     |
| Guessable ids in URLs                                      | Public UUID v4 next to bigint keys                                             | 1     | ✅     |
| Old invoices changing when a product price changes         | Prices and tax rate are copied onto the invoice (snapshot)                     | 1     | ✅     |
| Invalid invoice transitions (paying a void invoice...)     | Explicit state machine, 409 on illegal transitions                             | 1     | ✅     |
| Gaps in invoice numbers when an operation fails            | Counter table under row lock; rollback gives the number back                   | 1     | ✅     |
| User clicks "pay" twice / client retries                   | Idempotency keys                                                               | 2     | ✅     |
| Customer opens an old email after the payment link expired | Stable pay URL; Stripe link created on demand and reused while alive           | 2     | ✅     |
| Two live payment links for the same invoice                | Partial unique index (schema ready), reuse logic                               | 2     | ✅     |
| Crash or timeout while creating a Stripe session           | `pending` row committed first, deterministic idempotency key                   | 2     | ✅     |
| Forged webhooks                                            | HMAC-SHA256 signature verification, written by hand                            | 3     | ⬜     |
| Replayed webhooks                                          | Timestamp tolerance                                                            | 3     | ⬜     |
| Same event delivered twice                                 | Event-id deduplication                                                         | 3     | ⬜     |
| Handler failure                                            | Retry with exponential backoff, dead-letter queue, replay command              | 3     | ⬜     |
| Customer pays on a dead link or pays twice                 | `is_applied` flag, Unapplied Payments account, automatic refund                | 3     | ⬜     |
| Unbalanced or tampered accounting entries                  | Balance enforced in code **and** by a deferred database trigger (schema ready) | 4     | ⬜     |
| Payment succeeded but no journal entry                     | Ledger posting in the same transaction as the payment                          | 4     | ⬜     |
| Refunds exceeding the amount paid                          | `lockForUpdate` inside a transaction                                           | 5     | ⬜     |
| Lost updates on balances, deadlocks                        | Consistent lock ordering, deadlock retry, multi-process tests                  | 6     | ⬜     |
| Our records disagree with Stripe                           | Reconciliation command                                                         | 8     | ⬜     |

## Tech stack

PHP 8.4 · Laravel 13 · Sanctum · PostgreSQL 17 · Redis · Docker · Pest · Larastan · Pint ·
GitHub Actions · Stripe (test mode, called over HTTP)

## Getting started

Requirements: Docker Desktop.

```bash
git clone https://github.com/MohamedAmmar94/billing-engine.git
cd billing-engine
cp .env.example .env
docker compose up -d --build
docker compose exec billing-engine_app composer install
docker compose exec billing-engine_app php artisan key:generate
docker compose exec billing-engine_app php artisan migrate --seed
```

The API is served at <http://localhost:8000/api/v1>. pgAdmin is at <http://localhost:8080>.
With the `local` environment the seeder creates a demo staff user (`admin@example.com` / `password`),
three customers and five products. These credentials are for local development only.

## API tour

```bash
# 1. get a token
curl -s -X POST http://localhost:8000/api/v1/auth/token \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"email":"admin@example.com","password":"password","device_name":"cli"}'

# 2. use it
curl -s http://localhost:8000/api/v1/products \
  -H "Accept: application/json" -H "Authorization: Bearer <token>"
```

| Method           | Endpoint                        | Purpose                                                       |
| ---------------- | ------------------------------- | ------------------------------------------------------------- |
| POST / DELETE    | `/auth/token`                   | Issue / revoke a token                                        |
| GET, POST        | `/customers`, `/customers/{id}` | Customers                                                     |
| GET, POST, PATCH | `/products`, `/products/{id}`   | Product catalog                                               |
| POST             | `/invoices`                     | Create a draft from `{customer, items:[{product, quantity}]}` |
| GET              | `/invoices?status=&customer=`   | List and filter                                               |
| POST             | `/invoices/{id}/open` · `/void` | Open (gets its number) or void                                |
| DELETE           | `/invoices/{id}`                | Discard a draft                                               |
| GET              | `/pay/{id}`                     | Stable customer link; redirects to a live Stripe page         |

Ids in URLs and responses are UUIDs. Amounts are integers in minor units (cents).
Errors: 401 no token, 404 unknown id, 409 illegal state transition, 422 invalid input or business rule.

## Development

```bash
docker compose exec billing-engine_app composer check   # lint + static analysis + tests
docker compose exec billing-engine_app composer lint    # Pint (code style)
docker compose exec billing-engine_app composer analyse # Larastan (static analysis)
docker compose exec billing-engine_app composer test    # Pest
```

## Testing strategy

Tests prove business outcomes and failure stories, not schema details.

- **Unit:** `Money` (rounding, allocation with a 1000-case property test, overflow), invoice totals.
- **Feature:** invoice lifecycle, staff API journey, error mapping.
- **Database guards:** the ledger rejects unbalanced entries and malformed lines on its own.
- **Architecture:** Pest arch tests enforce strict types and the dependency rules between modules.
- **Coming:** ledger scenarios asserting account balances after every step, an invariants check
  (debits = credits, balances = sum of lines, receivable = open invoices), webhook and idempotency
  scenarios, and multi-process concurrency tests on real PostgreSQL.

Tests run against a dedicated PostgreSQL database (`billing_engine_testing`), never SQLite.

## Project structure

```
app/
├── Shared/     # Money value object and cross-cutting building blocks
├── Billing/    # Customers, products, invoices: models, actions, HTTP layer
├── Ledger/     # Accounts, journal entries, balances         (models and schema ready, logic in Phase 4)
├── Payments/   # Payment links, payments, gateway           (Phases 2, 3, 5)
└── Webhooks/   # Inbound events, signature check, retries   (Phase 3)
config/billing.php   # currency, tax rate, payment terms
docs/                # business-logic.md and architecture decision records
```

## Architecture decisions

- [0001 Represent money as integer minor units](docs/adr/0001-money-as-integer-minor-units.md)
- [0002 Use PostgreSQL as the database](docs/adr/0002-postgresql-as-database.md)
- [0003 Hide the payment provider behind an interface](docs/adr/0003-payment-gateway-abstraction.md)
- [0004 Expose a public UUID, keep bigint primary keys](docs/adr/0004-public-uuid-alongside-bigint-id.md)
- [0005 Payment links are created on demand; a payment is a fact](docs/adr/0005-payment-link-parent-of-payment.md)
- [0006 Authenticate staff and external systems with Sanctum personal access tokens](docs/adr/0006-sanctum-personal-access-tokens.md)

## Roadmap

| Phase | Scope                                               | Status |
| ----- | --------------------------------------------------- | ------ |
| 0     | Foundation: Docker, tooling, CI, `Money`            | ✅     |
| 1     | Schema, invoices, staff API with tokens             | ✅     |
| 2     | Payment links, payments, idempotency (fake gateway) | ⬜     |
| 3     | Stripe over HTTP and webhooks                       | ⬜     |
| 4     | General ledger: journal entries and balances        | ⬜     |
| 5     | Refunds (full and partial)                          | ⬜     |
| 6     | Concurrency hardening                               | ⬜     |
| 7     | Financial reports and admin endpoints               | ⬜     |
| 8     | Reconciliation                                      | ⬜     |
| 9     | Polish: OpenAPI, mutation testing, end-to-end test  | ⬜     |
| 10    | React dashboard (separate repository)               | ⬜     |

### Idempotency

The three invoice mutations (create, open, void) require an Idempotency-Key header (see docs/business-logic.md, section 4.11).
Example:
