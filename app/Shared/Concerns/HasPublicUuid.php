<?php

declare(strict_types=1);

namespace App\Shared\Concerns;

use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids;

/**
 * عمود `uuid` هو العنوان العام للكيان (URLs والـ API)، والـ PK يفضل bigint.
 */
trait HasPublicUuid
{
    use HasVersion4Uuids;

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function initializeHasPublicUuid(): void
    {
        $this->makeHidden('id');
    }
}
