# Business Logic

How money moves through the system. Read this before the code.

## 1. Actors

| Actor          | Role                                                                        |
| -------------- | --------------------------------------------------------------------------- |
| **Staff user** | Authenticates with an API token. Manages customers, products and invoices.  |
| **Customer**   | A record only (no login). Receives invoices and pays through a stable link. |
| **Stripe**     | Hosts the payment page and confirms payments through signed webhooks.       |

## 2. The happy path

```mermaid
sequenceDiagram
    actor Staff
    actor Customer
    participant API as Billing Engine
    participant Stripe
    Staff->>API: create customer, create invoice (draft)
    Staff->>API: open invoice
    API-->>Customer: email with stable pay URL
    Customer->>API: GET /pay/{invoice uuid}
    API->>Stripe: create Checkout Session
    API-->>Customer: redirect to Stripe
    Customer->>Stripe: pays by card
    Stripe->>API: webhook checkout.session.completed
    API->>API: payment + invoice paid + journal entry (one transaction)
```

## 3. State machines

```mermaid
stateDiagram-v2
    [*] --> draft
    draft --> open: open
    draft --> [*]: discard
    open --> paid: payment confirmed
    open --> void: void
```

```mermaid
stateDiagram-v2
    [*] --> pending
    pending --> open: Stripe session created
    pending --> canceled: invoice voided
    open --> completed: paid
    open --> expired: time ran out
    open --> canceled: replaced or invoice voided
```

`Payment` has no status: it is a fact (money arrived). `is_applied = false` marks money
that could not be matched to an open invoice.

## 4. Step by step

### 4.1 Create the customer and the products

Staff creates customers (unique email) and maintains the product catalog.
Changing a product price never affects existing invoices.

### 4.2 Create the invoice (`draft`)

Staff sends a customer and a list of `{product, quantity}`.

1. Each product's name and unit price are **copied** onto the invoice item (snapshot).
2. `line_total = quantity x unit_price`; `subtotal` is their sum.
3. Tax (14%) is computed **once on the subtotal**, rounded half away from zero.
4. `total = subtotal + tax`; `created_by` is the staff user.

Example: Pro Plan 99.00 + 2 x Extra Seat 15.00 + Setup Fee 50.00 = 179.00,
tax 25.06, total **204.06**.

A draft has no number and no accounting entry. It can be discarded.
Rejected: no items, quantity < 1, inactive product, currency mismatch.

### 4.3 Open the invoice (`draft -> open`)

One transaction: lock the invoice, take the next number from the `sequences` table
(gap-free: a rolled-back transaction gives the number back), set `issued_at` and
`due_at` (+14 days), status `open`, then post the journal entry. An open invoice is immutable;
corrections are done by voiding and issuing a new invoice. A voided invoice keeps its number.

### 4.4 Ask the customer to pay

The customer gets a **stable URL** GET /api/v1/pay/{invoice uuid} that never changes (the invoice resource exposes it as pay_url while the invoice is open).
Stripe links expire, so a Stripe link is only created when the customer clicks.
Staff can resend the same URL at any time.

### 4.5 The customer opens the pay URL

The invoice is locked, then:

| Situation                                                      | Result                                                   | Old link       |
| -------------------------------------------------------------- | -------------------------------------------------------- | -------------- |
| Invoice is `paid`                                              | "Already paid"                                           |                |
| Invoice is `draft` or `void`                                   | Refused                                                  |                |
| No link yet                                                    | Create link (attempt 1), redirect to Stripe              |                |
| Live link, more than 30 min left, still open at Stripe         | Redirect to the **same** link                            | stays `open`   |
| Stripe says the session is expired                             | Create a new link                                        | `expired`      |
| Stripe says the session is complete (webhook not yet received) | Show "payment processing", no new link                   | unchanged      |
| Live link about to expire (< 30 min)                           | Expire it at Stripe, create a new one                    | `canceled`     |
| A `pending` link younger than 2 min                            | "Try again in a few seconds"                             |                |
| An older `pending` link (crash in the middle)                  | Resume the Stripe call with the **same idempotency key** | becomes `open` |

