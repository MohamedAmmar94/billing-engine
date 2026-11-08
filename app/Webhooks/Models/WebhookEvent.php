<?php

declare(strict_types=1);

namespace App\Webhooks\Models;

use App\Shared\Concerns\HasPublicUuid;
use App\Webhooks\Enums\WebhookEventStatusEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $event_id
 * @property string $type
 * @property WebhookEventStatusEnum $status
 * @property array<string, mixed> $payload
 * @property string|null $note
 * @property int $attempts
 * @property Carbon|null $processed_at
 */
class WebhookEvent extends Model
{
    use HasPublicUuid;

    protected $fillable = [
        'provider', 'event_id', 'type', 'payload', 'status',
        'attempts', 'note', 'received_at', 'processed_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'payload' => 'array',
        'status' => WebhookEventStatusEnum::class,
        'attempts' => 'integer',
        'received_at' => 'datetime',
        'processed_at' => 'datetime',
    ];
}
