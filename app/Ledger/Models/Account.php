<?php

declare(strict_types=1);

namespace App\Ledger\Models;

use App\Ledger\Enums\AccountTypeEnum;
use App\Ledger\Enums\NormalSideEnum;
use App\Shared\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Account extends Model
{
    use HasPublicUuid;

    protected $fillable = [
        'code', 'name', 'type', 'normal_side', 'currency', 'is_postable', 'parent_id',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'type' => AccountTypeEnum::class,
        'normal_side' => NormalSideEnum::class,
        'is_postable' => 'boolean',
    ];

    /** @return BelongsTo<Account, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<Account, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }
}
