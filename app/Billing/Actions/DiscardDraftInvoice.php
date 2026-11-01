<?php

declare (strict_types = 1);

namespace App\Billing\Actions;

use App\Billing\Enums\InvoiceStatus;
use App\Billing\Exceptions\InvoiceStateException;
use App\Billing\Models\Invoice;
use Illuminate\Support\Facades\DB;

final class DiscardDraftInvoice {
    public function handle(Invoice $invoice): void {
        DB::transaction(function () use ($invoice): void {
            $invoice = $invoice->lockedCopy();

            if ($invoice->status !== InvoiceStatus::Draft) {
                throw InvoiceStateException::cannot('discard', $invoice->status);
            }

            $invoice->items()->delete();
            $invoice->delete();
        });
    }
}