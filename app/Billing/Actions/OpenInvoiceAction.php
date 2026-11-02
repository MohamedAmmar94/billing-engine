<?php

declare(strict_types=1);

namespace App\Billing\Actions;

use App\Billing\Enums\InvoiceStatusEnum;
use App\Billing\Events\InvoiceOpenedEvent;
use App\Billing\Exceptions\InvalidInvoiceException;
use App\Billing\Exceptions\InvoiceStateException;
use App\Billing\Models\Invoice;
use App\Billing\Support\InvoiceNumberGeneratorService;
use Illuminate\Support\Facades\DB;

final class OpenInvoiceAction
{
    public function __construct(private readonly InvoiceNumberGeneratorService $numbers) {}

    public function handle(Invoice $invoice): Invoice
    {
        return DB::transaction(function () use ($invoice): Invoice {
            $invoice = $invoice->lockedCopy();

            if (! $invoice->status->canTransitionTo(InvoiceStatusEnum::Open)) {
                throw InvoiceStateException::cannot('open', $invoice->status);
            }

            if ($invoice->total <= 0) {
                throw InvalidInvoiceException::nothingToBill();
            }

            $issuedAt = now();

            $invoice->update([
                'number' => $this->numbers->next(),
                'status' => InvoiceStatusEnum::Open,
                'issued_at' => $issuedAt,
                'due_at' => $issuedAt->copy()->addDays(config()->integer('billing.payment_terms_days')),
            ]);

            // نقطة التوسع: Phase 4 هتسجل القيد المحاسبي هنا جوه نفس الـ transaction
            event(new InvoiceOpenedEvent($invoice));

            return $invoice;
        });
    }
}