Creating a link: (1) save a `pending` row and commit, no lock held while calling Stripe;
(2) call Stripe with amount, expiry, `client_reference_id` and the idempotency key
`invoice-{invoice uuid}-link-{attempt}`; (3) store the session id and URL, status `open`.
At most **one live link (`pending` or `open`) per invoice**, enforced by a partial unique index.

#### Payment link flow

```mermaid
flowchart TD
    A(["Customer opens the pay URL<br/>GET /pay/{invoice uuid}"]) --> B{"Invoice status?"}
    B -->|paid| B1["✅ 200 already_paid"]
    B -->|"draft or void"| B2["⛔ 409 not_payable"]
    B -->|open| C{"Live link?<br/>(pending or open)"}

    C -->|none| N["Save a new link as PENDING<br/>attempt = last attempt + 1"]
    C -->|open| V["Ask Stripe about the session"]
    C -->|pending| P{"Pending for<br/>less than 120 s?"}

    P -->|yes| W["⏳ 503 try_again<br/>someone is calling Stripe right now"]
    P -->|no| X{"Close to expiry?"}
    X -->|yes| Z["Cancel the old link"] --> N
    X -->|"no: resume"| S

    N --> S["Call Stripe<br/>key = invoice-uuid-link-attempt"]
    S -->|"session created"| O["Link becomes OPEN<br/>store session id and url"] --> R["✅ 302 redirect to Stripe"]
    S -->|timeout| T["Link stays PENDING"] --> W2["⏳ 503 try_again<br/>next time the same key is reused"]
    S -->|rejected| K["Link becomes CANCELED"] --> U["⛔ 502 unavailable"]

    V -->|complete| Q["⏳ 200 processing<br/>no new link, wait for the webhook"]
    V -->|"open, enough time left"| R
    V -->|"open, close to expiry"| E1["Expire it at Stripe<br/>link becomes CANCELED"] --> N
    V -->|expired| E2["Link becomes EXPIRED"] --> N

    W -.->|"customer clicks again"| A
    W2 -.->|"customer clicks again"| A

    classDef start fill:#e0e7ff,stroke:#4f46e5,color:#1e1b4b
    classDef dec fill:#fef9c3,stroke:#ca8a04,color:#422006
    classDef act fill:#dbeafe,stroke:#2563eb,color:#1e3a8a
    classDef good fill:#d1fae5,stroke:#059669,color:#064e3b
    classDef wait fill:#ffedd5,stroke:#ea580c,color:#7c2d12
    classDef bad fill:#fee2e2,stroke:#dc2626,color:#7f1d1d
    class A start
    class B,C,P,X dec
    class N,V,S,Z,O,T,K,E1,E2 act
    class B1,R good
    class W,W2,Q wait
    class B2,U bad
```

The row created on the first click lives in `payment_links` (a `payments` row only exists once the
webhook confirms the money arrived). There is **no background retry**: every new click by the
customer is the retry.

| Customer action                             | Result                                                                                                                   |
| ------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------ |
| First click                                 | Redirect to Stripe, or `503` if Stripe timed out (the link stays `pending`)                                              |
| Clicks again within 120 s of a pending link | `503 try_again`, no new call to Stripe                                                                                   |
| Clicks again after 120 s                    | The same Stripe call is resumed with the same idempotency key; Stripe returns the same session if it already created one |
| The link expired or is about to             | The old link is closed (`expired` / `canceled`) and a new attempt is created                                             |
| Paid, webhook not received yet              | `200 processing`, no new link                                                                                            |

Inside one request the code loops at most 3 rounds, which only matters when an old link must
be retired and a new attempt created.

### 4.6 Paying on Stripe

Declined cards are retried inside Stripe's page; we see nothing.
The success redirect is **never trusted**; only the webhook confirms a payment.
Card only, full invoice amount only.
The success page reads the invoice status from the database and refreshes until the webhook has marked it paid.

### 4.7 The webhook (`checkout.session.completed`)

Endpoint: verify signature and timestamp (bad = 400), store the event by its unique id
(duplicate = ignore, still 200), queue it, answer 200.
Job (one transaction, invoice locked before link): find the link by `client_reference_id`,
check amount and currency.

