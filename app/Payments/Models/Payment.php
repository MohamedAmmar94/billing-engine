<?php

declare(strict_types=1);

namespace App\Payments\Models;

use App\Billing\Models\Invoice;
use App\Shared\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    use HasPublicUuid;

    protected $fillable = [
        'invoice_id', 'payment_link_id', 'stripe_payment_intent_id',
        'amount', 'currency', 'is_applied', 'paid_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'amount' => 'integer',
        'is_applied' => 'boolean',
        'paid_at' => 'datetime',
    ];

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
