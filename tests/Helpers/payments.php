<?php

declare(strict_types=1);

use App\Billing\Models\Invoice;
use App\Payments\Gateway\Fake\FakeGateway;
use App\Payments\Gateway\PaymentGateway;
use App\Payments\Models\PaymentLink;

function fakeGateway(): FakeGateway
{
    $gateway = app(PaymentGateway::class);
    assert($gateway instanceof FakeGateway);

    return $gateway;
}

function payUrl(Invoice $invoice): string
{
    return route('pay', ['invoice' => $invoice->uuid]);
}

function linkOf(Invoice $invoice): PaymentLink
{
    return PaymentLink::query()->where('invoice_id', $invoice->id)->latest('attempt')->firstOrFail();
}

function liveLinkCount(Invoice $invoice): int
{
    return PaymentLink::query()
        ->where('invoice_id', $invoice->id)
        ->whereIn('status', ['pending', 'open'])
        ->count();
}
