<?php

declare(strict_types=1);

namespace App\Billing\Http\Controllers;

use App\Billing\Actions\CreateInvoice;
use App\Billing\Actions\DiscardDraftInvoice;
use App\Billing\Actions\OpenInvoice;
use App\Billing\Actions\VoidInvoice;
use App\Billing\Enums\InvoiceStatus;
use App\Billing\Http\Requests\StoreInvoiceRequest;
use App\Billing\Http\Resources\InvoiceResource;
use App\Billing\Models\Customer;
use App\Billing\Models\Invoice;
use App\Billing\Models\Product;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

final class InvoiceController
{
    /** العلاقات اللي بتتحمّل مرة واحدة بدل ما تتجاب جوه loop (N+1) */
    private const FULL = ['customer', 'creator', 'items.product'];

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['sometimes', Rule::enum(InvoiceStatus::class)],
            'customer' => ['sometimes', 'uuid'],
        ]);

        $query = Invoice::query()->with('customer')->latest('id');

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['customer'])) {
            $query->whereRelation('customer', 'uuid', $filters['customer']);
        }

        return InvoiceResource::collection($query->paginate(25));
    }

    public function store(StoreInvoiceRequest $request, CreateInvoice $createInvoice): JsonResponse
    {
        /** @var User $staff */
        $staff = $request->user();

        /** @var list<array{product: string, quantity: int|string}> $requested */
        $requested = $request->validated('items');

        $customer = Customer::query()->where('uuid', $request->validated('customer'))->firstOrFail();

        $products = Product::query()
            ->whereIn('uuid', array_column($requested, 'product'))
            ->get()
            ->keyBy('uuid');

        $items = array_map(
            fn (array $item): array => [
                'product' => $products[$item['product']],
                'quantity' => (int) $item['quantity'],
            ],
            $requested,
        );

        $invoice = $createInvoice->handle($customer, $staff, $items);

        return InvoiceResource::make($invoice->load(self::FULL))->response()->setStatusCode(201);
    }

    public function show(Invoice $invoice): InvoiceResource
    {
        return InvoiceResource::make($invoice->load(self::FULL));
    }

    public function open(Invoice $invoice, OpenInvoice $action): InvoiceResource
    {
        return InvoiceResource::make($action->handle($invoice)->load(self::FULL));
    }

    public function void(Invoice $invoice, VoidInvoice $action): InvoiceResource
    {
        return InvoiceResource::make($action->handle($invoice)->load(self::FULL));
    }

    public function destroy(Invoice $invoice, DiscardDraftInvoice $action): Response
    {
        $action->handle($invoice);

        return response()->noContent();
    }
}
