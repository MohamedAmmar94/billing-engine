<?php

declare (strict_types = 1);

namespace App\Billing\Actions;

use App\Billing\Enums\InvoiceStatus;
use App\Billing\Events\InvoiceOpened;
use App\Billing\Exceptions\InvalidInvoiceException;
use App\Billing\Exceptions\InvoiceStateException;
use App\Billing\Models\Invoice;
use App\Billing\Support\InvoiceNumberGenerator;
use Illuminate\Support\Facades\DB;

final class OpenInvoice {
    public function __construct(private readonly InvoiceNumberGenerator $numbers) {}

    public function handle(Invoice $invoice): Invoice {
        return DB::transaction(function () use ($invoice): Invoice {
            $invoice = $invoice->lockedCopy();

            if (! $invoice->status->canTransitionTo(InvoiceStatus::Open)) {
                throw InvoiceStateException::cannot('open', $invoice->status);
            }

            if ($invoice->total <= 0) {
                throw InvalidInvoiceException::nothingToBill();
            }

            $issuedAt = now();

            $invoice->update([
                'number'    => $this->numbers->next(),
                'status'    => InvoiceStatus::Open,
                'issued_at' => $issuedAt,
                'due_at'    => $issuedAt->copy()->addDays(config()->integer('billing.payment_terms_days')),
            ]);

            // نقطة التوسع: Phase 4 هتسجل القيد المحاسبي هنا جوه نفس الـ transaction
            event(new InvoiceOpened($invoice));

            return $invoice;
        });
    }
}