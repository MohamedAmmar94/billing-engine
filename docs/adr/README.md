# Architecture Decision Records

Short documents that capture significant technical decisions, the context behind
them and the alternatives that were rejected.

Full walkthrough with every edge case: [docs/business-logic.md](docs/business-logic.md).
| # | Decision | Status |
| -------------------------------------------- | --------------------------------------------- | -------- |
| [0001](0001-money-as-integer-minor-units.md) | Represent money as integer minor units | Accepted |
| [0002](0002-postgresql-as-database.md) | Use PostgreSQL as the database | Accepted |
| [0003](0003-payment-gateway-abstraction.md) | Hide the payment provider behind an interface | Accepted |
| [0004](0004-public-uuid-alongside-bigint-id.md) | Expose a public UUID, keep bigint primary keys | Accepted |
| [0005](0005-payment-link-parent-of-payment.md) | Payment links are created on demand; a payment is a fact | Accepted |
| [0006](0006-sanctum-personal-access-tokens.md) | Authenticate staff and external systems with Sanctum personal access tokens | Accepted |