| Situation                                                                                                     | Payment                                                                       | Invoice   | Link        | Journal entry                                                      |
| ------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------- | --------- | ----------- | ------------------------------------------------------------------ | --- |
| Normal                                                                                                        | `is_applied = true`                                                           | `paid`    | `completed` | Dr Gateway Clearing / Cr Receivable                                |
| Paid on a link our database already marked `expired` (the webhook arrived late) and the invoice is still open | New payment, `is_applied = true` (same as the normal case; the money is real) | `paid`    | `completed` | Dr Gateway Clearing / Cr Receivable                                |     |
| Invoice already `paid` (second link)                                                                          | `is_applied = false`                                                          | unchanged | `completed` | Dr Gateway Clearing / Cr Unapplied Payments, then automatic refund |
| Invoice `void`                                                                                                | `is_applied = false`                                                          | unchanged | `completed` | same as above                                                      |
| Amount or currency mismatch                                                                                   | `is_applied = false`                                                          | unchanged | `completed` | same as above, flagged for review                                  |
| Session not ours                                                                                              |                                                                               |           |             | ignored                                                            |
| Same event twice                                                                                              | not processed again                                                           |           |             |                                                                    |
| Job fails                                                                                                     |                                                                               |           |             | retry with backoff, then dead-letter + replay command              |
| Webhook never arrives                                                                                         |                                                                               |           |             | reconciliation finds it                                            |

The link status is only our bookkeeping and can lag behind Stripe, so it is never used to decide whether money is accepted; only the invoice state, the amount and the currency are.
After commit, any other live link of the invoice is expired at Stripe and marked `canceled`.

### 4.8 Refund (full or partial)

Staff requests an amount on a payment. Under a lock on the payment: sum of refunds

- new amount must not exceed the amount paid. The refund is recorded `pending`, sent to Stripe with
  an idempotency key, and confirmed by webhook (`succeeded` or `failed`; failed posts nothing).
  The amount is split between revenue and tax in the invoice's proportion (no cent lost).
  The invoice stays `paid`; the refund is its own document.

### 4.9 Void

| Invoice | Result                                                                  |
| ------- | ----------------------------------------------------------------------- |
| `draft` | Discard: deleted, no number, no entry                                   |
| `open`  | `void`: reversing entry, every live link canceled and expired at Stripe |
| `paid`  | Not allowed: refund first                                               |

### 4.10 Late invoices

Passing `due_at` does not change the status. The invoice shows up in the AR Aging report.

### 4.11 Retrying API calls safely (`Idempotency-Key`)

`POST /invoices`, `POST /invoices/{id}/open` and `POST /invoices/{id}/void` require an
`Idempotency-Key` header (8-255 characters: letters, digits, `-`, `_`; a UUID is recommended).
One key per user intention; reuse it only to retry the same request.

| Situation                                         | Response                                                                         |
| ------------------------------------------------- | -------------------------------------------------------------------------------- |
| First request with the key                        | Executed; the response is stored for 24 hours                                    |
| Same key, same request, after it finished         | The stored response with `Idempotent-Replayed: true`                             |
| Same key while the first request is still running | `409 idempotency_in_progress` + `Retry-After`                                    |
| Same key, different body or endpoint              | `422 idempotency_key_reused`                                                     |
| Missing or malformed key                          | `400 idempotency_key_missing` / `idempotency_key_invalid`                        |
| First request failed with 5xx or 429              | Nothing stored, key released, a retry executes again                             |
| Deterministic errors (404, 409, 422)              | Stored and replayed                                                              |
| Request running for more than 60 s                | Taken over by the next attempt; the old one can no longer commit (fencing token) |

The marker row is committed first; the business work and the stored response commit in one
transaction, so "invoice created but no stored response" cannot happen. Keys are scoped per user.

#### Idempotency flow

