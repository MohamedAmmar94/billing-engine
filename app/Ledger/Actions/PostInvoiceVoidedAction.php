<?php

declare(strict_types=1);

namespace App\Ledger\Actions;

use App\Billing\Models\Invoice;
use App\Ledger\Models\JournalEntry;
use App\Ledger\Services\LedgerService;
use Carbon\CarbonImmutable;

final class PostInvoiceVoidedAction
{
    public function __construct(private readonly LedgerService $ledger) {}

    /** عكس قيد الفتح (مش تعديله). لو الفاتورة اتفتحت قبل الـ ledger مفيش قيد يتعكس. */
    public function handle(Invoice $invoice): JournalEntry
    {
        $opened = JournalEntry::query()
            ->where('source_type', 'invoice')
            ->where('source_id', $invoice->id)
            ->where('event', 'opened')
            ->firstOrFail();

        return $this->ledger->reverse(
            original: $opened,
            sourceType: 'invoice',
            sourceId: $invoice->id,
            event: 'voided',
            description: 'Invoice '.(string) $invoice->number.' voided',
            postedAt: CarbonImmutable::now(),
        );
    }
}
