<?php

declare (strict_types = 1);

namespace App\Billing\Actions;

use App\Billing\Enums\InvoiceStatus;
use App\Billing\Exceptions\InvalidInvoiceException;
use App\Billing\Models\Customer;
use App\Billing\Models\Invoice;
use App\Billing\Models\Product;
use App\Billing\Support\InvoiceCalculator;
use App\Models\User;
use App\Shared\Money\Currency;
use App\Shared\Money\Money;
use Illuminate\Support\Facades\DB;

final class CreateInvoice {
    public function __construct(private readonly InvoiceCalculator $calculator) {}

    /**
     * @param  list<array{product: Product, quantity: int}>  $items
     */
    public function handle(Customer $customer, User $creator, array $items): Invoice {
        if ($items === []) {
            throw InvalidInvoiceException::noItems();
        }

        $currency = Currency::from(config()->string('billing.currency'));
        $lines    = [];

        foreach ($items as ['product' => $product, 'quantity' => $quantity]) {
            if ($quantity < 1) {
                throw InvalidInvoiceException::invalidQuantity($quantity);
            }

            if (! $product->is_active) {
                throw InvalidInvoiceException::inactiveProduct($product->sku);
            }

            if ($product->currency !== $currency->value) {
                throw InvalidInvoiceException::currencyMismatch($product->sku);
            }

            $lines[] = [
                'product'  => $product,
                'quantity' => $quantity,
                'total'    => Money::of($product->unit_price, $currency)->multiply($quantity),
            ];
        }

        $taxRateBp = config()->integer('billing.tax_rate_bp');
        $totals    = $this->calculator->totals($currency, array_column($lines, 'total'), $taxRateBp);

        return DB::transaction(function () use ($customer, $creator, $currency, $lines, $taxRateBp, $totals): Invoice {
            $invoice = Invoice::query()->create([
                'customer_id' => $customer->id,
                'created_by'  => $creator->id,
                'status'      => InvoiceStatus::Draft,
                'currency'    => $currency->value,
                'subtotal'    => $totals->subtotal->amount,
                'tax_rate_bp' => $taxRateBp,
                'tax_amount'  => $totals->tax->amount,
                'total'       => $totals->total->amount,
            ]);

            foreach ($lines as $line) {
                // السعر والاسم بيتنسخوا وقت البيع (snapshot)
                $invoice->items()->create([
                    'product_id'  => $line['product']->id,
                    'description' => $line['product']->name,
                    'quantity'    => $line['quantity'],
                    'unit_price'  => $line['product']->unit_price,
                    'line_total'  => $line['total']->amount,
                ]);
            }

            return $invoice->load('items');
        });
    }
}