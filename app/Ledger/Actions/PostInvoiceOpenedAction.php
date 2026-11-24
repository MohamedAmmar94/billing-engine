<?php

declare(strict_types=1);

namespace App\Ledger\Actions;

use App\Billing\Models\Invoice;
use App\Ledger\Enums\AccountCodeEnum;
use App\Ledger\Models\JournalEntry;
use App\Ledger\Services\LedgerService;
use App\Ledger\Support\JournalEntryDTO;
use App\Ledger\Support\JournalLineDTO;
use App\Shared\Money\CurrencyEnum;
use App\Shared\Money\Money;
use Carbon\CarbonImmutable;

final class PostInvoiceOpenedAction
{
    public function __construct(private readonly LedgerService $ledger) {}

    /** مدين: المدينين بالإجمالي. دائن: الإيراد بالـ subtotal والضريبة المستحقة. */
    public function handle(Invoice $invoice): JournalEntry
    {
        $currency = CurrencyEnum::from($invoice->currency);

        $lines = [
            JournalLineDTO::debit(AccountCodeEnum::AccountsReceivable, Money::of($invoice->total, $currency)),
            JournalLineDTO::credit(AccountCodeEnum::SalesRevenue, Money::of($invoice->subtotal, $currency)),
        ];

        if ($invoice->tax_amount > 0) {
            $lines[] = JournalLineDTO::credit(AccountCodeEnum::TaxPayable, Money::of($invoice->tax_amount, $currency));
        }

        return $this->ledger->post(new JournalEntryDTO(
            description: 'Invoice '.(string) $invoice->number.' opened',
            postedAt: CarbonImmutable::instance($invoice->issued_at ?? now()),
            currency: $currency,
            sourceType: 'invoice',
            sourceId: $invoice->id,
            event: 'opened',
            lines: $lines,
        ));
    }
}
