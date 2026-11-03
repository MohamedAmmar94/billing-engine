<?php

declare(strict_types=1);

namespace App\Payments\Http\Controllers;

use App\Billing\Enums\InvoiceStatusEnum;
use App\Billing\Models\Invoice;
use App\Shared\Money\CurrencyEnum;
use App\Shared\Money\Money;
use Illuminate\Contracts\View\View;

final class PaymentResultController
{
    /** الـ redirect مش دليل دفع: بنقرا الحالة من الـ DB (اللي بيحدثها الـ webhook بس). */
    public function success(Invoice $invoice): View
    {
        return $this->page($invoice, match ($invoice->status) {
            InvoiceStatusEnum::Paid => 'paid',
            InvoiceStatusEnum::Void => 'voided',
            default => 'confirming',
        });
    }

    public function cancel(Invoice $invoice): View
    {
        return $this->page($invoice, match ($invoice->status) {
            InvoiceStatusEnum::Paid => 'paid',
            InvoiceStatusEnum::Void => 'voided',
            default => 'canceled',
        });
    }

    private function page(Invoice $invoice, string $state): View
    {
        $amount = Money::of($invoice->total, CurrencyEnum::from($invoice->currency));

        return view('payments.result', [
            'state' => $state,
            'number' => $invoice->number,
            'amount' => $amount->toDecimalString().' '.$invoice->currency,
            'payUrl' => route('pay', ['invoice' => $invoice->uuid]),
        ]);
    }
}
