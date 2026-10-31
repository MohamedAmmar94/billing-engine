# Billing Engine

![CI](https://github.com/MohamedAmmar94/billing-engine/actions/workflows/ci.yml/badge.svg)

A fault-tolerant payment and double-entry accounting backend built with
**Laravel 13**, **PostgreSQL** and **Stripe**.

> 🚧 **Status:** under active development. **Phase 0 (foundation) is complete.**

## What it does

```
Purchase → Invoice → Payment via gateway → Webhook confirmation
        → Journal entries → Account balances → Financial reports
```

The business logic is intentionally small. The focus of this project is
**engineering for what goes wrong** in a system that moves money: duplicate requests,
forged or replayed webhooks, race conditions, partial failures and drift
between our books and the payment provider.

## Problems this project solves

Each row will link to the test that proves it as the phases land.

| Problem                                                   | Approach                                                             | Phase | Status |
| --------------------------------------------------------- | -------------------------------------------------------------------- | ----- | ------ |
| Floating point errors and lost cents when splitting money | Integer minor units, basis points, allocation algorithm              | 0     | ✅     |
| Money values mixed across currencies                      | Immutable `Money` value object refuses mismatches                    | 0     | ✅     |
| Unbalanced or tampered accounting entries                 | Balance enforced in code **and** by the database, append-only ledger | 1     | ⬜     |
| Duplicate invoice numbers under concurrency               | Gap-free sequence under row lock                                     | 2     | ⬜     |
| User clicks "pay" twice / client retries                  | Idempotency keys                                                     | 3     | ⬜     |
| Two concurrent requests with the same key                 | Locking on the key                                                   | 3     | ⬜     |
| Gateway timeout, charge state unknown                     | `processing` state resolved by webhook or reconciliation             | 3     | ⬜     |
| Forged webhooks                                           | HMAC-SHA256 signature verification                                   | 4     | ⬜     |
| Replayed webhooks                                         | Timestamp tolerance                                                  | 4     | ⬜     |
| Same event delivered twice                                | Event-id deduplication                                               | 4     | ⬜     |
| Handler failure                                           | Retry with exponential backoff, then dead-letter queue               | 4     | ⬜     |
| Events arriving out of order                              | State-based processing                                               | 4     | ⬜     |
| Refunds exceeding the amount paid                         | `lockForUpdate` inside a transaction                                 | 5     | ⬜     |
| Lost updates on balances, deadlocks                       | Consistent lock ordering, deadlock retry, multi-process tests        | 6     | ⬜     |
| Our records disagree with Stripe                          | Reconciliation command                                               | 8     | ⬜     |

## Tech stack

PHP 8.4 · Laravel 13 · PostgreSQL 17 · Redis · Docker · Pest · Larastan · Pint ·
GitHub Actions · Stripe (test mode)

## Getting started

Requirements: Docker Desktop.

```bash
git clone https://github.com/MohamedAmmar94/billing-engine.git
cd billing-engine
cp .env.example .env
docker compose up -d --build
docker compose exec billing_engine_app composer install
docker compose exec billing_engine_app php artisan key:generate
docker compose exec billing_engine_app php artisan migrate
```

The app is served at <http://localhost:8000>. pgAdmin is at <http://localhost:8080>.
The credentials in `docker-compose.yml` are for local development only.

## Development

```bash
docker compose exec billing_engine_app composer check   # lint + static analysis + tests
docker compose exec billing_engine_app composer lint    # Pint (code style)
docker compose exec billing_engine_app composer analyse # Larastan (static analysis)
docker compose exec billing_engine_app composer test    # Pest
```

Tests run against a dedicated PostgreSQL database (`billing_engine_testing`),
and an environment test guards against accidentally running them on the dev database.

## Project structure

```
app/
├── Shared/     # Money value object and other cross-cutting building blocks
├── Ledger/     # Accounts, journal entries, balances         (Phase 1)
├── Billing/    # Customers, products, invoices               (Phase 2)
├── Payments/   # Payments, gateway, idempotency, refunds     (Phases 3, 5)
└── Webhooks/   # Inbound events, signature check, retries    (Phase 4)
docs/adr/       # Architecture decision records
```

Architecture tests (Pest) enforce the dependency rules between these modules.

## Architecture decisions

- [0001 Represent money as integer minor units](docs/adr/0001-money-as-integer-minor-units.md)
- [0002 Use PostgreSQL as the database](docs/adr/0002-postgresql-as-database.md)
- [0003 Hide the payment provider behind an interface](docs/adr/0003-payment-gateway-abstraction.md)

## Roadmap

| Phase | Scope                                    | Status |
| ----- | ---------------------------------------- | ------ |
| 0     | Foundation: Docker, tooling, CI, `Money` | ✅     |
| 1     | Double-entry ledger                      | ⬜     |
| 2     | Customers, products, invoices            | ⬜     |
| 3     | Payments, idempotency, gateway           | ⬜     |
| 4     | Webhooks                                 | ⬜     |
| 5     | Refunds                                  | ⬜     |
| 6     | Concurrency hardening                    | ⬜     |
| 7     | Financial reports and admin API          | ⬜     |
| 8     | Reconciliation                           | ⬜     |
| 9     | Polish and docs                          | ⬜     |
| 10    | React dashboard (separate repository)    | ⬜     |
