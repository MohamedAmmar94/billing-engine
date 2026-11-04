<?php

declare(strict_types=1);

namespace App\Shared\Idempotency;

use Illuminate\Database\Eloquent\Model;

class IdempotencyKey extends Model
{
    protected $fillable = [
        'user_id', 'key', 'request_hash', 'lock_token',
        'response_status', 'response_body', 'locked_at', 'expires_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'response_status' => 'integer',
        'locked_at' => 'datetime',
        'expires_at' => 'datetime',
    ];
}
