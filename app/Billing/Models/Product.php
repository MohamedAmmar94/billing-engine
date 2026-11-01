<?php

declare(strict_types=1);

namespace App\Billing\Models;

use App\Shared\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasPublicUuid;

    protected $fillable = ['sku', 'name', 'unit_price', 'currency', 'is_active'];

    protected function casts(): array
    {
        return ['unit_price' => 'integer', 'is_active' => 'boolean'];
    }
}
