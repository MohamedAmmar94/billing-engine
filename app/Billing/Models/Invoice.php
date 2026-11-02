<?php

declare(strict_types=1);

namespace App\Billing\Models;

use App\Billing\Enums\InvoiceStatusEnum;
use App\Models\User;
use App\Payments\Models\Payment;
use App\Payments\Models\PaymentLink;
use App\Shared\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    use HasPublicUuid;

    protected $fillable = [
        'number', 'customer_id', 'created_by', 'status', 'currency',
        'subtotal', 'tax_rate_bp', 'tax_amount', 'total',
        'issued_at', 'due_at', 'paid_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'status' => InvoiceStatusEnum::class,
        'issued_at' => 'datetime',
        'due_at' => 'datetime',
        'paid_at' => 'datetime',
        'subtotal' => 'integer',
        'tax_rate_bp' => 'integer',
        'tax_amount' => 'integer',
        'total' => 'integer',
    ];

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<InvoiceItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    /** @return HasMany<PaymentLink, $this> */
    public function paymentLinks(): HasMany
    {
        return $this->hasMany(PaymentLink::class);
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function lockedCopy(): self
    {
        return self::query()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();
    }
}
