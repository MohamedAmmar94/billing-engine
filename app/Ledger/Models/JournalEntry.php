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
        'posted_at', 'description', 'currency', 'source_type', 'source_id', 'event', 'reverses_entry_id',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'posted_at' => 'datetime',
        'source_id' => 'integer',
        'reverses_entry_id' => 'integer',
    ];

    /** @return HasMany<JournalLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }
}
