<?php

declare (strict_types = 1);

use App\Billing\Actions\CreateInvoice;
use App\Billing\Actions\DiscardDraftInvoice;
use App\Billing\Actions\OpenInvoice;
use App\Billing\Actions\VoidInvoice;
use App\Billing\Enums\InvoiceStatus;
use App\Billing\Events\InvoiceOpened;
use App\Billing\Events\InvoiceVoided;
use App\Billing\Exceptions\InvalidInvoiceException;
use App\Billing\Exceptions\InvoiceStateException;
use App\Billing\Models\Invoice;
use App\Billing\Models\InvoiceItem;
use Database\Seeders\SequenceSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->seed(SequenceSeeder::class);
});

// ---------- الحالات المسموحة ----------

it('only allows the agreed status transitions', function (InvoiceStatus $from, InvoiceStatus $to, bool $allowed) {
    expect($from->canTransitionTo($to))->toBe($allowed);
})->with([
    'draft to open' => [InvoiceStatus::Draft, InvoiceStatus::Open, true],
    'open to paid'  => [InvoiceStatus::Open, InvoiceStatus::Paid, true],
    'open to void'  => [InvoiceStatus::Open, InvoiceStatus::Void, true],
    'draft to paid' => [InvoiceStatus::Draft, InvoiceStatus::Paid, false],
    'draft to void' => [InvoiceStatus::Draft, InvoiceStatus::Void, false],
    'open to draft' => [InvoiceStatus::Open, InvoiceStatus::Draft, false],
    'paid to void'  => [InvoiceStatus::Paid, InvoiceStatus::Void, false],
    'paid to open'  => [InvoiceStatus::Paid, InvoiceStatus::Open, false],
    'void to open'  => [InvoiceStatus::Void, InvoiceStatus::Open, false],
    'void to paid'  => [InvoiceStatus::Void, InvoiceStatus::Paid, false],
]);

// ---------- الإنشاء (draft) ----------

it('creates a draft invoice with totals copied from the products', function () {
    $staff    = makeStaff();
    $customer = makeCustomer();

    $invoice = app(CreateInvoice::class)->handle($customer, $staff, [
        ['product' => makeProduct(9900), 'quantity' => 1],
        ['product' => makeProduct(1500), 'quantity' => 2],
        ['product' => makeProduct(5000), 'quantity' => 1],
    ]);

    expect($invoice->status)->toBe(InvoiceStatus::Draft)
        ->and($invoice->number)->toBeNull()
        ->and($invoice->customer_id)->toBe($customer->id)
        ->and($invoice->created_by)->toBe($staff->id)
        ->and($invoice->subtotal)->toBe(17900)
        ->and($invoice->tax_rate_bp)->toBe(1400)
        ->and($invoice->tax_amount)->toBe(2506)
        ->and($invoice->total)->toBe(20406)
        ->and($invoice->items->sortBy('id')->pluck('line_total')->values()->all())->toBe([9900, 3000, 5000]);
});

it('keeps the copied price when the product price changes later', function () {
    $product = makeProduct(9900);
    $invoice = app(CreateInvoice::class)->handle(makeCustomer(), makeStaff(), [
        ['product' => $product, 'quantity' => 1],
    ]);

    $product->update(['unit_price' => 15000]);

    $item = InvoiceItem::query()->where('invoice_id', $invoice->id)->firstOrFail();

    expect($item->unit_price)->toBe(9900)
        ->and($invoice->fresh()->total)->toBe(11286);
});

it('rejects invalid invoice input', function (string $case) {
    $items = match ($case) {
        'no items'          => [],
        'zero quantity'     => [['product' => makeProduct(), 'quantity' => 0]],
        'negative quantity' => [['product' => makeProduct(), 'quantity' => -2]],
        'inactive product'  => [['product' => makeProduct(active: false), 'quantity' => 1]],
    };

    app(CreateInvoice::class)->handle(makeCustomer(), makeStaff(), $items);
})->with(['no items', 'zero quantity', 'negative quantity', 'inactive product'])
    ->throws(InvalidInvoiceException::class);

it('discards a draft together with its items', function () {
    $invoice = draftInvoice();

    app(DiscardDraftInvoice::class)->handle($invoice);

    expect(Invoice::query()->count())->toBe(0)
        ->and(InvoiceItem::query()->count())->toBe(0);
});

it('refuses to discard an invoice that was opened', function () {
    $opened = app(OpenInvoice::class)->handle(draftInvoice());

    app(DiscardDraftInvoice::class)->handle($opened);
})->throws(InvoiceStateException::class);

// ---------- الفتح والترقيم ----------

it('opens a draft: number, dates and event', function () {
    $this->travelTo('2026-11-01 10:00:00');
    Event::fake([InvoiceOpened::class]);

    $opened = app(OpenInvoice::class)->handle(draftInvoice());

    expect($opened->status)->toBe(InvoiceStatus::Open)
        ->and($opened->number)->toBe('INV-000001')
        ->and($opened->issued_at->toDateString())->toBe('2026-11-01')
        ->and($opened->due_at->toDateString())->toBe('2026-11-15');

    Event::assertDispatched(
        InvoiceOpened::class,
        fn(InvoiceOpened $event): bool => $event->invoice->is($opened),
    );
});

it('gives consecutive numbers to consecutive invoices', function () {
    $numbers = collect(range(1, 3))
        ->map(fn() => app(OpenInvoice::class)->handle(draftInvoice())->number)
        ->all();

    expect($numbers)->toBe(['INV-000001', 'INV-000002', 'INV-000003']);
});

it('gives the number back when opening fails halfway (no gaps)', function () {
    $invoice = draftInvoice();

    Event::listen(InvoiceOpened::class, function (): void {
        throw new RuntimeException('listener failed');
    });

    expect(fn() => app(OpenInvoice::class)->handle($invoice))
        ->toThrow(RuntimeException::class, 'listener failed');

    expect((int) DB::table('sequences')->where('name', 'invoice')->value('last_value'))->toBe(0)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Draft)
        ->and($invoice->fresh()->number)->toBeNull();
});

it('refuses to open an invoice twice', function () {
    $opened = app(OpenInvoice::class)->handle(draftInvoice());

    app(OpenInvoice::class)->handle($opened);
})->throws(InvoiceStateException::class);

it('refuses to open an invoice with nothing to bill', function () {
    app(OpenInvoice::class)->handle(draftInvoice(unitPrice: 0));
})->throws(InvalidInvoiceException::class, 'nothing to bill');

// ---------- الإلغاء ----------

it('voids an open invoice, keeps its number and announces it', function () {
    Event::fake([InvoiceVoided::class]);
    $opened = app(OpenInvoice::class)->handle(draftInvoice());

    $voided = app(VoidInvoice::class)->handle($opened);

    expect($voided->status)->toBe(InvoiceStatus::Void)
        ->and($voided->number)->toBe($opened->number);

    Event::assertDispatched(InvoiceVoided::class);
});

it('refuses to void an invoice that is not open', function (InvoiceStatus $status) {
    $invoice = draftInvoice();
    $invoice->update(['status' => $status]);

    app(VoidInvoice::class)->handle($invoice);
})->with([
    'draft'        => [InvoiceStatus::Draft],
    'paid'         => [InvoiceStatus::Paid],
    'already void' => [InvoiceStatus::Void],
])->throws(InvoiceStateException::class);