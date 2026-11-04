<?php

declare(strict_types=1);

use Illuminate\Support\Str;

/** Header جاهز لـ postJson، ومفتاح جديد كل مرة لو ما حددتش واحد. */
function idem(?string $key = null): array
{
    return ['Idempotency-Key' => $key ?? (string) Str::uuid()];
}
