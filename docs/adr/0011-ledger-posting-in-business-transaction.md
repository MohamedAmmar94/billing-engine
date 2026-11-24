# 0011. Post journal entries inside the business transaction

- **Status:** Accepted
- **Date:** 2026-11-10

## Context

Every money movement must appear in the books, and the books must never disagree with
the business documents. "Payment succeeded but no journal entry" (or the reverse) is the
failure this decision rules out.

## Decision

- Billing and Payments announce facts with domain events (`InvoiceOpenedEvent`,
  `InvoiceVoidedEvent`, `PaymentRecordedEvent`) and know nothing about the ledger.
- Ledger listeners are **synchronous** (never queued), so the entry is written inside the
  transaction that caused it. If posting fails, the whole operation rolls back: the
  invoice stays a draft, the webhook stays `received` and is retried.
- `LedgerService` is the only writer of journal entries, lines and balances. Inside `post()`:
  validate (two or more lines, positive amounts, one currency, debits = credits), resolve
  postable accounts, lock the balance rows **ordered by account id**, check the
  (source type, source id, event) key, insert the entry and all its lines in one statement,
  update the balances, then force the deferred balance trigger with `SET CONSTRAINTS ... IMMEDIATE`
  so a violation surfaces at the call site instead of at commit.
- Corrections are reversing entries linked through a unique `reverses_entry_id`; an entry
  can be reversed once. Nothing is updated or deleted.
- Single currency (USD): payments in another currency are stored but not posted (a warning is logged).
- `ledger:verify` (scheduled daily) checks: total debits = total credits, every entry
  balances, stored balances = sums of lines, and the control accounts agree with the
  business tables (receivable = open invoices, clearing = payments received, unapplied
  = payments not applied).

## Consequences

- (+) Books and documents change atomically; recovery is the same retry mechanism as for webhooks.
- (+) Billing and Payments stay independent of accounting (enforced by architecture tests).
- (-) Entries touching the same accounts (receivable, revenue) serialize on the balance rows.
  Each hold is short; contention is measured in the concurrency phase.

## Alternatives considered

- **Post asynchronously from a queue**: leaves windows where a payment exists without an entry.
- **Maintain balances with a database trigger**: hides the locking behaviour this project wants to show and test.
- **Compute balances on read only**: slower reports and no cheap consistency check against stored totals.
