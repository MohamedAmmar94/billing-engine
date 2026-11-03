<?php

declare(strict_types=1);

use App\Billing\Enums\InvoiceStatusEnum;
use Database\Seeders\SequenceSeeder;

beforeEach(function () {
    $this->seed(SequenceSeeder::class);
    $this->invoice = openInvoice();
});

it('never treats the success redirect as proof of payment', function () {
    $this->get(route('payment.success', ['invoice' => $this->invoice->uuid]))
        ->assertOk()
        ->assertSee('Confirming your payment');

    expect($this->invoice->fresh()->status)->toBe(InvoiceStatusEnum::Open);
});

it('shows the paid state only when the invoice is really paid', function () {
    $this->invoice->update(['status' => InvoiceStatusEnum::Paid]);

    $this->get(route('payment.success', ['invoice' => $this->invoice->uuid]))
        ->assertOk()
        ->assertSee('Payment received');
});

it('offers the same stable pay link after an abandoned attempt', function () {
    $this->get(route('payment.cancel', ['invoice' => $this->invoice->uuid]))
        ->assertOk()
        ->assertSee('Payment not completed')
        ->assertSee(payUrl($this->invoice));
});

it('tells the customer when the invoice was canceled in the meantime', function () {
    $this->invoice->update(['status' => InvoiceStatusEnum::Void]);

    $this->get(route('payment.success', ['invoice' => $this->invoice->uuid]))
        ->assertOk()
        ->assertSee('This invoice was canceled');
});

it('answers 404 for a malformed invoice id', function () {
    $this->get('/payment/abc/success')->assertNotFound();
});
