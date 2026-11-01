<?php

declare(strict_types=1);

namespace App\Payments\Models;

use App\Billing\Models\Invoice;
use App\Payments\Enums\PaymentLinkStatus;
use App\Shared\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentLink extends Model
{
    use HasPublicUuid;

    protected $fillable = [
        'invoice_id', 'attempt', 'stripe_session_id', 'url', 'status', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => PaymentLinkStatus::class,
            'expires_at' => 'datetime',
            'attempt' => 'integer',
        ];
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
