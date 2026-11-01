<?php

declare (strict_types = 1);

namespace App\Billing\Actions;

use App\Billing\Enums\InvoiceStatus;
use App\Billing\Events\InvoiceVoided;
use App\Billing\Exceptions\InvoiceStateException;
use App\Billing\Models\Invoice;
use Illuminate\Support\Facades\DB;

final class VoidInvoice {
    public function handle(Invoice $invoice): Invoice {
        return DB::transaction(function () use ($invoice): Invoice {
            $invoice = $invoice->lockedCopy();

            if (! $invoice->status->canTransitionTo(InvoiceStatus::Void)) {
                throw InvoiceStateException::cannot('void', $invoice->status);
            }

            $invoice->update(['status' => InvoiceStatus::Void]);

            event(new InvoiceVoided($invoice));

            return $invoice;
        });
    }
}