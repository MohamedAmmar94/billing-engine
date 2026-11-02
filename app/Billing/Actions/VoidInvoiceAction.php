<?php

declare(strict_types=1);

namespace App\Billing\Actions;

use App\Billing\Enums\InvoiceStatusEnum;
use App\Billing\Events\InvoiceVoidedEvent;
use App\Billing\Exceptions\InvoiceStateException;
use App\Billing\Models\Invoice;
use Illuminate\Support\Facades\DB;

final class VoidInvoiceAction
{
    public function handle(Invoice $invoice): Invoice
    {
        return DB::transaction(function () use ($invoice): Invoice {
            $invoice = $invoice->lockedCopy();

            if (! $invoice->status->canTransitionTo(InvoiceStatusEnum::Void)) {
                throw InvoiceStateException::cannot('void', $invoice->status);
            }

            $invoice->update(['status' => InvoiceStatusEnum::Void]);

            event(new InvoiceVoidedEvent($invoice));

            return $invoice;
        });
    }
}