```mermaid
flowchart TD
    A(["POST create / open / void<br/>with Idempotency-Key"]) --> B{"Key present<br/>and well formed?"}
    B -->|no| B1["⛔ 400 missing or invalid"]
    B -->|yes| C["Insert a marker row<br/>unique per user + key"]
    C --> D{"Insert won?"}

    D -->|yes| E["BEGIN transaction<br/>run the business action"]
    D -->|no| F["Read the existing row"]

    F --> G{"Older than 24 h?"}
    G -->|yes| G1["Delete it"] --> C
    G -->|no| H{"Same request<br/>fingerprint?"}
    H -->|no| H1["⛔ 422 key reused"]
    H -->|yes| I{"Response<br/>stored?"}
    I -->|yes| I1["✅ Replay the stored response<br/>header Idempotent-Replayed: true"]
    I -->|no| J{"Locked for more<br/>than 60 s?"}
    J -->|no| J1["⏳ 409 in progress<br/>Retry-After: 2"]
    J -->|yes| K["Take over:<br/>write a new lock token"]
    K --> E

    E --> L{"Result of the action"}
    L -->|"2xx, 404, 409, 422"| M{"Still holding<br/>my lock token?"}
    L -->|"5xx, 429 or exception"| N["ROLLBACK<br/>delete the marker"]
    N --> N1["Return the error<br/>a retry will run again"]
    M -->|yes| O["Store the response<br/>and COMMIT"] --> O1["✅ Return the response"]
    M -->|"no: someone took over"| P["ROLLBACK"] --> J1

    classDef start fill:#e0e7ff,stroke:#4f46e5,color:#1e1b4b
    classDef dec fill:#fef9c3,stroke:#ca8a04,color:#422006
    classDef act fill:#dbeafe,stroke:#2563eb,color:#1e3a8a
    classDef good fill:#d1fae5,stroke:#059669,color:#064e3b
    classDef wait fill:#ffedd5,stroke:#ea580c,color:#7c2d12
    classDef bad fill:#fee2e2,stroke:#dc2626,color:#7f1d1d
    class A start
    class B,D,G,H,I,J,L,M dec
    class C,E,F,G1,K,N,O,P act
    class I1,O1 good
    class J1 wait
    class B1,H1,N1 bad
```

## 5. Journal entries

| Event                          | Debit                                | Credit                               |
| ------------------------------ | ------------------------------------ | ------------------------------------ |
| Invoice opened                 | 1100 Accounts Receivable (total)     | 4010 Sales Revenue, 2100 Tax Payable |
| Payment applied                | 1010 Gateway Clearing                | 1100 Accounts Receivable             |
| Payment not applied            | 1010 Gateway Clearing                | 2200 Unapplied Payments              |
| Refund of an applied payment   | 4020 Sales Refunds, 2100 Tax Payable | 1010 Gateway Clearing                |
| Refund of an unapplied payment | 2200 Unapplied Payments              | 1010 Gateway Clearing                |
| Open invoice voided            | 4010 Sales Revenue, 2100 Tax Payable | 1100 Accounts Receivable             |

## 6. Rules that always hold

- Money is an integer in minor units; arithmetic goes through `Money`.
- Every journal entry balances (debits = credits); a database trigger enforces it.
- Receivable (1100) always equals the sum of open invoices.
- Stripe's webhook is the only proof of payment.
- One live payment link per invoice.
- Every state change happens under a row lock.

## 7. Out of scope (v1)

Partial or over-payments, non-card methods, currencies other than USD, Stripe fees and
payouts, automatic reminders, credit notes.

## 8. Implementation status

| Step                                                                 | Status  |
| -------------------------------------------------------------------- | ------- |
| 4.1 - 4.3 Customers, products, invoices: create, open, void, discard | ✅ Done |
| Staff authentication with API tokens                                 | ✅ Done |
| 4.4 - 4.5 Stable pay URL and payment links (fake gateway)            | ✅ Done |
| 4.6 Customer-facing result pages                                     | ✅ Done |
| 4.11 Idempotency-Key                                                 | ✅ Done |
| Stripe over HTTP and 4.7 Webhooks                                    | Phase 3 |
| Journal entries (section 5)                                          | Phase 4 |
| 4.8 Refunds                                                          | Phase 5 |
