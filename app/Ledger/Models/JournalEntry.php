<?php

declare(strict_types=1);

namespace App\Ledger\Models;

use App\Shared\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JournalEntry extends Model
{
    use HasPublicUuid;

    public const UPDATED_AT = null;

    protected $fillable = [
        'posted_at', 'description', 'currency', 'source_type', 'source_id', 'event',
    ];

    /** @var array<string, string> */
    protected $casts = ['posted_at' => 'datetime'];

    /** @return HasMany<JournalLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }
}
